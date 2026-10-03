<?php
/**
 * Hippoo BI – sales reports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/** Build the BI sales overview report. */
function hippoo_bi_get_sales_overview( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_sales_overview_' . md5( $period . $date_from . $date_to );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $summary = hippoo_bi_get_sales_summary( $args );

    if ( is_wp_error( $summary ) ) {
        return $summary;
    }

    $result = array_merge(
        $summary,
        array(
            'revenue_chart' => hippoo_bi_get_sales_chart( $args ),
        )
    );

    set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
    return $result;
}

/** Get the sales summary report. */
function hippoo_bi_get_sales_summary( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_sales_summary_' . md5( $period . $date_from . $date_to );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $date_range = hippoo_bi_get_date_range( $period, $date_from, $date_to );

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $stats = hippoo_bi_get_site_stats_from_summary( $date_range['from'], $date_range['to'] );

        $total_views         = (int) ( $stats->total_views ?? 0 );
        $unique_sessions     = (int) ( $stats->unique_sessions ?? 0 );
        $order_count         = (int) ( $stats->order_count ?? 0 );
        $total_revenue       = (float) ( $stats->total_revenue ?? 0 );
        $net_revenue         = (float) ( $stats->net_revenue ?? 0 );
        $refund_amount       = (float) ( $stats->total_refund ?? 0 );
        $new_customers       = (int) ( $stats->new_customers ?? 0 );
        $returning_customers = (int) ( $stats->returning_customers ?? 0 );

        $avg_order_value   = $order_count > 0 ? round( $net_revenue / $order_count, 2 ) : 0;
        $refund_rate       = $total_revenue > 0 ? round( ( $refund_amount / $total_revenue ) * 100, 2 ) : 0;
        $conversion_rate   = $unique_sessions > 0 ? round( ( $order_count / $unique_sessions ) * 100, 2 ) : 0;
        $revenue_per_visit = $total_views > 0 ? round( $net_revenue / $total_views, 2 ) : 0;

        $prev_revenue = hippoo_bi_get_previous_sales_revenue( $args );
        $change = $prev_revenue > 0
            ? round( ( ( $total_revenue - $prev_revenue ) / $prev_revenue ) * 100, 1 )
            : ( $total_revenue > 0 ? 100 : 0 );

        $response = array(
            'total_revenue'       => round( $total_revenue ),
            'net_revenue'         => round( $net_revenue ),
            'refund_amount'       => round( $refund_amount ),
            'order_count'         => $order_count,
            'avg_order_value'     => round( $avg_order_value ),
            'refund_rate'         => $refund_rate,
            'conversion_rate'     => $conversion_rate,
            'revenue_per_visit'   => $revenue_per_visit,
            'new_customers'       => $new_customers,
            'returning_customers' => $returning_customers,
            'comparison'          => array(
                'vs_previous_period' => ( $change >= 0 ? '+' : '' ) . $change . '%',
                'previous_revenue'   => round( $prev_revenue ),
            ),
        );

        set_transient( $cache_key, $response, HOUR_IN_SECONDS );
        return $response;
    }

    $sales_stats   = hippoo_bi_get_sales_stats( $args );
    $traffic_stats = hippoo_bi_get_sales_traffic_stats( $args );
    $customers     = hippoo_bi_get_customer_summary( $args );
    $prev_revenue  = hippoo_bi_get_previous_sales_revenue( $args );

    $total_views     = (int) ( $traffic_stats->total_views ?? 0 );
    $unique_sessions = (int) ( $traffic_stats->unique_sessions ?? 0 );

    $order_count   = (int) ( $sales_stats->order_count ?? 0 );
    $total_revenue = (float) ( $sales_stats->total_revenue ?? 0 );
    $net_revenue   = (float) ( $sales_stats->net_revenue ?? 0 );
    $refund_amount = (float) ( $sales_stats->total_refund ?? 0 );

    $net_revenue = $net_revenue - $refund_amount;

    $avg_order_value   = $order_count > 0 ? round( $net_revenue / $order_count, 2 ) : 0;
    $refund_rate       = $total_revenue > 0 ? round( ( $refund_amount / $total_revenue ) * 100, 2 ) : 0;
    $conversion_rate   = $unique_sessions > 0 ? round( ( $order_count / $unique_sessions ) * 100, 2 ) : 0;
    $revenue_per_visit = $total_views > 0 ? round( $net_revenue / $total_views, 2 ) : 0;

    $change = $prev_revenue > 0
        ? round( ( ( $total_revenue - $prev_revenue ) / $prev_revenue ) * 100, 1 )
        : ( $total_revenue > 0 ? 100 : 0 );

    $response = array(
        'total_revenue'       => round( $total_revenue ),
        'net_revenue'         => round( $net_revenue ),
        'refund_amount'       => round( $refund_amount ),
        'order_count'         => $order_count,
        'avg_order_value'     => round( $avg_order_value ),
        'refund_rate'         => $refund_rate,
        'conversion_rate'     => $conversion_rate,
        'revenue_per_visit'   => $revenue_per_visit,
        'new_customers'       => $customers->new_customers,
        'returning_customers' => $customers->returning_customers,
        'comparison'          => array(
            'vs_previous_period' => ( $change >= 0 ? '+' : '' ) . $change . '%',
            'previous_revenue'   => round( $prev_revenue ),
        ),
    );

    set_transient( $cache_key, $response, 15 * MINUTE_IN_SECONDS );
    return $response;
}

/** Get sales metrics for a report period. */
function hippoo_bi_get_sales_stats( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $stats = $wpdb->get_row( $wpdb->prepare( "
        SELECT 
            COUNT(order_id) as order_count,
            SUM(total) as total_revenue,
            SUM(net) as net_revenue,
            SUM(refund) as total_refund
        FROM $table
        WHERE date_created BETWEEN %s AND %s
    ", $date_range['from'], $date_range['to'] ) );

    return $stats;
}

/** Get traffic metrics required by sales reports. */
function hippoo_bi_get_sales_traffic_stats( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $stats = $wpdb->get_row( $wpdb->prepare( "
        SELECT 
            COUNT(*) as total_views,
            COUNT(DISTINCT session_id) as unique_sessions
        FROM $table 
        WHERE created_at BETWEEN %s AND %s
    ", $date_range['from'], $date_range['to'] ) );

    return $stats;
}

/** Get new and returning customer counts for a report period. */
function hippoo_bi_get_customer_summary( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    // Identity = billing_email when present, otherwise customer_id (covers both registered + guests)
    $customers = $wpdb->get_row( $wpdb->prepare( "
        SELECT 
            COUNT(DISTINCT CASE WHEN first_order >= %s THEN identity END) as new_customers,
            COUNT(DISTINCT CASE WHEN first_order < %s THEN identity END) as returning_customers
        FROM (
            SELECT 
                COALESCE(NULLIF(billing_email, ''), CONCAT('uid_', customer_id)) as identity,
                MIN(date_created) as first_order
            FROM $table
            WHERE date_created <= %s
                AND (billing_email IS NOT NULL AND billing_email != '' OR customer_id IS NOT NULL)
            GROUP BY identity
        ) as customer_first
        WHERE EXISTS (
            SELECT 1 
            FROM $table t 
            WHERE COALESCE(NULLIF(t.billing_email, ''), CONCAT('uid_', t.customer_id)) = customer_first.identity
                AND t.date_created BETWEEN %s AND %s
            LIMIT 1
        )
    ", $date_range['from'], $date_range['from'], $date_range['to'], $date_range['from'], $date_range['to'] ) );

    return $customers;
}

/** Get previous period revenue for sales comparison. */
function hippoo_bi_get_previous_sales_revenue( $args = array() ) {
    $period    = $args['period'] ?? 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $prev_range = hippoo_bi_get_previous_date_range( $period, $date_from, $date_to );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $revenue = (float) $wpdb->get_var( $wpdb->prepare("
        SELECT SUM(total)
        FROM $table
        WHERE date_created BETWEEN %s AND %s
    ", $prev_range['from'], $prev_range['to'] ) );

    return $revenue;
}

/** Get daily revenue and order chart data. */
function hippoo_bi_get_sales_chart( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $rows  = hippoo_bi_get_site_chart_from_summary( $date_range['from'], $date_range['to'] );
        $chart = array();
        foreach ( $rows as $row ) {
            $chart[] = (object) array(
                'date'    => $row->date,
                'revenue' => (float) $row->revenue,
                'orders'  => (int) $row->orders,
            );
        }
        return $chart;
    }

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $chart = $wpdb->get_results( $wpdb->prepare( "
        SELECT 
            DATE(date_created) as date,
            SUM(total) as revenue,
            COUNT(order_id) as orders
        FROM $table
        WHERE date_created BETWEEN %s AND %s
        GROUP BY DATE(date_created)
        ORDER BY date ASC
    ", $date_range['from'], $date_range['to'] ) );

    return $chart;
}
