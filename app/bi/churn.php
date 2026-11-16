<?php
/**
 * Hippoo BI – customer churn calculations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Number of inactive days used by BI churn scoring. */
define( 'HIPPOO_BI_CHURN_THRESHOLD', 90 );

/** Calculate and store BI customer churn scores. */
add_action( 'hippoo_bi_daily_churn', 'hippoo_bi_churn_calculate_scores' );

function hippoo_bi_churn_calculate_scores() {
    global $wpdb;

    $table_churn = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;
    $table_stats = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    // Identity = billing_email when present, otherwise uid_{customer_id}
    $customer_orders = $wpdb->get_results( "
        SELECT
            COALESCE(NULLIF(billing_email, ''), CONCAT('uid_', customer_id)) as identity,
            MAX(customer_id) as customer_id,
            MAX(NULLIF(billing_email, '')) as email,
            COUNT(order_id) as total_orders,
            SUM(total) as total_spent,
            MIN(date_created) as first_order,
            MAX(date_created) as last_order
        FROM $table_stats
        WHERE (billing_email IS NOT NULL AND billing_email != '') OR customer_id IS NOT NULL
        GROUP BY identity
        ORDER BY last_order DESC
    " );

    if ( empty( $customer_orders ) ) {
        return;
    }

    $customer_data = array();

    foreach ( $customer_orders as $row ) {
        $identity = $row->identity;

        if ( empty( $identity ) ) {
            continue;
        }

        $email = $row->email ?: '';
        $cid   = (int) $row->customer_id;

        if ( empty( $email ) && $cid > 0 ) {
            $user  = get_user_by( 'id', $cid );
            $email = $user ? $user->user_email : '';
        }

        // Guests must have an email; skip otherwise
        if ( empty( $email ) && 0 === strpos( $identity, 'uid_' ) ) {
            continue;
        }

        if ( empty( $email ) ) {
            $email = $identity;
        }

        $customer_data[ $email ] = array(
            'customer_id'  => $cid,
            'email'        => $email,
            'total_orders' => (int) $row->total_orders,
            'total_spent'  => (float) $row->total_spent,
            'first_order'  => $row->first_order,
            'last_order'   => $row->last_order,
        );
    }

    if ( empty( $customer_data ) ) {
        return;
    }

    $all_spent  = wp_list_pluck( $customer_data, 'total_spent' );
    $all_orders = wp_list_pluck( $customer_data, 'total_orders' );
    $max_orders = ! empty( $all_orders ) ? max( $all_orders ) : 1;
    $avg_spent  = ! empty( $all_spent ) ? array_sum( $all_spent ) / count( $all_spent ) : 1;
    $avg_spent  = max( 1, $avg_spent );

    foreach ( $customer_data as $email => $data ) {
        $days_since_last = ( time() - strtotime( $data['last_order'] ) ) / DAY_IN_SECONDS;

        $first_ts  = strtotime( $data['first_order'] );
        $last_ts   = strtotime( $data['last_order'] );
        $span_days = max( 1, ( $last_ts - $first_ts ) / DAY_IN_SECONDS );

        $avg_days_between = $data['total_orders'] > 1
            ? round( $span_days / ( $data['total_orders'] - 1 ), 1 )
            : round( $span_days, 1 );

        $freq_score = min( 1, $avg_days_between / 45 );

        $score = (
            ( $days_since_last / HIPPOO_BI_CHURN_THRESHOLD ) * 40
            - ( log( $data['total_orders'] + 1 ) / log( $max_orders + 1 ) ) * 25
            - ( $data['total_spent'] / $avg_spent ) * 20
            - $freq_score * 15
        );

        $final_score = max( 0, min( 100, (int) round( $score ) ) );

        $status = 'active';

        if ( $final_score >= 81 ) {
            $status = 'churned';
        } elseif ( $final_score >= 61 ) {
            $status = 'high_risk';
        } elseif ( $final_score >= 31 ) {
            $status = 'at_risk';
        }

        $aov = $data['total_orders'] > 0 ? round( (float) $data['total_spent'] / $data['total_orders'], 2 ) : 0;
        $clv = round( ( $aov * ( $data['total_orders'] / max( 1, $span_days / 365 ) ) * 1.2 ), 2 );

        $wpdb->replace( $table_churn, array(
            'email'            => $email,
            'customer_id'      => $data['customer_id'],
            'churn_score'      => $final_score,
            'status'           => $status,
            'first_order_date' => $data['first_order'],
            'last_order_date'  => $data['last_order'],
            'total_orders'     => $data['total_orders'],
            'total_spent'      => $data['total_spent'],
            'clv'              => $clv,
            'avg_days_between' => $avg_days_between,
            'calculated_at'    => current_time( 'mysql' ),
        ) );
    }
}
