<?php
/**
 * Hippoo Weekly Notification – weekly sales notification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Send the weekly sales notification. */
add_action( 'hippoo_weekly_notif', 'hippoo_weekly_notif_run' );

function hippoo_weekly_notif_run() {
    if ( ! hippoo_weekly_notif_is_enabled() ) {
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

/** Check whether the Weekly Notification is enabled. */
function hippoo_weekly_notif_is_enabled() {
    return get_option( 'hippoo_weekly_notif', '1' );
}
