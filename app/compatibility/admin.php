<?php
/**
 * Hippoo Compatibility – settings and AJAX callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/** Register Compatibility settings section and fields. */
add_action( 'admin_init', 'hippoo_compatibility_register_settings' );

function hippoo_compatibility_register_settings() {
    add_settings_section(
        'hippoo_compatibility_section',
        null,
        null,
        'hippoo_settings'
    );

    $description = '<p>'
        . esc_html__( 'Fix issues when products, orders, or coupons don\'t load correctly in the Hippoo WooCommerce app.', 'hippoo' )
        . '<br><strong>' . esc_html__( 'Note:', 'hippoo' ) . '</strong> '
        . esc_html__( 'Enabling this option may affect other tools and plugins that use the WooCommerce API.', 'hippoo' )
        . '</p>';

    add_settings_field(
        'compatibility_mode',
        __( 'Enable Compatibility Mode', 'hippoo' ) . $description,
        'hippoo_compatibility_field_mode',
        'hippoo_settings',
        'hippoo_compatibility_section'
    );

    add_settings_field(
        'compatibility_applies',
        __( 'Applies to:', 'hippoo' ),
        'hippoo_compatibility_field_applies',
        'hippoo_settings',
        'hippoo_compatibility_section',
        array(
            'class' => 'compatibility-applies-row',
        )
    );

    add_settings_section(
        'hippoo_compatibility_log_section',
        null,
        null,
        'hippoo_settings'
    );

    add_settings_field(
        'compatibility_log',
        __( 'Hippoo Debug log', 'hippoo' ),
        'hippoo_compatibility_field_log',
        'hippoo_settings',
        'hippoo_compatibility_log_section'
    );
}


// ---------------------------------------------------------------------------
// Settings sanitization
// ---------------------------------------------------------------------------

/** Sanitize Compatibility settings. */
add_filter( 'hippoo_sanitize_settings', 'hippoo_compatibility_sanitize_settings', 10, 2 );

function hippoo_compatibility_sanitize_settings( $sanitized, $input ) {
    if ( isset( $input['compatibility_mode'] ) ) {
        $sanitized['compatibility_mode'] = (bool) $input['compatibility_mode'];
    }

    if ( isset( $input['compatibility_applies'] ) && is_array( $input['compatibility_applies'] ) ) {
        $sanitized['compatibility_applies'] = array_map( 'sanitize_text_field', $input['compatibility_applies'] );
    }

    return $sanitized;
}


// ---------------------------------------------------------------------------
// Settings fields
// ---------------------------------------------------------------------------

/** Render the Compatibility Mode field. */
function hippoo_compatibility_field_mode() {
    echo '<input type="checkbox" class="switch" id="compatibility_mode" name="hippoo_settings[compatibility_mode]" ' .
        checked( hippoo_compatibility_is_enabled(), 1, false ) .
        ' value="1">';
}

/** Render the Compatibility Applies field. */
function hippoo_compatibility_field_applies() {
    $settings = get_option( 'hippoo_settings', array() );
    $applies  = hippoo_compatibility_get_applies();
    ?>
    <label class="checkbox-label">
        <?php esc_html_e( 'WooCommerce orders api', 'hippoo' ); ?>
        <input
            type="checkbox"
            name="hippoo_settings[compatibility_applies][]"
            <?php checked( in_array( 'orders', $applies, true ), true ); ?>
            value="orders"
        >
    </label>

    <label class="checkbox-label">
        <?php esc_html_e( 'WooCommerce products api', 'hippoo' ); ?>
        <input
            type="checkbox"
            name="hippoo_settings[compatibility_applies][]"
            <?php checked( in_array( 'products', $applies, true ), true ); ?>
            value="products"
        >
    </label>

    <label class="checkbox-label">
        <?php esc_html_e( 'WooCommerce coupons api', 'hippoo' ); ?>
        <input
            type="checkbox"
            name="hippoo_settings[compatibility_applies][]"
            <?php checked( in_array( 'coupons', $applies, true ), true ); ?>
            value="coupons"
        >
    </label>

    <?php
}

/** Render the Compatibility log field. */
function hippoo_compatibility_field_log() {
    ?>
    <a href="#" id="copy-compatibility-log" class="copy-log-link">
        <?php esc_html_e( 'Copy API debug log', 'hippoo' ); ?>
    </a>
    <?php
}


// ---------------------------------------------------------------------------
// AJAX
// ---------------------------------------------------------------------------

/** Return the Compatibility debug log. */
add_action( 'wp_ajax_hippoo_get_compatibility_log', 'hippoo_compatibility_ajax_get_log' );

function hippoo_compatibility_ajax_get_log() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Invalid request.', 'hippoo' ) );
    }

    wp_send_json_success( hippoo_compatibility_get_log_content() );
}
