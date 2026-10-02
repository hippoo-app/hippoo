<?php
/**
 * Hippoo AI – REST API routes and callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// WooCommerce authentication
// ---------------------------------------------------------------------------

/** Allow the BI REST namespace to use WooCommerce authentication. */
add_filter( 'hippoo_rest_wc_auth_namespaces', 'hippoo_bi_rest_use_wc_authentication' );

function hippoo_bi_rest_use_wc_authentication( $namespaces ) {
    $namespaces[] = 'hippoo/v1/bi';
    return $namespaces;
}


// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

/** Register Hippoo BI REST API routes. */
add_action( 'rest_api_init', 'hippoo_bi_register_rest_routes' );

function hippoo_bi_register_rest_routes() {
    register_rest_route( 'hippoo/v1', '/bi/track', array(
        'methods'             => 'POST',
        'callback'            => 'hippoo_bi_rest_track_pageview',
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/overview', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_general_overview',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/traffic/overview', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_traffic_overview',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/products/intelligence', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_products_intelligence',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/products/(?P<id>\d+)/intelligence', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_product_intelligence',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/sales/overview', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_sales_overview',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/churn/overview', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_churn_overview',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/churn/customers', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_churn_customers',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/churn/export', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_churn_export',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );

    register_rest_route( 'hippoo/v1', '/bi/churn/export-status', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_bi_rest_churn_export_status',
        'permission_callback' => 'hippoo_bi_rest_permission_check',
    ) );
}


// ---------------------------------------------------------------------------
// Route callbacks
// ---------------------------------------------------------------------------

/** Store a pageview received through REST. */
function hippoo_bi_rest_track_pageview( $request ) {
    if ( ! hippoo_bi_rest_check_rate_limit() ) {
        return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'hippoo' ), array( 'status' => 429 ) );
    }

    $body = $request->get_body();
    $params = json_decode( $body, true );

    if ( JSON_ERROR_NONE !== json_last_error() || empty( $params ) ) {
        $params = $request->get_json_params();
    }

    if ( ! is_array( $params ) ) {
        $params = array();
    }

    if ( empty( $params['pid'] ) ) {
        return new WP_Error( 'missing_pid', __( 'Missing product_id parameter.', 'hippoo' ), array( 'status' => 400 ) );
    }

    $result = hippoo_bi_track_pageview( array(
        'session_id' => $params['sid'] ?? '',
        'page_url'   => $params['url'] ?? '',
        'referrer'   => $params['ref'] ?? '',
        'product_id' => $params['pid'],
    ) );

    if ( ! $result ) {
        return new WP_Error( 'tracking_failed', __( 'Unable to store tracking data.', 'hippoo' ), array( 'status' => 500 ) );
    }

    return rest_ensure_response( array( 'success' => true ) );
}

/** Return the general BI overview through REST. */
function hippoo_bi_rest_general_overview( $request ) {
    $response = hippoo_bi_get_general_overview( $request->get_params() );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return rest_ensure_response( $response );
}

/** Return the traffic overview through REST. */
function hippoo_bi_rest_traffic_overview( $request ) {
    $response = hippoo_bi_get_traffic_overview( $request->get_params() );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return rest_ensure_response( $response );
}

/** Return product intelligence through REST. */
function hippoo_bi_rest_products_intelligence( $request ) {
    $response = hippoo_bi_get_products_overview( $request->get_params() );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $response = rest_ensure_response( $response );

    if ( isset( $response->data['total'] ) ) {
        $response->header( 'X-WP-Total', $response->data['total'] );
    }

    if ( isset( $response->data['total_pages'] ) ) {
        $response->header( 'X-WP-TotalPages', $response->data['total_pages'] );
    }

    return $response;
}

/** Return single product intelligence through REST. */
function hippoo_bi_rest_product_intelligence( $request ) {
    $response = hippoo_bi_get_product_overview( $request->get_params() );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return rest_ensure_response( $response );
}

/** Return the sales overview through REST. */
function hippoo_bi_rest_sales_overview( $request ) {
    $response = hippoo_bi_get_sales_overview( $request->get_params() );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return rest_ensure_response( $response );
}

/** Return the churn overview through REST. */
function hippoo_bi_rest_churn_overview( $request ) {
    $response = hippoo_bi_get_churn_overview( $request->get_params() );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return rest_ensure_response( $response );
}

/** Return churn customers through REST. */
function hippoo_bi_rest_churn_customers( $request ) {
    $response = hippoo_bi_get_churn_customers( $request->get_params() );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $response = rest_ensure_response( $response );

    if ( isset( $response->data['total'] ) ) {
        $response->header( 'X-WP-Total', $response->data['total'] );
    }

    if ( isset( $response->data['total_pages'] ) ) {
        $response->header( 'X-WP-TotalPages', $response->data['total_pages'] );
    }

    return $response;
}

/** Export churned customers through REST. */
function hippoo_bi_rest_churn_export( $request ) {
    $response = hippoo_bi_export_churn_customers();

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return hippoo_bi_rest_output_churn_csv(
        $response['customers'],
        $response['filename']
    );
}

/** Export churn customers by status through REST. */
function hippoo_bi_rest_churn_export_status( $request ) {
    $response = hippoo_bi_export_churn_customers_by_status(
        array(
            'status' => sanitize_text_field( $request->get_param( 'status' ) ?: '' ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return hippoo_bi_rest_output_churn_csv(
        $response['customers'],
        $response['filename']
    );
}


// ---------------------------------------------------------------------------
// REST helpers
// ---------------------------------------------------------------------------

/** Check BI REST permissions. */
function hippoo_bi_rest_permission_check( $request ) {
    return apply_filters( 'hippoo_bi_permission_check', current_user_can( 'manage_options' ) );
}

/** Check whether the current REST tracking request is within the rate limit. */
function hippoo_bi_rest_rate_limit() {
    $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );

    $action_id       = 'hippoo_bi_track_' . md5( $ip );
    $delay_in_seconds = round( MINUTE_IN_SECONDS / 200, 2 );

    try {
        if ( \WC_Rate_Limiter::retried_too_soon( $action_id ) ) {
            return false;
        }
        \WC_Rate_Limiter::set_rate_limit( $action_id, $delay_in_seconds );
        return true;
    } catch ( Exception $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'HippooBI Rate Limiter Error: ' . $e->getMessage() );
        }
        return true;
    }
}

/** Output churn customers as CSV. */
function hippoo_bi_rest_output_churn_csv( $customers, $filename ) {
    if ( headers_sent() ) {
        return new WP_Error( 'headers_sent', __( 'Unable to generate CSV export.', 'hippoo' ), array( 'status' => 500 ) );
    }

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $output = fopen( 'php://output', 'w' );

    fputcsv( $output, array(
        __( 'Customer ID', 'hippoo' ),
        __( 'Name', 'hippoo' ),
        __( 'Email', 'hippoo' ),
        __( 'Churn Score', 'hippoo' ),
        __( 'Status', 'hippoo' ),
        __( 'Last Order', 'hippoo' ),
        __( 'Days Since', 'hippoo' ),
        __( 'Total Orders', 'hippoo' ),
        __( 'Total Spent', 'hippoo' ),
        __( 'CLV', 'hippoo' ),
        __( 'Avg Order Value', 'hippoo' ),
        __( 'Avg Days Between', 'hippoo' ),
    ), ',', '"', '\\' );

    foreach ( $customers as $customer ) {
        $name  = $customer->display_name ?: '';
        $email = $customer->email ?: '';

        $aov = $customer->total_orders > 0 ? round( (float) $customer->total_spent / $customer->total_orders, 2 ) : 0;

        fputcsv( $output, array(
            $customer->customer_id,
            $name,
            $email,
            $customer->churn_score,
            $customer->status,
            $customer->last_order_date,
            $customer->days_since_last ?? 0,
            $customer->total_orders,
            $customer->total_spent,
            $customer->clv,
            $aov,
            $customer->avg_days_between ?? 0,
        ), ',', '"', '\\' );
    }

    fclose( $output );
    exit;
}
