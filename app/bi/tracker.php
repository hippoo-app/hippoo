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
        'post_id' => absint( get_queried_object_id() ),
    ] );
}

/** Track a WooCommerce add-to-cart event. */
add_action( 'woocommerce_add_to_cart', 'hippoo_bi_track_add_to_cart', 10, 6 );

function hippoo_bi_track_add_to_cart( $cart_id, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    if ( empty( $product_id ) ) {
        return false;
    }

    $session_id = hippoo_bi_get_session_id();
    if ( ! $session_id ) {
        return false;
    }

    $insert_data = array(
        'session_id' => $session_id,
        'product_id' => absint( $product_id ),
        'created_at' => current_time( 'mysql' ),
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;

    $wpdb->insert( $table, $insert_data );
}

/** Store a BI pageview. */
function hippoo_bi_track_pageview( $data ) {
    $page_url = sanitize_url( $data['page_url'] ?? '' );
    if ( empty( $page_url ) ) {
        return false;
    }

    $path_id = hippoo_bi_get_or_create_path_id( $page_url );
    if ( ! $path_id ) {
        return false;
    }

    $referrer_id = hippoo_bi_get_or_create_referrer_id( $data['referrer'] ?? '' );
    if ( ! $referrer_id ) {
        return false;
    }

    $post_id = absint( $data['post_id'] );
    if ( ! $post_id ) {
        $post_id = absint( url_to_postid( $page_url ) );
    }

    $session_id = hippoo_bi_get_session_id( $data['session_id'] ?? '' );
    if ( ! $session_id ) {
        return false;
    }

    $insert_data = array(
        'session_id'      => $session_id,
        'post_id'         => $post_id,
        'path_id'         => $path_id,
        'referrer_id'     => $referrer_id,
        'device_type'     => hippoo_bi_get_device_type(),
        'country'         => hippoo_bi_get_country_from_ip(),
        'created_at'      => current_time( 'mysql' ),
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    return false !== $wpdb->insert( $table, $insert_data );
}

// ---------------------------------------------------------------------------
// Tracking helpers
// ---------------------------------------------------------------------------

/** Normalize a page URL to a compact, query-free path key. */
function hippoo_bi_normalize_path( $url ) {
    $path = wp_parse_url( (string) $url, PHP_URL_PATH );
    $path = is_string( $path ) ? untrailingslashit( $path ) : '/';
    $path = empty( $path ) ? '/' : substr( $path, 0, 255 );

    return $path;
}

/** Return the stable lookup ID for a normalized path, inserting it once. */
function hippoo_bi_get_or_create_path_id( $url ) {
    static $cache = array();

    $path = hippoo_bi_normalize_path( $url );
    if ( isset( $cache[ $path ] ) ) {
        return $cache[ $path ];
    }

    global $wpdb;

    $table  = $wpdb->prefix . HIPPOO_BI_TABLE_PATHS;
    $result = $wpdb->query( $wpdb->prepare(
        "INSERT INTO $table (path) VALUES (%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
        $path
    ) );

    if ( false === $result ) {
        return 0;
    }

    return $cache[ $path ] = (int) $wpdb->insert_id;
}

/** Return the stable lookup ID for a referrer host or the direct label. */
function hippoo_bi_get_or_create_referrer_id( $referrer ) {
    static $cache = array();

    $host = ! empty( $referrer ) ? wp_parse_url( (string) $referrer, PHP_URL_HOST ) : null;
    $source = $host ? strtolower( sanitize_text_field( $host ) ) : 'direct';
    $source = substr( $source, 0, 255 );
    if ( '' === $source ) {
        $source = 'direct';
    }

    if ( isset( $cache[ $source ] ) ) {
        return $cache[ $source ];
    }

    global $wpdb;

    $table  = $wpdb->prefix . HIPPOO_BI_TABLE_REFERRERS;
    $result = $wpdb->query( $wpdb->prepare(
        "INSERT INTO $table (source) VALUES (%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
        $source
    ) );

    if ( false === $result ) {
        return 0;
    }

    return $cache[ $source ] = (int) $wpdb->insert_id;
}

/** Resolve session from client id or existing cookie. */
function hippoo_bi_get_session_id( $session_id = '' ) {
    $cookie_name = 'hpbi';
    $expire      = time() + ( 2 * YEAR_IN_SECONDS );
    $session_id  = strtolower( sanitize_text_field( (string) $session_id ) );

    // Valid id from JS, persist cookie and use it.
    if ( ! empty( $session_id ) && preg_match( '/^[a-z0-9]{16}$/', $session_id ) ) {
        if ( empty( $_COOKIE[ $cookie_name ] ) || $_COOKIE[ $cookie_name ] !== $session_id ) {
            if ( ! headers_sent() ) {
                setcookie( $cookie_name, $session_id, $expire, '/', '', is_ssl(), false );
            }
            $_COOKIE[ $cookie_name ] = $session_id;
        }
        return $session_id;
    }

    // Fallback to an existing valid cookie.
    if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
        $cookie_id = strtolower( sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) );
        if ( preg_match( '/^[a-z0-9]{16}$/', $cookie_id ) ) {
            return $cookie_id;
        }
    }

    return '';
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
