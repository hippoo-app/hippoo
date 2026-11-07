<?php
/**
 * Hippoo BI – daily aggregate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Backfill incomplete dates in batches and refresh today/yesterday hourly. */
add_action( 'hippoo_bi_aggregate_stats', 'hippoo_bi_aggregate_stats_run' );

function hippoo_bi_aggregate_stats_run() {
    $chunk = 50;
    $missing = hippoo_bi_aggregate_missing_dates( $chunk );

    foreach ( $missing as $day ) {
        hippoo_bi_aggregate_date( $day );
    }

    if ( count( $missing ) >= $chunk ) {
        // Still more raw days without a site_stats row — continue shortly.
        wp_schedule_single_event( time() + 5, 'hippoo_bi_aggregate_stats' );
        return;
    }

    $tz = wp_timezone();
    hippoo_bi_aggregate_date( ( new DateTimeImmutable( 'yesterday', $tz ) )->format( 'Y-m-d' ) );
    hippoo_bi_aggregate_date( ( new DateTimeImmutable( 'today', $tz ) )->format( 'Y-m-d' ) );
}

/** Calendar days that exist in pageviews or order_stats but not yet in site_stats. */
function hippoo_bi_aggregate_missing_dates( $limit = 50 ) {
    global $wpdb;

    $table_pv   = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $table_os = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $table_ss  = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;

    $yesterday = ( new DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' );

    $sql = "
        SELECT dates.d
        FROM (
            SELECT DATE(created_at) AS d FROM $table_pv GROUP BY DATE(created_at)
            UNION
            SELECT DATE(date_created) AS d FROM $table_os GROUP BY DATE(date_created)
        ) dates
        LEFT JOIN $table_ss s ON s.date = dates.d
        WHERE s.date IS NULL
          AND dates.d <= %s
        ORDER BY dates.d DESC
    ";

    $params = array( $yesterday );
    $limit  = absint( $limit );

    if ( $limit > 0 ) {
        $sql     .= ' LIMIT %d';
        $params[] = $limit;
    }

    $rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

    return is_array( $rows ) ? $rows : array();
}

/** Aggregate one calendar day into site_stats + post_stats. */
function hippoo_bi_aggregate_date( $date ) {
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        return;
    }

    hippoo_bi_aggregate_site_stats( $date );
    hippoo_bi_aggregate_post_stats( $date );
}

/** Upsert one site_stats row from raw pageviews + order_stats. */
function hippoo_bi_aggregate_site_stats( $date ) {
    global $wpdb;

    $table_pv = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $table_os = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $table_ss = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;

    $from = $date . ' 00:00:00';
    $to   = $date . ' 23:59:59';

    $traffic = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            COUNT(*) as total_views,
            COUNT(DISTINCT session_id) as unique_sessions,
            SUM(CASE WHEN device_type = 'm' THEN 1 ELSE 0 END) as mobile_views,
            SUM(CASE WHEN device_type = 't' THEN 1 ELSE 0 END) as tablet_views,
            SUM(CASE WHEN device_type = 'd' THEN 1 ELSE 0 END) as desktop_views
        FROM $table_pv
        WHERE created_at BETWEEN %s AND %s
    ", $from, $to ) );

    $bounce = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(*) FROM (
            SELECT session_id
            FROM $table_pv
            WHERE created_at BETWEEN %s AND %s
            GROUP BY session_id
            HAVING COUNT(*) = 1
        ) b
    ", $from, $to ) );

    $sales = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            COUNT(order_id) as order_count,
            COALESCE(SUM(total), 0) as total_revenue,
            COALESCE(SUM(net), 0) as net_revenue,
            COALESCE(SUM(refund), 0) as total_refund
        FROM $table_os
        WHERE date_created BETWEEN %s AND %s
    ", $from, $to ) );

    $wpdb->replace( $table_ss, array(
        'date'                => $date,
        'views'               => (int) ( $traffic->total_views ?? 0 ),
        'unique_sessions'     => (int) ( $traffic->unique_sessions ?? 0 ),
        'bounce_sessions'     => $bounce,
        'orders'              => (int) ( $sales->order_count ?? 0 ),
        'total'               => (float) ( $sales->total_revenue ?? 0 ),
        'net'                 => (float) ( $sales->net_revenue ?? 0 ),
        'refund'              => (float) ( $sales->total_refund ?? 0 ),
        'mobile_views'        => (int) ( $traffic->mobile_views ?? 0 ),
        'tablet_views'        => (int) ( $traffic->tablet_views ?? 0 ),
        'desktop_views'       => (int) ( $traffic->desktop_views ?? 0 ),
    ) );
}

/** Replace all post_stats rows for one day from raw pageviews / ATC / orders. */
function hippoo_bi_aggregate_post_stats( $date ) {
    global $wpdb;

    $table_pv  = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $table_atc = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $table_ol  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $table_ps  = $wpdb->prefix . HIPPOO_BI_TABLE_POST_STATS;

    $from = $date . ' 00:00:00';
    $to   = $date . ' 23:59:59';

    $wpdb->delete( $table_ps, array( 'date' => $date ) );
    $products = array();

    $traffic_rows = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            post_id,
            MAX(path_id) as path_id,
            COUNT(*) as views,
            COUNT(DISTINCT session_id) as unique_sessions
        FROM $table_pv
        WHERE created_at BETWEEN %s AND %s
          AND post_id > 0
          AND path_id > 0
        GROUP BY post_id
    ", $from, $to ), ARRAY_A );

    foreach ( $traffic_rows as $row ) {
        $products[ $row['post_id'] ] = $row;
    }

    $atc_rows = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            product_id,
            COUNT(*) as add_to_cart,
            COUNT(DISTINCT session_id) as atc_sessions
        FROM $table_atc
        WHERE created_at BETWEEN %s AND %s
        GROUP BY product_id
    ", $from, $to ), ARRAY_A );

    foreach ( $atc_rows as $row ) {
        $post_id = (int) $row['product_id'];

        $products[ $post_id ]['post_id']      = $post_id;
        $products[ $post_id ]['add_to_cart']  = (int) $row['add_to_cart'];
        $products[ $post_id ]['atc_sessions'] = (int) $row['atc_sessions'];
    }

    $sales_rows = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            product_id,
            COUNT(DISTINCT order_id) as orders,
            SUM(revenue) as revenue
        FROM $table_ol
        WHERE date_created BETWEEN %s AND %s
        GROUP BY product_id
    ", $from, $to ), ARRAY_A );

    foreach ( $sales_rows as $row ) {
        $post_id = (int) $row['product_id'];

        $products[ $post_id ]['post_id'] = $post_id;
        $products[ $post_id ]['orders']  = (int) $row['orders'];
        $products[ $post_id ]['revenue'] = (float) $row['revenue'];
    }

    foreach ( $products as $post_id => $row ) {
        $wpdb->insert( $table_ps, array(
            'date'            => $date,
            'post_id'         => $post_id,
            'path_id'         => (int) ( $row['path_id'] ?? 0 ),
            'views'           => (int) ( $row['views'] ?? 0 ),
            'unique_sessions' => (int) ( $row['unique_sessions'] ?? 0 ),
            'add_to_cart'     => (int) ( $row['add_to_cart'] ?? 0 ),
            'atc_sessions'    => (int) ( $row['atc_sessions'] ?? 0 ),
            'orders'          => (int) ( $row['orders'] ?? 0 ),
            'revenue'         => (float) ( $row['revenue'] ?? 0 ),
        ) );
    }
}
