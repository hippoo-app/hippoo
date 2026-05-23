<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooEventNotificationController {
    protected $namespace = 'wc-hippoo/v1';
    protected $rest_base = 'event-notification';
    protected $table_name = 'hippoo_event_notifications';

    const DB_VERSION = '1.0.0';

    private $hook_groups = array(
        'order' => array(
            'woocommerce_order_status_pending',
            'woocommerce_order_status_processing',
            'woocommerce_order_status_completed',
            'woocommerce_order_status_failed',
        ),
        'product' => array(
            'woocommerce_low_stock',
            'woocommerce_no_stock',
        ),
        'user' => array(
            'user_register',
            'profile_update',
        ),
        'comment' => array(
            'comment_post',
        ),
        'post' => array(
            'post_updated',
        ),
        'custom' => array(),
    );

    private $variable_definitions = array(
        'order' => array(
            '{{order_id}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_order_id'),
                'args' => array('order'),
            ),
            '{{billing_first_name}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_billing_first_name'),
                'args' => array('order'),
            ),
            '{{billing_last_name}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_billing_last_name'),
                'args' => array('order'),
            ),
            '{{billing_email}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_billing_email'),
                'args' => array('order'),
            ),
            '{{order_total}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_order_total'),
                'args' => array('order'),
            ),
            '{{order_status}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_order_status'),
                'args' => array('order'),
            ),
        ),
        'product' => array(
            '{{product_id}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_product_id'),
                'args' => array('product'),
            ),
            '{{product_name}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_product_name'),
                'args' => array('product'),
            ),
            '{{stock_quantity}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_stock_quantity'),
                'args' => array('product'),
            ),
        ),
        'user' => array(
            '{{user_id}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_user_id'),
                'args' => array('user'),
            ),
            '{{user_login}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_user_login'),
                'args' => array('user'),
            ),
            '{{user_email}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_user_email'),
                'args' => array('user'),
            ),
        ),
        'comment' => array(
            '{{comment_content}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_comment_content'),
                'args' => array('comment'),
            ),
            '{{comment_author}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_comment_author'),
                'args' => array('comment'),
            ),
            '{{comment_post_ID}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_comment_post_ID'),
                'args' => array('comment'),
            ),
        ),
        'post' => array(
            '{{post_id}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_post_id'),
                'args' => array('post'),
            ),
            '{{post_title}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_post_title'),
                'args' => array('post'),
            ),
            '{{post_status}}' => array(
                'callback' => array('HippooEventNotificationController', 'get_post_status'),
                'args' => array('post'),
            ),
        ),
        'custom' => array(),
    );

    private static function get_order_id($order) {
        return $order ? $order->get_id() : '';
    }

    private static function get_billing_first_name($order) {
        return $order ? $order->get_billing_first_name() : '';
    }

    private static function get_billing_last_name($order) {
        return $order ? $order->get_billing_last_name() : '';
    }

    private static function get_billing_email($order) {
        return $order ? $order->get_billing_email() : '';
    }

    private static function get_order_total($order) {
        return $order ? $order->get_total() : '';
    }

    private static function get_order_status($order) {
        return $order ? $order->get_status() : '';
    }

    private static function get_product_id($product) {
        return $product ? $product->get_id() : '';
    }

    private static function get_product_name($product) {
        return $product ? $product->get_name() : '';
    }

    private static function get_stock_quantity($product) {
        return $product ? $product->get_stock_quantity() : '';
    }

    private static function get_user_login($user) {
        return $user ? $user->user_login : '';
    }

    private static function get_user_email($user) {
        return $user ? $user->user_email : '';
    }

    private static function get_user_id($user) {
        return $user ? $user->ID : '';
    }

    private static function get_comment_content($comment) {
        return $comment ? $comment->comment_content : '';
    }

    private static function get_comment_author($comment) {
        return $comment ? $comment->comment_author : '';
    }

    private static function get_comment_post_ID($comment) {
        return $comment ? $comment->comment_post_ID : '';
    }

    private static function get_post_id($post) {
        return $post ? $post->ID : '';
    }

    private static function get_post_title($post) {
        return $post ? $post->post_title : '';
    }

    private static function get_post_status($post) {
        return $post ? $post->post_status : '';
    }

    public function init_database() {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event varchar(255) NOT NULL,
            sound varchar(255) DEFAULT '',
            title varchar(255) NOT NULL,
            description text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('hippoo_notification_db_version', self::DB_VERSION);
    }

    public function register_hooks() {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;

        $notifications = $wpdb->get_results("SELECT id, event FROM $table_name", ARRAY_A);

        foreach ($notifications as $notification) {
            $event = $notification['event'];
            $notification_id = $notification['id'];

            add_action($event, function (...$args) use ($event, $notification_id) {
                $this->send_notification($event, $notification_id, $args);
            }, 10, 10);
        }
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_notifications'),
            'permission_callback' => array($this, 'permissions_check'),
            'args'                => array(
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_notification'),
            'permission_callback' => array($this, 'permissions_check'),
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'create_notification'),
            'permission_callback' => array($this, 'permissions_check'),
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'update_notification'),
            'permission_callback' => array($this, 'permissions_check'),
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array($this, 'delete_notification'),
            'permission_callback' => array($this, 'permissions_check'),
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/all-events', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_all_events'),
            'permission_callback' => array($this, 'permissions_check'),
            'args'                => array(
                'page'     => array(
                    'type'        => 'integer',
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'default'     => 50,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
            ),
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/all-variables', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_all_variables'),
            'permission_callback' => array($this, 'permissions_check'),
            'args'                => array(
                'event' => array(
                    'type'        => 'string',
                ),
            ),
        ));
    }

    public function permissions_check($request) {
        return current_user_can('manage_options');
    }

    public function get_notifications($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;

        $page = max(1, $request['page']);
        $per_page = max(1, min(100, $request['per_page']));
        $offset = ($page - 1) * $per_page;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        $response = rest_ensure_response($results);
        $response->header('X-WP-Total', (int) $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));

        return $response;
    }

    public function get_notification($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;
        $id = (int) $request['id'];

        $notification = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$notification) {
            return new WP_Error('not_found', __('Notification not found.', 'hippoo'), ['status' => 404]);
        }

        return rest_ensure_response($notification);
    }

    public function create_notification($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;

        $data = $this->validate_notification_data($request);
        if (is_wp_error($data)) {
            return $data;
        }

        $wpdb->insert($table_name, $data);
        $id = $wpdb->insert_id;

        $notification = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        return rest_ensure_response($notification);
    }

    public function update_notification($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;
        $id = (int) $request['id'];

        $data = $this->validate_notification_data($request);
        if (is_wp_error($data)) {
            return $data;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$existing) {
            return new WP_Error('not_found', __('Notification not found.', 'hippoo'), ['status' => 404]);
        }

        $wpdb->update($table_name, $data, array('id' => $id));

        $notification = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        return rest_ensure_response($notification);
    }

    public function delete_notification($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;
        $id = (int) $request['id'];

        $notification = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$notification) {
            return new WP_Error('not_found', __('Notification not found.', 'hippoo'), ['status' => 404]);
        }

        $wpdb->delete($table_name, array('id' => $id));

        return rest_ensure_response(array(
            'status' => 'deleted',
            'id'     => $id,
        ));
    }

    public function get_all_events($request) {
        $hooks = $this->get_available_hooks();
        $page = max(1, $request['page']);
        $per_page = max(1, min(100, $request['per_page']));
        $offset = ($page - 1) * $per_page;

        $paged_hooks = array_slice($hooks, $offset, $per_page);
        $total = count($hooks);

        $response = rest_ensure_response($paged_hooks);
        $response->header('X-WP-Total', (int) $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));

        return $response;
    }

    public function get_all_variables($request) {
        $event = $request['event'] ? $request['event'] : '';
        $variables = $this->get_hook_variables($event);
        return rest_ensure_response($variables);
    }

    private function validate_notification_data($request) {
        $data = $request->get_json_params();
        $required = array('event', 'title', 'description');

        foreach ($required as $field) {
            if (empty($data[$field])) {
                /* translators: %s: missing required field name */
                return new WP_Error('missing_field', sprintf(__('Missing %s field.', 'hippoo'), $field), ['status' => 400]);
            }
        }

        $available_hooks = $this->get_available_hooks();
        if (!in_array($data['event'], $available_hooks)) {
            return new WP_Error('invalid_event', __('Invalid event hook.', 'hippoo'), ['status' => 400]);
        }

        return array(
            'event'       => sanitize_text_field($data['event']),
            'sound'       => isset($data['sound']) ? sanitize_file_name($data['sound']) : '',
            'title'       => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description']),
        );
    }

    private function get_available_hooks() {
        $hooks = array();
        $custom_hook_groups = array();

        $custom_hook_groups = apply_filters('hippoo_event_notification_hooks', $this->hook_groups);

        foreach ($custom_hook_groups as $group => $group_hooks) {
            $hooks = array_merge($hooks, $group_hooks);
            $this->hook_groups[$group] = $group_hooks;
            if (!isset($this->variable_definitions[$group])) {
                $this->variable_definitions[$group] = array();
            }
        }

        return array_unique($hooks);
    }

    private function get_hook_variables($event) {
        if ($event) {
            $group = $this->get_hook_group($event);
            $variables = array();

            if ($group && isset($this->variable_definitions[$group])) {
                $variables = array_keys($this->variable_definitions[$group]);
            }

            $custom_variables = apply_filters('hippoo_event_notification_variables', array(), $event, $group);
            if (is_array($custom_variables) && !empty($custom_variables)) {
                $variables = array_merge($variables, array_keys($custom_variables));
                $this->variable_definitions[$group] = array_merge(
                    $this->variable_definitions[$group],
                    $custom_variables
                );
            }

            return array_unique($variables);
        }

        $all_variables = array();
        foreach ($this->variable_definitions as $variables) {
            $all_variables = array_merge($all_variables, array_keys($variables));
        }

        $custom_variables = apply_filters('hippoo_event_notification_variables', array(), '', 'custom');
        if (is_array($custom_variables) && !empty($custom_variables)) {
            $all_variables = array_merge($all_variables, array_keys($custom_variables));
            $this->variable_definitions['custom'] = array_merge(
                $this->variable_definitions['custom'],
                $custom_variables
            );
        }

        return array_unique($all_variables);
    }

    private function get_hook_group($event) {
        foreach ($this->hook_groups as $group_name => $hooks) {
            if (in_array($event, $hooks)) {
                return $group_name;
            }
        }
        return 'custom';
    }

    private function send_notification($event, $notification_id, $args) {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name;

        $notification = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $notification_id),
            ARRAY_A
        );

        if (!$notification) {
            return;
        }

        $variables = $this->get_hook_variables($event);
        $replacements = $this->get_variable_replacements($event, $args);

        $title = $this->replace_variables($notification['title'], $variables, $replacements);
        $description = $this->replace_variables($notification['description'], $variables, $replacements);

        $home_url = home_url();
        $parsed_url = wp_parse_url($home_url);
        $cs_hostname = $parsed_url['host'];

        $payload = array(
            'cs_hostname' => $cs_hostname,
            'notif_data'  => array(
                'title'   => $title,
                'content' => $description,
            ),
        );

        wp_remote_post(hippoo_proxy_notifiction_url, array(
            'body' => $payload,
        ));
    }

    private function get_variable_replacements($event, $args) {
        $replacements = array();
        $group = $this->get_hook_group($event);

        if ($group && isset($this->variable_definitions[$group])) {
            $variables = $this->variable_definitions[$group];

            $arg_map = array();
            if ($event === 'comment_post' && isset($args[0]) && is_numeric($args[0])) {
                $comment_id = $args[0];
                $arg_map['comment'] = get_comment($comment_id);
                if (!$arg_map['comment'] && isset($args[2]) && is_array($args[2])) {
                    $arg_map['comment'] = (object) $args[2];
                }
            } else {
                foreach ($args as $index => $arg) {
                    if (is_a($arg, 'WC_Order')) {
                        $arg_map['order'] = $arg;
                    } elseif (is_a($arg, 'WC_Product')) {
                        $arg_map['product'] = $arg;
                    } elseif (is_a($arg, 'WP_Comment')) {
                        $arg_map['comment'] = $arg;
                    } elseif (is_a($arg, 'WP_Post')) {
                        $arg_map['post'] = $arg;
                    } elseif (is_a($arg, 'WP_User')) {
                        $arg_map['user'] = $arg;
                    } elseif (is_numeric($arg)) {
                        if (in_array($event, $this->hook_groups['user']) && $event !== 'comment_post') {
                            $arg_map['user'] = get_user_by('id', $arg);
                        } elseif (in_array($event, $this->hook_groups['post']) && $event !== 'comment_post') {
                            $arg_map['post'] = get_post($arg);
                        }
                    }
                }
            }

            foreach ($variables as $variable => $config) {
                $callback = $config['callback'];
                $required_arg = $config['args'][0];

                if (isset($arg_map[$required_arg])) {
                    $replacements[$variable] = call_user_func($callback, $arg_map[$required_arg]);
                } else {
                    $replacements[$variable] = '';
                }
            }
        }

        $custom_variables = apply_filters('hippoo_event_notification_variables', array(), $event, $group);
        if (is_array($custom_variables) && !empty($custom_variables)) {
            foreach ($custom_variables as $variable => $config) {
                if (isset($config['callback']) && is_callable($config['callback'])) {
                    $required_arg = isset($config['args'][0]) ? $config['args'][0] : null;
                    $index = isset($config['arg_index']) ? $config['arg_index'] : 0;

                    if ($required_arg && isset($args[$index])) {
                        $replacements[$variable] = call_user_func($config['callback'], $args[$index]);
                    } else {
                        $replacements[$variable] = call_user_func($config['callback'], $args);
                    }
                } else {
                    $replacements[$variable] = '';
                }
            }
        }

        return $replacements;
    }

    private function replace_variables($text, $variables, $replacements) {
        foreach ($variables as $variable) {
            $value = isset($replacements[$variable]) ? $replacements[$variable] : '';
            $text = str_replace($variable, $value, $text);
        }
        return $text;
    }
}