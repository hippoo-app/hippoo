<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function get_template_params($order_id) {
    $order_id = absint($order_id);
    $order = wc_get_order($order_id);
    if (!$order){
        return null;
    }
    $items = $order->get_items();

    $order_address = $order->get_address();
    $address_parts = array(
        $order_address['address_1'],
        $order_address['address_2'],
        $order_address['city'],
        $order_address['state'],
        $order_address['postcode'],
        $order_address['country'],
    );
    $address_parts = array_filter( $address_parts );
    $one_line_address = implode( ', ', $address_parts );

    $customer_note = $order->get_customer_note();

    $settings = get_option('hippoo_invoice_settings');
    $direction = ( isset( $settings['language_direction'] ) && $settings['language_direction'] == 'RTL'
        ? 'rtl' : 'ltr' );
    
    $shop_logo = isset($settings['shop_logo']) && !empty($settings['shop_logo'])
        ? base64_encode(file_get_contents($settings['shop_logo']))
        : '';
    
    
    $shipping_courier_logo = isset( $settings['shipping_courier_logo'] ) && !empty($settings['shipping_courier_logo'])
        ? base64_encode( file_get_contents( $settings['shipping_courier_logo'] ) ) : '';
    
    $shop_address = get_option( 'woocommerce_store_address' );
    $invoice_barcode = base64_encode( generate_barcode_image( $order->get_id() ) );

    $weight = 0;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        // Check if $product is an object before calling methods on it
        if ( is_object($product) && method_exists($product, 'is_virtual') && ! $product->is_virtual() ) {
            $weight += intval( $product->get_weight() ) * $item['qty'];
        }
    }

    $invoice_paper_size = isset($settings['invoice_paper_size']) ? $settings['invoice_paper_size'] : 'A4';
    $shipping_paper_size = isset($settings['shipping_paper_size']) ? $settings['shipping_paper_size'] : 'A4';

    return compact(
        'order', 'one_line_address', 'customer_note', 'settings', 'direction',
        'shop_logo', 'shipping_courier_logo', 'shop_address', 'invoice_barcode', 'weight', 'items',
        'invoice_paper_size', 'shipping_paper_size'
    );
}

function generate_barcode_image( $sku ) {
    $generator = new Picqer\Barcode\BarcodeGeneratorJPG();
    return $generator->getBarcode( $sku, $generator::TYPE_CODE_128 );
}

function generate_barcode_html($sku) {
    
    // Check if $sku is empty or not provided
    if (empty($sku)) {
        return 'Invalid SKU for barcode generation';
    }

    // Instantiate the barcode generator
    $generator = new Picqer\Barcode\BarcodeGeneratorHTML();

    // Generate and return the barcode HTML
    return $generator->getBarcode($sku, $generator::TYPE_CODE_128);
}

function generate_html( $order_id, $type ) {
    $type = sanitize_file_name( $type );
    
    // Security: Only allow specific template types
    $allowed_types = array( 'factor', 'label' );
    if ( ! in_array( $type, $allowed_types, true ) ) {
        return false;
    }

    $custom_template_path = get_stylesheet_directory() . '/hippoo-' . $type . '.php';
    if ( ! file_exists( $custom_template_path ) || ! is_readable( $custom_template_path ) ) {
        $custom_template_path = get_template_directory() . '/hippoo-' . $type . '.php';
    }

    $file_path = file_exists( $custom_template_path ) && is_readable( $custom_template_path )
        ? $custom_template_path
        : HIPPOO_INVOICE_PLUGIN_TEMPLATE_PATH . 'hippoo-' . $type . '.php';
    
    $file_path = apply_filters( 'hippoo_invoice_template_path', $file_path, $type, $order_id );
    
    // Security: Validate file path is within allowed directories
    $plugin_path = realpath( HIPPOO_INVOICE_PLUGIN_PATH );
    $theme_path = realpath( get_stylesheet_directory() );
    $parent_theme_path = realpath( get_template_directory() );
    $real_file_path = realpath( $file_path );
    
    if ( ! $real_file_path || 
         ( strpos( $real_file_path, $plugin_path ) !== 0 && 
           strpos( $real_file_path, $theme_path ) !== 0 && 
           strpos( $real_file_path, $parent_theme_path ) !== 0 ) ) {
        return false;
    }
    
    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return false;
    }

    $template_params = get_template_params( $order_id );
    if ( is_null( $template_params ) ) {
        return '<p>Undefined Order</p>'; 
    }

    extract( $template_params  );
    ob_start();
    include $file_path;
    $html_code = ob_get_clean();
    return $html_code;
}



function user_has_order_access($order_id) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return false; 
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }

    $order_user_id = $order->get_user_id();
    
    if ($order_user_id == $user_id) {
        return true;
    } else {
        return false;
    }
}


function hippoo_invoice_check_hpos_enabled() {
    $hpos_enabled = FALSE;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
        $hpos_enabled = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    } else {
        $hpos_enabled = FALSE;
    }
    return $hpos_enabled;

}

function hippoo_wp_kses($html) {
    $allowed_html = wp_kses_allowed_html( 'post' );
    $allowed_html['style'] = [];
    $allowed_html['link'] = array(
        'rel' => true,
        'href' => true,
        'type' => true,
    );
    $allowed_html['img'] = array(
        'src' => true,
        'alt' => true,
        'width' => true,
        'height' => true,
        'class' => true,
    );
    $allowed_protocols = wp_allowed_protocols();
    $allowed_protocols[] = 'data';
    return wp_kses( $html, $allowed_html, $allowed_protocols );
}