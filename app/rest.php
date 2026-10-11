<?php
/**
 * Hippoo REST – infrastructure, permission helpers, routes, callbacks, WC enrichments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Infrastructure: CORS, WC auth, /ext
// ---------------------------------------------------------------------------

/** Send CORS headers for allowed origins and handle OPTIONS preflight. */
add_action( 'rest_api_init', 'hippoo_rest_send_cors_headers', 0 );

function hippoo_rest_send_cors_headers() {
    $origin = get_http_origin();

    // Only allow hippoo.app (and optionally localhost for development)
    $allowed_origins = array(
        'https://hippoo.app',
        // 'http://localhost', // Uncomment during local development
        // 'http://127.0.0.1', // Optional: if using 127.0.0.1
    );

    // Check if the origin is allowed
    if ( in_array( $origin, $allowed_origins ) ) {
        header( "Access-Control-Allow-Origin: $origin" );
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );
        header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With' );
        header( 'Access-Control-Max-Age: 86400' ); // Cache preflight response for 24 hours
    }

    // Handle preflight OPTIONS request
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        status_header( 200 );
        header( 'Content-Length: 0' ); // Ensure no content is sent with OPTIONS
        exit();
    }
}

/**
 * Allow Hippoo REST namespaces to use WooCommerce authentication.
 *
 * Default: wc-hippoo/v1
 * Modules add more via `hippoo_rest_wc_auth_namespaces`:
 *
 *   add_filter( 'hippoo_rest_wc_auth_namespaces', function ( $ns ) {
 *       $ns[] = 'hippoo-ai/v1';
 *       return $ns;
 *   } );
 */
add_filter( 'woocommerce_rest_is_request_to_rest_api', 'hippoo_rest_use_wc_authentication' );

function hippoo_rest_use_wc_authentication( $condition ) {
    if ( empty( $_SERVER['REQUEST_URI'] ) ) {
        return $condition;
    }

    $rest_prefix = trailingslashit( rest_get_url_prefix() );
    $request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
    $namespaces  = apply_filters( 'hippoo_rest_wc_auth_namespaces', array( 'wc-hippoo/v1' ) );

    foreach ( (array) $namespaces as $ns ) {
        $ns = trim( (string) $ns, '/' );
        if ( '' !== $ns && false !== strpos( $request_uri, $rest_prefix . $ns ) ) {
            return true;
        }
    }

    return $condition;
}

/** Re-register non-Hippoo routes under /wc-hippoo/v1/ext for the app. */
add_action( 'rest_api_init', 'hippoo_rest_re_register_external_routes', PHP_INT_MAX );

function hippoo_rest_re_register_external_routes() {
    $server        = rest_get_server();
    $endpoints     = $server->get_routes();
    $new_namespace = 'wc-hippoo/v1/ext';

    foreach ( $endpoints as $route => $handlers ) {
        if ( 0 === strpos( $route, 'wc-hippoo/v1' ) ) {
            continue;
        }

        foreach ( $handlers as $handler ) {
            $methods = is_array( $handler['methods'] )
                ? implode( ',', array_keys( $handler['methods'] ) )
                : $handler['methods'];

            $permission = apply_filters(
                'hippoo_extension_permission_check',
                'hippoo_rest_is_admin',
                $route,
                $handler
            );

            register_rest_route(
                $new_namespace,
                $route,
                array(
                    'methods'             => $methods,
                    'callback'            => $handler['callback'],
                    'args'                => $handler['args'] ?? [],
                    'permission_callback' => $permission,
                )
            );
        }
    }
}


// ---------------------------------------------------------------------------
// Permission helpers
// ---------------------------------------------------------------------------

/** Simple admin check. */
function hippoo_rest_is_admin() {
    return current_user_can( 'manage_options' );
}

/** Product images access. */
function hippoo_rest_permission_product_images() {
    return current_user_can( 'edit_others_posts' );
}

/** Read / list access. */
function hippoo_rest_permission_read( $request = null ) {
    if ( current_user_can( 'manage_options' ) || current_user_can( 'list_users' ) ) {
        return true;
    }
    return new WP_Error( 'hippoo_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'hippoo' ), array( 'status' => rest_authorization_required_code() ) );
}

/** Create access. */
function hippoo_rest_permission_create( $request = null ) {
    if ( current_user_can( 'manage_options' ) || current_user_can( 'create_customers' ) || current_user_can( 'create_users' ) || current_user_can( 'upload_files' ) ) {
        return true;
    }
    return new WP_Error( 'hippoo_rest_cannot_create', __( 'Sorry, you are not allowed to create resources.', 'hippoo' ), array( 'status' => rest_authorization_required_code() ) );
}

/** Delete access. */
function hippoo_rest_permission_delete( $request = null ) {
    if ( current_user_can( 'manage_options' ) || current_user_can( 'delete_users' ) ) {
        return true;
    }
    return new WP_Error( 'hippoo_rest_cannot_delete', __( 'Sorry, you are not allowed to delete resources.', 'hippoo' ), array( 'status' => rest_authorization_required_code() ) );
}

/** Full management access. */
function hippoo_rest_permission_manage( $request = null ) {
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }
    return new WP_Error( 'hippoo_rest_cannot_manage',  __( 'Sorry, you are not allowed to do that.', 'hippoo' ), array( 'status' => rest_authorization_required_code() ) );
}


// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

/** Register REST API routes. */
add_action( 'rest_api_init', 'hippoo_rest_register_routes' );

function hippoo_rest_register_routes() {

    // --- Auth & config (hippoo/v1) ---

    register_rest_route( 'hippoo/v1', 'wc/token/get', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_token_get',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'hippoo/v1', 'wc/token/save_callback/(?P<token_id>\w+)', array(
        'methods'             => 'POST',
        'callback'            => 'hippoo_rest_token_save_callback',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'hippoo/v1', 'wc/token/show/(?P<token_id>\w+)', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_token_show',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'hippoo/v1', 'wc/token/return/(?P<token_id>\w+)', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_token_return',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'hippoo/v1', 'config', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_config',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'hippoo/v1', 'shop-config', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_shop_config',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'hippoo/v1', 'locations/countries', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_get_countries',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'hippoo/v1', 'locations/countries/(?P<country_code>[A-Z]{2})', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_get_states',
        'permission_callback' => '__return_true',
    ) );
    // Legacy – remove when the app no longer uses woohouse/v1.
    register_rest_route( 'woohouse/v1', 'config', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_config',
        'permission_callback' => '__return_true',
    ) );

    // --- Core API (wc-hippoo/v1) ---

    register_rest_route( 'wc-hippoo/v1', '/wc/stock(?:/(?P<id>\d+))?', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_stock_list',
        'permission_callback' => 'hippoo_rest_permission_read',
        'args'                => array(
            'page' => array( 'required' => false ),
        ),
    ) );
    register_rest_route( 'wc-hippoo/v1', '/wp/media/item', array(
        'methods'             => 'POST',
        'callback'            => 'hippoo_rest_media_upload',
        'permission_callback' => 'hippoo_rest_permission_create',
    ) );
    register_rest_route( 'wc-hippoo/v1', '/wp/media/item', array(
        'methods'             => 'DELETE',
        'callback'            => 'hippoo_rest_media_delete',
        'permission_callback' => 'hippoo_rest_permission_delete',
        'args'                => array(
            'ids' => array(
                'required'          => true,
                'sanitize_callback' => 'rest_sanitize_request_arg',
                'validate_callback' => 'rest_validate_request_arg',
                'type'              => 'array',
                'description'       => __( 'Array of media item IDs to delete.', 'hippoo' ),
            ),
        ),
    ) );
    register_rest_route( 'wc-hippoo/v1', '/wp/system/info', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_system_info',
        'permission_callback' => 'hippoo_rest_permission_read',
    ) );
    register_rest_route( 'wc-hippoo/v1', '/setting', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'hippoo_rest_get_setting',
            'permission_callback' => 'hippoo_rest_permission_read',
        ),
        array(
            'methods'             => 'PUT',
            'callback'            => 'hippoo_rest_update_setting',
            'permission_callback' => 'hippoo_rest_permission_manage',
        ),
    ) );
    register_rest_route( 'wc-hippoo/v1', '/ability/list', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_ability_list',
        'permission_callback' => 'hippoo_rest_permission_read',
    ) );
    register_rest_route( 'wc-hippoo/v1', '/ability/execute', array(
        'methods'             => 'POST',
        'callback'            => 'hippoo_rest_ability_execute',
        'permission_callback' => 'hippoo_rest_permission_manage',
    ) );

    // --- Product images (wc/v3) ---

    register_rest_route( 'wc/v3', 'productsimg/(?P<id>\d+)/imgs', array(
        'methods'             => 'POST',
        'callback'            => 'hippoo_rest_product_images',
        'permission_callback' => 'hippoo_rest_permission_product_images',
    ) );
    register_rest_route( 'wc/v3', 'productsimg/(?P<id>\d+)/dlmg/(?P<cnt>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_product_images_delete',
        'permission_callback' => 'hippoo_rest_permission_product_images',
    ) );
    register_rest_route( 'wc/v3', 'productsimg/image-sizes', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_product_image_sizes',
        'permission_callback' => '__return_true',
    ) );

    // --- Store API extras (wc/store/v1) ---

    register_rest_route( 'wc/store/v1', 'settings', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_store_settings',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'wc/store/v1', 'cart/count', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_rest_cart_count',
        'permission_callback' => '__return_true',
    ) );
}


// ---------------------------------------------------------------------------
// Route callbacks
// ---------------------------------------------------------------------------

/** Start WC Auth: redirect the browser to /wc-auth/v1/authorize. */
function hippoo_rest_token_get() {
    $key          = md5( microtime() . wp_rand() );
    $store_url    = str_replace( 'http://', 'https://', get_option( 'siteurl' ) );
    $return_url   = $store_url . '/wp-json/hippoo/v1/wc/token/return/' . $key;
    $callback_url = $store_url . '/wp-json/hippoo/v1/wc/token/save_callback/' . $key;

    $params = array(
        'app_name'     => __( 'Hippoo', 'hippoo' ),
        'scope'        => 'read_write',
        'user_id'      => $key,
        'return_url'   => $return_url,
        'callback_url' => $callback_url,
    );

    $url = $store_url . '/wc-auth/v1/authorize?' . http_build_query( $params );

    if ( headers_sent() ) {
        // Fallback: Output JavaScript redirect
        echo '<script>window.location.href="' . esc_url( $url ) . '";</script>';
        exit;
    }

    wp_redirect( $url );
    exit;
}

/** WC Auth callback: store consumer keys in a short-lived transient. */
function hippoo_rest_token_save_callback( $request ) {
    $token_id = isset( $request['token_id'] ) ? $request['token_id'] : '';
    if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $token_id ) ) {
        return new WP_REST_Response( array( 'Message' => __( 'Invalid token.', 'hippoo' ) ), 400 );
    }

    $decoded = json_decode( $request->get_body(), true );
    if ( ! is_array( $decoded ) ) {
        return new WP_REST_Response( array( 'Message' => __( 'Invalid JSON payload.', 'hippoo' ) ), 400 );
    }

    $allowed = array( 'key_id', 'user_id', 'consumer_key', 'consumer_secret', 'key_permissions' );
    $clean   = array_intersect_key( $decoded, array_flip( $allowed ) );
    if ( empty( $clean ) ) {
        return new WP_REST_Response( array( 'Message' => __( 'No valid token data.', 'hippoo' ) ), 400 );
    }

    set_transient( 'hippoo_token_' . $token_id, $clean, 20000 );
    return new WP_REST_Response( array( 'Message' => 'Token Saved' ), 200 );
}

/** App polls this endpoint to retrieve keys after the auth redirect. */
function hippoo_rest_token_show( $request ) {
    // Clean old legacy token files.
    $tokens = glob( hippoo_get_temp_dir() . 'hippoo_*.json' );
    if ( is_array( $tokens ) ) {
        foreach ( $tokens as $t ) {
            if ( time() - filemtime( $t ) > 20000 ) {
                wp_delete_file( $t );
            }
        }
    }

    $token_id = isset( $request['token_id'] ) ? $request['token_id'] : '';
    if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $token_id ) ) {
        return new WP_REST_Response( array( 'Message' => __( 'Invalid token.', 'hippoo' ) ), 400 );
    }

    $cache_key = 'hippoo_token_' . $token_id;
    $token     = get_transient( $cache_key );
    if ( false !== $token ) {
        delete_transient( $cache_key );
        return new WP_REST_Response( $token, 200 );
    }

    // Legacy fallback: file-based token
    $file = hippoo_get_temp_dir() . 'hippoo_' . $token_id . '.json';
    if ( file_exists( $file ) ) {
        $token = file_get_contents( $file );
        wp_delete_file( $file );
        return new WP_REST_Response( json_decode( $token ), 200 );
    }

    return new WP_REST_Response( array( 'Message' => __( 'Unauthenticated. No token found. Please reauthenticate.', 'hippoo' ) ), 401 );
}

/** HTML page that deep-links back into the mobile app with the token id. */
function hippoo_rest_token_return( $request ) {
    $title = __( 'Auto Redirect', 'hippoo' );
    $desc  = __( 'This page will automatically redirect in a few seconds...', 'hippoo' );
    $html  = '<!DOCTYPE html>
        <html>
        <head>
        <title>{{TITLE}}</title>
        <script type="text/javascript">
            window.onload = function() {
            window.location.href = {{LINK}};
            };
        </script>
        </head>
        <body>
        <h1>{{TITLE}}</h1>
        <h3>{{MSG}}</h3>
        <p>{{DESC}}</p>
        </body>
        </html>';

    $html = str_replace(
        array( '{{TITLE}}', '{{DESC}}' ),
        array( esc_html( $title ), esc_html( $desc ) ),
        $html
    );

    $url_params = $request->get_url_params();
    $token_id   = isset( $url_params['token_id'] ) ? $url_params['token_id'] : '';

    if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $token_id ) ) {
        return new WP_REST_Response( array( 'Message' => __( 'Invalid token.', 'hippoo' ) ), 400 );
    }
    
    if ( ! empty( $token_id ) ) {
        $msg  = __( 'You can get the data from here', 'hippoo' );
        $link = 'hippoo://app/login/?token=' . $token_id;
        $html = str_replace(
            array( '{{LINK}}', '{{MSG}}' ),
            array( wp_json_encode( $link ), esc_html( $msg ) ),
            $html
        );
    } else {
        $html = str_replace( '{{MSG}}', __( 'Unauthenticated, No Token Data', 'hippoo' ), $html );
    }

    header( 'Content-Type: text/html;charset=utf-8;' );
    echo wp_kses(
        $html,
        array(
            'script' => array(
                'type' => true,
                'src'  => true,
            ),
        )
    );
    exit;
}

/** Public plugin/shop fingerprint for the mobile app. */
function hippoo_rest_config() {
    $plugin_data = function_exists( 'get_plugin_data' )
        ? get_plugin_data( HIPPOO_MAIN_FILE_PATH )
        : array( 'Version' => HIPPOO_VERSION );
    
    $response = array(
        'hippoo'                => 'true',
        'hippoo_plugin_version' => $plugin_data['Version'],
        'lang'                  => get_bloginfo( 'language' ),
        'direction'             => is_rtl() ? 'rtl' : 'ltr',
        'currency'              => get_option( 'woocommerce_currency' ),
        'weight_unit'           => get_option( 'woocommerce_weight_unit' ),
        'dimension_unit'        => get_option( 'woocommerce_dimension_unit' ),
    );

    return new WP_REST_Response( $response, 200 );
}

/** Storefront-oriented config (nonce, logo, PWA CSS URL, …). */
function hippoo_rest_shop_config() {
    $settings         = get_option( 'hippoo_settings', array() );
    $invoice_settings = get_option( 'hippoo_invoice_settings', array() );

    $pwa_enabled = ! empty( $settings['pwa_plugin_enabled']);
    $route_name  = isset( $settings['pwa_route_name'] ) ? $settings['pwa_route_name'] : 'hippooshop';
    $custom_css  = isset( $settings['pwa_custom_css'] ) ? $settings['pwa_custom_css'] : null;
    $shop_logo   = isset( $invoice_settings['shop_logo'] ) ? $invoice_settings['shop_logo'] : '';

    $response = array(
        'nonce'          => wp_create_nonce( 'wc_store_api' ),
        'currency'       => get_option( 'woocommerce_currency' ),
        'shop_name'      => get_bloginfo( 'name' ),
        'shop_logo'      => $shop_logo,
        'direction'      => is_rtl() ? 'rtl' : 'ltr',
        'country'        => WC()->countries->get_base_country(),
        'language'       => strtoupper( substr( get_bloginfo( 'language' ), 0, 2 ) ),
        'custom_css_url' => ( $pwa_enabled && ! empty( $custom_css ) ) ? home_url( $route_name . '/custom.css/' ) : null,
    );

    return new WP_REST_Response( $response, 200 );
}

/** List sellable countries according to WooCommerce “allowed countries” settings. */
function hippoo_rest_get_countries() {
    $wc_countries = WC()->countries->get_countries();
    $allowed_type = get_option( 'woocommerce_allowed_countries' );
    $response     = array();

    if ( 'specific' === $allowed_type ) {
        // Only specific countries allowed
        $allowed_list = get_option( 'woocommerce_specific_allowed_countries', array() );
        foreach ( $wc_countries as $code => $name ) {
            if ( in_array( $code, $allowed_list, true ) ) {
                $response[] = array( 'code' => $code, 'name' => $name );
            }
        }
    } elseif ( 'all_except' === $allowed_type ) {
        // All countries except these
        $excluded = get_option( 'woocommerce_all_except_countries', array() );
        foreach ( $wc_countries as $code => $name ) {
            if ( ! in_array( $code, $excluded, true ) ) {
                $response[] = array( 'code' => $code, 'name' => $name );
            }
        }
    } else {
        // Sell to all countries
        foreach ( $wc_countries as $code => $name ) {
            $response[] = array( 'code' => $code, 'name' => $name );
        }
    }

    return new WP_REST_Response( $response, 200 );
}

/** States/provinces for a given two-letter country code. */
function hippoo_rest_get_states( $request ) {
    $country_code = $request['country_code'];
    if ( ! preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
        return new WP_Error( 'invalid_country_code', __( 'Country code must be a valid two-letter uppercase code.', 'hippoo' ), array( 'status' => 400 ) );
    }

    $countries = WC()->countries->get_countries();
    if ( ! array_key_exists( $country_code, $countries ) ) {
        return new WP_Error( 'invalid_country', __( 'Invalid or unauthorized country code.', 'hippoo' ), array( 'status' => 404 ) );
    }

    $states   = WC()->countries->get_states( $country_code );
    $response = array();
    if ( is_array( $states ) && ! empty( $states ) ) {
        foreach ( $states as $code => $name ) {
            $response[] = array( 'code' => $code, 'name' => $name );
        }
    }

    return new WP_REST_Response( $response, 200 );
}

/** Paginated list of out-of-stock products (uses out_stock_time meta). */
function hippoo_rest_stock_list( $request ) {
    global $wpdb;

    if ( isset( $request['page'] ) ) {
        $page = (int) $request['page'];
    } elseif ( isset( $request['id'] ) ) {
        $page = (int) $request['id'];
    } else {
        $page = 1;
    }
    $offset = max( 0, ( $page - 1 ) * 25 );

    // phpcs:ignore
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.ID as post_id, p.post_title, pm.meta_value as product_quantity, o.meta_value as out_of_stock_date
            FROM {$wpdb->posts} AS p
            JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
            JOIN {$wpdb->postmeta} AS o ON p.ID = o.post_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND o.meta_key = 'out_stock_time'
            AND pm.meta_key = '_stock'
            AND pm.meta_value <= 0
            ORDER BY out_of_stock_date DESC
            LIMIT %d, 25",
            $offset
        )
    );

    if ( empty( $rows ) ) {
        return new WP_REST_Response( array(), 200 );
    }

    $response = array();
    foreach ( $rows as $row ) {
        $img        = empty( $row->post_parent ) ? $row->post_id : $row->post_parent;
        $response[] = array(
            'id'                => $row->post_id,
            'img'               => get_the_post_thumbnail_url( $img, 'thumbnail' ),
            'out_of_stock_date' => $row->out_of_stock_date,
            'title'             => $row->post_title,
            'product_quantity'  => $row->product_quantity,
        );
    }

    return new WP_REST_Response( $response, 200 );
}

/** Upload a media file from multipart form data. */
function hippoo_rest_media_upload() {
    if ( empty( $_FILES['file'] ) || UPLOAD_ERR_OK !== $_FILES['file']['error'] ) {
        return new WP_Error( 'invalid_file', __( 'Invalid or missing file.', 'hippoo' ), array( 'status' => 400 ) );
    }

    $file          = $_FILES['file'];
    $file_name     = sanitize_file_name( $file['name'] );
    $file_tmp_path = $file['tmp_name'];
    $file_mime     = mime_content_type( $file_tmp_path );
    $file_content  = file_get_contents( $file_tmp_path );

    if ( false === $file_content ) {
        return new WP_Error( 'file_read_error', __( 'Failed to read file content.', 'hippoo' ), array( 'status' => 500 ) );
    }

    // Upload the file
    $upload = wp_upload_bits( $file_name, null, $file_content );
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'upload_failed', __( 'Media upload failed.', 'hippoo' ), array( 'status' => 500 ) );
    }

    // Prepare attachment data
    $attachment = array(
        'post_mime_type' => $file_mime_type,
        'post_title'     => pathinfo( $file_name, PATHINFO_FILENAME ),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );

    // Insert attachment into WordPress
    $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

    if ( is_wp_error( $attachment_id ) ) {
        return new WP_Error( 'attachment_failed', __( 'Failed to create attachment.', 'hippoo' ), array( 'status' => 500 ) );
    }

    // Generate metadata and update attachment
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
    wp_update_attachment_metadata( $attachment_id, $attachment_data );

    // Get the media URL
    $media_url = wp_get_attachment_url( $attachment_id );

    $response = array(
        'status'          => 'success',
        'media_url'       => $media_url,
        'attachment_id'   => $attachment_id,
        'attachment_data' => $attachment_data,
    );

    return new WP_REST_Response( $response, 200 );
}

/** Delete one or more media attachments by ID. */
function hippoo_rest_media_delete( $request ) {
    $ids     = $request->get_param( 'ids' );
    $deleted = array();

    if ( empty( $ids ) || ! is_array( $ids ) ) {
        return new WP_Error( 'invalid_file', __( 'Nothing to delete.', 'hippoo' ), array( 'status' => 400 ) );
    }

    foreach ( $ids as $attachment_id ) {
        if ( ! get_attached_file( $attachment_id ) ) {
            return new WP_Error( 'invalid_attachment', __( 'Attachment not found.', 'hippoo' ), array( 'status' => 404 ) );
        }
        if ( false === wp_delete_attachment( $attachment_id, true ) ) {
            return new WP_Error( 'delete_error', __( 'Error deleting the attachment.', 'hippoo' ), array( 'status' => 500 ) );
        }
        $deleted[] = $attachment_id;
    }

    $response = array(
        'status'                 => 'success',
        'message'                => __( 'Attachment(s) deleted successfully.', 'hippoo' ),
        'attachment_ids_deleted' => $deleted,
    );

    return new WP_REST_Response( $response, 200 );
}

/** List Hippoo-family plugins installed on the site. */
function hippoo_rest_system_info( $request ) {
    $response = wp_remote_get(
        'https://hippoo.app/wp-json/hippoo/v1/get_plugins',
        array(
            'timeout' => 30,
        )
    );
    $available = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $available ) ) {
        $available = array();
    }

    $plugins      = get_plugins();
    $plugins_info = array();

    foreach ( $plugins as $plugin_file => $plugin ) {
        $central = hippoo_get_product_by_slug( $available, $plugin['TextDomain'] );
        if ( is_null( $central ) ) {
            continue;
        }
        $min = $central['attributes']['pa_minimum-support'] ?? '0';
        $lat = $central['attributes']['pa_latest-version'] ?? '999';
        $cur = $plugin['Version'];
        if ( version_compare( $cur, $min, '>=' ) && version_compare( $cur, $lat, '<=' ) ) {
            $central['installation_status']                     = is_plugin_active( $plugin_file ) ? 'active' : 'installed';
            $central['attributes']['current_installed_version'] = $cur;
            $plugins_info[]                                     = $central;
        }
    }

    $plugins_info = apply_filters( 'hippoo_system_info_extensions', $plugins_info, $request );
    return new WP_REST_Response( $plugins_info, 200 );
}

/** Return hippoo_settings merged with defaults (booleans normalized). */
function hippoo_rest_get_setting() {
    $settings = get_option( 'hippoo_settings', array() );
    $defaults = array(
        'invoice_plugin_enabled'          => false,
        'send_notification_wc-processing' => true,
    );

    if ( function_exists( 'wc_get_order_statuses' ) ) {
        $order_statuses = wc_get_order_statuses();
        foreach ( $order_statuses as $status_key => $status_label ) {
            $key = 'send_notification_' . $status_key;
            if ( ! array_key_exists( $key, $defaults ) ) {
                $defaults[ $key ] = false;
            }
        }
    }

    $settings = array_merge( $defaults, $settings );
    $settings = array_map(
        function ( $value ) {
            return ( '1' === $value ) ? true : ( ( '0' === $value ) ? false : $value );
        },
        $settings
    );

    update_option( 'hippoo_settings', $settings );
    return rest_ensure_response( $settings );
}

/** Merge JSON body into hippoo_settings and return the updated option. */
function hippoo_rest_update_setting( $request ) {
    $settings     = get_option( 'hippoo_settings', array() );
    $new_settings = json_decode( $request->get_body(), true );
    if ( ! is_array( $new_settings ) ) {
        $new_settings = array();
    }

    $settings = array_merge( $settings, $new_settings );

    // Convert string 'true' or 'false' to boolean true or false
    $settings = array_map(
        function ( $value ) {
            return ( 'true' === $value ) ? true : ( ( 'false' === $value ) ? false : $value );
        },
        $settings
    );

    update_option( 'hippoo_settings', $settings );
    return rest_ensure_response( $settings );
}

/** Ability catalog for the mobile app. */
function hippoo_rest_ability_list() {
    return new WP_REST_Response( hippoo_ability_catalog_for_list(), 200 );
}

/** Execute one ability by name. */
function hippoo_rest_ability_execute( $request ) {
    $body = json_decode( $request->get_body(), true );
    if ( ! is_array( $body ) || empty( $body['name'] ) || ! is_string( $body['name'] ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'name is required' ), 400 );
    }

    $name      = $body['name'];
    $input     = ( isset( $body['input'] ) && is_array( $body['input'] ) ) ? $body['input'] : array();
    $confirmed = ( isset( $body['confirmed'] ) && true === $body['confirmed'] );
    $result    = hippoo_ability_execute( $name, $input, $confirmed );

    return new WP_REST_Response( $result['body'], $result['status'] );
}

/** Set product featured + gallery images from base64 payloads. */
function hippoo_rest_product_images( $request ) {
    $arr = $request->get_json_params();

    if ( empty( $arr['imgs'] ) ) {
        return new WP_Error( 'invalid_file', __( 'There is not any images!', 'hippoo' ), array( 'status' => 400 ) );
    }

    $requested_size = isset( $arr['image_size'] ) ? sanitize_text_field( $arr['image_size'] ) : null;
    $gallery        = array();

    foreach ( $arr['imgs'] as $i => $img ) {
        $img_id = hippoo_rest_attachment_from_base64( $img, $requested_size );
        if ( 0 === $i ) {
            set_post_thumbnail( $request['id'], $img_id );
        } else {
            $gallery[] = $img_id;
        }
    }

    if ( ! empty( $gallery ) ) {
        update_post_meta( $request['id'], '_product_image_gallery', implode( ',', $gallery ) );
    }

    return new WP_REST_Response( __( 'Image saved successfully.', 'hippoo' ), 200 );
}

/** Delete product featured image (cnt=0) or a gallery image by 1-based index. */
function hippoo_rest_product_images_delete( $request ) {
    if ( 0 == $request['cnt'] ) {
        delete_post_thumbnail( $request['id'] );
        return new WP_REST_Response( __( 'Image deleted successfully.', 'hippoo' ), 200 );
    }

    $imgs = explode( ',', get_post_meta( $request['id'], '_product_image_gallery', true ) );
    $cnt  = (int) $request['cnt'] - 1;

    if ( isset( $imgs[ $cnt ] ) ) {
        unset( $imgs[ $cnt ] );
        update_post_meta( $request['id'], '_product_image_gallery', implode( ',', $imgs ) );
        return new WP_REST_Response( __( 'Image deleted successfully.', 'hippoo' ), 200 );
    }

    return new WP_REST_Response( __( 'Image not found!', 'hippoo' ), 404 );
}

/** List available image sizes for the app. */
function hippoo_rest_product_image_sizes() {
    $all_sizes = hippoo_get_available_image_sizes();
    $sizes     = array();

    foreach ( $all_sizes as $size => $dimensions ) {
        $sizes[] = array(
            'name'   => $size,
            'width'  => $dimensions['width'],
            'height' => $dimensions['height'],
        );
    }

    return new WP_REST_Response( $sizes, 200 );
}

/** Create an attachment from a base64 image payload, optionally resized. */
function hippoo_rest_attachment_from_base64( $data, $size_override = null ) {
    $settings = get_option( 'hippoo_settings', array() );

    $optimization = array(
        'enabled'      => isset( $settings['image_optimization_enabled'] ) ? (bool) $settings['image_optimization_enabled'] : true,
        'default_size' => isset( $settings['image_size_selection'] ) ? $settings['image_size_selection'] : 'large',
    );

    $target_size = $size_override ?: $optimization['default_size'];

    if ( $optimization['enabled'] ) {
        $data = hippoo_rest_optimize_image_payload( $data, $target_size );
    }

    $fin       = wp_upload_bits( $data['name'], null, base64_decode( $data['content'] ) );
    $file_name = basename( $fin['file'] );
    $file_type = wp_check_filetype( $file_name, null );

    // Prepare attachment data
    $post_info = array(
        'guid'           => $fin['url'],
        'post_mime_type' => $file_type['type'],
        'post_title'     => sanitize_file_name( $file_name ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    );

    // Insert attachment into WordPress
    $attach_id = wp_insert_attachment( $post_info, $fin['file'] );

    // Generate metadata and update attachment
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata( $attach_id, $fin['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    return $attach_id;
}

/** Optionally resize a base64 image payload before upload. */
function hippoo_rest_optimize_image_payload( $file_data, $target_size ) {
    if ( ! function_exists( 'wp_tempnam' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $file_extension = pathinfo( $file_data['name'], PATHINFO_EXTENSION );
    $temp_file_path = wp_tempnam() . '.' . $file_extension;
    file_put_contents( $temp_file_path, base64_decode( $file_data['content'] ) );

    $file_type = wp_check_filetype( $temp_file_path );
    if ( ! in_array( $file_type['type'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
        wp_delete_file( $temp_file_path );
        return $file_data;
    }

    $optimized = hippoo_rest_resize_image_file( $temp_file_path, $target_size );
    if ( $optimized !== $temp_file_path ) {
        $file_data['content'] = base64_encode( file_get_contents( $optimized ) );
        wp_delete_file( $temp_file_path );
        wp_delete_file( $optimized );
    } else {
        wp_delete_file( $temp_file_path );
    }

    return $file_data;
}

/** Resize an image file to a registered size. Returns original path if no resize needed. */
function hippoo_rest_resize_image_file( $file_path, $target_size = 'large' ) {
    if ( 'original' === $target_size ) {
        return $file_path;
    }

    $editor = wp_get_image_editor( $file_path );
    if ( is_wp_error( $editor ) ) {
        return $file_path;
    }

    $sizes = hippoo_get_available_image_sizes();
    if ( ! isset( $sizes[ $target_size ] ) ) {
        return $file_path;
    }

    $max_width  = $sizes[ $target_size ]['width'];
    $max_height = $sizes[ $target_size ]['height'];
    $current    = $editor->get_size();

    if ( $current['width'] <= $max_width && $current['height'] <= $max_height ) {
        return $file_path;
    }

    $result = $editor->resize( $max_width, $max_height, false );
    if ( is_wp_error( $result ) ) {
        return $file_path;
    }

    $saved = $editor->save();
    if ( is_wp_error( $saved ) ) {
        return $file_path;
    }

    return $saved['path'];
}

/** Minimal store settings for the PWA / app. */
function hippoo_rest_store_settings() {
    $response = array(
        'shop_title' => get_bloginfo( 'name' ),
        'cart_url'   => wc_get_cart_url(),
        'base_url'   => get_site_url(),
    );

    return new WP_REST_Response( $response, 200 );
}

/** Current cart item count. */
function hippoo_rest_cart_count() {
    if ( function_exists( 'wc_load_cart' ) ) {
        wc_load_cart();
    }
    $cart  = WC()->cart;
    $count = $cart ? $cart->get_cart_contents_count() : 0;
    $response = array( 'count' => $count );
    return new WP_REST_Response( $response, 200 );
}


// ---------------------------------------------------------------------------
// WC response enrichments
// ---------------------------------------------------------------------------

/** Persist current user as order-note author when creating a note. */
add_filter( 'woocommerce_new_order_note_data', 'hippoo_rest_save_order_note_author' );

function hippoo_rest_save_order_note_author( $data ) {
    if ( is_user_logged_in() ) {
        $user                         = get_user_by( 'id', get_current_user_id() );
        $data['user_id']              = $user->ID;
        $data['comment_author']       = $user->display_name;
        $data['comment_author_email'] = $user->user_email;
    }
    return $data;
}

/** Expose author_id / author_email on order-note REST responses. */
add_filter( 'woocommerce_rest_prepare_order_note', 'hippoo_rest_prepare_order_note_author', 10, 3 );

function hippoo_rest_prepare_order_note_author( $response, $note, $request ) {
    $data = $response->get_data();

    if ( $note->user_id > 0 ) {
        $user = get_user_by( 'id', $note->user_id );
    } else {
        $user = get_user_by( 'email', $note->comment_author_email );
    }

    if ( $user ) {
        $data['author_id']    = $user->ID;
        $data['author_email'] = $user->user_email;
    } else {
        $data['author_id']    = 0;
        $data['author_email'] = '';
    }

    $response->set_data( $data );
    return $response;
}

/** Add total_weight, weight_unit, total_items and line-item images to order responses. */
add_filter( 'woocommerce_rest_prepare_shop_order_object', 'hippoo_rest_enrich_order', 10, 3 );

function hippoo_rest_enrich_order( $response, $order, $request ) {
    if ( empty( $response->data ) ) {
        return $response;
    }

    // Calculate total weight and total items
    $total_weight = 0;
    $total_items  = 0;

    foreach ( $order->get_items() as $item ) {
        $quantity     = $item->get_quantity();
        $total_items += $quantity;
        $product      = $item->get_product();
        if ( $product && $product->get_weight() ) {
            $total_weight += $product->get_weight() * $quantity;
        }
    }

    // Add total weight, weight unit, and total items to response data
    $response->data['total_weight'] = round( $total_weight, 2 );
    $response->data['weight_unit']  = get_option( 'woocommerce_weight_unit' );
    $response->data['total_items']  = $total_items;

    // Set correct image (variation if exists) for each line item
    if ( ! empty( $response->data['line_items'] ) ) {
        foreach ( $response->data['line_items'] as $item_id => $item_values ) {
            $product_id   = $item_values['product_id'];
            $variation_id = isset( $item_values['variation_id'] ) ? $item_values['variation_id'] : 0;
            $image_url    = '';

            if ( $variation_id && $variation_id != $product_id ) {
                // Try to get variation image
                $variation = wc_get_product( $variation_id );
                if ( $variation && $variation->get_image_id() ) {
                    $image_url = wp_get_attachment_image_url( $variation->get_image_id(), 'thumbnail' );
                }
            }

            // If no variation image, fallback to product image
            if ( empty( $image_url ) ) {
                $img_id = get_post_thumbnail_id( $product_id );
                if ( $img_id ) {
                    $image_url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
                }
            }

            $response->data['line_items'][ $item_id ]['image'] = $image_url;
        }
    }

    return $response;
}

/** Append payment_methods_detailed to /wc/store/v1/cart responses. */
add_filter( 'rest_request_after_callbacks', 'hippoo_rest_enrich_cart_payment_methods', 10, 3 );

function hippoo_rest_enrich_cart_payment_methods( $response, $handler, $request ) {
    if ( '/wc/store/v1/cart' !== $request->get_route() ) {
        return $response;
    }

    if ( ! class_exists( 'WooCommerce' ) || ! WC()->payment_gateways() ) {
        return $response;
    }

    if ( is_wp_error( $response ) || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
        return $response;
    }

    $data            = $response->get_data();
    $payment_methods = isset( $data['payment_methods'] ) ? $data['payment_methods'] : array();
    $gateways        = WC()->payment_gateways()->get_available_payment_gateways();
    $detailed        = array();

    foreach ( $gateways as $gateway ) {
        if ( ! in_array( $gateway->id, $payment_methods, true ) ) {
            continue;
        }

        $icon = null;
        if ( ! empty( $gateway->icon ) ) {
            $icon_url = $gateway->icon;
            if ( 0 !== strpos( $icon_url, 'http' ) ) {
                $icon_url = site_url( $icon_url );
            }
            $icon = $icon_url;
        }

        $detailed[] = array(
            'id'          => $gateway->id,
            'title'       => $gateway->get_title() ?: $gateway->id,
            'description' => $gateway->get_description() ?: '',
            'icon'        => $icon,
        );
    }

    $data['payment_methods_detailed'] = $detailed;
    $response->set_data( $data );

    return $response;
}
