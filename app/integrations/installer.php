<?php
/**
 * Hippoo Integrations – product catalog, plugin status and installer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HIPPOO_INTEGRATIONS_PRODUCTS_ENDPOINT' ) ) {
    define( 'HIPPOO_INTEGRATIONS_PRODUCTS_ENDPOINT', 'https://hippoo.app/wp-json/wc/store/v1/products?category=57&per_page=100' );
}

// ---------------------------------------------------------------------------
// Product catalog
// ---------------------------------------------------------------------------

/**
 * Get available Hippoo integrations from the central store.
 * Results are cached for 12 hours.
 */
function hippoo_integrations_get_products() {
    $cache_key = 'hippoo_products';
    $products  = get_transient( $cache_key );

    if ( false !== $products ) {
        return $products;
    }

    $response = wp_remote_get(
        HIPPOO_INTEGRATIONS_PRODUCTS_ENDPOINT,
        array(
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $products = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $products ) ) {
        return false;
    }

    set_transient( $cache_key, $products, 12 * HOUR_IN_SECONDS );

    return $products;
}


// ---------------------------------------------------------------------------
// Plugin status
// ---------------------------------------------------------------------------

/** Check whether an integration is allowed by the central product catalog. */
function hippoo_integrations_is_allowed_plugin( $slug ) {
    $products = hippoo_integrations_get_products();

    if ( ! $products ) {
        return false;
    }

    $allowed_slugs = array_column( $products, 'slug' );

    return in_array( $slug, $allowed_slugs, true );
}

/** Get the installed plugin file for an integration slug. */
function hippoo_integrations_get_plugin_file( $slug ) {
    $plugins = get_plugins();
    foreach ( $plugins as $file => $data ) {
        if ( dirname( $file ) === $slug ) {
            return $file;
        }
    }
    return false;
}

/** Build the integrations list with local installation status. */
function hippoo_integrations_plugins_list( array $products ) {
    $result = array();

    foreach ( $products as $product ) {
        $slug = $product['slug'] ?? '';

        if ( empty( $slug ) ) {
            continue;
        }

        $plugin_file = hippoo_integrations_get_plugin_file( $slug );
        $status      = 'not_installed';

        if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
            $status = 'active';
        } elseif ( $plugin_file ) {
            $status = 'installed';
        }

        $screenshots = array_values( array_filter( array_map( function ( $image ) {
            return $image['src'] ?? null;
        }, $product['images'] ?? array() ) ) );

        $main_image  = isset( $screenshots[0] ) ? $screenshots[0] : '';
        $screenshots = array_slice( $screenshots, 1 );

        $result[] = array(
            'id'          => $product['id'] ?? 0,
            'name'        => wp_strip_all_tags( $product['name'] ?? '' ),
            'slug'        => $slug,
            'description' => wp_kses_post( $product['short_description'] ?: $product['description'] ),
            'status'      => $status,
            'plugin_file' => $plugin_file,
            'detail_url'  => "https://wordpress.org/plugins/{$slug}/",
            'image'       => $main_image,
            'screenshots' => $screenshots,
        );
    }

    return $result;
}


// ---------------------------------------------------------------------------
// Installation
// ---------------------------------------------------------------------------

/**
 * Install an integration from WordPress.org.
 */
function hippoo_integrations_install_plugin( $slug ) {
    if ( ! class_exists( 'Plugin_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );

    $result = $upgrader->install(
        'https://downloads.wordpress.org/plugin/' . $slug . '.latest-stable.zip'
    );

    if ( ! $result || is_wp_error( $result ) ) {
        $message = $upgrader->skin->get_errors()->get_error_message();
        return new WP_Error( 'install_failed', $message ? $message : __( 'Installation failed.', 'hippoo' ), array( 'status' => 500 ) );
    }

    return true;
}
