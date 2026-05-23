<?php // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

## Add Colums to orders table
add_filter(
    ( hippoo_invoice_check_hpos_enabled() )
        ? 'woocommerce_shop_order_list_table_columns'
        : 'manage_edit-shop_order_columns',
    'add_wc_order_list_hippoo_columns'
);

add_action(
    ( hippoo_invoice_check_hpos_enabled() )
        ? 'woocommerce_shop_order_list_table_custom_column'
        : 'manage_shop_order_posts_custom_column',
    'populate_order_hippoo_columns',
    10,
    2
);

function add_wc_order_list_hippoo_columns( $columns ) {
    
    $settings = get_option('hippoo_invoice_settings');
    $new_columns = array();

    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;

        if ( $key === 'order_status' 
          && $settings['show_barcode_order_list'] ) 
          {
            $new_columns['order_id_barcode'] = 'Order ID Barcode';
        }
    }

    $new_columns['order_print'] = 'Print';
    return $new_columns;
}



function populate_order_hippoo_columns( $column, $order ) {
    if (is_int($order)) {
        $order = wc_get_order($order);
    }
    $order_id = $order->get_id();
    switch ($column) {
        case 'order_id_barcode':
            $barcode = base64_encode( generate_barcode_html( $order_id ) );
            echo '<img src="' . esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/barcode-scanner.svg') . '" data-src="' . esc_attr($barcode) . '" data-text="' . esc_attr($order_id) . '" class="hippoo-tooltip" />';
            break;

        case 'order_print':
            echo '<a href="?post_id=' . intval($order_id) . '&download_type=factor" target="_blank" data-barcode="' .
                esc_attr($order_id) . '" data-type="factor" data-text="Invoice" class="hippoo-tooltip" title="Invoice"><img src="' .
                esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/invoice-factor.svg') . '" /></a>';
            
            echo '<a href="?post_id=' . intval($order_id) . '&download_type=label" target="_blank" data-barcode="' . 
                esc_attr($order_id) . '" data-type="label" data-text="Shipping Label" class="hippoo-tooltip" title="Shipping Label"><img src="' . 
                esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/shipping-label.svg') . '" /></a>';
            break;
    }
}


####

add_action('woocommerce_admin_order_item_headers', 'add_stock_status_header');
add_action('woocommerce_admin_order_item_values', 'add_barcode_value');


function add_stock_status_header() {
    echo '<th class="quantity sortable" data-sort="string-ins">' . esc_html__('Barcode', 'hippoo') . '</th>';
}

function add_barcode_value($product) {
    if (!is_a($product, 'WC_Product')) {
        return;
    }

    $sku = $product->get_sku();

    $barcode = base64_encode(generate_barcode_html($sku));

    echo '<td class="barcode" width="1%"><img src="' . esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/barcode-scanner.svg') . '" data-src="' . esc_attr($barcode) . '" data-text="' . esc_attr($sku) . '" class="hippoo-tooltip" /></td>';
}





// function add_custom_meta_box() {
//     add_meta_box(
//         'custom_order_meta_box', // Meta box ID
//         __('Custom Meta Box Title'), // Title displayed in the meta box
//         'render_custom_meta_box_content', // Callback function to render content
//         'post', // Post type to display meta box (in this case, WooCommerce orders)
//         'normal', // Context: 'normal', 'side', or 'advanced'
//         'default' // Priority: 'high', 'core', 'default', or 'low'
//     );
// }
// add_action('add_meta_boxes', 'add_custom_meta_box',  10, 2 );

// function render_custom_meta_box_content($post) {
//     // Output your custom meta box content here
//     echo 'This is a custom meta box content for WooCommerce orders.';
// }

// add_action('add_meta_boxes_shop_order', 'add_meta_boxes'); // Corrected hook for meta boxes


// // Add custom meta box to WooCommerce orders page

// // add_action( 'add_meta_boxes', function( string $post_type, WP_Post $post ): void {} );
// function custom_order_meta_box() {
//     add_meta_box(
//         'custom-order-meta-box',
//         __( 'Custom Meta Box', 'webkul' ),
//         'render_custom_meta_box_content',
//         'shop_order_placehold',
//         'advanced',
//         'core'
//     );
// }
// add_action( 'add_meta_boxes', 'custom_order_meta_box' );

// function add_meta_boxes() {
//     echo "Kian";
//     $settings = get_option('hippoo_invoice_settings');
//     var_dump($settings);
//     // if ($settings['show_barcode_order_details']) {
//         add_meta_box(
//             'order_id_barcode_meta',
//             __('Order ID Barcode', 'hippoo-invoice'),
//             'render_order_barcode_meta_box',
//             'shop_order',
//             'side',
//             'high'
//         );
//     // }

//     add_meta_box(
//         'invoice_and_label_meta',
//         __('Invoice and Label', 'hippoo-invoice'),
//         'render_invoice_and_label_meta_box',
//         'shop_order',
//         'side',
//         'default'
//     );
// }


// function render_order_barcode_meta_box($post) {
//     $order_id = $post->ID;
//     $barcode = generate_barcode_html($order_id);

//     echo $barcode . '<br><strong>' . $order_id . '</strong>';
// }

// function render_invoice_and_label_meta_box($post) {
//     $order_id = $post->ID;

//     echo '<a href="?post_id=' . $order_id . '&download_type=factor" target="_blank"><img src="' . HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/invoice-factor.svg" /> Print invoice</a>';
//     echo '<br>';
//     echo '<a href="?post_id=' . $order_id . '&download_type=label" target="_blank"><img src="' . HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/shipping-label.svg" /> Print shipping label</a>';
// }


// add_action( 'add_meta_boxes', 'custom_order_meta_box' );
// /**
//  * Add custom meta box.
//  *
//  * @return void
//  */
// function custom_order_meta_box() {
// 	add_meta_box(
// 		'custom-order-meta-box',
// 		__( 'Custom Meta Box', 'webkul' ),
// 		'custom_order_meta_box_callback',
// 		'post',
// 		'side',
// 		'high'
// 	);
// }
// function custom_order_meta_box_callback( $post ) {
// 	echo 'HI KIAN';
// }

// require_once HIPPOO_INVOICE_PLUGIN_PATH . 'src/woocommerce/order-test.php';
// new Metabox();




##########
##########
##########

add_action('add_meta_boxes', 'hippoo_invoice_add_meta_boxes_order_id', 10, 2);

function hippoo_invoice_add_meta_boxes_order_id() {
    $settings = get_option('hippoo_invoice_settings');
    if (!isset($settings['show_barcode_order_details']) || !$settings['show_barcode_order_details']) {
        return;
    }
    
    $post_type = hippoo_invoice_check_hpos_enabled()
                    ? wc_get_page_screen_id('shop-order')
                    : 'shop_order';

    if (is_null($post_type)) 
        return;

    add_meta_box(
        'hippoo_invoice_order_barcode_metabox',
        __('Order ID Barcode', 'hippoo'),
        'display_order_barcode_metabox',
        $post_type,
        'side',
        'high'
    );
}

function display_order_barcode_metabox($post_or_order_object) {
    $order = ($post_or_order_object instanceof WP_Post)
        ? wc_get_order($post_or_order_object->ID)
        : $post_or_order_object;

    if (!$order) {
        return;
    }

    $order_id = $order->get_id();
    $barcode = generate_barcode_html($order_id);
    echo wp_kses_post($barcode);
}