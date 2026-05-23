<?php // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hippoo_Ticket_Woo_My_Account {
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_filter( 'woocommerce_email_attachments', array( $this, 'email_attachments' ), 10, 3 );
        add_filter( 'woocommerce_account_orders_columns', array( $this, 'my_account_orders_columns' ) );
        add_action( 'woocommerce_my_account_my_orders_column_factor', array( $this, 'my_account_my_orders_column_factor' ) );
    }

    function enqueue_scripts() {
        wp_enqueue_style ( 'hippoo-css', HIPPOO_INVOICE_PLUGIN_URL . 'assets/css/style.css', [], hippoo_version );
        wp_enqueue_script( 'hippoo-js', HIPPOO_INVOICE_PLUGIN_URL . 'assets/js/admin-script.js', array( 'jquery' ), hippoo_version, true ); // Enqueue script with dependencies
    }

    function email_attachments( $attachments, $email_id, $order ) {
        if ( ! is_a( $order, 'WC_Order' ) || ! isset( $email_id ) ) {
            return $attachments;
        }
    
        $order_id = $order->get_id();
    
        if ( ! user_has_order_access( $order_id ) ) {
            return $attachments;
        }
    
        $html_doc = generate_html( $order_id, 'factor' );
        $attachments[] = $html_doc;
        return $attachments;
    }
    

    function my_account_orders_columns( $columns ) { // Updated function name
        $new_columns = array();
    
        foreach ( $columns as $key => $column ) {
            $new_columns[ $key ] = $column;
    
            if ( $key === 'order-number' ) {
                $new_columns['factor'] = 'Invoice';
            }
        }
    
        return $new_columns;
    }

    function my_account_my_orders_column_factor( $order ) {
        $order_id = $order->get_id();

        echo '<a href="?post_id=' . intval($order_id) . '&download_type=factor" target="_blank" class="factor-download"><img src="' . esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/invoice-factor.svg') . '" /></a>';
    }
}

new Hippoo_Ticket_Woo_My_Account();
