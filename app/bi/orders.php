<?php
/**
 * Hippoo BI – WooCommerce order synchronization
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Synchronize missing WooCommerce orders into the BI lookup tables. */
add_action( 'hippoo_bi_sync_lookup', 'hippoo_bi_orders_sync_lookup' );

function hippoo_bi_orders_sync_lookup( $page = 1 ) {
    global $wpdb;

    $table_stats = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $batch_size = 500;
    $page = max( 1, absint( $page ) );

    $orders = wc_get_orders( array(
        'type'     => 'shop_order',
        'limit'    => $batch_size,
        'page'     => $page,
        'orderby'  => 'ID',
        'order'    => 'ASC',
        'return'   => 'ids',
        'status'   => hippoo_bi_orders_get_report_statuses(),
    ) );

    if ( empty( $orders ) ) {
        return;
    }

    $orders = array_map( 'absint', $orders );
    $placeholders = implode( ',', array_fill( 0, count( $orders ), '%d' ) );
    $existing = $wpdb->get_col( $wpdb->prepare(
        "SELECT order_id FROM $table_stats WHERE order_id IN ($placeholders)",
        $orders
    ) );
    $existing = array_fill_keys( array_map( 'absint', $existing ), true );

    foreach ( $orders as $order_id ) {
        if ( ! isset( $existing[ $order_id ] ) ) {
            hippoo_bi_orders_update_lookup( $order_id );
        }
    }

    if ( count( $orders ) === $batch_size ) {
        wp_schedule_single_event( time() + 10, 'hippoo_bi_sync_lookup', array( $page + 1 ) );
    }
}

/** Update BI order lookup and order statistics for an order. */
add_action( 'woocommerce_order_status_changed', 'hippoo_bi_orders_update_lookup', 20 );
add_action( 'woocommerce_new_order', 'hippoo_bi_orders_update_lookup', 20 );
add_action( 'woocommerce_update_order', 'hippoo_bi_orders_update_lookup', 20 );
add_action( 'woocommerce_order_refunded', 'hippoo_bi_orders_update_lookup', 20 );
add_action( 'woocommerce_saved_order_items', 'hippoo_bi_orders_update_lookup', 20 );

function hippoo_bi_orders_update_lookup( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    global $wpdb;

    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $table_stats  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $wpdb->delete( $table_lookup, array( 'order_id' => $order_id ) );
    $wpdb->delete( $table_stats, array( 'order_id' => $order_id ) );

    $included_statuses = hippoo_bi_orders_get_report_statuses();

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
}

/** Delete BI order lookup data for an order. */
add_action( 'woocommerce_delete_order', 'hippoo_bi_orders_delete_lookup', 20 );

function hippoo_bi_orders_delete_lookup( $order_id ) {
    global $wpdb;

    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $table_stats  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;

    $wpdb->delete( $table_lookup, array( 'order_id' => $order_id ) );
    $wpdb->delete( $table_stats, array( 'order_id' => $order_id ) );
}

/** Get the WooCommerce order statuses included in BI reports. */
function hippoo_bi_orders_get_report_statuses() {
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
