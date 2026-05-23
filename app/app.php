<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Removed load_plugin_textdomain as it's automatically handled by WordPress.org for plugins
// add_action('plugins_loaded', 'hippoo_load_textdomain');

function hippoo_page_style( $hook ) {
    wp_deregister_script('select2');
    wp_deregister_style('select2');

    wp_enqueue_style('select2', hippoo_url . 'css/select2.min.css', [], '4.0.13');
    wp_enqueue_script('select2', hippoo_url . 'js/select2.min.js', [ 'jquery' ], '4.0.13', true);

    wp_enqueue_style('hippoo-main-page-style', hippoo_url . 'css/style.css', null, hippoo_version);
    wp_enqueue_style('hippoo-main-admin-style', hippoo_url . 'css/admin-style.css', null, hippoo_version);

    wp_enqueue_script('hippoo-main-scripts', hippoo_url . 'js/admin-script.js', [ 'jquery', 'jquery-ui-core', 'jquery-ui-tooltip' ], hippoo_version, true);
    wp_localize_script('hippoo-main-scripts', 'hippoo', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('hippoo_nonce')
    ]);
}
add_action( 'admin_enqueue_scripts', 'hippoo_page_style' );

// Invoice
define('HIPPOO_INVOICE_PLUGIN_PATH', hippoo_path . 'invoice/');
define('HIPPOO_INVOICE_PLUGIN_URL', plugins_url('hippoo') . '/invoice/');

$options = get_option('hippoo_settings');
if (isset($options['invoice_plugin_enabled']) && $options['invoice_plugin_enabled']) {
    require_once HIPPOO_INVOICE_PLUGIN_PATH . 'main.php';
}


// Initialize Hippoo settings with defaults
add_action('init', 'init_setting');
function init_setting($request) {
    $settings = get_option('hippoo_settings');

    if (empty($settings)) {
        $settings = [];
    }

    // Define default settings with explicit data types
    $default_settings = [
        'invoice_plugin_enabled' => false,
        'send_notification_wc-processing' => true,
        'image_optimization_enabled' => true,
        'image_size_selection' => 'large',
    ];

    if (function_exists('wc_get_order_statuses')) {
        $order_statuses = wc_get_order_statuses();
        foreach ($order_statuses as $status_key => $status_label) {
            $key = 'send_notification_' . $status_key;
            if (!array_key_exists($key, $default_settings)) {
                $default_settings[$key] = false;
            }
        }
    }

    $settings = array_merge($default_settings, $settings);

    $settings = array_map(function($value) {
        return ($value === '1') ? true : (($value === '0') ? false : $value);
    }, $settings);

    update_option('hippoo_settings', $settings);
}


// Add custom action links to the Hippoo plugin
function hippoo_add_plugin_action_links($links) {
    $custom_links = array(
        'help_center' => '<a href="https://hippoo.app/docs" target="_blank">' . __('Help Center', 'hippoo') . '</a>',
        'feature_request' => '<a href="https://hippoo.canny.io/feature-request/" target="_blank">' . __('Feature Request', 'hippoo') . '</a>',
        'customer_support' => '<a href="mailto:feedback@hippoo.app">' . __('Customer Support', 'hippoo') . '</a>',
    );

    return array_merge($custom_links, $links);
}
add_filter('plugin_action_links_hippoo/hippoo.php', 'hippoo_add_plugin_action_links');


// Dashboard widget
function hippoo_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'hippoo_blog_feed',
        __('Latest blog posts on Hippoo', 'hippoo'),
        'hippoo_render_dashboard_widget_content'
    );
}
add_action('wp_dashboard_setup', 'hippoo_add_dashboard_widget');

function hippoo_render_dashboard_widget_content() {
    wp_widget_rss_output( [
        'url'          => 'https://hippoo.app/category/blog/feed/',
        'title'        => __('Latest blog posts on Hippoo', 'hippoo'),
        'items'        => 3,
        'show_summary' => 1,
        'show_author'  => 0,
        'show_date'    => 0,
    ] );
    ?>
    <div style="border-top: 1px solid #e7e7e7; padding-top: 12px !important; font-size: 14px;">
        <a href="https://hippoo.app/category/blog/" target="_blank"><?php esc_html_e('Read more on our blog', 'hippoo'); ?></a>
    </div>
    <?php
}


// Displays a review request banner after two weeks of plugin activation
function hippoo_banner_activation_hook() {
    if (!get_option('hippoo_activation_time')) {
        update_option('hippoo_activation_time', time());
    }
}
register_activation_hook(hippoo_main_file_path, 'hippoo_banner_activation_hook');

function hippoo_display_review_banner() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (get_option('hippoo_review_dismissed')) {
        return;
    }

    $activation_time = get_option('hippoo_activation_time', 0);
    $two_weeks_ago = time() - (2 * WEEK_IN_SECONDS);
    if ($activation_time > $two_weeks_ago) {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible hippoo-review-banner">
        <p><?php esc_html_e('Enjoying the Hippoo Mobile App for WooCommerce? We would love to hear your feedback! Please take a moment to leave a review.', 'hippoo'); ?></p>
        <p>
            <a href="https://wordpress.org/support/plugin/hippoo/reviews/#new-post" target="_blank" class="button button-primary"><?php esc_html_e('Leave a Review', 'hippoo'); ?></a>
            <button class="button hippoo-dismiss-review"><?php esc_html_e('Dismiss', 'hippoo'); ?></button>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'hippoo_display_review_banner');

function hippoo_dismiss_review_banner() {
    check_ajax_referer('hippoo_nonce', 'nonce');
    update_option('hippoo_review_dismissed', true);
    wp_send_json_success();
}
add_action('wp_ajax_hippoo_dismiss_review', 'hippoo_dismiss_review_banner');
add_action('wp_ajax_nopriv_hippoo_dismiss_review', 'hippoo_dismiss_review_banner');


// Display REST API error notice
function hippoo_check_rest_activation_hook() {
    $status = hippoo_check_rest_api_status();
    update_option('hippoo_rest_api_last_status', $status);
    delete_option('hippoo_rest_api_error_dismissed');
}
register_activation_hook(hippoo_main_file_path, 'hippoo_check_rest_activation_hook');

function hippoo_display_rest_api_error_banner() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $is_settings_page = (isset($_GET['page']) && $_GET['page'] === 'hippoo_setting_page');

    if (!$is_settings_page && get_option('hippoo_rest_api_error_dismissed')) {
        return;
    }

    if ($is_settings_page) {
        $api_status = hippoo_check_rest_api_status();
        update_option('hippoo_rest_api_last_status', $api_status);
    } else {
        $api_status = get_option('hippoo_rest_api_last_status');
    }

    if (empty($api_status) || $api_status['status'] !== 'error') {
        return;
    }
    ?>
    <div class="notice notice-error <?php echo $is_settings_page ? '' : 'is-dismissible'; ?> hippoo-rest-api-error">
        <div class="logo-wrapper">
            <img src="<?php echo esc_url(hippoo_url . 'images/icon.png'); ?>" alt="<?php esc_attr_e('Hippoo Logo', 'hippoo'); ?>" class="hippoo-logo">
        </div>
        <div class="content">
            <h4><?php esc_html_e('Hippoo app can’t connect to your WooCommerce', 'hippoo'); ?></h4>
            <p><?php echo wp_kses_post($api_status['message']); ?></p>
        </div>
        <div class="actions">
            <?php if (!$is_settings_page) : ?>
                <button class="button hippoo-dismiss-api-error"><?php esc_html_e('Dismiss', 'hippoo'); ?></button>
            <?php endif; ?>
            <button class="button hippoo-retry-api-check"><?php esc_html_e('Try again', 'hippoo'); ?></button>
        </div>
    </div>
    <?php
}
add_action('admin_notices', 'hippoo_display_rest_api_error_banner');

function hippoo_retry_api_check() {
    check_ajax_referer('hippoo_nonce', 'nonce');

    $api_status = hippoo_check_rest_api_status();
    update_option('hippoo_rest_api_last_status', $api_status);

    if ($api_status['status'] === 'success') {
        delete_option('hippoo_rest_api_error_dismissed');
    }

    wp_send_json($api_status);
}
add_action('wp_ajax_hippoo_retry_api_check', 'hippoo_retry_api_check');

function hippoo_dismiss_api_error() {
    check_ajax_referer('hippoo_nonce', 'nonce');
    update_option('hippoo_rest_api_error_dismissed', true);
    wp_send_json_success();
}
add_action('wp_ajax_hippoo_dismiss_api_error', 'hippoo_dismiss_api_error');


// Display Premium Upgrade banner
function hippoo_display_upgrade_banner() {
    $license_status = hippoo_check_user_license();
    $email = get_option('admin_email');
    $hostname = wp_parse_url(home_url(), PHP_URL_HOST);

    if ($license_status === 'basic') : ?>
        <div class="hippoo-upgrade-banner">
            <div class="logo-wrapper">
                <img src="<?php echo esc_url(hippoo_url . 'images/premium.png'); ?>" class="hippoo-logo" />
            </div>

            <div class="content">
                <h4><?php esc_html_e('Upgrade to Hippoo Premium', 'hippoo'); ?></h4>
                <p><?php esc_html_e('Upgrade to Hippoo Premium for custom event notifications, full extension access, Shippo integration, advanced analytics, and smart AI tools.', 'hippoo'); ?></p>
            </div>

            <div class="actions">
                <a href="https://hippoo.app/2024/07/07/hippoo-premium-everything-you-need-to-know-about-the-upcoming-release/" 
                   target="_blank" 
                   class="button learn-more">
                    <?php esc_html_e('Learn more', 'hippoo'); ?>
                </a>
                <a href="https://hippoo.app/checkout/?add-to-cart=1999&hippoo_email=<?php echo esc_attr(urlencode($email)); ?>&cs_hostname=<?php echo esc_attr(urlencode($hostname)); ?>" 
                   target="_blank" 
                   class="button upgrade-btn">
                    <?php esc_html_e('Upgrade', 'hippoo'); ?>
                </a>
            </div>
        </div>
    <?php 
    endif;
}
add_action('hippoo_before_settings_page', 'hippoo_display_upgrade_banner');