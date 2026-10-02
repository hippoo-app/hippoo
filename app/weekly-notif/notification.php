<?php
/**
 * Hippoo Weekly Notification – weekly sales notification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Cron registration
// ---------------------------------------------------------------------------

/** Register the weekly notification cron event. */
add_action( 'init', 'hippoo_weekly_notif_schedule_cron' );

function hippoo_weekly_notif_schedule_cron() {
    if ( wp_next_scheduled( 'hippoo_weekly_notif_cron' ) ) {
        return;
    }

    hippoo_weekly_notif_schedule_next();
}

/** Schedule the next Monday notification at 11:00 in the site timezone. */
function hippoo_weekly_notif_schedule_next() {
    $timezone = wp_timezone();
    $now      = new DateTime( 'now', $timezone );
    $next     = new DateTime( 'monday this week 11:00:00', $timezone );

    if ( $next <= $now ) {
        $next->modify( '+1 week' );
    }

    wp_schedule_single_event( $next->getTimestamp(), 'hippoo_weekly_notif_cron' );
}

/** Clear the weekly notification cron event. */
register_deactivation_hook( HIPPOO_MAIN_FILE_PATH, 'hippoo_weekly_notif_clear_cron' );

function hippoo_weekly_notif_clear_cron() {
    $timestamp = wp_next_scheduled( 'hippoo_weekly_notif_cron' );

    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'hippoo_weekly_notif_cron' );
    }
}


// ---------------------------------------------------------------------------
// Notification
// ---------------------------------------------------------------------------

/** Send the weekly sales notification. */
add_action( 'hippoo_weekly_notif_cron', 'hippoo_weekly_notif_run' );

function hippoo_weekly_notif_run() {
    hippoo_weekly_notif_schedule_next();

    if ( ! get_option( 'hippoo_weekly_notif', '1' ) ) {
        return;
    }

    $data = hippoo_weekly_notif_collect_data();

    if ( $data['net_revenue_raw'] <= 0 ) {
        return;
    }

    $title = hippoo_weekly_notif_build_title( $data );
    $body  = hippoo_weekly_notif_build_body( $data );

    $response = hippoo_push_notification( $title, $body );

    if ( is_wp_error( $response ) ) {
        error_log( 'Hippoo weekly notification failed: ' . $response->get_error_message() );
        return;
    }

    $status_code = wp_remote_retrieve_response_code( $response );

    if ( $status_code >= 400 ) {
        error_log( 'Hippoo weekly notification failed with HTTP status ' . $status_code );
    }
}

/** Build the weekly notification title. */
function hippoo_weekly_notif_build_title( $data ) {
    return sprintf(
        HIPPOO_WEEKLY_NOTIF_TITLE_TEMPLATE,
        hippoo_weekly_notif_truncate( $data['site_name'], 20 )
    );
}

/** Build the weekly notification body. */
function hippoo_weekly_notif_build_body( $data ) {
    $lines = array();

    $lines[] = sprintf(
        HIPPOO_WEEKLY_NOTIF_SUMMARY_TEMPLATE,
        $data['net_revenue'],
        $data['order_count'],
        $data['aov']
    );

    $lines[] = sprintf(
        HIPPOO_WEEKLY_NOTIF_BEST_TEMPLATE,
        hippoo_weekly_notif_truncate( $data['top_product'], 20 ),
        $data['top_revenue']
    );

    if ( $data['has_comparison'] ) {
        $direction = $data['change_pct'] >= 0 ? '↑' : '↓';
        $percentage = abs( $data['change_pct'] );

        $lines[] = sprintf(
            HIPPOO_WEEKLY_NOTIF_COMPARISON_TEMPLATE,
            $direction,
            $percentage
        );
    }

    if ( ! empty( $data['churning_soon'] ) ) {
        $lines[] = sprintf(
            HIPPOO_WEEKLY_NOTIF_CHURN_TEMPLATE,
            $data['churning_soon']
        );
    }

    return implode( "\n", $lines );
}

/** Truncate text and append an ellipsis when needed. */
function hippoo_weekly_notif_truncate( $text, $max ) {
    return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '...' : $text;
}
