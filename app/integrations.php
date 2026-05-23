<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooIntegrations
{
    public $namespace = 'hippoo-integrations/v1';
    
    const PRODUCTS_ENDPOINT = 'https://hippoo.app/wp-json/wc/store/v1/products?category=57&per_page=100';
    const LICENSE_ENDPOINT = 'https://hippoo.app/wp-json/woohouse/v1/get_licenses_by_cs_hostname';

    public function __construct()
    {
        add_filter('hippoo_settings_tabs', array($this, 'add_settings_tab'));
        add_filter('hippoo_settings_tab_contents', array($this, 'add_settings_tab_content'));

        add_action('wp_ajax_hippoo_get_integrations', array($this, 'ajax_get_integrations'));
        add_action('wp_ajax_hippoo_install_integration', array($this, 'ajax_install_integration'));

        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_filter('woocommerce_rest_is_request_to_rest_api', array($this, 'rest_use_wc_authentication'));
    }

    public function add_settings_tab($tabs)
    {
        $tabs['integrations'] = [
            'label'    => esc_html__('Hippoo Integrations', 'hippoo'),
            'priority' => 40,
        ];
        return $tabs;
    }

    public function add_settings_tab_content($contents)
    {
        $contents['integrations'] = function() {
            ob_start();
            ?>
            <div class="hippoo-integrations-tab">
                <h3 class="section-title"><?php esc_html_e('Hippoo Integrations', 'hippoo'); ?></h3>
                <p><?php esc_html_e('Integrations are free WordPress plugins that bring new features to both WooCommerce and the Hippoo App. They are completely free to install and use in WooCommerce. A Premium license is required to use these features inside the Hippoo App.', 'hippoo'); ?></p>
                <div id="hippoo-integrations-loading"></div>
                <div id="hippoo-integrations-list"></div>
            </div>
            <?php
            return ob_get_clean();
        };
        return $contents;
    }

    public function ajax_get_integrations()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');

        $products = self::get_products();
        if (!$products) {
            wp_send_json_error(__('Failed to fetch integrations.', 'hippoo'));
        }

        wp_send_json_success(
            $this->build_plugins_list($products)
        );
    }

    public function ajax_install_integration()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');

        $slug = sanitize_text_field($_POST['slug'] ?? '');
        if (empty($slug) || !$this->is_allowed_plugin($slug)) {
            wp_send_json_error(__('Invalid or unauthorized plugin.', 'hippoo'));
        }

        $plugin_file = $this->get_plugin_file_by_slug($slug);

        if ($plugin_file) {
            if (is_plugin_active($plugin_file)) {
                wp_send_json_success([
                    'status'  => 'active',
                    'message' => __('Already active.', 'hippoo'),
                ]);
            }

            $result = activate_plugin($plugin_file);
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }

            wp_send_json_success([
                'status'  => 'active',
                'message' => __('Activated successfully.', 'hippoo'),
            ]);
        }

        $installed = $this->install_plugin($slug);
        if (is_wp_error($installed)) {
            wp_send_json_error($installed->get_error_message());
        }

        wp_send_json_success([
            'status'  => 'installed',
            'message' => __('Installed successfully.', 'hippoo'),
        ]);
    }

    public function register_rest_routes()
    {
        register_rest_route($this->namespace, '/plugins-list', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_plugins_list'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => array(
                'page'     => array(
                    'type'        => 'integer',
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'default'     => 10,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
            ),
        ));

        register_rest_route($this->namespace, '/manage-plugin', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_manage_plugin'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args'                => array(
                'slug' => array(
                    'required'    => true,
                    'type'        => 'string',
                ),
                'action' => array(
                    'required'    => true,
                    'type'        => 'string',
                )
            ),
        ));
    }

    public function rest_use_wc_authentication($condition)
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $rest_prefix = trailingslashit(rest_get_url_prefix());
        $request_uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        
        // Allow the plugin use wc authentication methods.
        $hippoo = (false !== strpos($request_uri, $rest_prefix . $this->namespace));
        
        return $condition || $hippoo;
    }

    public function rest_permission_check($request)
    {
        return current_user_can('manage_options');
    }

    public function rest_plugins_list($request)
    {
        $products = self::get_products();
        if (!$products) {
            return new WP_Error('fetch_failed', __('Failed to fetch integrations.', 'hippoo'), ['status' => 500]);
        }

        $plugins = $this->build_plugins_list($products);

        $page     = max(1, (int) $request['page']);
        $per_page = max(1, min(100, (int) $request['per_page']));
        $offset   = ($page - 1) * $per_page;

        $total = count($plugins);
        $paginated_plugins = array_slice($plugins, $offset, $per_page);

        $response = rest_ensure_response($paginated_plugins);

        $response->header('X-WP-Total', (int) $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));

        return $response;
    }

    public function rest_manage_plugin($request)
    {
        $params = $request->get_json_params();

        $slug   = sanitize_text_field($params['slug'] ?? '');
        $action = sanitize_text_field($params['action'] ?? '');

        if (!$this->is_allowed_plugin($slug)) {
            return new WP_Error('forbidden', __('Invalid or unauthorized plugin.', 'hippoo'), ['status' => 403]);
        }

        $plugin_file = $this->get_plugin_file_by_slug($slug);

        switch ($action) {
            case 'install':
                if ($plugin_file) {
                    return new WP_Error('already_installed', __('Plugin already installed.', 'hippoo'), ['status' => 409]);
                }

                $installed = $this->install_plugin($slug);
                if (is_wp_error($installed)) {
                    return $installed;
                }

                return rest_ensure_response([
                    'status'  => 'installed',
                    'message' => __('Plugin installed successfully.', 'hippoo')
                ]);
            
            case 'activate':
                if (!$plugin_file) {
                    $installed = $this->install_plugin($slug);
                    if (is_wp_error($installed)) {
                        return $installed;
                    }

                    $plugin_file = $this->get_plugin_file_by_slug($slug);
                }

                if (is_plugin_active($plugin_file)) {
                    return rest_ensure_response([
                        'status'  => 'active',
                        'message' => __('Plugin already active.', 'hippoo')
                    ]);
                }

                $result = activate_plugin($plugin_file);
                if (is_wp_error($result)) {
                    return new WP_Error('activation_failed', $result->get_error_message(), ['status' => 500]);
                }

                return rest_ensure_response([
                    'status'  => 'active',
                    'message' => __('Plugin activated successfully.', 'hippoo')
                ]);
            
            case 'deactivate':
                if (!$plugin_file || !is_plugin_active($plugin_file)) {
                    return new WP_Error('not_active', __('Plugin is not active.', 'hippoo'), ['status' => 409]);
                }

                deactivate_plugins($plugin_file);

                if (is_plugin_active($plugin_file)) {
                    return new WP_Error('deactivation_failed', __('Failed to deactivate plugin.', 'hippoo'), ['status' => 500]);
                }

                return rest_ensure_response([
                    'status'  => 'deactivated',
                    'message' => __('Plugin deactivated successfully.', 'hippoo')
                ]);
        }

        return new WP_Error('bad_request', __('Invalid action.', 'hippoo'), ['status' => 400]);
    }

    public static function get_products()
    {
        $cache_key = 'hippoo_products';
        $products  = get_transient($cache_key);

        if ($products !== false) {
            return $products;
        }

        $response = wp_remote_get(self::PRODUCTS_ENDPOINT, ['timeout' => 30]);
        if (is_wp_error($response)) {
            return false;
        }

        $products = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($products)) {
            return false;
        }

        set_transient($cache_key, $products, 12 * HOUR_IN_SECONDS);

        return $products;
    }

    private function install_plugin($slug)
    {
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $result = $upgrader->install("https://downloads.wordpress.org/plugin/{$slug}.latest-stable.zip");

        if (!$result || is_wp_error($result)) {
            return new WP_Error('install_failed', $upgrader->skin->get_errors()->get_error_message() ?: __('Installation failed.', 'hippoo'), ['status' => 500]);
        }

        return true;
    }

    private function get_plugin_file_by_slug($slug)
    {
        $plugins = get_plugins();

        foreach ($plugins as $file => $data) {
            if (dirname($file) === $slug) {
                return $file;
            }
        }

        return false;
    }

    private function is_allowed_plugin($slug)
    {
        $products = self::get_products();
        if (!$products) {
            return false;
        }

        $allowed_slugs = array_column($products, 'slug');
        return in_array($slug, $allowed_slugs, true);
    }

    private function build_plugins_list(array $products)
    {
        $result = [];

        foreach ($products as $product) {
            $slug = $product['slug'] ?? '';
            if (empty($slug)) {
                continue;
            }

            $plugin_file = $this->get_plugin_file_by_slug($slug);
            $status = 'not_installed';

            if ($plugin_file && is_plugin_active($plugin_file)) {
                $status = 'active';
            } elseif ($plugin_file) {
                $status = 'installed';
            }

            $screenshots = array_values(array_filter(array_map(function ($img) {
                return $img['src'] ?? null;
            }, $product['images'] ?? [])));

            $main_image = $screenshots[0] ?? '';
            $screenshots = array_slice($screenshots, 1);

            $result[] = [
                'id'          => $product['id'],
                'name'        => wp_strip_all_tags($product['name']),
                'slug'        => $slug,
                'description' => wp_kses_post(
                    $product['short_description'] ?: $product['description']
                ),
                'status'      => $status,
                'plugin_file' => $plugin_file,
                'detail_url'  => "https://wordpress.org/plugins/{$slug}/",
                'image'       => $main_image,
                'screenshots' => $screenshots,
            ];
        }

        return $result;
    }
}

new HippooIntegrations();