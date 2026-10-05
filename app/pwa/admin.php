<?php
/**
 * Hippoo PWA – settings, fields and sanitization
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/** Register PWA settings section and fields. */
add_action( 'admin_init', 'hippoo_pwa_register_settings' );

function hippoo_pwa_register_settings() {
    add_settings_section(
        'hippoo_pwa_section',
        null,
        null,
        'hippoo_settings'
    );

    $description = '<p>' . esc_html__(
        'Showcase your products with a clean, minimal design. This lightweight theme creates a mobile-friendly product display. Use the link below as an Instagram-style shop to share your products. We recommend keeping it enabled.',
        'hippoo'
    ) . '</p>';

    add_settings_field(
        'pwa_plugin_enabled',
        __( 'Hippoo Mobile Storefront', 'hippoo' ) . $description,
        'hippoo_pwa_field_plugin_enabled',
        'hippoo_settings',
        'hippoo_pwa_section'
    );

    add_settings_field(
        'pwa_route_name',
        __( 'Storefront address', 'hippoo' ),
        'hippoo_pwa_field_route_name',
        'hippoo_settings',
        'hippoo_pwa_section'
    );

    $description = '<p>' . esc_html__(
        'Add your own CSS rules for Hippoo Shop. Styles entered here override defaults.',
        'hippoo'
    ) . '</p>';

    add_settings_field(
        'pwa_custom_css',
        __( 'Custom CSS', 'hippoo' ) . $description,
        'hippoo_pwa_field_custom_css',
        'hippoo_settings',
        'hippoo_pwa_section',
        array(
            'class' => 'custom-css-row',
        )
    );
}


// ---------------------------------------------------------------------------
// Settings sanitization
// ---------------------------------------------------------------------------

/** Sanitize PWA settings before they are saved. */
add_filter( 'hippoo_sanitize_settings', 'hippoo_pwa_sanitize_settings', 10, 2 );

function hippoo_pwa_sanitize_settings( $sanitized, $input ) {
    if ( isset( $input['pwa_plugin_enabled'] ) ) {
        $sanitized['pwa_plugin_enabled'] = (bool) $input['pwa_plugin_enabled'];
    }

    if ( isset( $input['pwa_route_name'] ) ) {
        $sanitized['pwa_route_name'] = sanitize_text_field( $input['pwa_route_name'] );
    }

    if ( isset( $input['pwa_custom_css'] ) ) {
        $sanitized['pwa_custom_css'] = wp_strip_all_tags( $input['pwa_custom_css'] );
    }

    return $sanitized;
}


// ---------------------------------------------------------------------------
// Settings fields
// ---------------------------------------------------------------------------

/** Render the PWA enabled/disabled field. */
function hippoo_pwa_field_plugin_enabled() {
    echo '<input type="checkbox" class="switch" id="pwa_plugin_enabled" name="hippoo_settings[pwa_plugin_enabled]" ' .
        checked( hippoo_pwa_is_enabled(), 1, false ) .
        ' value="1">';
}

/** Render the PWA storefront route field. */
function hippoo_pwa_field_route_name() {
    $disabled = 'disabled';

    echo '<label for="pwa_route_name" class="route-name-field">';
    echo esc_html( preg_replace( '#^https?://#', '', get_site_url() ) . '/ ' );

    echo '<input
        type="text"
        id="pwa_route_name"
        name="hippoo_settings[pwa_route_name]"
        value="' . esc_attr( hippoo_pwa_get_route_name() ) . '"
        ' . esc_attr( $disabled ) . '
    >';

    echo '</label>';
}

/** Render the PWA custom CSS field. */
function hippoo_pwa_field_custom_css() {
    $custom_css = hippoo_pwa_get_custom_css();
    $disabled   = hippoo_pwa_is_enabled() ? '' : 'disabled';

    echo '<textarea
        id="pwa_custom_css"
        name="hippoo_settings[pwa_custom_css]"
        rows="7"
        cols="50"
        ' . esc_attr( $disabled ) . '
    >' . esc_textarea( $custom_css ) . '</textarea>';
}
