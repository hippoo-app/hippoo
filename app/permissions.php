<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooPermissions
{
    public function __construct()
    {
        add_filter('hippoo_settings_tabs', array($this, 'add_settings_tab'));
        add_filter('hippoo_settings_tab_contents', array($this, 'add_settings_tab_content'));

        add_action('wp_ajax_hippoo_add_permission_role', array($this, 'ajax_add_permission_role'));
        add_action('wp_ajax_hippoo_save_permission_role', array($this, 'ajax_save_permission_role'));
        add_action('wp_ajax_hippoo_delete_permission_role', array($this, 'ajax_delete_permission_role'));

        add_action('rest_api_init', array($this, 'register_rest_filters'));
    }

    public function register_rest_filters()
    {
        // General
        add_filter('woocommerce_rest_check_permissions', array($this, 'override_woocommerce_permissions'), 9999, 4);
        add_filter('rest_pre_dispatch', array($this, 'block_unauthorized_access'), 9999, 3);

        // Orders
        add_filter('woocommerce_rest_shop_order_object_query', array($this, 'filter_orders_query'), 99, 2);
        add_filter('woocommerce_rest_prepare_shop_order_object', array($this, 'filter_orders_response'), 99, 3);
        add_filter('rest_request_after_callbacks', array($this, 'filter_order_count_response'), 99, 3);

        // Products
        add_filter('woocommerce_rest_product_object_query', array($this, 'filter_products_query'), 99, 2);
        add_filter('woocommerce_rest_prepare_product_object', array($this, 'filter_products_response'), 99, 3);

        // Customers
        add_filter('woocommerce_rest_prepare_customer', array($this, 'filter_customers_response'), 99, 3); 

        // Reviews
        add_filter('woocommerce_rest_prepare_product_review', array($this, 'filter_reviews_response'), 99, 3);

        // App features
        add_filter('hippoo_system_info_extensions', array($this, 'filter_system_info_response'), 99, 2);

        // Override permission callback for Hippoo extensions
        add_filter('hippoo_extension_permission_check', array($this, 'override_extension_permission_callback'), 10, 3);
    }

    public function add_settings_tab($tabs)
    {
        $tabs['permissions'] = [
            'label'    => esc_html__('Role & permissions', 'hippoo'),
            'priority' => 30,
        ];
        return $tabs;
    }

    public function add_settings_tab_content($contents)
    {
        $contents['permissions'] = function() {
            $license_status = hippoo_check_user_license();
            $settings = get_option('hippoo_permissions_settings', []);
            $roles = self::get_available_roles();
            ob_start();
            ?>
            <div class="hippoo-permissions-tab <?php echo ($license_status === 'basic') ? 'is-locked' : ''; ?>">
                <h3 class="section-title"><?php esc_html_e('Role & permissions', 'hippoo'); ?></h3>
                <p><?php esc_html_e('Control what data each user role can see in the Hippoo app, including orders, revenue, customers, and reviews and more. These settings are managed by admins and applied directly at the API level for better data security.', 'hippoo'); ?></p>
                
                <div class="permissions-select-role">
                    <label for="select-role"><?php esc_html_e('Select role', 'hippoo'); ?></label>
                    <div class="select-wrapper">
                        <select id="select-role">
                            <option value=""><?php esc_html_e('Select role to manage permission', 'hippoo'); ?></option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo esc_attr($role['key']); ?>" <?php echo $role['disabled'] ? 'disabled' : ''; ?>>
                                    <?php echo esc_html($role['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="permissions-list">
                    <?php
                    foreach ($settings as $role_key => $role_settings) {
                        $this->render_permission_card($role_key, $role_settings, false);
                    }
                    ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        };
        return $contents;
    }

    public function ajax_add_permission_role()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');

        $role_key = sanitize_key($_POST['role_key'] ?? '');
        if (!$role_key || !current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid request.', 'hippoo'));
        }

        $settings = get_option('hippoo_permissions_settings', []);
        if (isset($settings[$role_key])) {
            wp_send_json_error(__('Role already exists.', 'hippoo'));
        }

        $role_settings = [];
        $settings[$role_key] = $role_settings;
        update_option('hippoo_permissions_settings', $settings);

        ob_start();
        $this->render_permission_card($role_key, $role_settings, true);
        $card = ob_get_clean();

        wp_send_json_success(['card' => $card]);
    }

    public function ajax_save_permission_role()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');
        
        $role_key = sanitize_key($_POST['role_key'] ?? '');
        if (!$role_key || !current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid request.', 'hippoo'));
        }

        $settings = get_option('hippoo_permissions_settings', []);
        $posted_settings = $_POST['hippoo_permissions_settings'][$role_key] ?? [];

        $settings[$role_key] = $this->sanitize_role_settings($posted_settings);
        
        update_option('hippoo_permissions_settings', $settings);

        wp_send_json_success();
    }

    public function ajax_delete_permission_role()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');

        $role_key = sanitize_key($_POST['role_key'] ?? '');
        if (!$role_key || !current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid request.', 'hippoo'));
        }

        $settings = get_option('hippoo_permissions_settings', []);
        unset($settings[$role_key]);
        update_option('hippoo_permissions_settings', $settings);

        wp_send_json_success();
    }

    public function override_woocommerce_permissions($permission, $context, $object_id, $post_type)
    {
        $perms = self::get_user_permissions();

        // Admin or no restrictions - default permission
        if ($perms === null || $perms === false || !is_array($perms)) {
            return $permission;
        }

        $general_perms = $perms['general'] ?? [];

        if (!empty($general_perms['read_only'])) {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            if (!in_array($method, ['GET', 'HEAD'], true)) {
                return false; // Proper 403
            }
        }

        return true;
    }

    public function block_unauthorized_access($response, $server, $request)
    {
        $route = untrailingslashit($request->get_route());

        // Orders
        if (strpos($route, '/wc/v') === 0 && strpos($route, '/orders') !== false) {
            if (!$this->has_role_access('orders', 'access_orders')) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Order note
        elseif (strpos($route, '/wc/v') === 0 && strpos($route, '/orders/notes') !== false) {
            if (
                !$this->has_role_access('orders', 'access_orders') || 
                !$this->has_role_access('orders', 'order_details') || 
                !$this->has_role_access('orders', 'order_notes')
            ) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Invoice
        elseif (strpos($route, '/wc-hippoo-invoice/v') === 0 && strpos($route, '/invoice') !== false) {
            if (
                !$this->has_role_access('orders', 'access_orders') || 
                !$this->has_role_access('orders', 'order_details') || 
                !$this->has_role_access('orders', 'invoice')
            ) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Shipping label
        elseif (strpos($route, '/wc-hippoo-invoice/v') === 0 && strpos($route, '/shipping-label') !== false) {
            if (
                !$this->has_role_access('orders', 'access_orders') || 
                !$this->has_role_access('orders', 'order_details') || 
                !$this->has_role_access('orders', 'shipping_label')
            ) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Products
        elseif (strpos($route, '/wc/v') === 0 && strpos($route, '/products') !== false) {
            if (strpos($route, '/products/attributes') !== false) {
                if (!$this->has_role_access('products', 'access_attributes')) {
                    return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
                }
            }
            elseif (strpos($route, '/products/categories') !== false) {
                if (!$this->has_role_access('products', 'access_categories')) {
                    return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
                }
            }
            elseif (strpos($route, '/products/tags') !== false) {
                if (!$this->has_role_access('products', 'access_tags')) {
                    return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
                }
            }
            elseif (strpos($route, '/products/brands') !== false) {
                if (!$this->has_role_access('products', 'access_brands')) {
                    return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
                }
            }
            elseif (strpos($route, '/products/shipping_classes') !== false) {
                if (!$this->has_role_access('products', 'access_shipping_classes')) {
                    return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
                }
            }
            elseif (strpos($route, '/products/reviews') !== false) {
                if (!$this->has_role_access('reviews', 'access_reviews')) {
                    return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
                }
            }
            else {
                if (!$this->has_role_access('products', 'access_products')) {
                    return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
                }
            }
        }
        // Out of stock list
        elseif (strpos($route, '/wc-hippoo/v') === 0 && strpos($route, '/wc/stock') !== false) {
            if (
                !$this->has_role_access('products', 'access_products') || 
                !$this->has_role_access('products', 'out_of_stock_list')
            ) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Customers
        elseif (strpos($route, '/wc/v') === 0 && strpos($route, '/customers') !== false) {
            if (!$this->has_role_access('customers', 'access_customers')) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Reports
        elseif (strpos($route, '/wc/v') === 0 && strpos($route, '/reports') !== false) {
            if (!$this->has_role_access('analytics', 'show_sale_analytics')) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Coupons
        elseif (strpos($route, '/wc/v') === 0 && strpos($route, '/coupons') !== false) {
            if (!$this->has_role_access('coupons', 'access_coupons')) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Settings
        elseif (strpos($route, '/wc/v') === 0 && strpos($route, '/settings') !== false) {
            if (!$this->has_role_access('settings', 'show_shop_settings')) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        // Extensions
        elseif (strpos($route, '/wc-hippoo/v') === 0 && strpos($route, '/wp/system/info') !== false) {
            if (!$this->has_role_access('app_features', 'access_extensions')) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }
        elseif (strpos($route, '/wc-hippoo/v') === 0 && strpos($route, '/ext') !== false) {
            if (!$this->has_role_access('app_features', 'access_extensions')) {
                return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'hippoo'), ['status' => 403]);
            }
        }

        return $response;
    }
    
    public function filter_orders_query($args, $request)
    {
        if ($this->has_role_access('orders', 'allowed_status')) {
            $perms = self::get_user_permissions();
            $order_perms = $perms['orders'] ?? [];
            $allowed = (array) ($order_perms['allowed_status'] ?? []);

            $clean = array_filter(array_map(function($s) {
                return str_replace('wc-', '', trim($s));
            }, $allowed));

            if (!empty($clean)) {
                $args['status']      = $clean;
                $args['post_status'] = array_map(fn($s) => 'wc-' . $s, $clean);

                add_filter('woocommerce_order_query_args', function($q_args) use ($clean) {
                    $q_args['status']      = $clean;
                    $q_args['post_status'] = array_map(fn($s) => 'wc-' . $s, $clean);
                    return $q_args;
                }, 9999);
            }
        }

        return $args;
    }

    public function filter_orders_response($response, $order, $request)
    {
        $data = $response->get_data();

        if (!$this->has_role_access('orders', 'order_details')) {
            $data['billing']  = [];
            $data['shipping'] = [];

            $data['line_items']     = [];
            $data['fee_lines']      = [];
            $data['tax_lines']      = [];
            $data['shipping_lines'] = [];
            $data['coupon_lines']   = [];
            $data['refunds']        = [];
            $data['meta_data']      = [];

            $data['total']          = '0';
            $data['total_tax']      = '0';
            $data['discount_total'] = '0';
            $data['discount_tax']   = '0';
            $data['shipping_total'] = '0';
            $data['shipping_tax']   = '0';
            $data['cart_tax']       = '0';

            $data['status']         = '-';
            $data['customer_note']  = '-';
            $data['payment_method'] = '-';
            $data['payment_method_title'] = '-';
            $data['transaction_id'] = '-';
            $data['date_paid']      = null;

            $data['customer_id'] = 0;
            $data['customer_ip_address'] = '-';
            $data['customer_user_agent'] = '-';

            $response->set_data($data);
            return $response;
        }

        if (!$this->has_role_access('orders', 'order_totals')) {
            $data['total'] = '0';
            $data['total_tax'] = '0';
            $data['discount_total'] = '0';
            $data['discount_tax'] = '0';
            $data['shipping_total'] = '0';
            $data['shipping_tax'] = '0';
            $data['cart_tax'] = '0';
        }

        if (!$this->has_role_access('orders', 'name')) {
            $data['billing']['first_name'] = '-';
            $data['billing']['last_name'] = '-';
            $data['billing']['company'] = '-';
            $data['shipping']['first_name'] = '-';
            $data['shipping']['last_name'] = '-';
            $data['shipping']['company'] = '-';
        }

        if (!$this->has_role_access('orders', 'items')) {
            $data['line_items'] = [];
            $data['fee_lines'] = [];
        }

        if (!$this->has_role_access('orders', 'taxes')) {
            $data['tax_lines'] = [];
        }

        if (!$this->has_role_access('orders', 'shipping_info')) {
            $data['shipping_lines'] = [];
            $data['shipping']['address_1'] = '-';
            $data['shipping']['address_2'] = '-';
            $data['shipping']['city'] = '-';
            $data['shipping']['state'] = '-';
            $data['shipping']['postcode'] = '-';
            $data['shipping']['country'] = '-';
        }

        if (!$this->has_role_access('orders', 'order_statuses')) {
            $data['status'] = '-';
        }

        if (!$this->has_role_access('orders', 'coupon_info')) {
            $data['coupon_lines'] = [];
        }

        if (!$this->has_role_access('orders', 'payment_info')) {
            $data['payment_method'] = '-';
            $data['payment_method_title'] = '-';
            $data['transaction_id'] = '-';
            $data['date_paid'] = null;
        }

        if (!$this->has_role_access('orders', 'customer_info')) {
            $data['customer_id'] = 0;
            $data['billing']['email'] = '-';
            $data['billing']['phone'] = '-';
            $data['billing']['address_1'] = '-';
            $data['billing']['address_2'] = '-';
            $data['billing']['city'] = '-';
            $data['billing']['state'] = '-';
            $data['billing']['postcode'] = '-';
            $data['billing']['country'] = '-';
            $data['customer_ip_address'] = '-';
            $data['customer_user_agent'] = '-';
        }

        if (!$this->has_role_access('orders', 'custom_fields')) {
            $data['meta_data'] = [];
        }

        if (!$this->has_role_access('orders', 'customer_note')) {
            $data['customer_note'] = '-';
        }

        if (!$this->has_role_access('orders', 'order_totals')) {
            $data['refunds'] = [];
        }

        $response->set_data($data);
        return $response;
    }

    public function filter_order_count_response($response, $handler, $request)
    {
        $route = untrailingslashit($request->get_route());

        if (strpos($route, '/wc/v') === 0 && strpos($route, '/orders') !== false) {
            if (is_wp_error($response)) {
                return $response;
            }

            if (!$this->has_role_access('orders', 'order_count')) {
                $response->header('X-WP-Total', '0');
                $response->header('X-WP-TotalPages', '1');
            }
        }

        return $response;
    }

    public function filter_products_query($args, $request)
    {
        $perms = self::get_user_permissions();
        $prod_perms = $perms['products'] ?? [];

        if (!isset($args['tax_query'])) {
            $args['tax_query'] = [];
        }

        if (
            $this->has_role_access('products', 'categories') &&
            !empty($prod_perms['categories'])
        ) {
            $args['tax_query'][] = [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => (array) ($prod_perms['categories'] ?? []),
                'include_children' => true,
            ];
        }

        if (
            $this->has_role_access('products', 'types') &&
            !empty($prod_perms['types'])
        ) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => (array) ($prod_perms['types'] ?? []),
            ];
        }

        return $args;
    }

    public function filter_products_response($response, $server, $request)
    {
        $data = $response->get_data();

        if (!$this->has_role_access('products', 'product_name')) {
            $data['name'] = '-';
        }

        if (!$this->has_role_access('products', 'prices')) {
            $data['price']         = '0';
            $data['regular_price'] = '0';
            $data['sale_price']    = '0';
        }

        if (!$this->has_role_access('products', 'stock_quantity')) {
            $data['stock_quantity'] = null;
            $data['manage_stock']   = false;
            $data['stock_status']   = '-';
        }

        if (!$this->has_role_access('products', 'sku')) {
            $data['sku'] = '-';
        }

        if (!$this->has_role_access('products', 'status')) {
            $data['status'] = '-';
        }

        $response->set_data($data);
        return $response;
    }

    public function filter_customers_response($response, $server, $request)
    {
        $data = $response->get_data();

        if (!$this->has_role_access('customers', 'name')) {
            $data['first_name'] = '-';
            $data['last_name'] = '-';
        }

        if (!$this->has_role_access('customers', 'address')) {
            $data['billing']['address_1'] = '-';
            $data['billing']['address_2'] = '-';
            $data['billing']['country'] = '-';
            $data['billing']['state'] = '-';
            $data['billing']['city'] = '-';
            $data['billing']['postcode'] = '-';

            $data['shipping']['address_1'] = '-';
            $data['shipping']['address_2'] = '-';
            $data['shipping']['country'] = '-';
            $data['shipping']['state'] = '-';
            $data['shipping']['city'] = '-';
            $data['shipping']['postcode'] = '-';
        }

        if (!$this->has_role_access('customers', 'phone')) {
            $data['billing']['phone'] = '-';
            $data['shipping']['phone'] = '-';
        }

        if (!$this->has_role_access('customers', 'email')) {
            $data['email'] = '-';
            $data['billing']['email'] = '-';
        }

        $response->set_data($data);
        return $response;
    }

    public function filter_reviews_response($response, $server, $request)
    {
        $data = $response->get_data();

        if (!$this->has_role_access('reviews', 'reviewer_name')) {
            $data['reviewer'] = '-';
            $data['reviewer_email'] = '-';
        }

        if (!$this->has_role_access('reviews', 'review_content')) {
            $data['review'] = '-';
        }

        $response->set_data($data);
        return $response;
    }

    public function filter_system_info_response($plugins_info, $request)
    {
        if (strpos($request->get_route(), '/wc-hippoo/v1/wp/system/info') !== 0) {
            return $plugins_info;
        }

        if (!$this->has_role_access('app_features', 'access_extensions')) {
            return [];
        }

        $perms = self::get_user_permissions();
        $allowed_slugs = $perms['app_features']['extensions'] ?? [];

        if (empty($allowed_slugs)) {
            return $plugins_info;
        }

        $filtered = array_filter($plugins_info, function ($ext) use ($allowed_slugs) {
            $slug = $ext['slug'] ?? '';
            return in_array($slug, $allowed_slugs, true);
        });

        return array_values($filtered);
    }

    public function override_extension_permission_callback($default_callback, $route, $handler)
    {
        if (!$this->has_role_access('app_features', 'access_extensions')) {
            return $default_callback;
        }

        $parts = explode('/', trim($route, '/'));
        $extension_slug = $parts[0] ?? '';
        
        $perms = self::get_user_permissions();
        $allowed_slugs = $perms['app_features']['extensions'] ?? [];

        if (empty($allowed_slugs)) {
            return '__return_true';
        }

        if (in_array($extension_slug, $allowed_slugs)) {
            return '__return_true';
        }

        return $default_callback;
    }

    public static function get_available_roles()
    {
        $wp_roles = wp_roles()->roles;
        $settings = get_option('hippoo_permissions_settings', []);

        $available_roles = [];

        foreach ($wp_roles as $role_key => $role_data) {
            if ($role_key === 'administrator') {
                continue;
            }

            $is_disabled = isset($settings[$role_key]);

            $available_roles[] = [
                'key'      => $role_key,
                'name'     => $role_data['name'] ?? ucfirst($role_key),
                'disabled' => $is_disabled,
            ];
        }

        usort($available_roles, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $available_roles;
    }

    public static function get_user_permissions()
    {
        $user = wp_get_current_user();
        
        if (empty($user) || !$user->exists() || !is_user_logged_in()) {
            return false;
        }

        if (in_array('administrator', (array) $user->roles)) {
            return null; // Full access
        }

        $settings = get_option('hippoo_permissions_settings', []);
        foreach ((array) $user->roles as $role) {
            if (isset($settings[$role])) {
                return $settings[$role];
            }
        }

        return false; // No access
    }

    private function has_role_access($section, $key = null)
    {
        $perms = self::get_user_permissions();

        if ($perms === null) {
            return true; // admin
        }

        if ($perms === false || !is_array($perms)) {
            return false;
        }

        if (empty($perms['general']['enable_access'])) {
            return false;
        }

        if (!isset($perms[$section])) {
            return false;
        }

        if ($key === null) {
            return true;
        }

        return !empty($perms[$section][$key]);
    }

    private function sanitize_role_settings($data)
    {
        $sanitized = [];

        // General
        $sanitized['general'] = [
            'enable_access' => isset($data['general']['enable_access']) ? 1 : 0,
            'read_only'     => isset($data['general']['read_only']) ? 1 : 0,
        ];

        // Orders
        $sanitized['orders'] = [
            'access_orders'   => isset($data['orders']['access_orders']) ? 1 : 0,
            'order_count'     => isset($data['orders']['order_count']) ? 1 : 0,
            'order_totals'    => isset($data['orders']['order_totals']) ? 1 : 0,
            'order_details'   => isset($data['orders']['order_details']) ? 1 : 0,

            'name'            => isset($data['orders']['name']) ? 1 : 0,
            'items'           => isset($data['orders']['items']) ? 1 : 0,
            'taxes'           => isset($data['orders']['taxes']) ? 1 : 0,
            'shipping_info'   => isset($data['orders']['shipping_info']) ? 1 : 0,
            'order_statuses'  => isset($data['orders']['order_statuses']) ? 1 : 0,
            'coupon_info'     => isset($data['orders']['coupon_info']) ? 1 : 0,
            'payment_info'    => isset($data['orders']['payment_info']) ? 1 : 0,
            'customer_info'   => isset($data['orders']['customer_info']) ? 1 : 0,
            'order_notes'     => isset($data['orders']['order_notes']) ? 1 : 0,
            'custom_fields'   => isset($data['orders']['custom_fields']) ? 1 : 0,
            'customer_note'   => isset($data['orders']['customer_note']) ? 1 : 0,
            'invoice'         => isset($data['orders']['invoice']) ? 1 : 0,
            'shipping_label'  => isset($data['orders']['shipping_label']) ? 1 : 0,

            'allowed_status'  => isset($data['orders']['allowed_status']) && is_array($data['orders']['allowed_status'])
                ? array_map('sanitize_text_field', $data['orders']['allowed_status'])
                : [],
        ];

        // Products
        $sanitized['products'] = [
            'access_products'    => isset($data['products']['access_products']) ? 1 : 0,
            'product_name'       => isset($data['products']['product_name']) ? 1 : 0,
            'prices'             => isset($data['products']['prices']) ? 1 : 0,
            'stock_quantity'     => isset($data['products']['stock_quantity']) ? 1 : 0,
            'out_of_stock_list'  => isset($data['products']['out_of_stock_list']) ? 1 : 0,
            'sku'                => isset($data['products']['sku']) ? 1 : 0,
            'status'             => isset($data['products']['status']) ? 1 : 0,

            'categories'         => isset($data['products']['categories']) && is_array($data['products']['categories'])
                ? array_map('absint', $data['products']['categories'])
                : [],

            'types'              => isset($data['products']['types']) && is_array($data['products']['types'])
                ? array_map('sanitize_text_field', $data['products']['types'])
                : [],
            
            'access_categories'  => isset($data['products']['access_categories']) ? 1 : 0,
            'access_attributes'  => isset($data['products']['access_attributes']) ? 1 : 0,
            'access_tags'        => isset($data['products']['access_tags']) ? 1 : 0,
            'access_brands'      => isset($data['products']['access_brands']) ? 1 : 0,
            'access_shipping_classes' => isset($data['products']['access_shipping_classes']) ? 1 : 0,
        ];

        // Customers
        $sanitized['customers'] = [
            'access_customers'   => isset($data['customers']['access_customers']) ? 1 : 0,
            'name'               => isset($data['customers']['name']) ? 1 : 0,
            'address'            => isset($data['customers']['address']) ? 1 : 0,
            'phone'              => isset($data['customers']['phone']) ? 1 : 0,
            'email'              => isset($data['customers']['email']) ? 1 : 0,
        ];

        // Reviews
        $sanitized['reviews'] = [
            'access_reviews'     => isset($data['reviews']['access_reviews']) ? 1 : 0,
            'reviewer_name'      => isset($data['reviews']['reviewer_name']) ? 1 : 0,
            'review_content'     => isset($data['reviews']['review_content']) ? 1 : 0,
        ];

        // Reports
        $sanitized['analytics'] = [
            'show_sale_analytics' => isset($data['analytics']['show_sale_analytics']) ? 1 : 0,
        ];

        // Coupons
        $sanitized['coupons'] = [
            'access_coupons'      => isset($data['coupons']['access_coupons']) ? 1 : 0,
        ];

        // Settings
        $sanitized['settings'] = [
            'show_shop_settings' => isset($data['settings']['show_shop_settings']) ? 1 : 0,
        ];

        // App features
        $sanitized['app_features'] = [
            'access_extensions'  => isset($data['app_features']['access_extensions']) ? 1 : 0,
            'extensions'         => isset($data['app_features']['extensions']) && is_array($data['app_features']['extensions'])
                ? array_map('sanitize_text_field', $data['app_features']['extensions'])
                : [],
        ];

        return $sanitized;
    }

    private function render_permission_card($role_key, $role_settings = [], $expanded = false)
    {
        $role_name = wp_roles()->get_names()[$role_key] ?? ucfirst($role_key);
        ?>
        <div class="permission-block" data-role="<?php echo esc_attr($role_key); ?>" data-role-name="<?php echo esc_attr($role_name); ?>">
            <div class="permission-header">
                <div class="role-name"><?php
                    /* translators: %s: role name */
                    printf(esc_html__('%s permissions', 'hippoo'), esc_html($role_name));
                ?></div>
                <div class="header-actions">
                    <a href="#" class="remove-role"><?php esc_html_e('Remove', 'hippoo'); ?></a>
                    <span class="accordion-toggle <?php echo $expanded ? 'open' : ''; ?>"></span>
                </div>
            </div>

            <div class="permission-content" <?php echo $expanded ? 'style="display:block;"' : 'style="display:none;"'; ?>>
                <form class="hippoo-permission-form" method="post">
                    <input type="hidden" name="action" value="hippoo_save_permission_role">
                    <input type="hidden" name="role_key" value="<?php echo esc_attr($role_key); ?>">
                    <?php wp_nonce_field('hippoo_nonce'); ?>
                    
                    <!-- General -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('General', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][general][enable_access]" <?php checked($role_settings['general']['enable_access'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Enable access for this role', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][general][read_only]" <?php checked($role_settings['general']['read_only'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Read-only mode', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Orders -->
                    <div class="permission-section">
                        <div class="permission-label">
                            <div class="permission-title"><?php esc_html_e('Orders', 'hippoo'); ?></div>
                            <div class="permission-label-actions">
                                <a href="#" class="select-all-btn"><?php esc_html_e('Select all', 'hippoo'); ?></a>
                                <span class="separator"> / </span>
                                <a href="#" class="deselect-all-btn"><?php esc_html_e('Deselect all', 'hippoo'); ?></a>
                            </div>
                        </div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][access_orders]" <?php checked($role_settings['orders']['access_orders'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access orders', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][order_count]" <?php checked($role_settings['orders']['order_count'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Order count', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][order_totals]" <?php checked($role_settings['orders']['order_totals'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Order totals', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][order_details]" <?php checked($role_settings['orders']['order_details'] ?? 0, 1); ?> value="1" class="sub-toggle">
                            <?php esc_html_e('Order details', 'hippoo'); ?>
                        </label>

                        <div class="sub-details">
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][name]" <?php checked($role_settings['orders']['name'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Name', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][items]" <?php checked($role_settings['orders']['items'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Items', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][taxes]" <?php checked($role_settings['orders']['taxes'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Taxes', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][shipping_info]" <?php checked($role_settings['orders']['shipping_info'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Shipping info', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][order_statuses]" <?php checked($role_settings['orders']['order_statuses'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Order statuses', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][coupon_info]" <?php checked($role_settings['orders']['coupon_info'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Coupon info', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][payment_info]" <?php checked($role_settings['orders']['payment_info'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Payment info', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][customer_info]" <?php checked($role_settings['orders']['customer_info'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Customer info', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][order_notes]" <?php checked($role_settings['orders']['order_notes'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Order note', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][custom_fields]" <?php checked($role_settings['orders']['custom_fields'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Order custom fields', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][customer_note]" <?php checked($role_settings['orders']['customer_note'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Customer note', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][invoice]" <?php checked($role_settings['orders']['invoice'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Invoice', 'hippoo'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][shipping_label]" <?php checked($role_settings['orders']['shipping_label'] ?? 0, 1); ?> value="1">
                                <?php esc_html_e('Shipping label', 'hippoo'); ?>
                            </label>
                        </div>

                        <div class="multi-select-group">
                            <label><?php esc_html_e('Allowed order status', 'hippoo'); ?></label>
                            <select name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][orders][allowed_status][]" multiple class="hippoo-select2">
                                <?php
                                $statuses = wc_get_order_statuses();
                                $selected_statuses = $role_settings['orders']['allowed_status'] ?? [];
                                foreach ($statuses as $status_key => $status_label) {
                                    $sel = in_array($status_key, $selected_statuses) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($status_key) . '" ' . esc_attr( $sel ) . '>' . esc_html($status_label) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <hr>

                    <!-- Products -->
                    <div class="permission-section">
                        <div class="permission-label">
                            <div class="permission-title"><?php esc_html_e('Products', 'hippoo'); ?></div>
                            <div class="permission-label-actions">
                                <a href="#" class="select-all-btn"><?php esc_html_e('Select all', 'hippoo'); ?></a>
                                <span class="separator"> / </span>
                                <a href="#" class="deselect-all-btn"><?php esc_html_e('Deselect all', 'hippoo'); ?></a>
                            </div>
                        </div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][access_products]" <?php checked($role_settings['products']['access_products'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access products', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][product_name]" <?php checked($role_settings['products']['product_name'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Product name', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][prices]" <?php checked($role_settings['products']['prices'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Prices', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][stock_quantity]" <?php checked($role_settings['products']['stock_quantity'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Stock quantity', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][out_of_stock_list]" <?php checked($role_settings['products']['out_of_stock_list'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Out of stock list', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][sku]" <?php checked($role_settings['products']['sku'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('SKU', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][status]" <?php checked($role_settings['products']['status'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Status', 'hippoo'); ?>
                        </label>

                        <div class="multi-select-group">
                            <label><?php esc_html_e('Limit to categories', 'hippoo'); ?></label>
                            <select name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][categories][]" multiple class="hippoo-select2">
                                <?php
                                $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'id=>name']);
                                $selected_cats = $role_settings['products']['categories'] ?? [];
                                foreach ($categories as $cat_id => $cat_name) {
                                    $sel = in_array($cat_id, $selected_cats) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($cat_id) . '" ' . esc_attr( $sel ) . '>' . esc_html($cat_name) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="multi-select-group">
                            <label><?php esc_html_e('Limit to product type', 'hippoo'); ?></label>
                            <select name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][types][]" multiple class="hippoo-select2">
                                <?php
                                $product_types = wc_get_product_types();
                                $selected_types = $role_settings['products']['types'] ?? [];
                                foreach ($product_types as $type_key => $type_label) {
                                    $sel = in_array($type_key, $selected_types) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($type_key) . '" ' . esc_attr( $sel ) . '>' . esc_html($type_label) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <hr>

                    <!-- Customers -->
                    <div class="permission-section">
                        <div class="permission-label">
                            <div class="permission-title"><?php esc_html_e('Customers', 'hippoo'); ?></div>
                            <div class="permission-label-actions">
                                <a href="#" class="select-all-btn"><?php esc_html_e('Select all', 'hippoo'); ?></a>
                                <span class="separator"> / </span>
                                <a href="#" class="deselect-all-btn"><?php esc_html_e('Deselect all', 'hippoo'); ?></a>
                            </div>
                        </div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][customers][access_customers]" <?php checked($role_settings['customers']['access_customers'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access customers', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][customers][name]" <?php checked($role_settings['customers']['name'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Name', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][customers][address]" <?php checked($role_settings['customers']['address'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Address', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][customers][phone]" <?php checked($role_settings['customers']['phone'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Phone number', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][customers][email]" <?php checked($role_settings['customers']['email'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Email', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Reviews -->
                    <div class="permission-section">
                        <div class="permission-label">
                            <div class="permission-title"><?php esc_html_e('Reviews', 'hippoo'); ?></div>
                            <div class="permission-label-actions">
                                <a href="#" class="select-all-btn"><?php esc_html_e('Select all', 'hippoo'); ?></a>
                                <span class="separator"> / </span>
                                <a href="#" class="deselect-all-btn"><?php esc_html_e('Deselect all', 'hippoo'); ?></a>
                            </div>
                        </div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][reviews][access_reviews]" <?php checked($role_settings['reviews']['access_reviews'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access reviews', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][reviews][reviewer_name]" <?php checked($role_settings['reviews']['reviewer_name'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Reviewer name', 'hippoo'); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][reviews][review_content]" <?php checked($role_settings['reviews']['review_content'] ?? 0, 1); ?> value="1">
                            <?php esc_html_e('Review content', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Reports -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Sale analytics', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][analytics][show_sale_analytics]" <?php checked($role_settings['analytics']['show_sale_analytics'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Show sale analytics', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Coupons -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Coupons', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][coupons][access_coupons]" <?php checked($role_settings['coupons']['access_coupons'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access coupons', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Product categories -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Product categories', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][access_categories]" <?php checked($role_settings['products']['access_categories'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access product categories', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Product attributes -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Product attributes', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][access_attributes]" <?php checked($role_settings['products']['access_attributes'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access product attributes', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Product tags -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Product tags', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][access_tags]" <?php checked($role_settings['products']['access_tags'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access product tags', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Product brands -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Product brands', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][access_brands]" <?php checked($role_settings['products']['access_brands'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access product brands', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Product shipping classes -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Product shipping classes', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][products][access_shipping_classes]" <?php checked($role_settings['products']['access_shipping_classes'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access product shipping classes', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- Settings -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('Settings', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][settings][show_shop_settings]" <?php checked($role_settings['settings']['show_shop_settings'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Show shop settings', 'hippoo'); ?>
                        </label>
                    </div>
                    <hr>

                    <!-- App features / Extensions -->
                    <div class="permission-section">
                        <div class="permission-label"><?php esc_html_e('App features', 'hippoo'); ?></div>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][app_features][access_extensions]" <?php checked($role_settings['app_features']['access_extensions'] ?? 0, 1); ?> value="1" class="section-toggle">
                            <?php esc_html_e('Access extensions', 'hippoo'); ?>
                        </label>

                        <div class="multi-select-group">
                            <label><?php esc_html_e('Limit to selected extensions', 'hippoo'); ?></label>
                            <select name="hippoo_permissions_settings[<?php echo esc_attr($role_key); ?>][app_features][extensions][]" multiple class="hippoo-select2">
                                <?php
                                $extensions = HippooIntegrations::get_products();
                                $selected_ext = $role_settings['app_features']['extensions'] ?? [];
                                if ($extensions) {
                                    foreach ($extensions as $extension) {
                                        $sel = in_array($extension['slug'], $selected_ext) ? 'selected' : '';
                                        echo '<option value="' . esc_attr($extension['slug']) . '" ' . esc_attr( $sel ) . '>' . esc_html($extension['name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="permission-actions">
                        <div class="notice inline save-settings-notice">
                            <p><?php esc_html_e('Settings updated successfully.', 'hippoo'); ?></p>
                        </div>
                        <button type="submit" class="button button-primary save-role-settings">
                            <?php esc_html_e('Save changes', 'hippoo'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="delete-confirm-modal" style="display:none;">
                <div class="modal-content">
                    <span class="close-delete-modal"></span>
                    <h4><?php
                        /* translators: %s: role name */
                        printf(esc_html__('Delete %s role permissions?', 'hippoo'), esc_html($role_name));
                    ?></h4>
                    <p><?php esc_html_e('This will remove all custom access rules for this role. This action can’t be undone.', 'hippoo'); ?></p>
                    <div class="modal-actions">
                        <button class="button cancel-delete"><?php esc_html_e('Cancel', 'hippoo'); ?></button>
                        <button class="button confirm-delete"><?php esc_html_e('Delete', 'hippoo'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

new HippooPermissions();