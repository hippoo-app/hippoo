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

/** Allow the AI REST namespace to use WooCommerce authentication. */
add_filter( 'hippoo_rest_wc_auth_namespaces', 'hippoo_ai_rest_use_wc_authentication' );

function hippoo_ai_rest_use_wc_authentication( $namespaces ) {
    $namespaces[] = 'hippoo-ai/v1';
    return $namespaces;
}


// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

/** Register Hippoo AI REST API routes. */
add_action( 'rest_api_init', 'hippoo_ai_register_rest_routes' );

function hippoo_ai_register_rest_routes() {
    register_rest_route( 'hippoo-ai/v1', '/generate-description', array(
            'methods'             => 'POST',
            'callback'            => 'hippoo_ai_rest_generate_description',
            'permission_callback' => 'hippoo_rest_permission_manage',
        )
    );

    register_rest_route( 'hippoo-ai/v1', '/test-connection', array(
            'methods'             => 'POST',
            'callback'            => 'hippoo_ai_rest_test_connection',
            'permission_callback' => 'hippoo_rest_permission_manage',
        )
    );

    register_rest_route( 'hippoo-ai/v1', '/models', array(
            'methods'             => 'GET',
            'callback'            => 'hippoo_ai_rest_get_models',
            'permission_callback' => 'hippoo_rest_permission_manage',
        )
    );

    register_rest_route( 'hippoo-ai/v1', '/prompts', array(
            array(
                'methods'             => 'GET',
                'callback'            => 'hippoo_ai_rest_get_prompts',
                'permission_callback' => 'hippoo_rest_permission_manage',
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => 'hippoo_ai_rest_update_prompts',
                'permission_callback' => 'hippoo_rest_permission_manage',
            ),
        )
    );

    register_rest_route( 'hippoo-ai/v1', '/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => 'hippoo_ai_rest_get_settings',
                'permission_callback' => 'hippoo_rest_permission_manage',
            ),
            array(
                'methods'             => 'PATCH',
                'callback'            => 'hippoo_ai_rest_update_settings',
                'permission_callback' => 'hippoo_rest_permission_manage',
            ),
        )
    );
}


// ---------------------------------------------------------------------------
// Route callbacks
// ---------------------------------------------------------------------------

/** Generate product description through AI. */
function hippoo_ai_rest_generate_description( $request ) {
    if ( ! hippoo_ai_rest_rate_limit() ) {
        return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'hippoo' ), array( 'status' => 429 ) );
    }

    $params = $request->get_json_params();
    $result = hippoo_ai_generate_description( $params );

    return rest_ensure_response( $result );
}

/** Test configured AI connection. */
function hippoo_ai_rest_test_connection( $request ) {
    $settings  = get_option( 'hippoo_ai_settings', array() );
    $provider  = $settings['ai_provider'] ?? 'gpt';
    $model     = $settings['ai_model'] ?? '';
    $api_token = $settings['api_token'] ?? '';

    if ( empty( $api_token ) ) {
        return new WP_Error( 'missing_token', __( 'You haven’t provided an API key. Please go to the Hippoo settings page and add your API key to connect to the AI.', 'hippoo' ), array( 'status' => 400 ) );
    }

    $start_time = microtime( true );

    $result = hippoo_ai_test_provider_connection( $provider, $api_token );

    $roundtrip_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

    if ( true !== $result ) {
        return new WP_Error( 'connection_failed', __( 'Failed to connect to AI service.', 'hippoo' ), array( 'status' => 500 ) );
    }

    return rest_ensure_response( array(
        'ok'           => true,
        'roundtrip_ms' => $roundtrip_ms,
        'provider'     => $provider,
        'model'        => $model,
    ) );
}

/** Return available models for configured provider. */
function hippoo_ai_rest_get_models( $request ) {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $provider = $settings['ai_provider'] ?? 'gpt';

    $models = hippoo_ai_get_models( $provider );

    if ( is_wp_error( $models ) ) {
        return $models;
    }

    return rest_ensure_response( array(
        'provider' => $provider,
        'models'   => $models,
    ) );
}

/** Return current AI prompts. */
function hippoo_ai_rest_get_prompts( $request ) {
    $settings = get_option( 'hippoo_ai_settings', array() );

    return rest_ensure_response( array(
        'system' => $settings['system_prompt'] ?? '',
        'description' => $settings['description_prompt'] ?? '',
    ) );
}

/** Update AI prompts. */
function hippoo_ai_rest_update_prompts( $request ) {
    $params   = $request->get_json_params();
    $settings = get_option( 'hippoo_ai_settings', array() );

    if ( isset( $params['system'] ) ) {
        $settings['system_prompt'] = sanitize_textarea_field( $params['system'] );
    }

    if ( isset( $params['description'] ) ) {
        $settings['description_prompt'] = sanitize_textarea_field( $params['description'] );
    }

    update_option( 'hippoo_ai_settings', $settings );

    return hippoo_ai_rest_get_prompts( $request );
}

/** Return public AI settings. */
function hippoo_ai_rest_get_settings( $request ) {
    $settings = get_option( 'hippoo_ai_settings', array() );

    $token_status = ! empty( $settings['api_token'] );

    unset( $settings['api_token'] );
    unset( $settings['system_prompt'] );
    unset( $settings['description_prompt'] );

    foreach ( $settings as $key => $value ) {
        if ( 'true' === $value ) {
            $settings[ $key ] = true;
        } elseif ( 'false' === $value ) {
            $settings[ $key ] = false;
        } elseif ( is_numeric( $value ) ) {
            $float_value = floatval( $value );
            if ( $float_value != intval( $float_value ) ) {
                $settings[ $key ] = floatval( number_format( $float_value, 2, '.', '' ) );
            } else {
                $settings[ $key ] = intval( $value );
            }
        }
    }

    $settings['api_token_status'] = $token_status;

    return rest_ensure_response( $settings );
}

/** Update AI settings through REST. */
function hippoo_ai_rest_update_settings( $request ) {
    $params = $request->get_json_params();

    if ( ! is_array( $params ) || empty( $params ) ) {
        return new WP_Error( 'invalid_params', __( 'Invalid parameters provided', 'hippoo' ), array( 'status' => 400 ) );
    }

    $settings = get_option( 'hippoo_ai_settings', array() );

    $sanitized_params = array();
    foreach ( $params as $key => $value ) {
        if ( 'true' === $value ) {
            $value = true;
        } elseif ( 'false' === $value ) {
            $value = false;
        } elseif ( is_numeric( $value ) ) {
            $float_value = floatval( $value );
            if ( $float_value != intval( $float_value ) ) {
                $value = floatval( number_format( $float_value, 2, '.', '' ) );
            } else {
                $value = intval( $value );
            }
        } elseif ( is_string( $value ) ) {
            $value = sanitize_text_field( $value );
        }
        $sanitized_params[ $key ] = $value;
    }

    $settings = array_merge( $settings, $sanitized_params );

    update_option( 'hippoo_ai_settings', $settings );

    return hippoo_ai_rest_get_settings( $request );
}


// ---------------------------------------------------------------------------
// REST helpers
// ---------------------------------------------------------------------------

/** Check AI generation rate limit. */
function hippoo_ai_rest_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $cache_key = 'hippoo_ai_rate_limit_' . $ip;
    $count = get_transient( $cache_key ) ?: 0;

    if ( $count >= 30 ) { // 30 requests per minute
        return false;
    }

    set_transient( $cache_key, $count + 1, MINUTE_IN_SECONDS );

    return true;
}
