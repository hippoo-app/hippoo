<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooInvoiceControllerWithAuth extends WC_REST_Customers_Controller {
    public $namespace;
    public function __construct() {
        $this->namespace = 'wc-hippoo-invoice/v1';
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/setting', array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_setting' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
        ) );
 
        register_rest_route( $this->namespace, '/setting', array(
            array(
                'methods'   => 'PUT',
                'callback'  => array( $this, 'update_setting' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/invoice/(?P<order_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_invoice' ),
            'permission_callback' => array( $this, 'get_items_permissions_check' ),
        ) );
    
        register_rest_route( $this->namespace, '/shipping-label/(?P<order_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_shipping_label' ),
            'permission_callback' => array( $this, 'get_items_permissions_check' ),
        ) );
    }

    public function get_setting($request) {
        $settings = get_option('hippoo_invoice_settings');
    
        if (empty($settings)) {
            $settings = [];
        }
    
        // Define default settings with explicit data types
        $default_settings = [
            'show_barcode_order_list' => false,
            'show_barcode_order_details' => false,
            'show_barcode_products_list' => false,
            'show_barcode_products_details' => false,
            'language_direction' => '',
            'shop_logo' => '',
            'font_name' => '',
            'invoice_show_logo' => false,
            'show_customer_note' => false,
            'show_product_sku_invoice' => false,
            'footer_description' => '',
            'invoice_paper_size' => 'A4',
            'shipping_show_logo' => false,
            'shipping_calculate_weight' => false,
            'shipping_courier_logo' => '',
            'shipping_paper_size' => 'A4',
        ];
    
        $settings = array_merge($default_settings, $settings);
    
        $settings = array_map(function($value) {
            return ($value === '1') ? true : (($value === '0') ? false : $value);
        }, $settings);
    
        return rest_ensure_response($settings);
    }
        

    public function update_setting( $request ) {
        $settings = get_option('hippoo_invoice_settings');
        $new_settings = json_decode( $request->get_body(), true );
    
        $settings = array_merge($settings, $new_settings);
    
        // Convert string 'true' or 'false' to boolean true or false
        $settings = array_map(function($value) {
            return ($value === 'true') ? true : (($value === 'false') ? false : $value);
        }, $settings);
    
        update_option('hippoo_invoice_settings', $settings);
    
        return rest_ensure_response($settings);
    }
    

    function get_invoice($request) {
        $order_id = intval( $request->get_param('order_id') );
        $html_doc = generate_html( $order_id, 'factor' );
        header( 'Content-Type: text/html; charset=utf-8' );
        echo hippoo_wp_kses( $html_doc );
        exit;
    }

    function get_shipping_label($request) {
        $order_id = intval( $request->get_param('order_id') );
        $html_doc = generate_html( $order_id, 'label' );
        header( 'Content-Type: text/html; charset=utf-8' );
        echo hippoo_wp_kses( $html_doc );
        exit;
    }
}