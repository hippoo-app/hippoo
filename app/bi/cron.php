<?php
/**
 * Hippoo BI – background processing + order synchronization
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Scheduled maintenance
// ---------------------------------------------------------------------------

/** Register BI cron events. */
add_action( 'plugins_loaded', 'hippoo_bi_schedule_cron_events' );

function hippoo_bi_schedule_cron_events() {
    if ( ! wp_next_scheduled( 'hippoo_bi_daily_churn' ) ) {
        $timezone = wp_timezone();
        $datetime = new DateTime( 'tomorrow 2:00 AM', $timezone );
        $timestamp = $datetime->getTimestamp();

        wp_schedule_event( $timestamp, 'daily', 'hippoo_bi_daily_churn' );
    }

    if ( ! wp_next_scheduled( 'hippoo_bi_daily_aggregate' ) ) {
        $timezone = wp_timezone();
        $datetime = new DateTime( 'tomorrow 3:00 AM', $timezone );
        $timestamp = $datetime->getTimestamp();

        wp_schedule_event( $timestamp, 'daily', 'hippoo_bi_daily_aggregate' );
    }

    if ( ! wp_next_scheduled( 'hippoo_bi_weekly_pruning' ) ) {
        wp_schedule_event( time(), 'weekly', 'hippoo_bi_weekly_pruning' );
    }

    if ( ! wp_next_scheduled( 'hippoo_bi_sync_lookup' ) ) {
        wp_schedule_event( time(), 'hourly', 'hippoo_bi_sync_lookup' );
    }
}

/** Clear all BI scheduled events. */
register_deactivation_hook( HIPPOO_MAIN_FILE_PATH, 'hippoo_bi_clear_cron_events' );

function hippoo_bi_clear_cron_events() {
    $hooks = array(
        'hippoo_bi_daily_churn',
        'hippoo_bi_daily_aggregate',
        'hippoo_bi_weekly_pruning',
        'hippoo_bi_sync_lookup',
    );

    foreach ( $hooks as $hook ) {
        $timestamp = wp_next_scheduled( $hook );

        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
            $timestamp = wp_next_scheduled( $hook );
        }
    }
}

/** Rebuild BI background tasks after database migration. */
add_action( 'hippoo_bi_database_migrated', 'hippoo_bi_rebuild_cron_events', 10, 2 );

function hippoo_bi_rebuild_cron_events( $version, $previous_version ) {
    hippoo_bi_clear_cron_events();
    wp_schedule_single_event( time() + 15, 'hippoo_bi_sync_lookup', array( 300 ) );
    wp_schedule_single_event( time() + 60, 'hippoo_bi_daily_aggregate' );
    wp_schedule_single_event( time() + 120, 'hippoo_bi_daily_churn' );
    hippoo_bi_schedule_cron_events();
}


// ---------------------------------------------------------------------------
// Churn calculation
// ---------------------------------------------------------------------------

/** Calculate and store BI customer churn scores. */
add_action( 'hippoo_bi_daily_churn', 'hippoo_bi_calculate_churn_scores' );

function hippoo_bi_calculate_churn_scores() {
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


// ---------------------------------------------------------------------------
// Prune pageviews
// ---------------------------------------------------------------------------

/** Remove old BI pageviews. */
add_action( 'hippoo_bi_weekly_pruning', 'hippoo_bi_prune_old_pageviews' );

function hippoo_bi_prune_old_pageviews() {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $wpdb->query( "
        DELETE FROM $table
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 13 MONTH)
        LIMIT 5000
    " );
}


// ---------------------------------------------------------------------------
// Order lookup
// ---------------------------------------------------------------------------

/** Synchronize missing WooCommerce orders into the BI lookup tables. */
add_action( 'hippoo_bi_sync_lookup', 'hippoo_bi_sync_orders_lookup' );

function hippoo_bi_sync_orders_lookup( $batch_size = 500 ) {
    global $wpdb;

    $table_stats = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $processed_orders = $wpdb->get_col( "SELECT DISTINCT order_id FROM $table_stats" );

    $args = array(
        'type'    => 'shop_order',
        'limit'   => -1,
        'orderby' => 'date_created',
        'order'   => 'DESC',
        'return'  => 'ids',
        'status'  => hippoo_bi_get_report_order_statuses(),
    );

    $orders = wc_get_orders( $args );

    $unprocessed_orders = array_diff( $orders, $processed_orders );

    if ( empty( $unprocessed_orders ) ) {
        return;
    }

    $batch_orders = array_slice( $unprocessed_orders, 0, absint( $batch_size ) );

    $count = 0;

    foreach ( $batch_orders as $order_id ) {
        hippoo_bi_update_order_lookup( $order_id );
        $count++;
    }

    $remaining = count( $unprocessed_orders ) - $count;

    if ( $remaining > 0 ) {
        wp_schedule_single_event( time() + 10, 'hippoo_bi_sync_lookup', array( absint( $batch_size ) ) );
    }
}

/** Update BI order lookup and order statistics for an order. */
add_action( 'woocommerce_order_status_changed', 'hippoo_bi_update_order_lookup', 20 );
add_action( 'woocommerce_new_order', 'hippoo_bi_update_order_lookup', 20 );

function hippoo_bi_update_order_lookup( $order_id ) {
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    global $wpdb;

    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $table_stats  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $wpdb->delete( $table_lookup, array( 'order_id' => $order_id ) );
    $wpdb->delete( $table_stats, array( 'order_id' => $order_id ) );

    $included_statuses = hippoo_bi_get_report_order_statuses();

    if ( ! in_array( $order->get_status(), $included_statuses, true ) ) {
        return;
    }

    if ( 'shop_order' !== $order->get_type() ) {
        return;
    }

    $order_items   = $order->get_items();
    $order_total   = (float) $order->get_total();
    $order_tax     = (float) $order->get_total_tax();
    $order_ship    = (float) $order->get_shipping_total();
    $order_disc    = (float) $order->get_discount_total();
    $order_net     = (float) ( $order_total - $order_tax - $order_ship );
    $order_refund  = (float) $order->get_total_refunded();
    $customer_id   = $order->get_customer_id();
    $billing_email = $order->get_billing_email() ?: '';
    $order_status  = $order->get_status();
    $date_created  = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

    foreach ( $order_items as $item ) {
        $product_id = $item->get_product_id();

        if ( ! $product_id ) {
            continue;
        }

        $wpdb->insert( $table_lookup, array(
            'order_id'     => $order_id,
            'product_id'   => $product_id,
            'customer_id'  => $customer_id > 0 ? $customer_id : null,
            'date_created' => $date_created,
            'quantity'     => (int) $item->get_quantity(),
            'revenue'      => (float) $item->get_total(),
        ) );
    }

    $wpdb->insert( $table_stats, array(
        'order_id'      => $order_id,
        'customer_id'   => $customer_id > 0 ? $customer_id : null,
        'billing_email' => $billing_email ?: null,
        'order_status'  => $order_status,
        'date_created'  => $date_created,
        'total'         => $order_total,
        'net'           => $order_net,
        'refund'        => $order_refund,
        'discount'      => $order_disc,
        'shipping'      => $order_ship,
        'tax'           => $order_tax,
    ) );

    hippoo_bi_clear_report_caches();
}

/** Delete BI order lookup data for an order. */
add_action( 'woocommerce_delete_order', 'hippoo_bi_delete_order_lookup', 20 );

function hippoo_bi_delete_order_lookup( $order_id ) {
    global $wpdb;

    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $table_stats  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $wpdb->delete( $table_lookup, array( 'order_id' => $order_id ) );
    $wpdb->delete( $table_stats, array( 'order_id' => $order_id ) );

    hippoo_bi_clear_report_caches();
}


// ---------------------------------------------------------------------------
// Daily aggregate hooks (logic in aggregate.php)
// ---------------------------------------------------------------------------

add_action( 'hippoo_bi_daily_aggregate', 'hippoo_bi_run_daily_aggregate' );
