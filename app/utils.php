<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function hippoo_get_temp_dir() {
    $wp_upload_dir = wp_upload_dir();
    $temp_dir = implode(DIRECTORY_SEPARATOR, [$wp_upload_dir['basedir'], 'hippoo', 'tmp']) . DIRECTORY_SEPARATOR;
    
    if (!file_exists($temp_dir)) {
        // phpcs:ignore
        mkdir($temp_dir, 0755, true);
    }

    return $temp_dir;
}

function hippoo_get_log_dir() {
    $wp_upload_dir = wp_upload_dir();
    $log_dir = implode(DIRECTORY_SEPARATOR, [$wp_upload_dir['basedir'], 'hippoo', 'logs']) . DIRECTORY_SEPARATOR;
    
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
        
        // Protect directory from direct access
        $htaccess_content = 'deny from all';
        $index_content = '';
        
        $htaccess_file = $log_dir . '.htaccess';
        $index_file = $log_dir . 'index.html';
        
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, $htaccess_content);
        }
        
        if (!file_exists($index_file)) {
            file_put_contents($index_file, $index_content);
        }
    }
    
    return $log_dir;
}

function hippoo_get_log_content($filename) {
    $log_dir = hippoo_get_log_dir();
    $log_file = $log_dir . basename($filename);
    
    if (!file_exists($log_file)) {
        return '';
    }
    
    return file_get_contents($log_file);
}

function hippoo_put_log_content($filename, $content) {
    $log_dir = hippoo_get_log_dir();
    $log_file = $log_dir . basename($filename);
    
    file_put_contents($log_file, $content, LOCK_EX);
}

function hippoo_get_product_by_slug( $products, $name ) {
    if ( ! is_array( $products ) ) {
        return null;
    }
    foreach ( $products as $product ) {
        if ( is_array( $product ) && isset( $product['slug'] ) && strcasecmp( $product['slug'], $name ) === 0 ) {
            return $product;
        }
    }
    return null;
}

function hippoo_get_available_image_sizes() {
    $sizes = [];
    $all_sizes = wp_get_registered_image_subsizes();
    
    foreach ($all_sizes as $size => $dimensions) {
        $sizes[$size] = [
            'width' => $dimensions['width'],
            'height' => $dimensions['height']
        ];
    }

    $sizes['original'] = ['width' => 'Original', 'height' => 'Original'];
    
    return $sizes;
}

function hippoo_check_rest_api_status() {
    $permalink_structure = get_option('permalink_structure');
    if (empty($permalink_structure)) {
        return [
            'status' => 'error',
            'message' => sprintf(
                /* translators: %s: URL to the Permalink Settings page in wp-admin */
                __('Hippoo can’t connect because your WordPress permalinks are set to “Plain”. To enable the WordPress REST API, open your <a href="%s">Permalink Settings</a> and select Post name or Custom Structure, then save changes.', 'hippoo'),
                esc_url(admin_url('options-permalink.php'))
            ),
            'code' => 'plain_permalinks'
        ];
    }

    if (!apply_filters('rest_enabled', true)) {
        return [
            'status' => 'error',
            'message' => __('Hippoo can’t connect because the WordPress REST API has been disabled by a theme or plugin.', 'hippoo'),
            'code' => 'rest_api_disabled'
        ];
    }

    $response = wp_remote_get(rest_url('wp/v2/posts'), [
        'timeout' => 30,
        'sslverify' => false,
    ]);

    if (is_wp_error($response)) {
        return [
            'status' => 'error',
            'message' => __('Hippoo can’t connect to your website because the WordPress REST API is not responding. Please check your firewall or hosting settings.', 'hippoo'),
            'code' => 'connection_error'
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);

    switch ($status_code) {
        case 401:
            return [
                'status' => 'error',
                'message' => __('Hippoo can’t connect to your site because the WordPress REST API is restricted. Please allow public access to the REST API.', 'hippoo'),
                'code' => 'unauthorized'
            ];
        case 403:
            return [
                'status' => 'error',
                'message' => __('Hippoo can’t connect because something on your site (like a security plugin or server rule) is blocking access to the WordPress REST API.', 'hippoo'),
                'code' => 'forbidden'
            ];
        case 404:
            return [
                'status' => 'error',
                'message' => __('Hippoo can’t connect because the WordPress API endpoint wasn’t found. Please make sure your WordPress installation is complete and .htaccess is correctly configured.', 'hippoo'),
                'code' => 'not_found'
            ];
        case 500:
            return [
                'status' => 'error',
                'message' => __('Hippoo can’t connect right now because your site returned an internal error when trying to reach the WordPress REST API.', 'hippoo'),
                'code' => 'internal_server_error'
            ];
    }

    $server = rest_get_server();
    $routes = array_keys($server->get_routes());
    if (!in_array('/wp/v2/posts', $routes)) {
        return [
            'status' => 'error',
            'message' => __('Hippoo can’t connect because essential WordPress API routes are missing. A plugin may have disabled them.', 'hippoo'),
            'code' => 'core_routes_missing'
        ];
    }

    return [
        'status' => 'success',
        'message' => '',
    ];
}

function hippoo_check_user_license() {
    $cache_key = 'hippoo_license_status';
    $license   = get_transient($cache_key);

    if ($license !== false) {
        return $license;
    }

    $email = get_option('admin_email'); 
    $hostname = home_url();

    if (!$email) {
        set_transient($cache_key, 'basic', HOUR_IN_SECONDS);
        return 'basic';
    }

    $url = "https://hippoo.app/wp-json/woohouse/v1/get_licenses_by_cs_hostname";
    $args = [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'email' => $email,
            'cs_hostname' => $hostname
        ])
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        set_transient($cache_key, 'basic', HOUR_IN_SECONDS);
        return 'basic';
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['Licenses'])) {
        set_transient($cache_key, 'basic', HOUR_IN_SECONDS);
        return 'basic';
    }

    $licenses = wp_list_pluck($data['Licenses'], 'Sku');

    // Premium SKUs
    $premium_skus = ['premium', 'hippoopremium', 'hippoo_premium', '14-days-trial'];

    foreach ($licenses as $sku) {
        if (in_array($sku, $premium_skus)) {
            set_transient($cache_key, 'premium', 12 * HOUR_IN_SECONDS);
            return 'premium';
        }
    }

    set_transient($cache_key, 'basic', HOUR_IN_SECONDS);
    return 'basic';
}