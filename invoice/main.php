<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

///
///Invoice 
///

define( 'HIPPOO_INVOICE_PLUGIN_LANG_DIR', HIPPOO_INVOICE_PLUGIN_PATH . 'languages'. DIRECTORY_SEPARATOR );
define( 'HIPPOO_INVOICE_PLUGIN_TEMPLATE_PATH', HIPPOO_INVOICE_PLUGIN_PATH . 'templates' . DIRECTORY_SEPARATOR . 'simple' . DIRECTORY_SEPARATOR );



add_action( 'admin_enqueue_scripts', 'hippoo_enqueue_scripts' );
function hippoo_enqueue_scripts() {
    wp_enqueue_style(  'hippoo-styles', HIPPOO_INVOICE_PLUGIN_URL . 'assets/css/admin-style.css', null, hippoo_version );
    wp_enqueue_script( 'hippoo-scripts', HIPPOO_INVOICE_PLUGIN_URL . 'assets/js/admin-script.js', [ 'jquery', 'jquery-ui-core', 'jquery-ui-tooltip' ], hippoo_version, true );
}
add_action('admin_head', 'hippoo_force_admin_styles', 9999);
function hippoo_force_admin_styles() {
    ?>
    <style type="text/css">
        .barcode-tooltip {
            background-color: #ffffff !important;
        }
    </style>
    <?php
}
add_action( 'woocommerce_init', 'hippoo_invoice_load' );
function hippoo_invoice_load() {
    
    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'libs/barcode/vendor/autoload.php';

    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'helper.php';
    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'api.php';
    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'settings.php';

    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'woocommerce/order.php';
    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'woocommerce/product.php';
    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'woocommerce/my-account.php';
}

add_filter( 'query_vars', 'hippoo_query_vars' );
function hippoo_query_vars( $vars ) {
    $vars[] = 'post_id';
    $vars[] = 'download_type';

    return $vars;
}

add_filter( 'init', 'hippoo_handle_html_display' );
add_filter( 'admin_init', 'hippoo_handle_html_display' );
function hippoo_handle_html_display() {
    $_get = map_deep($_GET, 'sanitize_key'); // phpcs:ignore

    if ( isset( $_get['download_type'] ) && isset( $_get['post_id'] ) ) {
        $post_id = sanitize_text_field( $_get['post_id'] );
        $download_type = sanitize_text_field( $_get['download_type'] );
        
        // Security: Only administrators or order owners can view invoices
        if ( user_has_order_access( $post_id ) || current_user_can( 'administrator' ) ) {
            // Generate HTML from secure template (input is sanitized, template is from plugin directory)
            $html_doc = generate_html( $post_id, $download_type );
            
            if ($html_doc === false) {
                echo '<p>Error: Template file not found</p>';
            } elseif (empty($html_doc)) {
                echo '<p>Error: Generated HTML is empty</p>';
            } else {
                // Set proper headers for HTML document
                header('Content-Type: text/html; charset=utf-8');
                nocache_headers();
                
                // Output complete HTML document
                // Security: HTML is generated from controlled template files with sanitized data
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Complete HTML document for invoice/label printing, generated from secure template with sanitized order data
                echo $html_doc;
            }
        } else {
            echo esc_html(__('You do not have access to view this order.', 'hippoo'));
        }
        exit;
    }
}