<?php
/**
 * Hippoo Integrations – REST API routes and callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// WooCommerce authentication
// ---------------------------------------------------------------------------

/** Allow the Integrations REST namespace to use WooCommerce authentication. */
add_filter( 'hippoo_rest_wc_auth_namespaces', 'hippoo_integrations_rest_use_wc_authentication' );

function hippoo_integrations_rest_use_wc_authentication( $namespaces ) {
    $namespaces[] = 'hippoo-integrations/v1';
    return $namespaces;
}


// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

/** Register Integrations REST API routes. */
add_action( 'rest_api_init', 'hippoo_integrations_register_rest_routes' );

function hippoo_integrations_register_rest_routes() {
    register_rest_route( 'hippoo-integrations/v1', '/plugins-list', array(
        'methods'             => 'GET',
        'callback'            => 'hippoo_integrations_rest_plugins_list',
        'permission_callback' => 'hippoo_rest_permission_manage',
        'args'                => array(
            'page' => array(
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ),
            'per_page' => array(
                'type'        => 'integer',
                'default'     => 10,
                'minimum'     => 1,
                'maximum'     => 100,
            ),
        ),
    ) );

    register_rest_route( 'hippoo-integrations/v1', '/manage-plugin', array(
        'methods'             => 'POST',
        'callback'            => 'hippoo_integrations_rest_manage_plugin',
        'permission_callback' => 'hippoo_rest_permission_manage',
        'args'                => array(
            'slug' => array(
                'required' => true,
                'type'     => 'string',
            ),
            'action' => array(
                'required' => true,
                'type'     => 'string',
            ),
        ),
    ) );
}


// ---------------------------------------------------------------------------
// Route callbacks
// ---------------------------------------------------------------------------

/** Return the available integrations with local installation status. */
function hippoo_integrations_rest_plugins_list( $request ) {
    $products = hippoo_integrations_get_products();

    if ( ! $products ) {
        return new WP_Error( 'fetch_failed', __( 'Failed to fetch integrations.', 'hippoo' ), array( 'status' => 500 ) );
    }

    $plugins = hippoo_integrations_plugins_list( $products );

    $page     = max( 1, (int) $request['page'] );
    $per_page = max( 1, min( 100, (int) $request['per_page'] ) );
    $offset   = ( $page - 1 ) * $per_page;

    $total             = count( $plugins );
    $paginated_plugins = array_slice( $plugins, $offset, $per_page );

    $response = rest_ensure_response( $paginated_plugins );

    $response->header( 'X-WP-Total', (int) $total );
    $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

    return $response;
}

/** Install, activate or deactivate an integration. */
function hippoo_integrations_rest_manage_plugin( $request ) {
    $params = $request->get_json_params();

    $slug   = sanitize_text_field( $params['slug'] ?? '' );
    $action = sanitize_text_field( $params['action'] ?? '' );

    if ( empty( $slug ) || ! hippoo_integrations_is_allowed_plugin( $slug ) ) {
        return new WP_Error( 'forbidden', __( 'Invalid or unauthorized plugin.', 'hippoo' ), array( 'status' => 403 ) );
    }

    $plugin_file = hippoo_integrations_get_plugin_file( $slug );

    switch ( $action ) {
        case 'install':
            if ( $plugin_file ) {
                return new WP_Error( 'already_installed', __( 'Plugin already installed.', 'hippoo' ), array( 'status' => 409 ) );
            }

            $installed = hippoo_integrations_install_plugin( $slug );

            if ( is_wp_error( $installed ) ) {
                return $installed;
            }

            return rest_ensure_response( array(
                'status'  => 'installed',
                'message' => __( 'Plugin installed successfully.', 'hippoo' ),
            ) );

        case 'activate':
            if ( ! $plugin_file ) {
                $installed = hippoo_integrations_install_plugin( $slug );

                if ( is_wp_error( $installed ) ) {
                    return $installed;
                }

                $plugin_file = hippoo_integrations_get_plugin_file( $slug );
            }

            if ( ! $plugin_file ) {
                return new WP_Error( 'plugin_not_found', __( 'Installed plugin could not be found.', 'hippoo' ), array( 'status' => 500 ) );
            }

            if ( is_plugin_active( $plugin_file ) ) {
                return rest_ensure_response( array(
                    'status'  => 'active',
                    'message' => __( 'Plugin already active.', 'hippoo' ),
                ) );
            }

            $result = activate_plugin( $plugin_file );

            if ( is_wp_error( $result ) ) {
                return new WP_Error( 'activation_failed', $result->get_error_message(), array( 'status' => 500 ) );
            }

            return rest_ensure_response( array(
                'status'  => 'active',
                'message' => __( 'Plugin activated successfully.', 'hippoo' ),
            ) );

        case 'deactivate':
            if ( ! $plugin_file || ! is_plugin_active( $plugin_file ) ) {
                return new WP_Error( 'not_active', __( 'Plugin is not active.', 'hippoo' ), array( 'status' => 409 ) );
            }

            deactivate_plugins( $plugin_file );

            if ( is_plugin_active( $plugin_file ) ) {
                return new WP_Error( 'deactivation_failed', __( 'Failed to deactivate plugin.', 'hippoo' ), array( 'status' => 500 ) );
            }

            return rest_ensure_response( array(
                'status'  => 'deactivated',
                'message' => __( 'Plugin deactivated successfully.', 'hippoo' ),
            ) );
    }

    return new WP_Error( 'bad_request', __( 'Invalid action.', 'hippoo' ), array( 'status' => 400 ) );
}
