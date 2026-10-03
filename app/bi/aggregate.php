<?php
/**
 * Hippoo BI – daily aggregate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Switch helpers
// ---------------------------------------------------------------------------

/**
 * Whether reports should read from daily summary tables.
 * Threshold: ranges longer than 90 days.
 */
function hippoo_bi_use_summary( $from, $to ) {
    $from_ts = strtotime( $from );
    $to_ts   = strtotime( $to );

    if ( ! $from_ts || ! $to_ts ) {
        return false;
    }

    return ( $to_ts - $from_ts ) > ( 90 * DAY_IN_SECONDS );
}

/** True when at least one daily_stats row exists in the range. */
function hippoo_bi_summary_is_ready( $from, $to ) {
    global $wpdb;

    $table     = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_STATS;
    $from_date = date( 'Y-m-d', strtotime( $from ) );
    $to_date   = date( 'Y-m-d', strtotime( $to ) );

    $count = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(*) FROM $table WHERE date BETWEEN %s AND %s
    ", $from_date, $to_date ) );

    return $count > 0;
}


// ---------------------------------------------------------------------------
// Read helpers
// ---------------------------------------------------------------------------

/** Sum site-wide daily rows for a range. */
function hippoo_bi_get_site_stats_from_summary( $from, $to ) {
    global $wpdb;

    $table     = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_STATS;
    $from_date = date( 'Y-m-d', strtotime( $from ) );
    $to_date   = date( 'Y-m-d', strtotime( $to ) );

    $result = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            SUM(views) as total_views,
            SUM(unique_sessions) as unique_sessions,
            SUM(new_visitors) as new_visitors,
            SUM(returning_visitors) as returning_visitors,
            SUM(bounce_sessions) as bounce_sessions,
            SUM(orders) as order_count,
            SUM(total) as total_revenue,
            SUM(net) as net_revenue,
            SUM(refund) as total_refund,
            SUM(new_customers) as new_customers,
            SUM(returning_customers) as returning_customers
        FROM $table
        WHERE date BETWEEN %s AND %s
    ", $from_date, $to_date ) );

    return $result;
}

/** Daily chart rows from site summary. */
function hippoo_bi_get_site_chart_from_summary( $from, $to ) {
    global $wpdb;

    $table     = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_STATS;
    $from_date = date( 'Y-m-d', strtotime( $from ) );
    $to_date   = date( 'Y-m-d', strtotime( $to ) );

    $rows = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            date,
            views,
            unique_sessions as sessions,
            orders,
            net as revenue
        FROM $table
        WHERE date BETWEEN %s AND %s
        ORDER BY date ASC
    ", $from_date, $to_date ) );

    return $rows ?: array();
}

/** Product metrics aggregated from daily product stats. */
function hippoo_bi_get_product_stats_from_summary( $from, $to, $product_ids = array() ) {
    global $wpdb;

    $table     = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_PRODUCT_STATS;
    $from_date = date( 'Y-m-d', strtotime( $from ) );
    $to_date   = date( 'Y-m-d', strtotime( $to ) );

    $sql    = "
        SELECT
            product_id,
            SUM(views) as views,
            SUM(unique_sessions) as unique_sessions,
            SUM(add_to_cart) as add_to_cart,
            SUM(atc_sessions) as atc_sessions,
            SUM(orders) as orders,
            SUM(revenue) as revenue
        FROM $table
        WHERE date BETWEEN %s AND %s
    ";
    $params = array( $from_date, $to_date );

    if ( ! empty( $product_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        $sql         .= " AND product_id IN ($placeholders)";
        $params       = array_merge( $params, array_map( 'intval', $product_ids ) );
    }

    $sql .= ' GROUP BY product_id';

    $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    $result = array();

    foreach ( $rows as $row ) {
        $pid            = (int) $row['product_id'];
        $result[ $pid ] = array(
            'views'           => (int) $row['views'],
            'unique_sessions' => (int) $row['unique_sessions'],
            'add_to_cart'     => (int) $row['add_to_cart'],
            'atc_sessions'    => (int) $row['atc_sessions'],
            'orders'          => (int) $row['orders'],
            'revenue'         => (float) $row['revenue'],
        );
    }

    return $result;
}

/** Per-day chart for one product from summary. */
function hippoo_bi_get_product_chart_from_summary( $from, $to, $product_id ) {
    global $wpdb;

    $table     = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_PRODUCT_STATS;
    $from_date = date( 'Y-m-d', strtotime( $from ) );
    $to_date   = date( 'Y-m-d', strtotime( $to ) );

    $rows = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            date,
            views,
            unique_sessions as sessions,
            add_to_cart,
            orders,
            revenue
        FROM $table
        WHERE product_id = %d
          AND date BETWEEN %s AND %s
        ORDER BY date ASC
    ", $product_id, $from_date, $to_date ) );

    return $rows ?: array();
}


// ---------------------------------------------------------------------------
// Write helpers
// ---------------------------------------------------------------------------

/** Fill missing daily stats from earliest data to yesterday, in batches. */
function hippoo_bi_run_daily_aggregate( $batch_days = 30 ) {
    global $wpdb;

    $table_pv    = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $table_stats = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $table_daily = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_STATS;

    $earliest_pv = $wpdb->get_var( "SELECT MIN(DATE(created_at)) FROM $table_pv" );
    $earliest_or = $wpdb->get_var( "SELECT MIN(DATE(date_created)) FROM $table_stats" );

    $candidates = array_filter( array( $earliest_pv, $earliest_or ) );
    if ( empty( $candidates ) ) {
        return;
    }

    $start = min( $candidates );
    $end   = ( new DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' );

    
    if ( $start > $end ) {
        return;
    }

    // Existing dates already in daily_stats within the full range
    $existing = $wpdb->get_col( $wpdb->prepare( "
        SELECT date FROM $table_daily WHERE date BETWEEN %s AND %s
    ", $start, $end ) );
    $existing = array_flip( $existing );

    // Build ordered list of missing days
    $missing  = array();
    $current  = new DateTimeImmutable( $end );
    $start_obj = new DateTimeImmutable( $start );

    while ( $current >= $start_obj ) {
        $day = $current->format( 'Y-m-d' );
        if ( ! isset( $existing[ $day ] ) ) {
            $missing[] = $day;
        }
        $current = $current->modify( '-1 day' );
    }

    if ( empty( $missing ) ) {
        return;
    }

    $batch_days  = max( 1, absint( $batch_days ) );
    $batch       = array_slice( $missing, 0, $batch_days );

    foreach ( $batch as $day ) {
        hippoo_bi_aggregate_day( $day );
    }

    $remaining = count( $missing ) - count( $batch );

    if ( $remaining > 0 ) {
        wp_schedule_single_event( time() + 5, 'hippoo_bi_daily_aggregate', array( $batch_days ) );
        return;
    }

    hippoo_bi_clear_report_caches();
}

/** Aggregate site + product metrics for one day into daily tables. */
function hippoo_bi_aggregate_day( $date ) {
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        return;
    }

    $day_args = array(
        'period'    => 'custom',
        'date_from' => $date,
        'date_to'   => $date,
    );

    hippoo_bi_aggregate_site_day( $date, $day_args );
    hippoo_bi_aggregate_product_day( $date, $day_args );
}

/** Upsert one row in hippoo_daily_stats using report helpers. */
function hippoo_bi_aggregate_site_day( $date, $day_args ) {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_STATS;

    $traffic  = hippoo_bi_get_traffic_stats( $day_args );
    $views    = (int) ( $traffic->total_views ?? 0 );
    $sessions = (int) ( $traffic->unique_sessions ?? 0 );

    $bounce_sessions = (int) hippoo_bi_get_single_sessions_count( $day_args );

    $visitors           = hippoo_bi_get_visitors( $day_args );
    $new_visitors       = (int) ( $visitors->new_visitors ?? 0 );
    $returning_visitors = (int) ( $visitors->returning_visitors ?? 0 );

    $sales         = hippoo_bi_get_sales_stats( $day_args );
    $orders        = (int) ( $sales->order_count ?? 0 );
    $total_revenue = (float) ( $sales->total_revenue ?? 0 );
    $net_revenue   = (float) ( $sales->net_revenue ?? 0 );
    $refund_amount = (float) ( $sales->total_refund ?? 0 );

    $customers           = hippoo_bi_get_customer_summary( $day_args );
    $new_customers       = (int) ( $customers->new_customers ?? 0 );
    $returning_customers = (int) ( $customers->returning_customers ?? 0 );

    $wpdb->replace( $table, array(
        'date'                => $date,
        'views'               => $views,
        'unique_sessions'     => $sessions,
        'new_visitors'        => $new_visitors,
        'returning_visitors'  => $returning_visitors,
        'bounce_sessions'     => $bounce_sessions,
        'orders'              => $orders,
        'total'               => $total_revenue,
        'net'                 => $net_revenue,
        'refund'              => $refund_amount,
        'new_customers'       => $new_customers,
        'returning_customers' => $returning_customers,
        'calculated_at'       => current_time( 'mysql' ),
    ) );
}

/** Replace all product rows for one day using report helpers. */
function hippoo_bi_aggregate_product_day( $date, $day_args ) {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_DAILY_PRODUCT_STATS;

    $wpdb->delete( $table, array( 'date' => $date ) );

    $traffic_args = array_merge( $day_args, array( 'min_views' => 0 ) );
    $traffic_rows = hippoo_bi_get_product_traffic_stats( $traffic_args );

    if ( empty( $traffic_rows ) ) {
        return;
    }

    $product_ids = array_map( 'intval', wp_list_pluck( $traffic_rows, 'product_id' ) );
    $sales_all   = hippoo_bi_get_product_sales_stats( $day_args, $product_ids );
    $atc_all     = hippoo_bi_get_product_atc_stats( $day_args, $product_ids );

    $now = current_time( 'mysql' );

    foreach ( $traffic_rows as $row ) {
        $pid = (int) $row['product_id'];

        $wpdb->insert( $table, array(
            'date'            => $date,
            'product_id'      => $pid,
            'views'           => (int) $row['views'],
            'unique_sessions' => (int) $row['unique_sessions'],
            'add_to_cart'     => (int) ( $atc_all[ $pid ]['add_to_cart'] ?? 0 ),
            'atc_sessions'    => (int) ( $atc_all[ $pid ]['atc_sessions'] ?? 0 ),
            'orders'          => (int) ( $sales_all[ $pid ]['orders'] ?? 0 ),
            'revenue'         => (float) ( $sales_all[ $pid ]['revenue'] ?? 0 ),
            'calculated_at'   => $now,
        ) );
    }
}
