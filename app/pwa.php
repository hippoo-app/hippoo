<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooPwa
{
    public function __construct()
    {
        add_action('init', array($this, 'add_pwa_route'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'template_redirect'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('update_option_hippoo_settings', 'flush_rewrite_rules');

        register_activation_hook(hippoo_main_file_path, array($this, 'activate'));
        register_deactivation_hook(hippoo_main_file_path, array($this, 'deactivate'));
    }

    public function activate()
    {
        $settings = get_option('hippoo_settings', []);

        if (!isset($settings['pwa_plugin_enabled'])) {
            $settings['pwa_plugin_enabled'] = 1;
        }

        update_option('hippoo_settings', $settings);

        $this->add_pwa_route();
        flush_rewrite_rules();
    }

    public function deactivate()
    {
        flush_rewrite_rules();
    }
    
    public function add_pwa_route()
    {
        if ($this->is_plugin_enabled()) {
            add_rewrite_rule('^' . $this->get_route_name() . '/?$', 'index.php?hippoo_pwa=1', 'top');
            add_rewrite_rule('^' . $this->get_route_name() . '/custom\.css$', 'index.php?hippoo_custom_css=1', 'top');
            add_rewrite_rule('^' . $this->get_route_name() . '/(.+?)/?$', 'index.php?hippoo_serve=$matches[1]', 'top');
        }
    }

    public function add_query_vars($vars)
    {
        $vars[] = 'hippoo_pwa';
        $vars[] = 'hippoo_serve';
        $vars[] = 'hippoo_custom_css';
        return $vars;
    }

    public function template_redirect()
    {
        if (get_query_var('hippoo_pwa')) {
            include hippoo_path . 'pwa' . DIRECTORY_SEPARATOR . 'index.html';
            exit;
        }

        if ($serve_path = get_query_var('hippoo_serve')) {
            $base       = realpath(hippoo_path . 'pwa');
            $file_path  = realpath($base . DIRECTORY_SEPARATOR . $serve_path);
            
            if (!$file_path || strpos($file_path, $base) !== 0) {
                include $base . DIRECTORY_SEPARATOR . 'index.html';
                exit;
            }

            if (file_exists($file_path)) {
                $mime_type = $this->get_mime_type($file_path);
                header('Content-Type: ' . $mime_type);
                readfile($file_path); // phpcs:ignore
                exit;
            }

            include $base . DIRECTORY_SEPARATOR . 'index.html';
            exit;
        }

        if (get_query_var('hippoo_custom_css')) {
            $custom_css = $this->get_custom_css();
            header('Content-Type: text/css');
            header('Cache-Control: public, max-age=86400');
            echo $custom_css; // phpcs:ignore
            exit;
        }
    }

    public function settings_init()
    {
        add_settings_section(
            'hippoo_pwa_section',
            null,
            null,
            'hippoo_settings'
        );

        $description = '<p>' . esc_html__( 'Showcase your products with a clean, minimal design. This lightweight theme creates a mobile-friendly product display. Use the link below as an Instagram-style shop to share your products. We recommend keeping it enabled.', 'hippoo' ) . '</p>';
        add_settings_field(
            'pwa_plugin_enabled',
            __('Hippoo Mobile Storefront', 'hippoo') . $description,
            array($this, 'field_plugin_enabled_render'),
            'hippoo_settings',
            'hippoo_pwa_section'
        );

        add_settings_field(
            'pwa_route_name',
            __('Storefront address', 'hippoo'),
            array($this, 'field_route_name_render'),
            'hippoo_settings',
            'hippoo_pwa_section'
        );

        $description = '<p>' . esc_html__( 'Add your own CSS rules for Hippoo Shop. Styles entered here override defaults.', 'hippoo' ) . '</p>';
        add_settings_field(
            'pwa_custom_css',
            __('Custom CSS', 'hippoo') . $description,
            array($this, 'field_custom_css_render'),
            'hippoo_settings',
            'hippoo_pwa_section',
            array('class' => 'custom-css-row')
        );
    }

    public function field_plugin_enabled_render()
    {
        echo '<input type="checkbox" class="switch" id="pwa_plugin_enabled" name="hippoo_settings[pwa_plugin_enabled]" ' . checked($this->is_plugin_enabled(), 1, false) . ' value="1">';
    }

    public function field_route_name_render()
    {
        // $disabled = $this->is_plugin_enabled() ? '' : 'disabled';
        $disabled = 'disabled';
        echo '<label for="pwa_route_name" class="route-name-field">';
        echo esc_html(preg_replace('#^https?://#', '', get_site_url()) . '/ ');
        echo '<input type="text" id="pwa_route_name" name="hippoo_settings[pwa_route_name]" value="' . esc_attr($this->get_route_name()) . '" ' . esc_html($disabled) . '>';
        echo '</label>';
    }

    public function field_custom_css_render()
    {
        $custom_css = $this->get_custom_css();
        $disabled = $this->is_plugin_enabled() ? '' : 'disabled';
        echo '<textarea id="pwa_custom_css" name="hippoo_settings[pwa_custom_css]" rows="10" cols="50" ' . esc_html($disabled) . '>' . esc_textarea($custom_css) . '</textarea>';
    }

    public function is_plugin_enabled()
    {
        $settings = get_option('hippoo_settings', []);
        return isset($settings['pwa_plugin_enabled']) && $settings['pwa_plugin_enabled'];
    }

    public function get_route_name()
    {
        $settings = get_option('hippoo_settings', []);
        return isset($settings['pwa_route_name']) ? $settings['pwa_route_name'] : 'hippooshop';
    }

    public function get_custom_css()
    {
        $settings = get_option('hippoo_settings', []);
        return isset($settings['pwa_custom_css']) ? $settings['pwa_custom_css'] : '';
    }

    private function get_mime_type($file_path)
    {
        $mime_types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ttf' => 'font/ttf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'eot' => 'application/vnd.ms-fontobject',
            'html' => 'text/html',
            'txt' => 'text/plain',
            'ico' => 'image/x-icon',
        ];

        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        return $mime_types[$extension] ?? 'application/octet-stream';
    }
}

new HippooPwa();