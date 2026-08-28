<?php
/**
 * Hippoo ability layer — WooCommerce-dependent handlers + dispatcher.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/ability_catalog.php';

/** Resolve a period + optional custom dates to a [from,to] unix-timestamp window. */
function hippoo_ability_date_range( $period, $from = null, $to = null ) {
    $tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    $now = new DateTimeImmutable( 'now', $tz );
    switch ( $period ) {
        case 'day':
            $start = $now->setTime( 0, 0, 0 );
            break;
        case 'week':
            $start = $now->modify( '-6 days' )->setTime( 0, 0, 0 );
            break;
        case 'month':
            $start = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), 1 )->setTime( 0, 0, 0 );
            break;
        case 'custom':
            $start = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, $tz );
            $end   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, $tz );
            $end   = $end ? $end->setTime( 23, 59, 59 ) : $now;
            return array( 'from' => $start ? $start->getTimestamp() : 0, 'to' => $end->getTimestamp() );
        default:
            $start = $now->setTime( 0, 0, 0 );
    }
    return array( 'from' => $start->getTimestamp(), 'to' => $now->getTimestamp() );
}

/** get_sales_summary: aggregate processing+completed orders in the period. */
function hippoo_ability_handle_get_sales_summary( $in ) {
    $range  = hippoo_ability_date_range( $in['period'], $in['date_from'] ?? null, $in['date_to'] ?? null );
    $orders = wc_get_orders( array(
        'status'       => array( 'processing', 'completed' ),
        'date_created' => $range['from'] . '...' . $range['to'],
        'limit'        => -1,
        'return'       => 'objects',
    ) );

    $gross = 0.0;
    $net   = 0.0;
    $count = 0;
    foreach ( $orders as $order ) {
        $gross += (float) $order->get_total();
        $net   += (float) $order->get_total() - (float) $order->get_total_tax() - (float) $order->get_total_refunded();
        $count++;
    }

    return array(
        'currency'    => get_woocommerce_currency(),
        'gross_sales' => round( $gross, 2 ),
        'net_sales'   => round( $net, 2 ),
        'order_count' => $count,
    );
}

/** list_orders: paginated order list, optional status filter + search. */
function hippoo_ability_handle_list_orders( $in ) {
    $per_page = isset( $in['per_page'] ) ? (int) $in['per_page'] : 10;
    $page     = isset( $in['page'] ) ? (int) $in['page'] : 1;

    $args = array(
        'limit'    => $per_page,
        'page'     => $page,
        'paginate' => true,
        'orderby'  => 'date',
        'order'    => 'DESC',
    );
    if ( isset( $in['status'] ) && $in['status'] !== 'any' ) {
        $args['status'] = $in['status'];
    }
    if ( isset( $in['search'] ) && $in['search'] !== '' ) {
        $args['s'] = $in['search'];
    }

    $results = wc_get_orders( $args );

    $orders = array();
    foreach ( $results->orders as $order ) {
        $created = $order->get_date_created();
        $orders[] = array(
            'id'            => $order->get_id(),
            'status'        => $order->get_status(),
            'total'         => (string) $order->get_total(),
            'currency'      => $order->get_currency(),
            'date_created'  => $created ? $created->date( 'Y-m-d\TH:i:s' ) : '',
            'customer_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        );
    }

    return array(
        'orders' => $orders,
        'total'  => (int) $results->total,
        'page'   => $page,
    );
}

/** get_product: look up a product by id or sku. */
function hippoo_ability_handle_get_product( $in ) {
    $id = 0;
    if ( isset( $in['product_id'] ) ) {
        $id = (int) $in['product_id'];
    } elseif ( isset( $in['sku'] ) ) {
        $id = (int) wc_get_product_id_by_sku( $in['sku'] );
    }

    $product = $id ? wc_get_product( $id ) : null;
    if ( ! $product ) {
        return new WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
    }

    $qty = $product->get_stock_quantity();
    return array(
        'id'             => $product->get_id(),
        'name'           => $product->get_name(),
        'sku'            => $product->get_sku(),
        'price'          => (string) $product->get_price(),
        'stock_status'   => $product->get_stock_status(),
        'stock_quantity' => is_null( $qty ) ? null : (int) $qty,
    );
}

/** Name → handler callable. */
function hippoo_ability_handler_map() {
    return array(
        'get_sales_summary' => 'hippoo_ability_handle_get_sales_summary',
        'list_orders'       => 'hippoo_ability_handle_list_orders',
        'get_product'       => 'hippoo_ability_handle_get_product',
    );
}

/**
 * Dispatch one ability call. Returns array{status:int, body:array}
 * where body is {ok:true,output} or {ok:false,error}.
 */
function hippoo_ability_execute( $name, $input, $confirmed ) {
    $descriptors = hippoo_ability_descriptors();
    if ( ! isset( $descriptors[ $name ] ) ) {
        return array( 'status' => 404, 'body' => array( 'ok' => false, 'error' => 'unknown ability: ' . $name ) );
    }
    if ( hippoo_ability_is_blocked_write( $descriptors[ $name ], $confirmed ) ) {
        return array( 'status' => 400, 'body' => array( 'ok' => false, 'error' => 'write ability requires confirmed:true' ) );
    }
    $errors = hippoo_ability_validate_input( $name, $input );
    if ( ! empty( $errors ) ) {
        return array( 'status' => 400, 'body' => array( 'ok' => false, 'error' => implode( '; ', $errors ) ) );
    }

    $handler = hippoo_ability_handler_map()[ $name ];
    $output  = call_user_func( $handler, $input );
    if ( is_wp_error( $output ) ) {
        $data   = $output->get_error_data();
        $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
        return array( 'status' => $status, 'body' => array( 'ok' => false, 'error' => $output->get_error_message() ) );
    }
    return array( 'status' => 200, 'body' => array( 'ok' => true, 'output' => $output ) );
}
