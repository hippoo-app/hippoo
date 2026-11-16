<?php
/**
 * Hippoo BI – raw-event pruning
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Remove old raw BI events. */
add_action( 'hippoo_bi_weekly_pruning', 'hippoo_bi_prune_old_raw_events' );

function hippoo_bi_prune_old_raw_events() {
    global $wpdb;

    $tables = array(
        $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS,
        $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS,
    );

    foreach ( $tables as $table ) {
        $wpdb->query( "
            DELETE FROM $table
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 13 MONTH)
            LIMIT 5000
        " );
    }
}
