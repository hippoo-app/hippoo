<?php
/**
 * Hippoo BI – storefront tracking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Storefront tracking
// ---------------------------------------------------------------------------

/** Register BI storefront tracking scripts. */
add_action( 'wp_enqueue_scripts', 'hippoo_bi_track_enqueue_scripts' );

function hippoo_bi_track_enqueue_scripts() {
    wp_enqueue_script( 'hippoo-bi-tracker', HIPPOO_URL . 'js/bi-tracker.js', [], HIPPOO_VERSION, true );

    wp_localize_script( 'hippoo-bi-tracker', 'hippooBI', [
        'rest_url'   => rest_url('hippoo/v1/bi/track'),
        'product_id' => is_product() ? get_the_ID() : null,
    ] );
}

/** Track a WooCommerce add-to-cart event. */
add_action( 'woocommerce_add_to_cart', 'hippoo_bi_track_add_to_cart', 10, 6 );

function hippoo_bi_track_add_to_cart( $cart_id, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    if ( empty( $product_id ) ) {
        return false;
    }

    $insert_data = array(
        'session_id' => hippoo_bi_get_current_session_id(),
        'product_id' => absint( $product_id ),
        'quantity'   => absint( $quantity ),
        'created_at' => current_time( 'mysql' ),
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;

    $wpdb->insert( $table, $insert_data );
}

/** Store a BI pageview. */
function hippoo_bi_track_pageview( $data ) {
    if ( empty( $data['product_id'] ) ) {
        return false;
    }

    $insert_data = array(
        'session_id'      => ! empty( $data['session_id'] )
            ? sanitize_text_field( $data['session_id'] )
            : hippoo_bi_get_current_session_id(),
        'page_url'        => sanitize_url( $data['page_url'] ?? '' ),
        'referrer_source' => hippoo_bi_get_referrer_source( $data['referrer'] ?? '' ),
        'device_type'     => hippoo_bi_get_device_type(),
        'country'         => hippoo_bi_get_country_from_ip(),
        'product_id'      => absint( $data['product_id'] ),
        'created_at'      => current_time( 'mysql' ),
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    return false !== $wpdb->insert( $table, $insert_data );
}

// ---------------------------------------------------------------------------
// Tracking helpers
// ---------------------------------------------------------------------------

/** Get or create the current BI tracking session ID. */
function hippoo_bi_get_current_session_id() {
    $cookie_name = 'hpbi';

    if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
        return sanitize_text_field( $_COOKIE[ $cookie_name ] );
    }

    $sid = substr( str_shuffle( str_repeat( '0123456789abcdefghijklmnopqrstuvwxyz', 8 ) ), 0, 16 );
    $expire = time() + ( 2 * YEAR_IN_SECONDS );

    setcookie( $cookie_name, $sid, $expire, '/', '', is_ssl(), false );
    $_COOKIE[ $cookie_name ] = $sid;

    return $sid;
}

/** Extract the hostname from a referrer URL. */
function hippoo_bi_get_referrer_source( $ref ) {
    if ( empty( $ref ) ) {
        return null;
    }

    $host = wp_parse_url( $ref, PHP_URL_HOST );

    return $host ? $host : null;
}

/** Detect the current visitor device type. */
function hippoo_bi_get_device_type() {
    try {
        $detect = new \Detection\MobileDetect();
        if ( $detect->isTablet() ) return 't';
        if ( $detect->isMobile() ) return 'm';
        return 'd';
    } catch ( Exception $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'HippooBI Mobile Detection Error: ' . $e->getMessage() );
        }
        return 'd';
    }
}

/** Resolve the visitor country from the current IP address. */
function hippoo_bi_get_country_from_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ( empty( $ip ) || '127.0.0.1' === $ip || 0 === strpos( $ip, '192.168.' ) || 0 === strpos( $ip, '10.' ) ) {
        return null;
    }

    $mmdb_path = HIPPOO_PATH . 'assets/geoip/GeoLite2-Country.mmdb';

    if ( ! file_exists( $mmdb_path ) ) {
        return null;
    }

    try {
        $reader = new \GeoIp2\Database\Reader( $mmdb_path );
        $record = $reader->country( $ip );
        return $record->country->isoCode;
    } catch ( Exception $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'GeoIP Error: ' . $e->getMessage() );
        }
        return null;
    }
}
