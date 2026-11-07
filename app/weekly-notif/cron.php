<?php
/**
 * Hippoo Weekly Notification – cron scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Register the weekly notification cron event. */
add_action( 'plugins_loaded', 'hippoo_weekly_notif_cron_setup' );

function hippoo_weekly_notif_cron_setup() {
    if ( wp_next_scheduled( 'hippoo_weekly_notif' ) ) {
        return;
    }

    $timezone = wp_timezone();
    $datetime = new DateTime( 'monday this week 11:00:00', $timezone );
    $timestamp = $datetime->getTimestamp();

    if ( $timestamp <= time() ) {
        $datetime->modify( '+1 week' );
    }

    wp_schedule_event( $timestamp, 'weekly', 'hippoo_weekly_notif' );
}

/** Clear the weekly notification cron event. */
register_deactivation_hook( HIPPOO_MAIN_FILE_PATH, 'hippoo_weekly_notif_cron_clear' );

function hippoo_weekly_notif_cron_clear() {
    $timestamp = wp_next_scheduled( 'hippoo_weekly_notif' );

    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'hippoo_weekly_notif' );
    }
}
