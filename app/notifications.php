<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Send a push notification through the Hippoo notification proxy. */
function hippoo_push_notification( $title, $content, $extra = [] ) {
    $home_url    = home_url();
    $parsed_url  = wp_parse_url( $home_url );
    $cs_hostname = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';

    $notif_data = array_merge(
        array(
            'title'   => $title,
            'content' => $content,
        ),
        $extra
    );

    return wp_remote_post(
        HIPPOO_PROXY_NOTIFICTION_URL,
        array(
            'body' => array(
                'cs_hostname' => $cs_hostname,
                'notif_data'  => $notif_data,
            ),
        )
    );
}


/** Register enabled WooCommerce order status notification hooks. */
add_action( 'init', 'hippoo_push_notification_on_order_status' );

function hippoo_push_notification_on_order_status() {
    $settings = get_option( 'hippoo_settings', [] );
    if ( empty( $settings ) ) {
        return;
    }
    foreach ( $settings as $key => $value ) {
        if ( 0 === strpos( $key, 'send_notification_wc-' ) && true === $value ) {
            $status_key = str_replace( 'send_notification_wc-', '', $key );
            add_filter( "woocommerce_order_status_{$status_key}", 'hippoo_push_notification_send_order_status' );
        }
    }
}

/** Send a push notification containing the order status and summary. */
function hippoo_push_notification_send_order_status( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $order_status      = $order->get_status();
    $order_count       = $order->get_item_count();
    $order_total_price = $order->get_total();
    $order_currency    = $order->get_currency();

    /* translators: 1: order ID, 2: order status */
    $title = sprintf( __( 'Order %1$d %2$s!', 'hippoo' ), $order_id, $order_status );
    /* translators: %d: number of items in the order */
    $content  = sprintf( __( '%d items', 'hippoo' ), $order_count );
    $content .= ' | ' . $order_total_price . $order_currency;
    /* translators: %s: order status */
    $content .= ' | ' . sprintf( __( 'Status: %s', 'hippoo' ), $order_status );

    hippoo_push_notification( $title, $content );
}


/** Handle WooCommerce low-stock notifications and record the out-of-stock time. */
add_action( 'woocommerce_no_stock_notification', 'hippoo_push_notification_on_no_stock', 10, 1 );

function hippoo_push_notification_on_no_stock( $product ) {
    update_post_meta( $product->get_id(), 'out_stock_time', gmdate( 'Y-m-d H:i:s' ) );
    hippoo_push_notification_send_out_of_stock( $product );
}

/** Send a push notification containing the out-of-stock product details. */
function hippoo_push_notification_send_out_of_stock( $product ) {
    $product_name      = $product->get_name();
    $product_image_url = get_the_post_thumbnail_url( $product->get_id(), 'thumbnail' );
    $url               = 'hippoo://app/outofstock/?product_id=' . $product->get_id();
    $title             = __( 'Product is out of stock!', 'hippoo' );
    $content           = __( 'Product ', 'hippoo' ) . $product_name;

    hippoo_push_notification(
        $title,
        $content,
        array(
            'image'     => $product_image_url,
            'largeIcon' => $product_image_url,
            'url'       => $url,
        )
    );
}
