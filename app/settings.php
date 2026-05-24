<?php // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooSettings
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));

        add_filter('hippoo_settings_tabs', array($this, 'add_settings_tab'), 1);
        add_filter('hippoo_settings_tab_contents', array($this, 'add_settings_tab_content'), 1);
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('Hippoo Settings', 'hippoo'),
            __('Hippoo', 'hippoo'),
            'manage_options',
            'hippoo_setting_page',
            array($this, 'settings_page_render'),
            (HIPPOO_URL . '/images/icon.svg')
        );
    }

    public function settings_init()
    {
        register_setting('hippoo_settings', 'hippoo_settings', array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));

        add_settings_section(
            'hippoo_general_settings_section',
            null,
            null,
            'hippoo_settings'
        );

        $description = '<p>' . esc_html__( 'Provides PDF invoices and shipping labels for easy printing. Also generates barcodes for orders and product SKUs inside the WooCommerce dashboard, allowing you to scan them with the Hippoo WooCommerce app\'s barcode scanner to quickly find orders and products.', 'hippoo' ) . '</p>';
        add_settings_field(
            'invoice_plugin_enabled',
            __('Enable Hippoo invoice and shipping label', 'hippoo') . $description,
            array($this, 'invoice_plugin_enabled_render'),
            'hippoo_settings',
            'hippoo_general_settings_section'
        );

        // $description = '<p>' . esc_html__( 'Enable this option to automatically optimize and compress product images uploaded through the Hippoo WooCommerce app. This setting only applies to images uploaded in Hippoo and will not affect uploads made directly through WordPress or other tools.', 'hippoo' ) . '</p>';
        // add_settings_field(
        //     'image_optimization_enabled',
        //     __('Optimize and Compress Images Uploaded in Hippoo App', 'hippoo') . $description,
        //     array($this, 'image_optimization_enabled_render'),
        //     'hippoo_settings',
        //     'hippoo_general_settings_section'
        // );

        // add_settings_field(
        //     'image_size_selection',
        //     __('Image size', 'hippoo'),
        //     array($this, 'image_size_selection_render'),
        //     'hippoo_settings',
        //     'hippoo_general_settings_section'
        // );
    }

    public function invoice_plugin_enabled_render()
    {
        $settings = get_option('hippoo_settings', []);
        $value = isset($settings['invoice_plugin_enabled']) ? $settings['invoice_plugin_enabled'] : 0;
        ?>
        <input type="checkbox" class="switch" name="hippoo_settings[invoice_plugin_enabled]" <?php checked($value, 1); ?> value="1">
        <?php
    }

    public function image_optimization_enabled_render()
    {
        $settings = get_option('hippoo_settings', []);
        $value = isset($settings['image_optimization_enabled']) ? $settings['image_optimization_enabled'] : 0;
        ?>
        <input type="hidden" name="hippoo_settings[image_optimization_enabled]" value="0">
        <input type="checkbox" class="switch" id="image_optimization_enabled" name="hippoo_settings[image_optimization_enabled]" <?php checked($value, 1); ?> value="1">
        <?php
    }

    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['invoice_plugin_enabled'])) {
            $sanitized['invoice_plugin_enabled'] = (bool) $input['invoice_plugin_enabled'];
        }
        
        if (isset($input['image_optimization_enabled'])) {
            $sanitized['image_optimization_enabled'] = (bool) $input['image_optimization_enabled'];
        }
        
        if (isset($input['image_size_selection'])) {
            $sanitized['image_size_selection'] = sanitize_text_field($input['image_size_selection']);
        }
        
        foreach ($input as $key => $value) {
            if (strpos($key, 'send_notification_') === 0) {
                $sanitized[$key] = (bool) $value;
            }
        }

        $sanitized = apply_filters('hippoo_sanitize_settings', $sanitized, $input);
        
        return $sanitized;
    }

    public function image_size_selection_render()
    {
        $settings = get_option('hippoo_settings', []);
        $selected_size = isset($settings['image_size_selection']) ? $settings['image_size_selection'] : 'large';
        $image_sizes = hippoo_get_available_image_sizes();
        $disabled = isset($settings['image_optimization_enabled']) && $settings['image_optimization_enabled'] ? '' : 'disabled';
        
        echo '<select id="image_size_selection" name="hippoo_settings[image_size_selection]" ' . esc_attr($disabled) . '>';
        foreach ($image_sizes as $size => $dimensions) {
            $selected = selected($selected_size, $size, false);
            echo '<option value="' . esc_attr($size) . '" ' . esc_attr($selected) . '>' . esc_html($size) . ' (' . esc_html($dimensions['width']) . '×' . esc_html($dimensions['height']) . ')</option>';
        }
        echo '</select>';
    }

    public function add_settings_tab($tabs)
    {
        $tabs['settings'] = [
            'label'    => esc_html__('Settings', 'hippoo'),
            'priority' => 10,
        ];
        $tabs['app'] = [
            'label'    => esc_html__('Hippoo App', 'hippoo'),
            'priority' => 999,
        ];
        return $tabs;
    }

    public function add_settings_tab_content($contents)
    {
        $contents['settings'] = function() {
            ob_start();
            ?>
            <div class="hippoo-settings-tab">
                <form action="options.php" method="post">
                    <?php
                    settings_fields('hippoo_settings');
                    do_settings_sections('hippoo_settings');
                    submit_button();
                    ?>
                </form>
            </div>
            <?php
            return ob_get_clean();
        };
        $contents['app'] = function() {
            ob_start();
            ?>
            <div class="introduction">
                <div class="details">
                    <h2><?php esc_html_e('Hippoo Woocommerce app', 'hippoo'); ?></h2>
                    <p><?php esc_html_e('Hippoo! is not just a shop management app, it\'s also a platform that enables you to extend its capabilities. With the ability to install extensions, you can customize your experience and add new features to the app. Browse and install other Hippoo plugins from our app to enhance your store\'s functionality.', 'hippoo'); ?></p>
                    <a href="https://play.google.com/store/apps/details?id=io.hippo" target="_blank" class="google-button">
                        <img src="<?php echo esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/google-play.svg'); ?>" alt="<?php esc_attr_e('Download Hippoo Android app', 'hippoo'); ?>" />
                        <strong><?php esc_html_e('Download Hippoo Android app', 'hippoo'); ?></strong>
                    </a>
                    <a href="https://apps.apple.com/ee/app/hippoo-woocommerce-admin-app/id1667265325" target="_blank" class="google-button">
                        <img src="<?php echo esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/apple.svg'); ?>" alt="<?php esc_attr_e('Download Hippoo iOS app', 'hippoo'); ?>" />
                        <strong><?php esc_html_e('Download Hippoo iOS app', 'hippoo'); ?></strong>
                    </a>
                </div>
                <div class="qrcode">
                    <p><?php esc_html_e('Scan QR code with your Android phone to install the app', 'hippoo'); ?></p>
                    <img src="<?php echo esc_url(HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/qrcode.png'); ?>" alt="<?php esc_attr_e('QR Code', 'hippoo'); ?>" />
                </div>
            </div>
            <div id="image-carousel">
                <div class="carousel-wrapper">
                    <div class="carousel-inner">
                        <img class="carousel-image" src="<?php echo esc_url(HIPPOO_URL . 'images/android-app/1.png'); ?>" alt="<?php esc_attr_e('App screenshot 1', 'hippoo'); ?>" />
                        <img class="carousel-image" src="<?php echo esc_url(HIPPOO_URL . 'images/android-app/2.png'); ?>" alt="<?php esc_attr_e('App screenshot 2', 'hippoo'); ?>" />
                        <img class="carousel-image" src="<?php echo esc_url(HIPPOO_URL . 'images/android-app/3.png'); ?>" alt="<?php esc_attr_e('App screenshot 3', 'hippoo'); ?>" />
                        <img class="carousel-image" src="<?php echo esc_url(HIPPOO_URL . 'images/android-app/4.png'); ?>" alt="<?php esc_attr_e('App screenshot 4', 'hippoo'); ?>" />
                        <img class="carousel-image" src="<?php echo esc_url(HIPPOO_URL . 'images/android-app/5.png'); ?>" alt="<?php esc_attr_e('App screenshot 5', 'hippoo'); ?>" />
                    </div>
                </div>
                <div class="carousel-nav">
                    <span class="carousel-arrow prev"><i class="carousel-prev"></i></span>
                    <span class="carousel-arrow next"><i class="carousel-next"></i></span>
                </div>
            </div>
            <?php
            return ob_get_clean();
        };
        return $contents;
    }

    public function settings_page_render()
    {
        $tabs = apply_filters('hippoo_settings_tabs', []);
        $tab_contents = apply_filters('hippoo_settings_tab_contents', []);

        uasort($tabs, function ($a, $b) {
            return ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50);
        });

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        $active_tab = apply_filters('hippoo_settings_active_tab', $active_tab);

        if (!isset($tabs[$active_tab])) {
            $active_tab = key($tabs);
        }

        do_action('hippoo_before_settings_page');
        ?>
        
        <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']): ?>
            <div class="updated notice is-dismissible"><p><?php esc_html_e('Settings saved successfully.', 'hippoo'); ?></p></div>
        <?php endif; ?>
        
        <div id="hippoo_settings">
            <h2><?php esc_html_e('Hippoo Settings', 'hippoo'); ?></h2>
            <div class="tabs">
                <div class="nav-tab-wrapper">
                    <?php foreach ($tabs as $id => $tab): ?>
                        <a href="<?php echo esc_url(add_query_arg('tab', $id, admin_url('admin.php?page=hippoo_setting_page'))); ?>" class="nav-tab <?php echo $id === $active_tab ? 'nav-tab-active' : ''; ?>">
                            <?php echo esc_html($tab['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($tabs as $id => $tab): ?>
                    <div id="tab-<?php echo esc_attr($id); ?>" class="tab-content <?php echo $id === $active_tab ? 'active' : ''; ?>">
                        <?php
                        if (isset($tab_contents[$id])) {
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is from registered tab content callbacks
                            echo $tab_contents[$id]();
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

new HippooSettings();