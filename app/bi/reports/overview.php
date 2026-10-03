<?php
/**
 * Hippoo BI – overview reports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/** Build the BI general overview report. */
function hippoo_bi_get_general_overview( $args = array() ) {
    $traffic_summary = hippoo_bi_get_traffic_summary( $args );
    if ( is_wp_error( $traffic_summary ) ) {
        return $traffic_summary;
    }

    $sales_summary = hippoo_bi_get_sales_summary( $args );
    if ( is_wp_error( $sales_summary ) ) {
        return $sales_summary;
    }

    $churn_summary = hippoo_bi_get_churn_summary();
    if ( is_wp_error( $churn_summary ) ) {
        return $churn_summary;
    }

    $product_highlights = hippoo_bi_get_product_highlights( $args );
    if ( is_wp_error( $product_highlights ) ) {
        return $product_highlights;
    }

    $current_user = wp_get_current_user();

    return array(
        'display_name'        => $current_user->display_name,
        'roles'               => array_values( $current_user->roles ),
        'net_revenue'         => $sales_summary['net_revenue'] ?? 0,
        'avg_order_value'     => $sales_summary['avg_order_value'] ?? 0,
        'conversion_rate'     => $sales_summary['conversion_rate'] ?? 0,
        'order_count'         => $sales_summary['order_count'] ?? 0,
        'revenue_per_visit'   => $sales_summary['revenue_per_visit'] ?? 0,
        'new_customers'       => $sales_summary['new_customers'] ?? 0,
        'returning_customers' => $sales_summary['returning_customers'] ?? 0,
        'total_views'         => $traffic_summary['total_views'] ?? 0,
        'unique_sessions'     => $traffic_summary['unique_sessions'] ?? 0,
        'new_visitors'        => $traffic_summary['new_visitors'] ?? 0,
        'returning_visitors'  => $traffic_summary['returning_visitors'] ?? 0,
        'bounce_rate'         => $traffic_summary['bounce_rate'] ?? 0,
        'chart'               => hippoo_bi_get_overview_chart( $args ),
        'churn_summary' => [
            'churn_rate'      => $churn_summary['churn_rate'] ?? 0,
            'active_count'    => $churn_summary['active_customers'] ?? 0,
            'at_risk_count'   => $churn_summary['at_risk_customers'] ?? 0,
            'high_risk_count' => $churn_summary['high_risk_customers'] ?? 0,
            'churned_count'   => $churn_summary['churned_customers'] ?? 0,
        ],
        'product_highlights'  => $product_highlights,
        'comparison'          => $sales_summary['comparison'] ?? array(
            'vs_previous_period' => '+0%',
            'previous_revenue'   => 0,
        ),
    );
}

/** Build the combined traffic and revenue chart for the general overview. */
function hippoo_bi_get_overview_chart( $args = array() ) {
    $traffic_chart = hippoo_bi_get_traffic_chart( $args );
    $sales_chart   = hippoo_bi_get_sales_chart( $args );

    $chart = array();

    foreach ( $sales_chart as $item ) {
        $date = $item->date;

        $chart[ $date ] = array(
            'date'     => $date,
            'revenue'  => (float) ( $item->revenue ?? 0 ),
            'orders'   => (int) ( $item->orders ?? 0 ),
            'views'    => 0,
            'sessions' => 0,
        );
    }

    foreach ( $traffic_chart as $item ) {
        $date = $item->date;

        if ( isset( $chart[ $date ] ) ) {
            $chart[ $date ]['views']    = (int) $item->views;
            $chart[ $date ]['sessions'] = (int) $item->sessions;
        } else {
            $chart[ $date ] = array(
                'date'     => $date,
                'revenue'  => 0,
                'orders'   => 0,
                'views'    => (int) $item->views,
                'sessions' => (int) $item->sessions,
            );
        }
    }

    $chart = array_values( $chart );

    usort( $chart, function ( $a, $b ) {
        return strtotime( $a['date'] ) <=> strtotime( $b['date'] );
    } );

    return $chart;
}
