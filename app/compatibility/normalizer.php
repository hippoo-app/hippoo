<?php
/**
 * Hippoo Compatibility – WooCommerce API response normalization
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// REST hooks
// ---------------------------------------------------------------------------

/** Intercept WooCommerce REST responses for Compatibility Mode. */
add_filter( 'rest_pre_dispatch', 'hippoo_compatibility_maybe_normalize_response', 10, 3 );

/** Prepare WooCommerce REST response normalization. */
function hippoo_compatibility_maybe_normalize_response( $result, $server, $request ) {
    $route = $request->get_route();

    // Handle Compatibility-Mode header to dynamically toggle normalization settings
    hippoo_compatibility_handle_mode_header( $route );

    if ( ! hippoo_compatibility_should_normalize( $route ) ) {
        return $result;
    }

    add_filter( 'rest_pre_echo_response', 'hippoo_compatibility_normalize_response', 10, 3 );

    return $result;
}

/** Normalize the WooCommerce REST response. */
function hippoo_compatibility_normalize_response( $response, $server, $request ) {
    $route = $request->get_route();

    if ( strpos( $route, '/wc/v' ) !== 0 ) {
        return $response;
    }

    if ( false !== strpos( $route, '/products' ) ) {
        return hippoo_compatibility_normalize_products( $response );
    }

    if ( false !== strpos( $route, '/orders' ) ) {
        return hippoo_compatibility_normalize_orders( $response );
    }

    if ( false !== strpos( $route, '/coupons' ) ) {
        return hippoo_compatibility_normalize_coupons( $response );
    }

    return $response;
}


// ---------------------------------------------------------------------------
// Compatibility mode
// ---------------------------------------------------------------------------

/** Handle the Compatibility-Mode request header. */
function hippoo_compatibility_handle_mode_header( $route ) {
    $is_hippoo_request = isset( $_SERVER['HTTP_HIPPOO'] ) && 'true' === strtolower( $_SERVER['HTTP_HIPPOO'] );

    if ( ! $is_hippoo_request ) {
        return;
    }

    $header = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_COMPATIBILITY_MODE'] ?? '' ) ) );

    if ( ! in_array( $header, array( 'enable', 'disable' ), true ) ) {
        return;
    }

    if ( false !== strpos( $route, '/orders' ) ) {
        $type = 'orders';
    } elseif ( false !== strpos( $route, '/products' ) ) {
        $type = 'products';
    } elseif ( false !== strpos( $route, '/coupons' ) ) {
        $type = 'coupons';
    } else {
        return;
    }

    $settings = get_option( 'hippoo_settings', array() );
    $applies  = isset( $settings['compatibility_applies'] ) ? (array) $settings['compatibility_applies'] : array();

    if ( 'enable' === $header ) {
        if ( ! in_array( $type, $applies ) ) {
            $applies[] = $type;
        }
    } else {
        $applies = array_diff( $applies, array( $type ) );
    }

    $settings['compatibility_applies'] = array_unique( $applies );
    $settings['compatibility_mode']    = true;

    update_option( 'hippoo_settings', $settings );

    hippoo_compatibility_log_issue( $type, 'compatibility_mode_changed', 'compatibility_mode', $header );
}

/** Determine whether the current REST request should be normalized. */
function hippoo_compatibility_should_normalize( $route ) {
    // Skip if compatibility mode is completely disabled in settings
    if ( ! hippoo_compatibility_is_enabled() ) {
        return false;
    }

    // Only apply to Hippoo App requests
    $is_hippoo_request = isset( $_SERVER['HTTP_HIPPOO'] ) && 'true' === strtolower( $_SERVER['HTTP_HIPPOO'] );

    if ( ! $is_hippoo_request ) {
        return false;
    }

    $applies = hippoo_compatibility_get_applies();

    if ( in_array( 'orders', $applies ) && false !== strpos( $route, '/orders' ) ) {
        return true;
    }

    if ( in_array( 'products', $applies ) && false !== strpos( $route, '/products' ) ) {
        return true;
    }

    if ( in_array( 'coupons', $applies ) && false !== strpos( $route, '/coupons' ) ) {
        return true;
    }

    return false;
}

/** Check whether Compatibility Mode is enabled. */
function hippoo_compatibility_is_enabled() {
    $settings = get_option( 'hippoo_settings', array() );
    return ! empty( $settings['compatibility_mode'] );
}

/** Return the Compatibility API types currently enabled. */
function hippoo_compatibility_get_applies() {
    $settings = get_option( 'hippoo_settings', array() );
    $applies  = isset( $settings['compatibility_applies'] ) ? (array) $settings['compatibility_applies'] : array();
    return $applies;
}


// ---------------------------------------------------------------------------
// Product normalization
// ---------------------------------------------------------------------------

/** Normalize products API response. */
function hippoo_compatibility_normalize_products( $data ) {
    if ( isset( $data[0] ) && is_array( $data[0] ) ) {
        foreach ( $data as &$product ) {
            $product = hippoo_compatibility_normalize_product( $product );
        }
    } elseif ( isset( $data['id'] ) ) {
        $data = hippoo_compatibility_normalize_product( $data );
    }

    return $data;
}

/** Normalize a single product. */
function hippoo_compatibility_normalize_product( $product ) {
    if ( ! is_array( $product ) ) {
        hippoo_compatibility_log_issue( 'products', 'item_skip', 'invalid_product', $product );
        return array();
    }

    $product_id = isset( $product['id'] ) ? $product['id'] : 'unknown';

    return hippoo_compatibility_fix_types( $product, hippoo_compatibility_product_types(), 'products', $product_id );
}


// ---------------------------------------------------------------------------
// Order normalization
// ---------------------------------------------------------------------------

/** Normalize orders API response. */
function hippoo_compatibility_normalize_orders( $data ) {
    if ( isset( $data[0] ) && is_array( $data[0] ) ) {
        foreach ( $data as &$order ) {
            $order = hippoo_compatibility_normalize_order( $order );
        }
    } elseif ( isset( $data['id'] ) ) {
        $data = hippoo_compatibility_normalize_order( $data );
    }

    return $data;
}

/** Normalize a single order. */
function hippoo_compatibility_normalize_order( $order ) {
    if ( ! is_array( $order ) ) {
        hippoo_compatibility_log_issue( 'orders', 'item_skip', 'invalid_order', $order );
        return array();
    }

    $order_id = isset( $order['id'] ) ? $order['id'] : 'unknown';

    $order = hippoo_compatibility_fix_types( $order, hippoo_compatibility_order_types(), 'orders', $order_id );

    // Fix billing/shipping required keys
    $billing_keys = ['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
    $shipping_keys = ['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

    foreach ( array( 'billing'  => $billing_keys, 'shipping' => $shipping_keys, ) as $key => $keys ) {
        if ( isset( $order[ $key ] ) && is_array( $order[ $key ] ) ) {
            $missing = array_diff( $keys, array_keys( $order[ $key ] ) );

            if ( ! empty( $missing ) ) {
                hippoo_compatibility_log_issue( 'orders', 'fixed', "{$key}_missing_keys", $missing, $order_id );

                foreach ( $missing as $missing_key ) {
                    $order[ $key ][ $missing_key ] = '-';
                }
            }
        }
    }

    // Clean line_items meta_data
    if ( isset( $order['line_items'] ) && is_array( $order['line_items'] ) ) {
        foreach ( $order['line_items'] as &$item ) {
            if ( isset( $item['meta_data'] ) ) {
                hippoo_compatibility_log_issue( 'orders', 'removed', 'line_item_meta_data', $item['meta_data'], $order_id );
                unset( $item['meta_data'] );
            }
        }
    }

    // Clean shipping_lines meta_data
    if ( isset( $order['shipping_lines'] ) && is_array( $order['shipping_lines'] ) ) {
        foreach ( $order['shipping_lines'] as &$shipping_line ) {
            if ( isset( $shipping_line['meta_data'] ) ) {
                hippoo_compatibility_log_issue( 'orders', 'removed', 'shipping_line_meta_data', $shipping_line['meta_data'], $order_id );
                unset( $shipping_line['meta_data'] );
            }
        }
    }

    return $order;
}


// ---------------------------------------------------------------------------
// Coupon normalization
// ---------------------------------------------------------------------------

/** Normalize coupons API response. */
function hippoo_compatibility_normalize_coupons( $data ) {
    if ( isset( $data[0] ) && is_array( $data[0] ) ) {
        foreach ( $data as &$coupon ) {
            $coupon = hippoo_compatibility_normalize_coupon( $coupon );
        }
    } elseif ( isset( $data['id'] ) ) {
        $data = hippoo_compatibility_normalize_coupon( $data );
    }

    return $data;
}

/** Normalize a single coupon. */
function hippoo_compatibility_normalize_coupon( $coupon ) {
    if ( ! is_array( $coupon ) ) {
        hippoo_compatibility_log_issue( 'coupons', 'item_skip', 'invalid_coupon', $coupon );
        return array();
    }

    $coupon_id = isset( $coupon['id'] ) ? $coupon['id'] : 'unknown';

    return hippoo_compatibility_fix_types( $coupon, hippoo_compatibility_coupon_types(), 'coupons', $coupon_id );
}


// ---------------------------------------------------------------------------
// Type normalization
// ---------------------------------------------------------------------------

/** Fix data types according to the WooCommerce API schema. */
function hippoo_compatibility_fix_types( $data, $type_map, $endpoint, $object_id ) {
    if ( isset( $data['meta_data'] ) ) {
        hippoo_compatibility_log_issue( $endpoint, 'removed', 'meta_data', $data['meta_data'], $object_id );
    }

    unset( $data['meta_data'] );

    foreach ( $type_map as $field => $expected ) {
        if ( ! array_key_exists( $field, $data ) ) {
            continue;
        }

        $value    = $data[ $field ];
        $fixed    = false;
        $fallback = null;

        switch ( $expected ) {
            case 'string':
                if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
                    $fallback = '';
                    $fixed    = true;
                }
                break;

            case 'bool':
                if ( ! is_bool( $value ) ) {
                    $fallback = false;
                    $fixed    = true;
                }
                break;

            case 'int':
                if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
                    $fallback = is_numeric( $value ) ? (int) $value : 0;
                    $fixed    = true;
                }
                break;

            case 'float':
                if ( ! is_float( $value ) && ! is_int( $value ) && ! is_numeric( $value ) ) {
                    $fallback = 0.0;
                    $fixed    = true;
                }
                break;

            case 'nullable':
                if ( ! is_null( $value ) && ! is_string( $value ) && '' !== $value ) {
                    $fallback = null;
                    $fixed    = true;
                }
                break;

            case 'nullable_int':
                if ( ! is_null( $value ) && ! is_numeric( $value ) ) {
                    $fallback = null;
                    $fixed    = true;
                }
                break;

            case 'array':
                if ( ! is_array( $value ) ) {
                    $fallback = array();
                    $fixed    = true;
                }
                break;

            case 'object':
                if ( ! is_array( $value ) && ! is_object( $value ) ) {
                    $fallback = new stdClass();
                    $fixed    = true;
                }
                break;
        }

        if ( $fixed ) {
            hippoo_compatibility_log_issue( $endpoint, 'fixed', $field, $value, $object_id );
            $data[ $field ] = $fallback;
        }
    }

    return $data;
}


// ---------------------------------------------------------------------------
// Type maps
// ---------------------------------------------------------------------------

/** Return WooCommerce product field types. */
function hippoo_compatibility_product_types() {
    return array(
        'name'                => 'string',
        'slug'                => 'string',
        'permalink'           => 'string',
        'type'                => 'string',
        'status'              => 'string',
        'description'         => 'string',
        'short_description'   => 'string',
        'sku'                 => 'string',
        'price'               => 'string',
        'regular_price'       => 'string',
        'sale_price'          => 'string',
        'tax_status'          => 'string',
        'tax_class'           => 'string',
        'backorders'          => 'string',
        'external_url'        => 'string',
        'button_text'         => 'string',
        'purchase_note'       => 'string',
        'weight'              => 'string',
        'shipping_class'      => 'string',
        'stock_status'        => 'string',
        'catalog_visibility'  => 'string',
        'price_html'          => 'string',
        'post_password'       => 'string',
        'global_unique_id'    => 'string',
        'date_created'        => 'string',
        'date_created_gmt'    => 'string',
        'date_modified'       => 'string',
        'date_modified_gmt'   => 'string',

        'featured'            => 'bool',
        'on_sale'             => 'bool',
        'purchasable'         => 'bool',
        'virtual'             => 'bool',
        'downloadable'        => 'bool',
        'manage_stock'        => 'bool',
        'backorders_allowed'  => 'bool',
        'backordered'         => 'bool',
        'sold_individually'   => 'bool',
        'shipping_required'   => 'bool',
        'shipping_taxable'    => 'bool',
        'reviews_allowed'     => 'bool',
        'has_options'         => 'bool',

        'total_sales'         => 'int',
        'download_limit'      => 'int',
        'download_expiry'     => 'int',
        'shipping_class_id'   => 'int',
        'rating_count'        => 'int',
        'parent_id'           => 'int',
        'menu_order'          => 'int',

        'average_rating'      => 'float',

        'date_on_sale_from'   => 'nullable',
        'date_on_sale_from_gmt' => 'nullable',
        'date_on_sale_to'     => 'nullable',
        'date_on_sale_to_gmt' => 'nullable',
        'low_stock_amount'    => 'nullable',

        'stock_quantity'      => 'nullable_int',

        'images'              => 'array',
        'categories'          => 'array',
        'tags'                => 'array',
        'brands'              => 'array',
        'attributes'          => 'array',
        'default_attributes'  => 'array',
        'variations'          => 'array',
        'grouped_products'    => 'array',
        'upsell_ids'          => 'array',
        'cross_sell_ids'      => 'array',
        'related_ids'         => 'array',
        'downloads'           => 'array',

        'dimensions'          => 'object',
        '_links'              => 'object',
    );
}

/** Return WooCommerce order field types. */
function hippoo_compatibility_order_types() {
    return array(
        'id'                    => 'int',
        'parent_id'             => 'int',
        'customer_id'           => 'int',

        'number'                => 'string',
        'status'                => 'string',
        'currency'              => 'string',
        'version'               => 'string',
        'order_key'             => 'string',
        'transaction_id'        => 'string',
        'customer_ip_address'   => 'string',
        'customer_user_agent'   => 'string',
        'created_via'            => 'string',
        'customer_note'          => 'string',
        'cart_hash'             => 'string',
        'payment_method'        => 'string',
        'payment_method_title'  => 'string',
        'payment_url'           => 'string',
        'currency_symbol'       => 'string',
        'weight_unit'           => 'string',
        'date_created'          => 'string',
        'date_created_gmt'      => 'string',
        'date_modified'         => 'string',
        'date_modified_gmt'     => 'string',
        'discount_total'        => 'string',
        'discount_tax'          => 'string',
        'shipping_total'        => 'string',
        'shipping_tax'          => 'string',
        'cart_tax'              => 'string',
        'total'                 => 'string',
        'total_tax'             => 'string',

        'prices_include_tax'    => 'bool',
        'is_editable'           => 'bool',
        'needs_payment'         => 'bool',
        'needs_processing'      => 'bool',

        'date_completed'        => 'nullable',
        'date_completed_gmt'    => 'nullable',
        'date_paid'             => 'nullable',
        'date_paid_gmt'         => 'nullable',

        'total_items'           => 'int',

        'total_weight'          => 'float',

        'billing'               => 'array',
        'shipping'              => 'array',
        'line_items'            => 'array',
        'tax_lines'             => 'array',
        'shipping_lines'        => 'array',
        'fee_lines'             => 'array',
        'coupon_lines'          => 'array',
        'refunds'               => 'array',

        '_links'                => 'object',
    );
}

/** Return WooCommerce coupon field types. */
function hippoo_compatibility_coupon_types() {
    return array(
        'id'                         => 'int',
        'usage_count'                => 'int',

        'code'                       => 'string',
        'amount'                     => 'string',
        'status'                     => 'string',
        'discount_type'              => 'string',
        'description'                => 'string',
        'date_created'               => 'string',
        'date_created_gmt'           => 'string',
        'date_modified'              => 'string',
        'date_modified_gmt'          => 'string',
        'minimum_amount'             => 'string',
        'maximum_amount'             => 'string',

        'individual_use'             => 'bool',
        'free_shipping'              => 'bool',
        'exclude_sale_items'         => 'bool',

        'date_expires'               => 'nullable',
        'date_expires_gmt'           => 'nullable',
        'usage_limit'                => 'nullable',
        'usage_limit_per_user'       => 'nullable',
        'limit_usage_to_x_items'     => 'nullable',

        'product_ids'                => 'array',
        'excluded_product_ids'       => 'array',
        'product_categories'         => 'array',
        'excluded_product_categories'=> 'array',
        'email_restrictions'         => 'array',
        'used_by'                    => 'array',

        '_links'                     => 'object',
    );
}
