<?php
/**
 * Hippoo PWA – storefront routes, file serving, frontend helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

/** Enable PWA by default and flush rewrite rules on plugin activation. */
register_activation_hook( HIPPOO_MAIN_FILE_PATH, 'hippoo_pwa_activate' );

function hippoo_pwa_activate() {
    $settings = get_option( 'hippoo_settings', array() );

    if ( ! isset( $settings['pwa_plugin_enabled'] ) ) {
        $settings['pwa_plugin_enabled'] = 1;
    }

    update_option( 'hippoo_settings', $settings );

    hippoo_pwa_add_route();
    flush_rewrite_rules();
}

/** Flush rewrite rules when the plugin is deactivated. */
register_deactivation_hook( HIPPOO_MAIN_FILE_PATH, 'hippoo_pwa_deactivate' );

function hippoo_pwa_deactivate() {
    flush_rewrite_rules();
}

/** Flush rewrite rules when Hippoo settings are updated. */
add_action( 'update_option_hippoo_settings', 'flush_rewrite_rules' );


// ---------------------------------------------------------------------------
// Rewrite rules & query vars
// ---------------------------------------------------------------------------

/** Register PWA rewrite rules. */
add_action( 'init', 'hippoo_pwa_add_route' );

function hippoo_pwa_add_route() {
    if ( ! hippoo_pwa_is_enabled() ) {
        return;
    }

    $route_name = hippoo_pwa_get_route_name();

    add_rewrite_rule(
        '^' . $route_name . '/?$',
        'index.php?hippoo_pwa=1',
        'top'
    );

    add_rewrite_rule(
        '^' . $route_name . '/custom\.css$',
        'index.php?hippoo_custom_css=1',
        'top'
    );

    add_rewrite_rule(
        '^' . $route_name . '/(.+?)/?$',
        'index.php?hippoo_serve=$matches[1]',
        'top'
    );
}

/** Register custom query vars used by the PWA routes. */
add_filter( 'query_vars', 'hippoo_pwa_add_query_vars' );

function hippoo_pwa_add_query_vars( $vars ) {
    $vars[] = 'hippoo_pwa';
    $vars[] = 'hippoo_serve';
    $vars[] = 'hippoo_custom_css';
    return $vars;
}


// ---------------------------------------------------------------------------
// Storefront request handling
// ---------------------------------------------------------------------------

/** Handle PWA storefront requests. */
add_action( 'template_redirect', 'hippoo_pwa_template_redirect' );

function hippoo_pwa_template_redirect() {

    // Main PWA entry point.
    if ( get_query_var( 'hippoo_pwa' ) ) {
        include HIPPOO_PATH . 'pwa/index.html';
        exit;
    }

    // Static PWA files.
    $serve_path = get_query_var( 'hippoo_serve' );

    if ( $serve_path ) {
        hippoo_pwa_serve_file( $serve_path );
    }

    // Generated custom CSS.
    if ( get_query_var( 'hippoo_custom_css' ) ) {
        hippoo_pwa_serve_custom_css();
    }
}

/** Serve a file from the PWA directory. */
function hippoo_pwa_serve_file( $serve_path ) {
    $base      = realpath( HIPPOO_PATH . 'pwa' );
    $file_path = realpath( $base . DIRECTORY_SEPARATOR . $serve_path );

    if ( ! $file_path || strpos( $file_path, $base ) !== 0 ) {
        include $base . '/index.html';
        exit;
    }

    if ( file_exists( $file_path ) ) {
        $mime_type = hippoo_pwa_get_mime_type( $file_path );
        header( 'Content-Type: ' . $mime_type );
        readfile( $file_path ); // phpcs:ignore
        exit;
    }

    include $base . '/index.html';
    exit;
}

/** Serve the custom CSS configured for the PWA storefront. */
function hippoo_pwa_serve_custom_css() {
    $custom_css = hippoo_pwa_get_custom_css();
    header( 'Content-Type: text/css' );
    header( 'Cache-Control: public, max-age=86400' );
    echo $custom_css; // phpcs:ignore
    exit;
}

/** Get the MIME type for a PWA asset. */
function hippoo_pwa_get_mime_type( $file_path ) {
    $mime_types = array(
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ttf'  => 'font/ttf',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'eot'  => 'application/vnd.ms-fontobject',
        'html' => 'text/html',
        'txt'  => 'text/plain',
        'ico'  => 'image/x-icon',
    );

    $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
    return $mime_types[ $extension ] ?? 'application/octet-stream';
}


// ---------------------------------------------------------------------------
// PWA settings access
// ---------------------------------------------------------------------------

/** Check whether the PWA storefront is enabled. */
function hippoo_pwa_is_enabled() {
    $settings = get_option( 'hippoo_settings', array() );
    return ! empty( $settings['pwa_plugin_enabled'] );
}

/** Get the PWA storefront route name. */
function hippoo_pwa_get_route_name() {
    $settings = get_option( 'hippoo_settings', array() );
    $route_name = isset( $settings['pwa_route_name'] ) ? $settings['pwa_route_name'] : 'hippooshop';
    return $route_name;
}

/** Get custom CSS configured for the PWA storefront. */
function hippoo_pwa_get_custom_css() {
    $settings = get_option( 'hippoo_settings', array() );
    $custom_css = isset( $settings['pwa_custom_css'] ) ? $settings['pwa_custom_css'] : '';
    return $custom_css;
}
