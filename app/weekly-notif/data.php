<?php
/**
 * Hippoo Weekly Notification – sales data
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Collect the sales data required by the weekly notification. */
function hippoo_weekly_notif_collect_data() {
    $timezone = wp_timezone();
    $end      = new DateTime( 'last sunday 23:59:59', $timezone );
    $start    = ( clone $end )->modify( '-6 days' )->setTime( 0, 0, 0 );

    $previous_end   = ( clone $start )->modify( '-1 second' );
    $previous_start = ( clone $previous_end )->modify( '-6 days' )->setTime( 0, 0, 0 );

    $current = hippoo_weekly_notif_get_sales_data( $start, $end );
    $previous = hippoo_weekly_notif_get_sales_data( $previous_start, $previous_end );

    $data = array(
        'site_name'        => get_bloginfo( 'name' ),
        'net_revenue'     => hippoo_weekly_notif_format_price( $current['net_revenue'] ),
        'net_revenue_raw' => $current['net_revenue'],
        'order_count'     => $current['order_count'],
        'aov'             => hippoo_weekly_notif_format_price( $current['order_count'] > 0 ? $current['net_revenue'] / $current['order_count'] : 0 ),
        'top_product'     => $current['top_product'],
        'top_revenue'     => hippoo_weekly_notif_format_price( $current['top_revenue'] ),
        'has_comparison'  => false,
        'change_pct'      => 0,
        'churning_soon'   => hippoo_weekly_notif_get_churning_soon(),
    );

    if ( $previous['order_count'] > 0 && $previous['net_revenue'] > 0 ) {
        $data['has_comparison'] = true;
        $data['change_pct'] = round( ( ( $current['net_revenue'] - $previous['net_revenue'] ) / $previous['net_revenue'] ) * 100 );
    }

    return $data;
}

/** Get weekly sales totals and top product from BI order tables. */
function hippoo_weekly_notif_get_sales_data( $start, $end ) {
    global $wpdb;

    $table_stats  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;

    $included_statuses = hippoo_bi_get_report_order_statuses();

    if ( empty( $included_statuses ) ) {
        return array(
            'order_count'  => 0,
            'net_revenue'  => 0,
            'top_product'  => '',
            'top_revenue'  => 0,
        );
    }

    $status_placeholders = implode( ', ', array_fill( 0, count( $included_statuses ), '%s' ) );

    $from = $start->format( 'Y-m-d H:i:s' );
    $to = $end->format( 'Y-m-d H:i:s' );

    $stats = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            COUNT(order_id) as order_count,
            SUM(net) as net_revenue
        FROM $table_stats
        WHERE order_status IN ($status_placeholders)
            AND date_created BETWEEN %s AND %s
    ", array_merge( $included_statuses, array( $from, $to ) ) ), ARRAY_A );

    $top_product = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            l.product_id,
            SUM(l.revenue) as revenue
        FROM $table_lookup l
        INNER JOIN $table_stats s ON s.order_id = l.order_id
        WHERE s.order_status IN ($status_placeholders)
            AND l.date_created BETWEEN %s AND %s
        GROUP BY l.product_id
        ORDER BY revenue DESC
        LIMIT 1
    ", array_merge( $included_statuses, array( $from, $to ) ) ) );

    $product_name = '';
    $product_revenue = $top_product ? (float) $top_product->revenue : 0;

    if ( $top_product && $top_product->product_id ) {
        $product = wc_get_product( $top_product->product_id );
        $product_name = $product ? $product->get_name() : '';
    }

    return array(
        'order_count'  => (int) ( $stats['order_count'] ?? 0 ),
        'net_revenue'  => (float) ( $stats['net_revenue'] ?? 0 ),
        'top_product'  => $product_name,
        'top_revenue'  => $product_revenue,
    );
}

/** Get customers who are approaching the BI churn threshold. */
function hippoo_weekly_notif_get_churning_soon() {
    if ( ! function_exists( 'hippoo_bi_get_churn_summary' ) ) {
        return 0;
    }

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;

    $timezone = wp_timezone();
    $now      = new DateTime( 'now', $timezone );
    $from     = ( clone $now )->modify( '-90 days' )->format( 'Y-m-d' );
    $to       = ( clone $now )->modify( '-75 days' )->format( 'Y-m-d' );

    $churning_soon =
        (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM $table
            WHERE last_order_date > %s
                AND last_order_date <= %s
        ", $from, $to ) );

    return $churning_soon;
}

/** Format a monetary value using the WooCommerce store currency. */
function hippoo_weekly_notif_format_price( $amount ) {
    if ( ! function_exists( 'wc_price' ) ) {
        return number_format_i18n( (float) $amount, 0 );
    }

    $formatted = wc_price( (float) $amount, array( 'decimals' => 0 ) );

    return html_entity_decode( wp_strip_all_tags( $formatted ), ENT_QUOTES, get_bloginfo( 'charset' ) );
}
