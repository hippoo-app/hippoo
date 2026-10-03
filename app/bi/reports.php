<?php
/**
 * Hippoo BI – reports and analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/reports/traffic.php';
require_once __DIR__ . '/reports/sales.php';
require_once __DIR__ . '/reports/products.php';
require_once __DIR__ . '/reports/churn.php';
require_once __DIR__ . '/reports/overview.php';


// ---------------------------------------------------------------------------
// Report helpers
// ---------------------------------------------------------------------------

/** Get the current BI report date range. */
function hippoo_bi_get_date_range( $period, $date_from = null, $date_to = null ) {
    $period = sanitize_text_field( strtolower( $period ) );
    $now    = current_time( 'mysql' );

    switch ( $period ) {
        case 'today':
            $from = date( 'Y-m-d 00:00:00', strtotime( 'today' ) );
            $to   = $now;
            break;

        case 'yesterday':
            $from = date( 'Y-m-d 00:00:00', strtotime( 'yesterday' ) );
            $to   = date( 'Y-m-d 23:59:59', strtotime( 'yesterday' ) );
            break;

        case 'this_week':
            $from = date( 'Y-m-d 00:00:00', strtotime( 'this week' ) );
            $to   = $now;
            break;

        case 'last_week':
            $from = date( 'Y-m-d 00:00:00', strtotime( '-1 week' ) );
            $to   = date( 'Y-m-d 23:59:59', strtotime( '-1 week + 6 days' ) );
            break;

        case 'this_month':
            $from = date( 'Y-m-01 00:00:00' );
            $to   = $now;
            break;

        case 'last_month':
            $from = date( 'Y-m-01 00:00:00', strtotime( 'first day of last month' ) );
            $to   = date( 'Y-m-t 23:59:59', strtotime( 'last day of last month' ) );
            break;

        case 'last_3_months':
            $from = date( 'Y-m-d 00:00:00', strtotime( '-3 months' ) );
            $to   = $now;
            break;

        case 'last_6_months':
            $from = date( 'Y-m-d 00:00:00', strtotime( '-6 months' ) );
            $to   = $now;
            break;

        case 'this_year':
            $from = date( 'Y-01-01 00:00:00' );
            $to   = $now;
            break;

        case 'last_year':
            $from = date( 'Y-01-01 00:00:00', strtotime( 'first day of last year' ) );
            $to   = date( 'Y-12-t 23:59:59', strtotime( 'last day of last year' ) );
            break;

        case 'custom':
            $from = ! empty( $date_from ) ? sanitize_text_field( $date_from ) . ' 00:00:00' : date( 'Y-m-01 00:00:00' );
            $to   = ! empty( $date_to ) ? sanitize_text_field( $date_to ) . ' 23:59:59' : $now;
            break;

        default:
            $from = date( 'Y-m-01 00:00:00' );
            $to   = $now;
    }

    return array(
        'from' => $from,
        'to'   => $to,
    );
}

/** Get the BI report date range immediately before the current range. */
function hippoo_bi_get_previous_date_range( $period, $date_from = null, $date_to = null ) {
    $current = hippoo_bi_get_date_range( $period, $date_from, $date_to );

    $from = strtotime( $current['from'] );
    $to   = strtotime( $current['to'] );
    $diff = $to - $from;

    return array(
        'from' => date( 'Y-m-d H:i:s', $from - $diff ),
        'to'   => date( 'Y-m-d H:i:s', $to - $diff ),
    );
}

/** Get the WooCommerce order statuses included in BI reports. */
function hippoo_bi_get_report_order_statuses() {
    $excluded = array();
    if ( class_exists( '\WC_Admin_Settings' ) ) {
        $excluded = \WC_Admin_Settings::get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
    } else {
        $excluded = get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
    }

    $excluded = apply_filters( 'woocommerce_analytics_excluded_order_statuses', $excluded );
    $excluded = array_map( function ( $status ) {
        return str_replace( 'wc-', '', $status );
    }, (array) $excluded );

    $all = array_map( function ( $status ) {
        return str_replace( 'wc-', '', $status );
    }, array_keys( wc_get_order_statuses() ) );

    // Always exclude auto-draft/trash
    $included = array_values( array_diff( $all, $excluded ) );
    $included = array_diff( $included, array( 'auto-draft', 'trash' ) );

    return $included ?: wc_get_is_paid_statuses();
}

/** Clear cached BI report data. */
function hippoo_bi_clear_report_caches() {
    global $wpdb;

    $wpdb->query( "
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_hippoo_bi_%'
    " );

    $wpdb->query( "
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_timeout_hippoo_bi_%'
    " );
}
