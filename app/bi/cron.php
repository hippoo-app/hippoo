<?php
/**
 * Hippoo BI – cron scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Register BI cron events. */
add_action( 'plugins_loaded', 'hippoo_bi_cron_setup' );

function hippoo_bi_cron_setup() {
    if ( ! wp_next_scheduled( 'hippoo_bi_daily_churn' ) ) {
        $timezone = wp_timezone();
        $datetime = new DateTime( 'tomorrow 2:00 AM', $timezone );
        $timestamp = $datetime->getTimestamp();

        wp_schedule_event( $timestamp, 'daily', 'hippoo_bi_daily_churn' );
    }

    if ( ! wp_next_scheduled( 'hippoo_bi_sync_lookup' ) ) {
        wp_schedule_event( time(), 'hourly', 'hippoo_bi_sync_lookup' );
    }

    if ( ! wp_next_scheduled( 'hippoo_bi_aggregate_stats' ) ) {
        wp_schedule_event( time() + 60, 'hourly', 'hippoo_bi_aggregate_stats' );
    }

    if ( ! wp_next_scheduled( 'hippoo_bi_weekly_pruning' ) ) {
        wp_schedule_event( time(), 'weekly', 'hippoo_bi_weekly_pruning' );
    }
}

/** Clear all BI scheduled events. */
register_deactivation_hook( HIPPOO_MAIN_FILE_PATH, 'hippoo_bi_cron_clear' );

function hippoo_bi_cron_clear() {
    $hooks = array(
        'hippoo_bi_daily_churn',
        'hippoo_bi_sync_lookup',
        'hippoo_bi_aggregate_stats',
        'hippoo_bi_weekly_pruning',
        'hippoo_bi_database_migrate',
    );

    $cron = _get_cron_array();
    if ( empty( $cron ) || ! is_array( $cron ) ) {
        return;
    }

    $changed = false;
    foreach ( $cron as $timestamp => $cronhooks ) {
        foreach ( $hooks as $hook ) {
            if ( isset( $cronhooks[ $hook ] ) ) {
                unset( $cron[ $timestamp ][ $hook ] );
                $changed = true;
            }
        }

        if ( empty( $cron[ $timestamp ] ) ) {
            unset( $cron[ $timestamp ] );
        }
    }

    if ( $changed ) {
        _set_cron_array( $cron );
    }
}

/** Rebuild BI background tasks after schema migration or reactivation. */
add_action( 'hippoo_bi_database_schema_migrated', 'hippoo_bi_cron_rebuild', 10, 2 );
register_activation_hook( HIPPOO_MAIN_FILE_PATH, 'hippoo_bi_cron_rebuild' );

function hippoo_bi_cron_rebuild( $version = '', $previous_version = '' ) {
    hippoo_bi_cron_clear();
    hippoo_bi_cron_setup();

    wp_schedule_single_event( time() + 10, 'hippoo_bi_database_migrate' );
    wp_schedule_single_event( time() + 15, 'hippoo_bi_sync_lookup', array( 1 ) );
    wp_schedule_single_event( time() + 60, 'hippoo_bi_aggregate_stats' );
    wp_schedule_single_event( time() + 120, 'hippoo_bi_daily_churn' );
}
