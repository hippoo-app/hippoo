<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooControllerWithAuth extends WC_REST_Customers_Controller
{
    protected $hippoo_namespace = 'wc-hippoo/v1';
    function register_routes()
    {
        
        #
        $args_hippoo_stock_list = array(
            'methods'             => 'GET',
            'callback'            => array($this, 'hippoo_stock_list'),
            'permission_callback' => array($this, 'get_items_permissions_check'),
            'args'                => array(
                'page' => array(
                    'required'          => false
                ),
            )
        );
        register_rest_route($this->hippoo_namespace, '/wc/stock(?:/(?P<id>\d+))?', $args_hippoo_stock_list);

        #
        $args_hippoo_media_upload = array(
            'methods'             => 'POST',
            'callback'            => array($this, 'hippoo_media_upload'),
            'permission_callback' => array($this, 'create_item_permissions_check')
        );
        register_rest_route($this->hippoo_namespace, '/wp/media/item', $args_hippoo_media_upload);

        #
        $args_hippoo_media_delete = array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'hippoo_media_delete'),
            'permission_callback' => array($this, 'delete_item_permissions_check'),
            'args'                => array(
                'ids' => array(
                    'required'          => true,
                    'sanitize_callback' => 'rest_sanitize_request_arg',
                    'validate_callback' => 'rest_validate_request_arg',
                    'type'              => 'array',
                    'description'       => __('Array of media item IDs to delete.', 'hippoo'),
                ),
            )
        );
        register_rest_route($this->hippoo_namespace, '/wp/media/item', $args_hippoo_media_delete);

        #
        $args_hippoo_system_info = array(
            'methods'             => 'GET',
            'callback'            => array($this, 'hippoo_system_info'),
            'permission_callback' => array($this, 'get_items_permissions_check')
        );
        register_rest_route($this->hippoo_namespace, '/wp/system/info', $args_hippoo_system_info);
        
        #
        register_rest_route($this->hippoo_namespace, '/setting', array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_setting' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
        ) );
 
        #
        register_rest_route($this->hippoo_namespace, '/setting', array(
            array(
                'methods'   => 'PUT',
                'callback'  => array( $this, 'update_setting' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
        ) );
    }


    function re_register_external_routes() {
        $server = rest_get_server();
        $endpoints = $server->get_routes();
    
        $new_namespace = $this->hippoo_namespace . '/ext';
    
        foreach ($endpoints as $route => $handlers) {
            if (strpos($route, $this->hippoo_namespace) === 0) {
                continue;
            }
    
            foreach ($handlers as $handler) {
                // print('Route: '. esc_html($route) . '</br>');
                $methods = is_array($handler['methods']) 
                    ? implode(',', array_keys($handler['methods']))
                    : $handler['methods'];

                $default_permission_callback = array($this, 'is_user_wordpress_admin');
                $permission_callback = apply_filters('hippoo_extension_permission_check', $default_permission_callback, $route, $handler);
    
                register_rest_route(
                    $new_namespace,
                    $route,
                    array(
                        'methods'             => $methods,
                        'callback'            => $handler['callback'],
                        'args'                => $handler['args'],
                        'permission_callback' => $permission_callback,
                    )
                );
            }
        }
    }
    
    function hippoo_stock_list($data)
    {

        if (isset($data['page'])) {
            $page = esc_sql($data['page']);
        } elseif (isset($data['id'])) {
            $page = esc_sql($data['id']);
        } else {
            $page = "1";
        }

        $page = --$page * 25;
        global $wpdb;
        
        // phpcs:ignore
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID as post_id, p.post_title, pm.meta_value as product_quantity, o.meta_value as out_of_stock_date
                FROM $wpdb->posts AS p
                JOIN $wpdb->postmeta AS pm ON p.ID = pm.post_id
                JOIN $wpdb->postmeta AS o ON p.ID = o.post_id
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND o.meta_key = 'out_stock_time'
                AND pm.meta_key = '_stock'
                AND pm.meta_value <= 0
                ORDER BY out_of_stock_date DESC limit %d,25",
                $page
            )
        );

        if (empty($rows)) {
            $response = array();
            return new WP_REST_Response($response, 200);
        }
        $response = array();
        foreach ($rows as $row) {
            $img = empty($row->post_parent) ? $row->post_id : $row->post_parent;
            $response[] = [
                'id'                => $row->post_id,
                'img'               => get_the_post_thumbnail_url($img, 'thumbnail'),
                'out_of_stock_date' => $row->out_of_stock_date,
                'title'             => $row->post_title,
                'product_quantity'  => $row->product_quantity,
            ];
        }
        return new WP_REST_Response($response, 200);
    }

    function hippoo_media_upload() {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('invalid_file', __('Invalid or missing file.', 'hippoo'), ['status' => 400]);
        }
    
        $file = $_FILES['file'];
        $file_name = sanitize_file_name($file['name']);
        $file_tmp_path = $file['tmp_name'];
        $file_mime_type = mime_content_type($file_tmp_path);
    
        // Read file content
        $file_content = file_get_contents($file_tmp_path);
        if ($file_content === false) {
            return new WP_Error('file_read_error', __('Failed to read file content.', 'hippoo'), ['status' => 500]);
        }
    
        // Upload the file
        $upload = wp_upload_bits($file_name, null, $file_content);
    
        if (!empty($upload['error'])) {
            return new WP_Error('upload_failed', __('Media upload failed.', 'hippoo'), ['status' => 500]);
        }
    
        // Prepare attachment data
        $attachment = [
            'post_mime_type' => $file_mime_type,
            'post_title'     => pathinfo($file_name, PATHINFO_FILENAME),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
    
        // Insert attachment into WordPress
        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
    
        if (is_wp_error($attachment_id)) {
            return new WP_Error('attachment_failed', __('Failed to create attachment.', 'hippoo'), ['status' => 500]);
        }
    
        // Generate metadata and update attachment
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
    
        // Get the media URL
        $media_url = wp_get_attachment_url($attachment_id);
    
        return new WP_REST_Response([
            'status'         => 'success',
            'media_url'      => $media_url,
            'attachment_id'  => $attachment_id,
            'attachment_data' => $attachment_data
        ], 200);
    }
    

    function hippoo_media_delete($request)
    {
        $attachment_ids = $request->get_param('ids');
        $attachment_ids_deleted = array();
        if (!is_null($attachment_ids) && count($attachment_ids) > 0) {
            foreach ($attachment_ids as $attachment_id) {

                $attachment_path = get_attached_file($attachment_id);
                if (!$attachment_path) {
                    return new WP_Error('invalid_attachment', __('Attachment not found.', 'hippoo'), ['status' => 404]);
                }

                $deleted = wp_delete_attachment($attachment_id, true);

                if ($deleted === false) {
                    return new WP_Error('delete_error', __('Error deleting the attachment.', 'hippoo'), ['status' => 500]);
                }

                $attachment_ids_deleted[] = $attachment_id;
            }


            $response =  array(
                'status' => 'success',
                'message' => __('Attachment(s) deleted successfully.', 'hippoo'),
                'attachment_ids_deleted' => $attachment_ids_deleted
            );
            return new WP_REST_Response($response, 200);
        }

        $response =  array(
            'status' => 'problem',
            'message' => __('Nothing to delete.', 'hippoo'),
        );
        return new WP_REST_Response($response, 404);
    }

    function hippoo_get_available_plugins_from_central()
    {
        $response = wp_remote_get('https://hippoo.app/wp-json/hippoo/v1/get_plugins');
        if (is_wp_error($response)) {
            return [];
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        return $data;
    }

    function hippoo_system_info($request)
    {

        $available_plugins = $this->hippoo_get_available_plugins_from_central();
        $plugins = get_plugins();
        $plugins_info = array();

        // # Filter hippoo family plugins // Due supporting external plugins we dont need this anymore
        // $plugins = array_filter($plugins, function ($plugin) {
        //     $plugin_family_names = array('hippoo');
        //     foreach ($plugin_family_names as $plugin_family_name) {
        //         if (stripos($plugin['TextDomain'], $plugin_family_name) === 0) {
        //             return true;
        //         }
        //     }
        //     return false;
        // });

        foreach ($plugins as $plugin_file => $plugin) {
            $available_plugin_from_central = hippoo_get_product_by_slug($available_plugins, $plugin['TextDomain']);
           
            if (!is_null($available_plugin_from_central)) {
                
                $minimum_support_version = $available_plugin_from_central['attributes']['pa_minimum-support'];
                $latest_version = $available_plugin_from_central['attributes']['pa_latest-version'];
                $current_installed_version = $plugin['Version'];

                if (
                    version_compare($current_installed_version, $minimum_support_version, '>=')
                &&  version_compare($current_installed_version, $latest_version,          '<=')
                ) 
                {

                    # Get plugin installation status
                    $plugin_status = 'installed';
                    if (is_plugin_active($plugin_file)) {
                        $plugin_status = 'active';
                    }

                    $available_plugin_from_central['installation_status'] = $plugin_status;
                    $available_plugin_from_central['attributes']['current_installed_version'] = $plugin['Version'];
                    $plugins_info[] = $available_plugin_from_central;
                }
                //$plugins_info[] = $available_plugin_from_central;
            }
        }

        $plugins_info = apply_filters('hippoo_system_info_extensions', $plugins_info, $request);

        return new WP_REST_Response($plugins_info, 200);
    }
    
    function get_setting($request) {
        $settings = get_option('hippoo_settings', []);

        $default_settings = [
            'invoice_plugin_enabled' => false,
            'send_notification_wc-processing' => true
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
        return rest_ensure_response($settings);
    }

        
    public function update_setting( $request ) {
        $settings = get_option('hippoo_settings', []);
        
        $new_settings = json_decode( $request->get_body(), true );
    
        $settings = array_merge($settings, $new_settings);
    
        // Convert string 'true' or 'false' to boolean true or false
        $settings = array_map(function($value) {
            return ($value === 'true') ? true : (($value === 'false') ? false : $value);
        }, $settings);
    
        update_option('hippoo_settings', $settings);
        return rest_ensure_response($settings);
    }

    function is_user_wordpress_admin(){
        return current_user_can('manage_options');
    }

}
