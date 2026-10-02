<?php
/**
 * Hippoo Weekly Notification – settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/** Register the weekly notification settings. */
add_action( 'admin_init', 'hippoo_weekly_notif_register_settings' );

function hippoo_weekly_notif_register_settings() {
    register_setting(
        'hippoo_settings',
        'hippoo_weekly_notif',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'hippoo_weekly_notif_sanitize_setting',
            'default'           => true,
        )
    );

    add_settings_section(
        'hippoo_notifications_settings_section',
        null,
        null,
        'hippoo_settings'
    );

    add_settings_field(
        'hippoo_weekly_notif',
        __( 'Weekly sales notification', 'hippoo' ),
        'hippoo_weekly_notif_settings_field',
        'hippoo_settings',
        'hippoo_notifications_settings_section'
    );
}

/** Sanitize the weekly notification settings. */
function hippoo_weekly_notif_sanitize_setting( $value ) {
    return ! empty( $value );
}

/** Print the weekly notification toggle. */
function hippoo_weekly_notif_settings_field() {
    $value = get_option( 'hippoo_weekly_notif', '1' );

    printf(
        '<input type="hidden" name="hippoo_weekly_notif" value="0" />' .
        '<input type="checkbox" class="switch" name="hippoo_weekly_notif" value="1" %s />',
        checked( $value, true, false )
    );
}

/** Ensure the weekly notification setting exists with the default value. */
add_action( 'init', 'hippoo_weekly_notif_ensure_default' );

function hippoo_weekly_notif_ensure_default() {
    if ( false === get_option( 'hippoo_weekly_notif', false ) ) {
        add_option( 'hippoo_weekly_notif', '1' );
    }
}
