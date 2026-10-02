<?php
/**
 * Hippoo Permissions – access control and API enforcement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Access filters
// ---------------------------------------------------------------------------

/** Register Permissions access filters. */
add_action( 'rest_api_init', 'hippoo_permissions_register_rest_filters' );

function hippoo_permissions_register_rest_filters() {
    // General
    add_filter( 'woocommerce_rest_check_permissions', 'hippoo_permissions_override_woocommerce_permissions', 9999, 4 );
    add_filter( 'rest_pre_dispatch', 'hippoo_permissions_block_unauthorized_access', 9999, 3 );

    // Orders
    add_filter( 'woocommerce_rest_shop_order_object_query', 'hippoo_permissions_filter_orders_query', 99, 2 );
    add_filter( 'woocommerce_rest_prepare_shop_order_object', 'hippoo_permissions_filter_orders_response', 99, 3 );
    add_filter( 'rest_request_after_callbacks', 'hippoo_permissions_filter_order_count_response', 99, 3 );

    // Products
    add_filter( 'woocommerce_rest_product_object_query', 'hippoo_permissions_filter_products_query', 99, 2 );
    add_filter( 'woocommerce_rest_prepare_product_object', 'hippoo_permissions_filter_products_response', 99, 3 );

    // Customers
    add_filter( 'woocommerce_rest_prepare_customer', 'hippoo_permissions_filter_customers_response', 99, 3 );

    // Reviews
    add_filter( 'woocommerce_rest_prepare_product_review', 'hippoo_permissions_filter_reviews_response', 99, 3 );

    // App features
    add_filter( 'hippoo_system_info_extensions', 'hippoo_permissions_filter_system_info_response', 99, 2 );

    // Hippoo extensions
    add_filter( 'hippoo_extension_permission_check', 'hippoo_permissions_override_extension_permission_callback', 10, 3 );

    // Hippoo BI
    add_filter( 'hippoo_bi_permission_check', 'hippoo_permissions_override_bi_permission_callback', 10, 1 );
}


// ---------------------------------------------------------------------------
// General access
// ---------------------------------------------------------------------------

/** Override WooCommerce REST permissions based on the current user's role. */
function hippoo_permissions_override_woocommerce_permissions( $permission, $context, $object_id, $post_type ) {
    $perms = hippoo_permissions_get_user_permissions();

    // Admin or no restrictions - default permission.
    if ( null === $perms || false === $perms || ! is_array( $perms ) ) {
        return $permission;
    }

    $general_perms = $perms['general'] ?? array();

    if ( ! empty( $general_perms['read_only'] ) ) {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            return false;  // Proper 403
        }
    }

    return true;
}

/** Block REST requests that are not allowed for the current user's role. */
function hippoo_permissions_block_unauthorized_access( $response, $server, $request ) {
    $route = untrailingslashit( $request->get_route() );

    // Order notes
    if ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/orders/notes' ) ) {
        if (
            false === hippoo_permissions_has_role_access( 'orders', 'access_orders' ) ||
            false === hippoo_permissions_has_role_access( 'orders', 'order_details' ) ||
            false === hippoo_permissions_has_role_access( 'orders', 'order_notes' )
        ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Orders
    elseif ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/orders' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'orders', 'access_orders' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Invoice
    elseif ( 0 === strpos( $route, '/wc-hippoo-invoice/v' ) && false !== strpos( $route, '/invoice' ) ) {
        if (
            false === hippoo_permissions_has_role_access( 'orders', 'access_orders' ) ||
            false === hippoo_permissions_has_role_access( 'orders', 'order_details' ) ||
            false === hippoo_permissions_has_role_access( 'orders', 'invoice' )
        ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Shipping label
    elseif ( 0 === strpos( $route, '/wc-hippoo-invoice/v' ) && false !== strpos( $route, '/shipping-label' ) ) {
        if (
            false === hippoo_permissions_has_role_access( 'orders', 'access_orders' ) ||
            false === hippoo_permissions_has_role_access( 'orders', 'order_details' ) ||
            false === hippoo_permissions_has_role_access( 'orders', 'shipping_label' )
        ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Products
    elseif ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/products' ) ) {
        if ( false !== strpos( $route, '/products/attributes' ) ) {
            if ( false === hippoo_permissions_has_role_access( 'products', 'access_attributes' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
            }
        }
        elseif ( false !== strpos( $route, '/products/categories' ) ) {
            if ( false === hippoo_permissions_has_role_access( 'products', 'access_categories' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
            }
        }
        elseif ( false !== strpos( $route, '/products/tags' ) ) {
            if ( false === hippoo_permissions_has_role_access( 'products', 'access_tags' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
            }
        }
        elseif ( false !== strpos( $route, '/products/brands' ) ) {
            if ( false === hippoo_permissions_has_role_access( 'products', 'access_brands' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
            }
        }
        elseif ( false !== strpos( $route, '/products/shipping_classes' ) ) {
            if ( false === hippoo_permissions_has_role_access( 'products', 'access_shipping_classes' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
            }
        }
        elseif ( false !== strpos( $route, '/products/reviews' ) ) {
            if ( false === hippoo_permissions_has_role_access( 'reviews', 'access_reviews' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
            }
        }
        else {
            if ( false === hippoo_permissions_has_role_access( 'products', 'access_products' ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
            }
        }
    }
    // Out of stock list
    elseif ( 0 === strpos( $route, '/wc-hippoo/v' ) && false !== strpos( $route, '/wc/stock' ) ) {
        if (
            false === hippoo_permissions_has_role_access( 'products', 'access_products' ) ||
            false === hippoo_permissions_has_role_access( 'products', 'out_of_stock_list' )
        ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Customers
    elseif ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/customers' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'customers', 'access_customers' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Reports
    elseif ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/reports' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'analytics', 'show_sale_analytics' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // BI reports
    elseif ( 0 === strpos( $route, '/hippoo/v' ) && false !== strpos( $route, '/bi' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'analytics', 'show_bi_analytics' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Coupons
    elseif ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/coupons' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'coupons', 'access_coupons' ) ) {
            return new WP_Error( 'rest_forbidden',  __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Settings
    elseif ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/settings' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'settings', 'show_shop_settings' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Extensions system info
    elseif ( 0 === strpos( $route, '/wc-hippoo/v' ) && false !== strpos( $route, '/wp/system/info' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'app_features', 'access_extensions' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }
    // Extensions
    elseif ( 0 === strpos( $route, '/wc-hippoo/v' ) && false !== strpos( $route, '/ext' ) ) {
        if ( false === hippoo_permissions_has_role_access( 'app_features', 'access_extensions' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => 403 ) );
        }
    }

    return $response;
}


// ---------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------

/** Limit order queries to the statuses allowed for the current role. */
function hippoo_permissions_filter_orders_query( $args, $request ) {
    if ( true === hippoo_permissions_has_role_access( 'orders', 'allowed_status' ) ) {
        $perms       = hippoo_permissions_get_user_permissions();
        $order_perms = $perms['orders'] ?? array();
        $allowed     = (array) ( $order_perms['allowed_status'] ?? array() );

        $clean = array_filter( array_map( function ( $status ) {
            return str_replace( 'wc-', '', trim( $status ) );
        }, $allowed ) );

        if ( ! empty( $clean ) ) {
            $args['status']      = $clean;
            $args['post_status'] = array_map( function ( $status ) {
                return 'wc-' . $status;
            }, $clean );

            add_filter( 'woocommerce_order_query_args', function ( $query_args ) use ( $clean ) {
                $query_args['status'] = $clean;
                $query_args['post_status'] = array_map( function ( $status ) {
                    return 'wc-' . $status;
                }, $clean );
                return $query_args;
            }, 9999 );
        }
    }

    return $args;
}

/** Filter sensitive order fields from REST responses. */
function hippoo_permissions_filter_orders_response( $response, $order, $request ) {
    $data = $response->get_data();

    if ( false === hippoo_permissions_has_role_access( 'orders', 'order_details' ) ) {
        $data['billing']  = array();
        $data['shipping'] = array();

        $data['line_items']     = array();
        $data['fee_lines']      = array();
        $data['tax_lines']      = array();
        $data['shipping_lines'] = array();
        $data['coupon_lines']   = array();
        $data['refunds']        = array();
        $data['meta_data']      = array();

        $data['total']          = '0';
        $data['total_tax']      = '0';
        $data['discount_total'] = '0';
        $data['discount_tax']   = '0';
        $data['shipping_total'] = '0';
        $data['shipping_tax']   = '0';
        $data['cart_tax']       = '0';

        $data['status']               = '-';
        $data['customer_note']        = '-';
        $data['payment_method']       = '-';
        $data['payment_method_title'] = '-';
        $data['transaction_id']       = '-';
        $data['date_paid']            = null;

        $data['customer_id']         = 0;
        $data['customer_ip_address'] = '-';
        $data['customer_user_agent'] = '-';

        $response->set_data( $data );

        return $response;
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'order_totals' ) ) {
        $data['total']          = '0';
        $data['total_tax']      = '0';
        $data['discount_total'] = '0';
        $data['discount_tax']   = '0';
        $data['shipping_total'] = '0';
        $data['shipping_tax']   = '0';
        $data['cart_tax']       = '0';
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'name' ) ) {
        $data['billing']['first_name']  = '-';
        $data['billing']['last_name']   = '-';
        $data['billing']['company']     = '-';
        $data['shipping']['first_name'] = '-';
        $data['shipping']['last_name']  = '-';
        $data['shipping']['company']    = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'items' ) ) {
        $data['line_items'] = array();
        $data['fee_lines']  = array();
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'taxes' ) ) {
        $data['tax_lines'] = array();
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'shipping_info' ) ) {
        $data['shipping_lines']       = array();
        $data['shipping']['address_1'] = '-';
        $data['shipping']['address_2'] = '-';
        $data['shipping']['city']      = '-';
        $data['shipping']['state']     = '-';
        $data['shipping']['postcode']  = '-';
        $data['shipping']['country']   = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'order_statuses' ) ) {
        $data['status'] = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'coupon_info' ) ) {
        $data['coupon_lines'] = array();
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'payment_info' ) ) {
        $data['payment_method']       = '-';
        $data['payment_method_title'] = '-';
        $data['transaction_id']       = '-';
        $data['date_paid']            = null;
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'customer_info' ) ) {
        $data['customer_id'] = 0;

        $data['billing']['email']    = '-';
        $data['billing']['phone']    = '-';
        $data['billing']['address_1'] = '-';
        $data['billing']['address_2'] = '-';
        $data['billing']['city']      = '-';
        $data['billing']['state']     = '-';
        $data['billing']['postcode']  = '-';
        $data['billing']['country']   = '-';

        $data['customer_ip_address'] = '-';
        $data['customer_user_agent'] = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'custom_fields' ) ) {
        $data['meta_data'] = array();
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'customer_note' ) ) {
        $data['customer_note'] = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'orders', 'order_totals' ) ) {
        $data['refunds'] = array();
    }

    $response->set_data( $data );

    return $response;
}

/** Hide order counts when the current role cannot access them. */
function hippoo_permissions_filter_order_count_response( $response, $handler, $request ) {
    $route = untrailingslashit( $request->get_route() );

    if ( 0 === strpos( $route, '/wc/v' ) && false !== strpos( $route, '/orders' ) ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( false === hippoo_permissions_has_role_access( 'orders', 'order_count' ) ) {
            $response->header( 'X-WP-Total', '0' );
            $response->header( 'X-WP-TotalPages', '1' );
        }
    }

    return $response;
}


// ---------------------------------------------------------------------------
// Products
// ---------------------------------------------------------------------------

/** Limit product queries to the categories and types allowed for the current role. */
function hippoo_permissions_filter_products_query( $args, $request ) {
    $perms     = hippoo_permissions_get_user_permissions();
    $prod_perms = $perms['products'] ?? array();

    if ( ! isset( $args['tax_query'] ) ) {
        $args['tax_query'] = array();
    }

    if (
        true === hippoo_permissions_has_role_access( 'products', 'categories' ) &&
        ! empty( $prod_perms['categories'] )
    ) {
        $args['tax_query'][] = array(
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => (array) ( $prod_perms['categories'] ?? array() ),
            'include_children' => true,
        );
    }

    if (
        true === hippoo_permissions_has_role_access( 'products', 'types' ) &&
        ! empty( $prod_perms['types'] )
    ) {
        $args['tax_query'][] = array(
            'taxonomy' => 'product_type',
            'field'    => 'slug',
            'terms'    => (array) ( $prod_perms['types'] ?? array() ),
        );
    }

    return $args;
}

/** Filter sensitive product fields from REST responses. */
function hippoo_permissions_filter_products_response( $response, $server, $request ) {
    $data = $response->get_data();

    if ( false === hippoo_permissions_has_role_access( 'products', 'product_name' ) ) {
        $data['name'] = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'products', 'prices' ) ) {
        $data['price']         = '0';
        $data['regular_price'] = '0';
        $data['sale_price']    = '0';
    }

    if ( false === hippoo_permissions_has_role_access( 'products', 'stock_quantity' ) ) {
        $data['stock_quantity'] = null;
        $data['manage_stock']   = false;
        $data['stock_status']   = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'products', 'sku' ) ) {
        $data['sku'] = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'products', 'status' ) ) {
        $data['status'] = '-';
    }

    $response->set_data( $data );

    return $response;
}


// ---------------------------------------------------------------------------
// Customers
// ---------------------------------------------------------------------------

/** Filter sensitive customer fields from REST responses. */
function hippoo_permissions_filter_customers_response( $response, $server, $request ) {
    $data = $response->get_data();

    if ( false === hippoo_permissions_has_role_access( 'customers', 'name' ) ) {
        $data['first_name'] = '-';
        $data['last_name']  = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'customers', 'address' ) ) {
        $data['billing']['address_1'] = '-';
        $data['billing']['address_2'] = '-';
        $data['billing']['country']   = '-';
        $data['billing']['state']     = '-';
        $data['billing']['city']      = '-';
        $data['billing']['postcode']  = '-';

        $data['shipping']['address_1'] = '-';
        $data['shipping']['address_2'] = '-';
        $data['shipping']['country']   = '-';
        $data['shipping']['state']     = '-';
        $data['shipping']['city']      = '-';
        $data['shipping']['postcode']  = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'customers', 'phone' ) ) {
        $data['billing']['phone']  = '-';
        $data['shipping']['phone'] = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'customers', 'email' ) ) {
        $data['email']          = '-';
        $data['billing']['email'] = '-';
    }

    $response->set_data( $data );

    return $response;
}


// ---------------------------------------------------------------------------
// Reviews
// ---------------------------------------------------------------------------

/** Filter sensitive review fields from REST responses. */
function hippoo_permissions_filter_reviews_response( $response, $server, $request ) {
    $data = $response->get_data();

    if ( false === hippoo_permissions_has_role_access( 'reviews', 'reviewer_name' ) ) {
        $data['reviewer']       = '-';
        $data['reviewer_email'] = '-';
    }

    if ( false === hippoo_permissions_has_role_access( 'reviews', 'review_content' ) ) {
        $data['review'] = '-';
    }

    $response->set_data( $data );

    return $response;
}


// ---------------------------------------------------------------------------
// App features
// ---------------------------------------------------------------------------

/** Filter the extension list returned by the Hippoo system info endpoint. */
function hippoo_permissions_filter_system_info_response( $plugins_info, $request ) {
    if ( 0 !== strpos( $request->get_route(), '/wc-hippoo/v1/wp/system/info' ) ) {
        return $plugins_info;
    }

    $access = hippoo_permissions_has_role_access( 'app_features', 'access_extensions' );

    if ( null === $access ) {
        return $plugins_info;
    }

    if ( false === $access ) {
        return array();
    }

    $perms = hippoo_permissions_get_user_permissions();
    $allowed_slugs = $perms['app_features']['extensions'] ?? array();

    if ( empty( $allowed_slugs ) ) {
        return $plugins_info;
    }

    $filtered = array_filter( $plugins_info, function ( $extension ) use ( $allowed_slugs ) {
        $slug = $extension['slug'] ?? '';
        return in_array( $slug, $allowed_slugs, true );
    } );

    return array_values( $filtered );
}

/** Override extension permission callbacks according to the current role. */
function hippoo_permissions_override_extension_permission_callback( $default_callback, $route, $handler ) {
    $access = hippoo_permissions_has_role_access( 'app_features', 'access_extensions' );

    if ( null === $access ) {
        return $default_callback;
    }

    if ( false === $access ) {
        return '__return_false';
    }

    $parts = explode( '/', trim( $route, '/' ) );
    $extension_slug = $parts[0] ?? '';

    $perms = hippoo_permissions_get_user_permissions();
    $allowed_slugs = $perms['app_features']['extensions'] ?? array();

    if ( empty( $allowed_slugs ) ) {
        return '__return_true';
    }

    if ( in_array( $extension_slug, $allowed_slugs, true ) ) {
        return '__return_true';
    }

    return $default_callback;
}


// ---------------------------------------------------------------------------
// BI
// ---------------------------------------------------------------------------

/** Override Hippoo BI permission according to the current role. */
function hippoo_permissions_override_bi_permission_callback( $default_callback ) {
    $access = hippoo_permissions_has_role_access( 'analytics', 'show_bi_analytics' );

    if ( true === $access ) {
        return '__return_true';
    }

    if ( false === $access ) {
        return '__return_false';
    }

    return $default_callback;
}


// ---------------------------------------------------------------------------
// Permission helpers
// ---------------------------------------------------------------------------

/** Get available WordPress roles for the Permissions settings UI. */
function hippoo_permissions_get_available_roles() {
    $wp_roles = wp_roles()->roles;
    $settings = get_option( 'hippoo_permissions_settings', array() );

    $available_roles = array();

    foreach ( $wp_roles as $role_key => $role_data ) {
        if ( 'administrator' === $role_key ) {
            continue;
        }

        $is_disabled = isset( $settings[ $role_key ] );

        $available_roles[] = array(
            'key'      => $role_key,
            'name'     => $role_data['name'] ?? ucfirst( $role_key ),
            'disabled' => $is_disabled,
        );
    }

    usort( $available_roles, function ( $a, $b ) {
        return strcmp( $a['name'], $b['name'] );
    } );

    return $available_roles;
}

/** Get the effective Hippoo permissions for the current user. */
function hippoo_permissions_get_user_permissions() {
    $user = wp_get_current_user();

    if ( empty( $user ) || ! $user->exists() || ! is_user_logged_in() ) {
        return false;
    }

    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
        return null; // Full access
    }

    $settings = get_option( 'hippoo_permissions_settings', array() );

    foreach ( (array) $user->roles as $role ) {
        if ( isset( $settings[ $role ] ) && is_array( $settings[ $role ] ) ) {
            return $settings[ $role ];
        }
    }

    // Hippoo doesn't manage this role.
    return false;
}

/**
 * Check whether the current user has access to a permission.
 *
 * Returns:
 * - true  = allowed
 * - false = denied
 * - null  = role is not managed by Hippoo
 */
function hippoo_permissions_has_role_access( $section, $key = null ) {
    $perms = hippoo_permissions_get_user_permissions();

    // Administrator.
    if ( null === $perms ) {
        return true;
    }

    // Hippoo doesn't manage this role.
    if ( false === $perms || ! is_array( $perms ) ) {
        return null;
    }

    if ( empty( $perms['general']['enable_access'] ) ) {
        return false;
    }

    if ( ! isset( $perms[ $section ] ) ) {
        return false;
    }

    if ( null === $key ) {
        return true;
    }

    return ! empty( $perms[ $section ][ $key ] );
}
