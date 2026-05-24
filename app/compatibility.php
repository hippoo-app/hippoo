<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooCompatibility
{
    private static $product_types = [
        // String
        'name' => 'string', 'slug' => 'string', 'permalink' => 'string', 'type' => 'string',
        'status' => 'string', 'description' => 'string', 'short_description' => 'string',
        'sku' => 'string', 'price' => 'string', 'regular_price' => 'string', 'sale_price' => 'string',
        'tax_status' => 'string', 'tax_class' => 'string', 'backorders' => 'string',
        'external_url' => 'string', 'button_text' => 'string', 'purchase_note' => 'string',
        'weight' => 'string', 'shipping_class' => 'string', 'stock_status' => 'string',
        'catalog_visibility' => 'string', 'price_html' => 'string', 'post_password' => 'string',
        'global_unique_id' => 'string', 'date_created' => 'string', 'date_created_gmt' => 'string',
        'date_modified' => 'string', 'date_modified_gmt' => 'string',
        // Bool
        'featured' => 'bool', 'on_sale' => 'bool', 'purchasable' => 'bool', 'virtual' => 'bool',
        'downloadable' => 'bool', 'manage_stock' => 'bool', 'backorders_allowed' => 'bool',
        'backordered' => 'bool', 'sold_individually' => 'bool', 'shipping_required' => 'bool',
        'shipping_taxable' => 'bool', 'reviews_allowed' => 'bool', 'has_options' => 'bool',
        // Int
        'total_sales' => 'int', 'download_limit' => 'int', 'download_expiry' => 'int',
        'shipping_class_id' => 'int', 'rating_count' => 'int', 'parent_id' => 'int', 'menu_order' => 'int',
        // Float
        'average_rating' => 'float',
        // Nullable (null, string, or empty)
        'date_on_sale_from' => 'nullable', 'date_on_sale_from_gmt' => 'nullable',
        'date_on_sale_to' => 'nullable', 'date_on_sale_to_gmt' => 'nullable',
        'low_stock_amount' => 'nullable',
        // Nullable Int (null or numeric)
        'stock_quantity' => 'nullable_int',
        // Array
        'images' => 'array', 'categories' => 'array', 'tags' => 'array', 'brands' => 'array',
        'attributes' => 'array', 'default_attributes' => 'array', 'variations' => 'array',
        'grouped_products' => 'array', 'upsell_ids' => 'array', 'cross_sell_ids' => 'array',
        'related_ids' => 'array', 'downloads' => 'array',
        // Object
        'dimensions' => 'object', '_links' => 'object',
    ];

    private static $order_types = [
        // Int
        'id' => 'int', 'parent_id' => 'int', 'customer_id' => 'int',
        // String
        'number' => 'string', 'status' => 'string', 'currency' => 'string', 'version' => 'string',
        'order_key' => 'string', 'transaction_id' => 'string', 'customer_ip_address' => 'string',
        'customer_user_agent' => 'string', 'created_via' => 'string', 'customer_note' => 'string',
        'cart_hash' => 'string', 'payment_method' => 'string', 'payment_method_title' => 'string',
        'payment_url' => 'string', 'currency_symbol' => 'string', 'weight_unit' => 'string',
        'date_created' => 'string', 'date_created_gmt' => 'string',
        'date_modified' => 'string', 'date_modified_gmt' => 'string',
        'discount_total' => 'string', 'discount_tax' => 'string',
        'shipping_total' => 'string', 'shipping_tax' => 'string',
        'cart_tax' => 'string', 'total' => 'string', 'total_tax' => 'string',
        // Bool
        'prices_include_tax' => 'bool', 'is_editable' => 'bool',
        'needs_payment' => 'bool', 'needs_processing' => 'bool',
        // Nullable
        'date_completed' => 'nullable', 'date_completed_gmt' => 'nullable',
        'date_paid' => 'nullable', 'date_paid_gmt' => 'nullable',
        // Int
        'total_items' => 'int',
        // Float
        'total_weight' => 'float',
        // Array
        'billing' => 'array', 'shipping' => 'array', 'line_items' => 'array',
        'tax_lines' => 'array', 'shipping_lines' => 'array', 'fee_lines' => 'array',
        'coupon_lines' => 'array', 'refunds' => 'array',
        // Object
        '_links' => 'object',
    ];

    private static $coupon_types = [
        // Int
        'id' => 'int', 'usage_count' => 'int',
        // String
        'code' => 'string', 'amount' => 'string', 'status' => 'string',
        'discount_type' => 'string', 'description' => 'string',
        'date_created' => 'string', 'date_created_gmt' => 'string',
        'date_modified' => 'string', 'date_modified_gmt' => 'string',
        'minimum_amount' => 'string', 'maximum_amount' => 'string',
        // Bool
        'individual_use' => 'bool', 'free_shipping' => 'bool', 'exclude_sale_items' => 'bool',
        // Nullable
        'date_expires' => 'nullable', 'date_expires_gmt' => 'nullable',
        'usage_limit' => 'nullable', 'usage_limit_per_user' => 'nullable',
        'limit_usage_to_x_items' => 'nullable',
        // Array
        'product_ids' => 'array', 'excluded_product_ids' => 'array',
        'product_categories' => 'array', 'excluded_product_categories' => 'array',
        'email_restrictions' => 'array', 'used_by' => 'array',
        // Object
        '_links' => 'object',
    ];

    private static $pending_logs = null;

    const LOG_FILE = 'api-compatibility.log';
    
    public function __construct()
    {
        add_action('admin_init', array($this, 'settings_init'));
        add_filter('hippoo_sanitize_settings', array($this, 'sanitize_settings'), 10, 2);

        add_action('wp_ajax_hippoo_get_compatibility_log', array($this, 'ajax_get_compatibility_log'));

        add_filter('rest_pre_dispatch', array($this, 'maybe_normalize_response'), 10, 3);
    }

    public function sanitize_settings($sanitized, $input)
    {
        if (isset($input['compatibility_mode'])) {
            $sanitized['compatibility_mode'] = (bool) $input['compatibility_mode'];
        }
        
        if (isset($input['compatibility_applies']) && is_array($input['compatibility_applies'])) {
            $sanitized['compatibility_applies'] = array_map('sanitize_text_field', $input['compatibility_applies']);
        }
        
        return $sanitized;
    }

    public function settings_init()
    {
        add_settings_section(
            'hippoo_compatibility_section',
            null,
            null,
            'hippoo_settings'
        );

        $description = '<p>'
            . esc_html__( 'Fix issues when products, orders, or coupons don\'t load correctly in the Hippoo WooCommerce app.', 'hippoo' )
            . '<br><strong>' . esc_html__( 'Note:', 'hippoo' ) . '</strong> '
            . esc_html__( 'Enabling this option may affect other tools and plugins that use the WooCommerce API.', 'hippoo' )
            . '</p>';
        add_settings_field(
            'compatibility_mode',
            __('Enable Compatibility Mode', 'hippoo') . $description,
            array($this, 'field_compatibility_mode_render'),
            'hippoo_settings',
            'hippoo_compatibility_section'
        );

        add_settings_field(
            'compatibility_applies',
            __('Applies to:', 'hippoo'),
            array($this, 'field_compatibility_applies_render'),
            'hippoo_settings',
            'hippoo_compatibility_section',
            array('class' => 'compatibility-applies-row')
        );


        add_settings_section(
            'hippoo_compatibility_log_section',
            null,
            null,
            'hippoo_settings'
        );

        add_settings_field(
            'compatibility_log',
            __('Hippoo Debug log', 'hippoo'),
            array($this, 'field_compatibility_log_render'),
            'hippoo_settings',
            'hippoo_compatibility_log_section'
        );
    }

    public function field_compatibility_mode_render()
    {
        echo '<input type="checkbox" class="switch" id="compatibility_mode" name="hippoo_settings[compatibility_mode]" ' . checked($this->is_compatibility_mode_enabled(), 1, false) . ' value="1">';
    }

    public function field_compatibility_applies_render()
    {
        $settings = get_option('hippoo_settings', []);
        $applies = isset($settings['compatibility_applies']) ? (array) $settings['compatibility_applies'] : [];
        ?>
        <label class="checkbox-label">
            <?php esc_html_e('WooCommerce orders api', 'hippoo'); ?>
            <input type="checkbox" name="hippoo_settings[compatibility_applies][]" <?php checked(in_array('orders', $applies), true); ?> value="orders">
        </label>
        <label class="checkbox-label">
            <?php esc_html_e('WooCommerce products api', 'hippoo'); ?>
            <input type="checkbox" name="hippoo_settings[compatibility_applies][]" <?php checked(in_array('products', $applies), true); ?> value="products">
        </label>
        <label class="checkbox-label">
            <?php esc_html_e('WooCommerce coupons api', 'hippoo'); ?>
            <input type="checkbox" name="hippoo_settings[compatibility_applies][]" <?php checked(in_array('coupons', $applies), true); ?> value="coupons">
        </label>
        <?php
    }

    public function field_compatibility_log_render()
    {
        ?>
        <a href="#" id="copy-compatibility-log" class="copy-log-link">
            <?php esc_html_e('Copy API debug log', 'hippoo'); ?>
        </a>
        <?php
    }

    public function ajax_get_compatibility_log()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid request.', 'hippoo'));
        }
        
        $log_content = $this->get_log_content();
        wp_send_json_success($log_content);
    }

    public function maybe_normalize_response($result, $server, $request)
    {
        $route = $request->get_route();

        // Handle Compatibility-Mode header to dynamically toggle normalization settings
        $this->handle_compatibility_mode_header($route);
        
        // Check if normalization should be applied to this route
        if (!$this->should_normalize($route)) {
            return $result;
        }

        add_filter('rest_pre_echo_response', array($this, 'normalize_response'), 10, 3);
        
        return $result;
    }

    public function normalize_response($response, $server, $request)
    {
        $route = $request->get_route();
        
        if (strpos($route, '/wc/v') === 0) {
            if (strpos($route, '/products') !== false) {
                $response = $this->normalize_products_response($response);
            } elseif (strpos($route, '/orders') !== false) {
                $response = $this->normalize_orders_response($response);
            } elseif (strpos($route, '/coupons') !== false) {
                $response = $this->normalize_coupons_response($response);
            }
        }
        
        return $response;
    }

    private function normalize_products_response($data)
    {
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as &$product) {
                $product = $this->normalize_product_data($product);
            }
        } elseif (isset($data['id'])) {
            $data = $this->normalize_product_data($data);
        }
        
        return $data;
    }

    private function normalize_product_data($product)
    {
        if (!is_array($product)) {
            $this->log_issue('products', 'item_skip', 'invalid_product', $product);
            return [];
        }

        $product_id = isset($product['id']) ? $product['id'] : 'unknown';

        $product = $this->fix_types($product, self::$product_types, 'products', $product_id);
        
        return $product;
    }

    private function normalize_orders_response($data)
    {
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as &$order) {
                $order = $this->normalize_order_data($order);
            }
        } elseif (isset($data['id'])) {
            $data = $this->normalize_order_data($data);
        }
        
        return $data;
    }

    private function normalize_order_data($order)
    {
        if (!is_array($order)) {
            $this->log_issue('orders', 'item_skip', 'invalid_order', $order);
            return [];
        }

        $order_id = isset($order['id']) ? $order['id'] : 'unknown';

        $order = $this->fix_types($order, self::$order_types, 'orders', $order_id);

        // Fix billing/shipping required keys
        $billing_keys = ['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        $shipping_keys = ['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        foreach (['billing' => $billing_keys, 'shipping' => $shipping_keys] as $k => $keys) {
            if (isset($order[$k]) && is_array($order[$k])) {
                $missing = array_diff($keys, array_keys($order[$k]));
                if (!empty($missing)) {
                    $this->log_issue('orders', 'fixed', "{$k}_missing_keys", $missing, $order_id);
                    foreach ($missing as $mk) $order[$k][$mk] = '-';
                }
            }
        }

        // Clean line_items meta_data
        if (isset($order['line_items']) && is_array($order['line_items'])) {
            foreach ($order['line_items'] as &$item) {
                if (isset($item['meta_data'])) {
                    $this->log_issue('orders', 'removed', 'line_item_meta_data', $item['meta_data'], $order_id);
                    unset($item['meta_data']);
                }
            }
        }

        // Clean shipping_lines meta_data
        if (isset($order['shipping_lines']) && is_array($order['shipping_lines'])) {
            foreach ($order['shipping_lines'] as &$sl) {
                if (isset($sl['meta_data'])) {
                    $this->log_issue('orders', 'removed', 'shipping_line_meta_data', $sl['meta_data'], $order_id);
                    unset($sl['meta_data']);
                }
            }
        }
        
        return $order;
    }

    private function normalize_coupons_response($data)
    {
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as &$coupon) {
                $coupon = $this->normalize_coupon_data($coupon);
            }
        } elseif (isset($data['id'])) {
            $data = $this->normalize_coupon_data($data);
        }
        
        return $data;
    }

    private function normalize_coupon_data($coupon)
    {
        if (!is_array($coupon)) {
            $this->log_issue('coupons', 'item_skip', 'invalid_coupon', $coupon);
            return [];
        }

        $coupon_id = isset($coupon['id']) ? $coupon['id'] : 'unknown';

        $coupon = $this->fix_types($coupon, self::$coupon_types, 'coupons', $coupon_id);
        
        return $coupon;
    }

    private function handle_compatibility_mode_header($route)
    {
        $is_hippoo_request = isset($_SERVER['HTTP_HIPPOO']) && strtolower($_SERVER['HTTP_HIPPOO']) === 'true';
        if (!$is_hippoo_request) {
            return;
        }

        $header = strtolower(sanitize_text_field($_SERVER['HTTP_COMPATIBILITY_MODE'] ?? ''));

        if (!in_array($header, ['enable', 'disable'], true)) {
            return;
        }

        if (strpos($route, '/orders') !== false) {
            $type = 'orders';
        } elseif (strpos($route, '/products') !== false) {
            $type = 'products';
        } elseif (strpos($route, '/coupons') !== false) {
            $type = 'coupons';
        } else {
            return;
        }

        $settings = get_option('hippoo_settings', []);
        $applies = isset($settings['compatibility_applies']) ? (array) $settings['compatibility_applies'] : [];

        $should_enable = ($header === 'enable');

        if ($should_enable) {
            if (!in_array($type, $applies)) {
                $applies[] = $type;
            }
        } else {
            $applies = array_diff($applies, [$type]);
        }

        $settings['compatibility_applies'] = array_unique($applies);
        $settings['compatibility_mode'] = true;

        update_option('hippoo_settings', $settings);

        $this->log_issue($type, 'compatibility_mode_changed', 'compatibility_mode', $header);
    }

    private function should_normalize($route)
    {
        // Skip if compatibility mode is completely disabled in settings
        if (!$this->is_compatibility_mode_enabled()) {
            return false;
        }

        // Only apply to Hippoo App requests
        $is_hippoo_request = isset($_SERVER['HTTP_HIPPOO']) && strtolower($_SERVER['HTTP_HIPPOO']) === 'true';
        if (!$is_hippoo_request) {
            return false;
        }

        $applies = $this->get_compatibility_applies();

        if (in_array('orders', $applies) && strpos($route, '/orders') !== false) {
            return true;
        }

        if (in_array('products', $applies) && strpos($route, '/products') !== false) {
            return true;
        }

        if (in_array('coupons', $applies) && strpos($route, '/coupons') !== false) {
            return true;
        }

        return false;
    }

    private function fix_types($data, $type_map, $endpoint, $object_id) {
        if (isset($data['meta_data'])) {
            $this->log_issue($endpoint, 'removed', 'meta_data', $data['meta_data'], $object_id);
        }
        unset($data['meta_data']);

        foreach ($type_map as $field => $expected) {
            if (!array_key_exists($field, $data)) continue;
            $val = $data[$field];
            $fixed = false;
            $fallback = null;

            switch ($expected) {
                case 'string':
                    if (!is_string($val) && !is_numeric($val)) { $fallback = ''; $fixed = true; }
                    break;
                case 'bool':
                    if (!is_bool($val)) { $fallback = false; $fixed = true; }
                    break;
                case 'int':
                    if (!is_int($val) && !(is_string($val) && ctype_digit($val))) {
                        $fallback = is_numeric($val) ? (int) $val : 0;
                        $fixed = true;
                    }
                    break;
                case 'float':
                    if (!is_float($val) && !is_int($val) && !is_numeric($val)) { $fallback = 0.0; $fixed = true; }
                    break;
                case 'nullable':
                    if (!is_null($val) && !is_string($val) && $val !== '') { $fallback = null; $fixed = true; }
                    break;
                case 'nullable_int':
                    if (!is_null($val) && !is_numeric($val)) { $fallback = null; $fixed = true; }
                    break;
                case 'array':
                    if (!is_array($val)) { $fallback = []; $fixed = true; }
                    break;
                case 'object':
                    if (!is_array($val) && !is_object($val)) { $fallback = new stdClass(); $fixed = true; }
                    break;
            }

            if ($fixed) {
                $this->log_issue($endpoint, 'fixed', $field, $val, $object_id);
                $data[$field] = $fallback;
            }
        }

        return $data;
    }

    private function log_issue($endpoint, $action, $field_name, $field_value, $object_id = null)
    {
        if (is_array($field_name)) {
            $field_name = json_encode($field_name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $field_name = (string) $field_name;
        
        $field_type = gettype($field_value);

        if (is_array($field_value)) {
            $field_value = json_encode($field_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_object($field_value)) {
            $field_value = '(object) ' . get_class($field_value);
        } elseif (is_null($field_value)) {
            $field_value = 'NULL';
        } elseif (is_bool($field_value)) {
            $field_value = $field_value ? 'true' : 'false';
        }
        $field_value = (string) $field_value;

        $id_info = ($object_id !== null) ? " #{$object_id}" : '';
        $now = current_time('mysql');

        $unique_key = sprintf('[%s] [%s] %s', strtoupper($endpoint), strtoupper($action), $field_name);
        
        // Initialize pending logs if first call
        if (self::$pending_logs === null) {
            self::$pending_logs = hippoo_get_log_content(self::LOG_FILE);

            // Register shutdown to write logs once at the end
            register_shutdown_function(function () {
                if (self::$pending_logs !== null) {
                    hippoo_put_log_content(self::LOG_FILE, self::$pending_logs);
                    self::$pending_logs = null;
                }
            });
        }
        
        $pattern = '/^(\[.*?\])? ?' . preg_quote($unique_key, '/') . ' \(occurred (\d+) times?, last seen: .*?\).*$/m';

        if (!empty(self::$pending_logs) && preg_match($pattern, self::$pending_logs, $matches)) {
            $current_count = (int) $matches[2];
            $new_count = $current_count + 1;
            
            $new_line = sprintf(
                '%s (occurred %d times, last seen: %s) | Sample: %s (%s) %s',
                $unique_key,
                $new_count,
                $now,
                $id_info,
                $field_type,
                $field_value
            );
            
            // Preserve timestamp if it existed, otherwise without
            $full_new_line = !empty($matches[1]) ? $matches[1] . ' ' . $new_line : $new_line;
            
            self::$pending_logs = preg_replace($pattern, $full_new_line, self::$pending_logs, 1);
        } else {
            $new_line = sprintf(
                '%s (occurred 1 time, last seen: %s) | Sample: %s (%s) %s',
                $unique_key,
                $now,
                $id_info,
                $field_type,
                $field_value
            );
            
            self::$pending_logs .= "[{$now}] " . $new_line . PHP_EOL;
        }
    }

    private function get_log_content()
    {
        $raw_log = hippoo_get_log_content(self::LOG_FILE);
        
        if (empty($raw_log)) {
            return __("No API compatibility issues logged yet.\n", 'hippoo');
        }
        
        $header = "=== " . __('Hippoo API Compatibility Debug Log', 'hippoo') . " ===\n";
        $header .= sprintf(__('Generated: %s', 'hippoo') . "\n", current_time('mysql'));
        $header .= "==========================================\n\n";
        
        return $header . $raw_log;
    }

    private function is_compatibility_mode_enabled()
    {
        $settings = get_option('hippoo_settings', []);
        return isset($settings['compatibility_mode']) && $settings['compatibility_mode'];
    }

    private function get_compatibility_applies()
    {
        $settings = get_option('hippoo_settings', []);
        return isset($settings['compatibility_applies']) ? (array) $settings['compatibility_applies'] : [];
    }
}

new HippooCompatibility();