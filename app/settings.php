<?php // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Register the top-level “Hippoo” item in wp-admin. */
add_action( 'admin_menu', 'hippoo_settings_register_menu' );

function hippoo_settings_register_menu() {
    add_menu_page(
        __( 'Hippoo Settings', 'hippoo' ),
        __( 'Hippoo', 'hippoo' ),
        'manage_options',
        'hippoo_setting_page',
        'hippoo_settings_render_page',
        HIPPOO_URL . '/images/icon.svg'
    );
}

/** Output the settings page: tab nav, notices, and the active tab panel. */
function hippoo_settings_render_page() {
    $tabs         = apply_filters( 'hippoo_settings_tabs', [] );
    $tab_contents = apply_filters( 'hippoo_settings_tab_contents', [] );

    uasort(
        $tabs,
        function ( $a, $b ) {
            return ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );
        }
    );

    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
    $active_tab = apply_filters( 'hippoo_settings_active_tab', $active_tab );

    if ( ! isset( $tabs[ $active_tab ] ) ) {
        $active_tab = key( $tabs );
    }

    do_action( 'hippoo_before_settings_page' );

    if ( ! empty( $_GET['settings-updated'] ) ) {
        echo '<div class="updated notice is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'hippoo' ) . '</p></div>';
    }
    ?>
    <div id="hippoo_settings">
        <h2><?php esc_html_e( 'Hippoo Settings', 'hippoo' ); ?></h2>
        <div class="tabs">
            <div class="nav-tab-wrapper">
                <?php foreach ( $tabs as $id => $tab ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', $id, admin_url( 'admin.php?page=hippoo_setting_page' ) ) ); ?>"
                        class="nav-tab <?php echo $id === $active_tab ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php foreach ( $tabs as $id => $tab ) : ?>
                <div id="tab-<?php echo esc_attr( $id ); ?>" class="tab-content <?php echo $id === $active_tab ? 'active' : ''; ?>">
                    <?php
                    if ( isset( $tab_contents[ $id ] ) ) {
                        echo $tab_contents[ $id ](); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/** Register the hippoo_settings option group and the General section fields. */
add_action( 'admin_init', 'hippoo_settings_register' );

function hippoo_settings_register() {
    register_setting(
        'hippoo_settings',
        'hippoo_settings',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'hippoo_settings_sanitize',
        )
    );

    add_settings_section( 'hippoo_general_settings_section', null, null, 'hippoo_settings' );

    $description = '<p>' . esc_html__( 'Provides PDF invoices and shipping labels for easy printing. Also generates barcodes for orders and product SKUs inside the WooCommerce dashboard, allowing you to scan them with the Hippoo WooCommerce app\'s barcode scanner to quickly find orders and products.', 'hippoo' ) . '</p>';

    add_settings_field(
        'invoice_plugin_enabled',
        __( 'Enable Hippoo invoice and shipping label', 'hippoo' ) . $description,
        'hippoo_settings_field_invoice',
        'hippoo_settings',
        'hippoo_general_settings_section'
    );
}

/** Sanitize posted settings; other modules extend via hippoo_sanitize_settings filter. */
function hippoo_settings_sanitize( $input ) {
    $sanitized = [];

    if ( ! is_array( $input ) ) {
        return $sanitized;
    }

    if ( isset( $input['invoice_plugin_enabled'] ) ) {
        $sanitized['invoice_plugin_enabled'] = (bool) $input['invoice_plugin_enabled'];
    }
    if ( isset( $input['image_optimization_enabled'] ) ) {
        $sanitized['image_optimization_enabled'] = (bool) $input['image_optimization_enabled'];
    }
    if ( isset( $input['image_size_selection'] ) ) {
        $sanitized['image_size_selection'] = sanitize_text_field( $input['image_size_selection'] );
    }

    foreach ( $input as $key => $value ) {
        if ( strpos( $key, 'send_notification_' ) === 0 ) {
            $sanitized[ $key ] = (bool) $value;
        }
    }

    return apply_filters( 'hippoo_sanitize_settings', $sanitized, $input );
}

/** Print the checkbox that enables the invoice and shipping-label feature. */
function hippoo_settings_field_invoice() {
    $settings = get_option( 'hippoo_settings', [] );
    $value    = ! empty( $settings['invoice_plugin_enabled'] );

    printf(
        '<input type="checkbox" class="switch" name="hippoo_settings[invoice_plugin_enabled]" value="1" %s />',
        checked( $value, true, false )
    );
}

/** Register the Settings and Hippoo App tabs. */
add_filter( 'hippoo_settings_tabs', 'hippoo_settings_register_tabs', 1 );

function hippoo_settings_register_tabs( $tabs ) {
    $tabs['settings'] = array(
        'label'    => esc_html__( 'Settings', 'hippoo' ),
        'priority' => 10,
    );
    $tabs['app'] = array(
        'label'    => esc_html__( 'Hippoo App', 'hippoo' ),
        'priority' => 999,
    );
    return $tabs;
}

/** Register HTML panels for the Settings form and the App promo tab. */
add_filter( 'hippoo_settings_tab_contents', 'hippoo_settings_register_tab_panels', 1 );

function hippoo_settings_register_tab_panels( $contents ) {
    $contents['settings'] = function () {
        ob_start();
        ?>
        <div class="hippoo-settings-tab">
            <form action="options.php" method="post">
                <?php
                settings_fields( 'hippoo_settings' );
                do_settings_sections( 'hippoo_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    };

    $contents['app'] = function () {
        ob_start();
        $inv_url = defined( 'HIPPOO_INVOICE_PLUGIN_URL' ) ? HIPPOO_INVOICE_PLUGIN_URL : HIPPOO_URL;
        ?>
        <div class="introduction">
            <div class="details">
                <h2><?php esc_html_e( 'Hippoo Woocommerce app', 'hippoo' ); ?></h2>
                <p><?php esc_html_e( 'Hippoo! is not just a shop management app, it\'s also a platform that enables you to extend its capabilities. With the ability to install extensions, you can customize your experience and add new features to the app. Browse and install other Hippoo plugins from our app to enhance your store\'s functionality.', 'hippoo' ); ?></p>
                <a href="https://play.google.com/store/apps/details?id=io.hippo" target="_blank" class="google-button">
                    <img src="<?php echo esc_url( HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/google-play.svg' ); ?>" alt="<?php esc_attr_e( 'Download Hippoo Android app', 'hippoo' ); ?>" />
                    <strong><?php esc_html_e( 'Download Hippoo Android app', 'hippoo' ); ?></strong>
                </a>
                <a href="https://apps.apple.com/ee/app/hippoo-woocommerce-admin-app/id1667265325" target="_blank" class="google-button">
                    <img src="<?php echo esc_url( HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/apple.svg' ); ?>" alt="<?php esc_attr_e( 'Download Hippoo iOS app', 'hippoo' ); ?>" />
                    <strong><?php esc_html_e( 'Download Hippoo iOS app', 'hippoo' ); ?></strong>
                </a>
            </div>
            <div class="qrcode">
                <p><?php esc_html_e( 'Scan QR code with your Android phone to install the app', 'hippoo' ); ?></p>
                <img src="<?php echo esc_url( HIPPOO_INVOICE_PLUGIN_URL . 'assets/images/qrcode.png' ); ?>" alt="<?php esc_attr_e( 'QR Code', 'hippoo' ); ?>" />
            </div>
        </div>
        <div id="image-carousel">
            <div class="carousel-wrapper">
                <div class="carousel-inner">
                    <img class="carousel-image" src="<?php echo esc_url( HIPPOO_URL . 'images/android-app/1.png' ); ?>" alt="<?php esc_attr_e( 'App screenshot 1', 'hippoo' ); ?>" />
                    <img class="carousel-image" src="<?php echo esc_url( HIPPOO_URL . 'images/android-app/2.png' ); ?>" alt="<?php esc_attr_e( 'App screenshot 2', 'hippoo' ); ?>" />
                    <img class="carousel-image" src="<?php echo esc_url( HIPPOO_URL . 'images/android-app/3.png' ); ?>" alt="<?php esc_attr_e( 'App screenshot 3', 'hippoo' ); ?>" />
                    <img class="carousel-image" src="<?php echo esc_url( HIPPOO_URL . 'images/android-app/4.png' ); ?>" alt="<?php esc_attr_e( 'App screenshot 4', 'hippoo' ); ?>" />
                    <img class="carousel-image" src="<?php echo esc_url( HIPPOO_URL . 'images/android-app/5.png' ); ?>" alt="<?php esc_attr_e( 'App screenshot 5', 'hippoo' ); ?>" />
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

/** Ensure hippoo_settings option exists with sensible defaults (order notification keys, etc.). */
add_action( 'init', 'hippoo_settings_ensure_defaults' );

function hippoo_settings_ensure_defaults() {
    $settings = get_option( 'hippoo_settings', [] );
    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    $defaults = array(
        'invoice_plugin_enabled'          => false,
        'send_notification_wc-processing' => true,
        'image_optimization_enabled'      => true,
        'image_size_selection'            => 'large',
    );

    if ( function_exists( 'wc_get_order_statuses' ) ) {
        $order_statuses = wc_get_order_statuses();
        foreach ( $order_statuses as $status_key => $status_label ) {
            $key = 'send_notification_' . $status_key;
            if ( ! array_key_exists( $key, $defaults ) ) {
                $defaults[ $key ] = false;
            }
        }
    }

    $settings = array_merge( $defaults, $settings );
    $settings = array_map(
        function ( $value ) {
            return ( $value === '1' ) ? true : ( ( $value === '0' ) ? false : $value );
        },
        $settings
    );

    update_option( 'hippoo_settings', $settings );
}
