<?php
/**
 * Hippoo Integrations – settings tab and AJAX callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Settings tab
// ---------------------------------------------------------------------------

/** Add the Integrations tab to Hippoo settings. */
add_filter( 'hippoo_settings_tabs', 'hippoo_integrations_add_settings_tab' );

function hippoo_integrations_add_settings_tab( $tabs ) {
    $tabs['integrations'] = array(
        'label'    => esc_html__( 'Hippoo Integrations', 'hippoo' ),
        'priority' => 40,
    );
    return $tabs;
}

/** Render the Integrations settings tab. */
add_filter( 'hippoo_settings_tab_contents', 'hippoo_integrations_add_settings_tab_content' );

function hippoo_integrations_add_settings_tab_content( $contents ) {
    $contents['integrations'] = function() {
        ob_start();
        ?>
        <div class="hippoo-integrations-tab">
            <h3 class="section-title"><?php esc_html_e( 'Hippoo Integrations', 'hippoo' ); ?></h3>
            <p><?php esc_html_e( 'Integrations are free WordPress plugins that bring new features to both WooCommerce and the Hippoo App. They are completely free to install and use in WooCommerce. A Premium license is required to use these features inside the Hippoo App.', 'hippoo' ); ?></p>
            <div id="hippoo-integrations-loading"></div>
            <div id="hippoo-integrations-list"></div>
        </div>
        <?php
        return ob_get_clean();
    };
    return $contents;
}


// ---------------------------------------------------------------------------
// AJAX
// ---------------------------------------------------------------------------

/** Return the available integrations to the admin UI. */
add_action( 'wp_ajax_hippoo_get_integrations', 'hippoo_integrations_ajax_get_integrations' );

function hippoo_integrations_ajax_get_integrations() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'hippoo' ), 403 );
    }

    $products = hippoo_integrations_get_products();

    if ( ! $products ) {
        wp_send_json_error( __( 'Failed to fetch integrations.', 'hippoo' ) );
    }

    wp_send_json_success( hippoo_integrations_plugins_list( $products ) );
}

/** Install or activate an integration from the admin UI. */
add_action( 'wp_ajax_hippoo_install_integration', 'hippoo_integrations_ajax_install_integration' );

function hippoo_integrations_ajax_install_integration() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );

    if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
        wp_send_json_error(  __( 'Sorry, you are not allowed to do that.', 'hippoo' ), 403 );
    }

    $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );

    if ( empty( $slug ) || ! hippoo_integrations_is_allowed_plugin( $slug ) ) {
        wp_send_json_error( __( 'Invalid or unauthorized plugin.', 'hippoo' ) );
    }

    $plugin_file = hippoo_integrations_get_plugin_file( $slug );

    if ( $plugin_file ) {
        if ( is_plugin_active( $plugin_file ) ) {
            wp_send_json_success(
                array(
                    'status'  => 'active',
                    'message' => __( 'Already active.', 'hippoo' ),
                )
            );
        }

        $result = activate_plugin( $plugin_file );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success(
            array(
                'status'  => 'active',
                'message' => __( 'Activated successfully.', 'hippoo' ),
            )
        );
    }

    $installed = hippoo_integrations_install_plugin( $slug );

    if ( is_wp_error( $installed ) ) {
        wp_send_json_error( $installed->get_error_message() );
    }

    wp_send_json_success(
        array(
            'status'  => 'installed',
            'message' => __( 'Installed successfully.', 'hippoo' ),
        )
    );
}
