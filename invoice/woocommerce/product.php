<?php // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hippoo_Ticket_Woo_Product {
    public function __construct() {
        add_filter( 'manage_edit-product_columns', array( $this, 'remove_product_sku_column' ) );
        add_filter( 'manage_edit-product_columns', array( $this, 'product_sku_column' ), 20 );
        add_action( 'manage_posts_custom_column', array( $this, 'populate_product_columns' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
    }

    function remove_product_sku_column( $columns ) {
        unset( $columns['sku'] );

        return $columns;
    }

    function product_sku_column( $columns ) {
        $settings = get_option( 'hippoo_invoice_settings', [] );
        if ( ! $settings['show_barcode_products_list'] ) {
            return $columns;
        }

        return array_slice( $columns, 0, 3, true )
            + array( 'sku_barcode' => __( 'SKU', 'hippoo' ) )
            + array_slice( $columns, 3, NULL, true );
    }

    function populate_product_columns( $column_name ) {
        global $product;

        if ( $column_name  == 'sku_barcode' ) {
            if ( ! $product ) {
                return;
            }

            $sku = $product->get_sku();
            $barcode = base64_encode(generate_barcode_html($sku));

            echo '<img src="' . esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/barcode-scanner.svg') . '" data-src="' . esc_attr($barcode) . '" data-text="' . esc_attr($sku) . '" class="hippoo-tooltip" />';
        }
    }

    function add_meta_boxes() {
        $settings = get_option( 'hippoo_invoice_settings', [] );
        if ( isset( $settings['show_barcode_products_details'] ) && $settings['show_barcode_products_details'] ) {
            add_meta_box(
                'product_barcode_meta',
                __( 'Product Barcode (SKU)', 'hippoo' ),
                array( $this, 'render_product_barcode_meta_box' ),
                'product',
                'side',
                'high'
            );
        }
    }
    
    function render_product_barcode_meta_box( $post ) {
        $product = wc_get_product( $post->ID );
        $sku = $product->get_sku();
        $barcode = generate_barcode_html( $sku );
    
        echo wp_kses_post($barcode) . '<br><strong>' . esc_html($sku) . '</strong>';
    }
}

new Hippoo_Ticket_Woo_Product();
