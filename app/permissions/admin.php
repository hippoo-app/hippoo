<?php
/**
 * Hippoo Permissions – settings tab and AJAX callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Settings tab
// ---------------------------------------------------------------------------

/** Add the Permissions tab to Hippoo settings. */
add_filter( 'hippoo_settings_tabs', 'hippoo_permissions_add_settings_tab' );

function hippoo_permissions_add_settings_tab( $tabs ) {
    $tabs['permissions'] = array(
        'label'    => esc_html__( 'Role & permissions', 'hippoo' ),
        'priority' => 30,
    );
    return $tabs;
}

/** Render the Permissions settings tab. */
add_filter( 'hippoo_settings_tab_contents', 'hippoo_permissions_add_settings_tab_content' );

function hippoo_permissions_add_settings_tab_content( $contents ) {
    $contents['permissions'] = function() {
        $license_status = hippoo_check_user_license();
        $settings = get_option( 'hippoo_permissions_settings', array() );
        $roles = hippoo_permissions_get_available_roles();
        ob_start();
        ?>
        <div class="hippoo-permissions-tab <?php echo ( 'basic' === $license_status ) ? 'is-locked' : ''; ?>">
            <h3 class="section-title"><?php esc_html_e( 'Role & permissions', 'hippoo' ); ?></h3>
            <p><?php esc_html_e( 'Control what data each user role can see in the Hippoo app, including orders, revenue, customers, and reviews and more. These settings are managed by admins and applied directly at the API level for better data security.', 'hippoo' ); ?></p>
            
            <div class="permissions-select-role">
                <label for="select-role"><?php esc_html_e( 'Select role', 'hippoo' ); ?></label>
                <div class="select-wrapper">
                    <select id="select-role">
                        <option value=""><?php esc_html_e( 'Select role to manage permission', 'hippoo' ); ?></option>
                        <?php foreach ( $roles as $role ): ?>
                            <option value="<?php echo esc_attr( $role['key'] ); ?>" <?php echo $role['disabled'] ? 'disabled' : ''; ?>>
                                <?php echo esc_html( $role['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="permissions-list">
                <?php
                foreach ( $settings as $role_key => $role_settings ) {
                    hippoo_permissions_render_role_card( $role_key, $role_settings, false );
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    };
    return $contents;
}


// ---------------------------------------------------------------------------
// Settings sanitization
// ---------------------------------------------------------------------------

/** Sanitize Permissions settings. */
function hippoo_permissions_sanitize_role_settings( $data ) {
    $sanitized = array();

    // General
    $sanitized['general'] = [
        'enable_access' => isset( $data['general']['enable_access'] ) ? 1 : 0,
        'read_only'     => isset( $data['general']['read_only'] ) ? 1 : 0,
    ];

    // Orders
    $sanitized['orders'] = [
        'access_orders'   => isset( $data['orders']['access_orders'] ) ? 1 : 0,
        'order_count'     => isset( $data['orders']['order_count'] ) ? 1 : 0,
        'order_totals'    => isset( $data['orders']['order_totals'] ) ? 1 : 0,
        'order_details'   => isset( $data['orders']['order_details'] ) ? 1 : 0,

        'name'            => isset( $data['orders']['name'] ) ? 1 : 0,
        'items'           => isset( $data['orders']['items'] ) ? 1 : 0,
        'taxes'           => isset( $data['orders']['taxes'] ) ? 1 : 0,
        'shipping_info'   => isset( $data['orders']['shipping_info'] ) ? 1 : 0,
        'order_statuses'  => isset( $data['orders']['order_statuses'] ) ? 1 : 0,
        'coupon_info'     => isset( $data['orders']['coupon_info'] ) ? 1 : 0,
        'payment_info'    => isset( $data['orders']['payment_info'] ) ? 1 : 0,
        'customer_info'   => isset( $data['orders']['customer_info'] ) ? 1 : 0,
        'order_notes'     => isset( $data['orders']['order_notes'] ) ? 1 : 0,
        'custom_fields'   => isset( $data['orders']['custom_fields'] ) ? 1 : 0,
        'customer_note'   => isset( $data['orders']['customer_note'] ) ? 1 : 0,
        'invoice'         => isset( $data['orders']['invoice'] ) ? 1 : 0,
        'shipping_label'  => isset( $data['orders']['shipping_label'] ) ? 1 : 0,

        'allowed_status'  => isset( $data['orders']['allowed_status'] ) && is_array( $data['orders']['allowed_status'] )
            ? array_map( 'sanitize_text_field', $data['orders']['allowed_status'] )
            : array(),
    ];

    // Products
    $sanitized['products'] = [
        'access_products'    => isset( $data['products']['access_products'] ) ? 1 : 0,
        'product_name'       => isset( $data['products']['product_name'] ) ? 1 : 0,
        'prices'             => isset( $data['products']['prices'] ) ? 1 : 0,
        'stock_quantity'     => isset( $data['products']['stock_quantity'] ) ? 1 : 0,
        'out_of_stock_list'  => isset( $data['products']['out_of_stock_list'] ) ? 1 : 0,
        'sku'                => isset( $data['products']['sku'] ) ? 1 : 0,
        'status'             => isset( $data['products']['status'] ) ? 1 : 0,

        'categories'         => isset( $data['products']['categories'] ) && is_array( $data['products']['categories'] )
            ? array_map( 'absint', $data['products']['categories'] )
            : array(),

        'types'              => isset( $data['products']['types'] ) && is_array( $data['products']['types'] )
            ? array_map( 'sanitize_text_field', $data['products']['types'] )
            : array(),
        
        'access_categories'  => isset( $data['products']['access_categories'] ) ? 1 : 0,
        'access_attributes'  => isset( $data['products']['access_attributes'] ) ? 1 : 0,
        'access_tags'        => isset( $data['products']['access_tags'] ) ? 1 : 0,
        'access_brands'      => isset( $data['products']['access_brands'] ) ? 1 : 0,
        'access_shipping_classes' => isset( $data['products']['access_shipping_classes'] ) ? 1 : 0,
    ];

    // Customers
    $sanitized['customers'] = [
        'access_customers'   => isset( $data['customers']['access_customers'] ) ? 1 : 0,
        'name'               => isset( $data['customers']['name'] ) ? 1 : 0,
        'address'            => isset( $data['customers']['address'] ) ? 1 : 0,
        'phone'              => isset( $data['customers']['phone'] ) ? 1 : 0,
        'email'              => isset( $data['customers']['email'] ) ? 1 : 0,
    ];

    // Reviews
    $sanitized['reviews'] = [
        'access_reviews'     => isset( $data['reviews']['access_reviews'] ) ? 1 : 0,
        'reviewer_name'      => isset( $data['reviews']['reviewer_name'] ) ? 1 : 0,
        'review_content'     => isset( $data['reviews']['review_content'] ) ? 1 : 0,
    ];

    // Reports
    $sanitized['analytics'] = [
        'show_sale_analytics' => isset( $data['analytics']['show_sale_analytics'] ) ? 1 : 0,
        'show_bi_analytics'   => isset( $data['analytics']['show_bi_analytics'] ) ? 1 : 0,
    ];

    // Coupons
    $sanitized['coupons'] = [
        'access_coupons'      => isset( $data['coupons']['access_coupons'] ) ? 1 : 0,
    ];

    // Settings
    $sanitized['settings'] = [
        'show_shop_settings' => isset( $data['settings']['show_shop_settings'] ) ? 1 : 0,
    ];

    // App features
    $sanitized['app_features'] = [
        'access_extensions'  => isset( $data['app_features']['access_extensions'] ) ? 1 : 0,
        'extensions'         => isset( $data['app_features']['extensions'] ) && is_array( $data['app_features']['extensions'] )
            ? array_map( 'sanitize_text_field', $data['app_features']['extensions'] )
            : array(),
    ];

    return $sanitized;
}


// ---------------------------------------------------------------------------
// AJAX
// ---------------------------------------------------------------------------

/** Add a permission role from admin. */
add_action( 'wp_ajax_hippoo_add_permission_role', 'hippoo_permissions_ajax_add_role' );

function hippoo_permissions_ajax_add_role() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );

    $role_key = sanitize_key( $_POST['role_key'] ?? '' );

    if ( ! $role_key || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Invalid request.', 'hippoo' ) );
    }

    $settings = get_option( 'hippoo_permissions_settings', array() );

    if ( isset( $settings[$role_key] ) ) {
        wp_send_json_error( __( 'Role already exists.', 'hippoo' ) );
    }

    $role_settings = array();
    $settings[$role_key] = $role_settings;

    update_option( 'hippoo_permissions_settings', $settings );

    ob_start();
    hippoo_permissions_render_role_card( $role_key, $role_settings, true );
    $card = ob_get_clean();

    wp_send_json_success( array( 'card' => $card ) );
}

/** Save a permission role from admin. */
add_action( 'wp_ajax_hippoo_save_permission_role', 'hippoo_permissions_ajax_save_role' );

function hippoo_permissions_ajax_save_role() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );
    
    $role_key = sanitize_key( $_POST['role_key'] ?? '' );

    if ( ! $role_key || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Invalid request.', 'hippoo' ) );
    }

    $settings = get_option( 'hippoo_permissions_settings', array() );
    $role_settings = $_POST['hippoo_permissions_settings'][$role_key] ?? array();

    $settings[$role_key] = hippoo_permissions_sanitize_role_settings( $role_settings );
    
    update_option( 'hippoo_permissions_settings', $settings );

    wp_send_json_success();
}

/** Delete a permission role from admin. */
add_action( 'wp_ajax_hippoo_delete_permission_role', 'hippoo_permissions_ajax_delete_role' );

function hippoo_permissions_ajax_delete_role() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );

    $role_key = sanitize_key( $_POST['role_key'] ?? '' );

    if ( ! $role_key || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Invalid request.', 'hippoo' ) );
    }

    $settings = get_option( 'hippoo_permissions_settings', array() );
    unset( $settings[$role_key] );

    update_option( 'hippoo_permissions_settings', $settings );

    wp_send_json_success();
}


// ---------------------------------------------------------------------------
// Permission card
// ---------------------------------------------------------------------------

/** Render a permission card for a role. */
function hippoo_permissions_render_role_card( $role_key, $role_settings = [], $expanded = false ) {
    $role_name = wp_roles()->get_names()[$role_key] ?? ucfirst( $role_key );
    ?>
    <div class="permission-block" data-role="<?php echo esc_attr( $role_key ); ?>" data-role-name="<?php echo esc_attr( $role_name ); ?>">
        <div class="permission-header">
            <div class="role-name"><?php
                /* translators: %s: role name */
                printf( esc_html__( '%s permissions', 'hippoo' ), esc_html( $role_name ) );
            ?></div>
            <div class="header-actions">
                <a href="#" class="remove-role"><?php esc_html_e( 'Remove', 'hippoo' ); ?></a>
                <span class="accordion-toggle <?php echo $expanded ? 'open' : ''; ?>"></span>
            </div>
        </div>

        <div class="permission-content" <?php echo $expanded ? 'style="display:block;"' : 'style="display:none;"'; ?>>
            <form class="hippoo-permission-form" method="post">
                <input type="hidden" name="action" value="hippoo_save_permission_role">
                <input type="hidden" name="role_key" value="<?php echo esc_attr( $role_key ); ?>">
                <?php wp_nonce_field( 'hippoo_nonce' ); ?>
                
                <!-- General -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'General', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][general][enable_access]" <?php checked( $role_settings['general']['enable_access'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Enable access for this role', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][general][read_only]" <?php checked( $role_settings['general']['read_only'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Read-only mode', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Orders -->
                <div class="permission-section">
                    <div class="permission-label">
                        <div class="permission-title"><?php esc_html_e( 'Orders', 'hippoo' ); ?></div>
                        <div class="permission-label-actions">
                            <a href="#" class="select-all-btn"><?php esc_html_e( 'Select all', 'hippoo' ); ?></a>
                            <span class="separator"> / </span>
                            <a href="#" class="deselect-all-btn"><?php esc_html_e( 'Deselect all', 'hippoo' ); ?></a>
                        </div>
                    </div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][access_orders]" <?php checked( $role_settings['orders']['access_orders'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access orders', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][order_count]" <?php checked( $role_settings['orders']['order_count'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Order count', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][order_totals]" <?php checked( $role_settings['orders']['order_totals'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Order totals', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][order_details]" <?php checked( $role_settings['orders']['order_details'] ?? 0, 1 ); ?> value="1" class="sub-toggle">
                        <?php esc_html_e( 'Order details', 'hippoo' ); ?>
                    </label>

                    <div class="sub-details">
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][name]" <?php checked( $role_settings['orders']['name'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Name', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][items]" <?php checked( $role_settings['orders']['items'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Items', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][taxes]" <?php checked( $role_settings['orders']['taxes'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Taxes', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][shipping_info]" <?php checked( $role_settings['orders']['shipping_info'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Shipping info', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][order_statuses]" <?php checked( $role_settings['orders']['order_statuses'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Order statuses', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][coupon_info]" <?php checked( $role_settings['orders']['coupon_info'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Coupon info', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][payment_info]" <?php checked( $role_settings['orders']['payment_info'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Payment info', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][customer_info]" <?php checked( $role_settings['orders']['customer_info'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Customer info', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][order_notes]" <?php checked( $role_settings['orders']['order_notes'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Order note', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][custom_fields]" <?php checked( $role_settings['orders']['custom_fields'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Order custom fields', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][customer_note]" <?php checked( $role_settings['orders']['customer_note'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Customer note', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][invoice]" <?php checked( $role_settings['orders']['invoice'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Invoice', 'hippoo' ); ?>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][shipping_label]" <?php checked( $role_settings['orders']['shipping_label'] ?? 0, 1 ); ?> value="1">
                            <?php esc_html_e( 'Shipping label', 'hippoo' ); ?>
                        </label>
                    </div>

                    <div class="multi-select-group">
                        <label><?php esc_html_e( 'Allowed order status', 'hippoo' ); ?></label>
                        <select name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][orders][allowed_status][]" multiple class="hippoo-select2">
                            <?php
                            $statuses = wc_get_order_statuses();
                            $selected_statuses = $role_settings['orders']['allowed_status'] ?? [];
                            foreach ( $statuses as $status_key => $status_label ) {
                                $sel = in_array( $status_key, $selected_statuses ) ? 'selected' : '';
                                echo '<option value="' . esc_attr( $status_key ) . '" ' . esc_attr( $sel ) . '>' . esc_html( $status_label ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <hr>

                <!-- Products -->
                <div class="permission-section">
                    <div class="permission-label">
                        <div class="permission-title"><?php esc_html_e( 'Products', 'hippoo' ); ?></div>
                        <div class="permission-label-actions">
                            <a href="#" class="select-all-btn"><?php esc_html_e( 'Select all', 'hippoo' ); ?></a>
                            <span class="separator"> / </span>
                            <a href="#" class="deselect-all-btn"><?php esc_html_e( 'Deselect all', 'hippoo' ); ?></a>
                        </div>
                    </div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][access_products]" <?php checked( $role_settings['products']['access_products'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access products', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][product_name]" <?php checked( $role_settings['products']['product_name'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Product name', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][prices]" <?php checked( $role_settings['products']['prices'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Prices', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][stock_quantity]" <?php checked( $role_settings['products']['stock_quantity'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Stock quantity', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][out_of_stock_list]" <?php checked( $role_settings['products']['out_of_stock_list'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Out of stock list', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][sku]" <?php checked( $role_settings['products']['sku'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'SKU', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][status]" <?php checked( $role_settings['products']['status'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Status', 'hippoo' ); ?>
                    </label>

                    <div class="multi-select-group">
                        <label><?php esc_html_e( 'Limit to categories', 'hippoo' ); ?></label>
                        <select name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][categories][]" multiple class="hippoo-select2">
                            <?php
                            $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'id=>name' ) );
                            $selected_cats = $role_settings['products']['categories'] ?? [];
                            foreach ( $categories as $cat_id => $cat_name ) {
                                $sel = in_array( $cat_id, $selected_cats ) ? 'selected' : '';
                                echo '<option value="' . esc_attr( $cat_id ) . '" ' . esc_attr( $sel ) . '>' . esc_html( $cat_name ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="multi-select-group">
                        <label><?php esc_html_e( 'Limit to product type', 'hippoo' ); ?></label>
                        <select name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][types][]" multiple class="hippoo-select2">
                            <?php
                            $product_types = wc_get_product_types();
                            $selected_types = $role_settings['products']['types'] ?? [];
                            foreach ( $product_types as $type_key => $type_label ) {
                                $sel = in_array( $type_key, $selected_types ) ? 'selected' : '';
                                echo '<option value="' . esc_attr( $type_key ) . '" ' . esc_attr( $sel ) . '>' . esc_html( $type_label ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <hr>

                <!-- Customers -->
                <div class="permission-section">
                    <div class="permission-label">
                        <div class="permission-title"><?php esc_html_e( 'Customers', 'hippoo' ); ?></div>
                        <div class="permission-label-actions">
                            <a href="#" class="select-all-btn"><?php esc_html_e( 'Select all', 'hippoo' ); ?></a>
                            <span class="separator"> / </span>
                            <a href="#" class="deselect-all-btn"><?php esc_html_e( 'Deselect all', 'hippoo' ); ?></a>
                        </div>
                    </div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][customers][access_customers]" <?php checked( $role_settings['customers']['access_customers'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access customers', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][customers][name]" <?php checked( $role_settings['customers']['name'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Name', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][customers][address]" <?php checked( $role_settings['customers']['address'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Address', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][customers][phone]" <?php checked( $role_settings['customers']['phone'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Phone number', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][customers][email]" <?php checked( $role_settings['customers']['email'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Email', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Reviews -->
                <div class="permission-section">
                    <div class="permission-label">
                        <div class="permission-title"><?php esc_html_e( 'Reviews', 'hippoo' ); ?></div>
                        <div class="permission-label-actions">
                            <a href="#" class="select-all-btn"><?php esc_html_e( 'Select all', 'hippoo' ); ?></a>
                            <span class="separator"> / </span>
                            <a href="#" class="deselect-all-btn"><?php esc_html_e( 'Deselect all', 'hippoo' ); ?></a>
                        </div>
                    </div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][reviews][access_reviews]" <?php checked( $role_settings['reviews']['access_reviews'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access reviews', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][reviews][reviewer_name]" <?php checked( $role_settings['reviews']['reviewer_name'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Reviewer name', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][reviews][review_content]" <?php checked( $role_settings['reviews']['review_content'] ?? 0, 1 ); ?> value="1">
                        <?php esc_html_e( 'Review content', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Reports -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'Sale analytics', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][analytics][show_sale_analytics]" <?php checked( $role_settings['analytics']['show_sale_analytics'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Show sale analytics', 'hippoo' ); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][analytics][show_bi_analytics]" <?php checked( $role_settings['analytics']['show_bi_analytics'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Business intelligence', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Coupons -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'Coupons', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][coupons][access_coupons]" <?php checked( $role_settings['coupons']['access_coupons'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access coupons', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Product categories -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e('Product categories', 'hippoo'); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][access_categories]" <?php checked( $role_settings['products']['access_categories'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access product categories', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Product attributes -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'Product attributes', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][access_attributes]" <?php checked( $role_settings['products']['access_attributes'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access product attributes', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Product tags -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'Product tags', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][access_tags]" <?php checked( $role_settings['products']['access_tags'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access product tags', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Product brands -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'Product brands', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][access_brands]" <?php checked( $role_settings['products']['access_brands'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access product brands', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Product shipping classes -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'Product shipping classes', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][products][access_shipping_classes]" <?php checked( $role_settings['products']['access_shipping_classes'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access product shipping classes', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- Settings -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'Settings', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][settings][show_shop_settings]" <?php checked( $role_settings['settings']['show_shop_settings'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Show shop settings', 'hippoo' ); ?>
                    </label>
                </div>
                <hr>

                <!-- App features / Extensions -->
                <div class="permission-section">
                    <div class="permission-label"><?php esc_html_e( 'App features', 'hippoo' ); ?></div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][app_features][access_extensions]" <?php checked( $role_settings['app_features']['access_extensions'] ?? 0, 1 ); ?> value="1" class="section-toggle">
                        <?php esc_html_e( 'Access extensions', 'hippoo' ); ?>
                    </label>

                    <div class="multi-select-group">
                        <label><?php esc_html_e( 'Limit to selected extensions', 'hippoo' ); ?></label>
                        <select name="hippoo_permissions_settings[<?php echo esc_attr( $role_key ); ?>][app_features][extensions][]" multiple class="hippoo-select2">
                            <?php
                            $extensions = hippoo_integrations_get_products();
                            $selected_ext = $role_settings['app_features']['extensions'] ?? [];
                            if ( $extensions ) {
                                foreach ( $extensions as $extension ) {
                                    $sel = in_array( $extension['slug'], $selected_ext ) ? 'selected' : '';
                                    echo '<option value="' . esc_attr( $extension['slug'] ) . '" ' . esc_attr( $sel ) . '>' . esc_html( $extension['name'] ) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="permission-actions">
                    <div class="notice inline save-settings-notice">
                        <p><?php esc_html_e( 'Settings updated successfully.', 'hippoo' ); ?></p>
                    </div>
                    <button type="submit" class="button button-primary save-role-settings">
                        <?php esc_html_e( 'Save changes', 'hippoo' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="delete-confirm-modal" style="display:none;">
            <div class="modal-content">
                <span class="close-delete-modal"></span>
                <h4><?php
                    /* translators: %s: role name */
                    printf( esc_html__( 'Delete %s role permissions?', 'hippoo' ), esc_html( $role_name ) );
                ?></h4>
                <p><?php esc_html_e( 'This will remove all custom access rules for this role. This action can’t be undone.', 'hippoo' ); ?></p>
                <div class="modal-actions">
                    <button class="button cancel-delete"><?php esc_html_e( 'Cancel', 'hippoo' ); ?></button>
                    <button class="button confirm-delete"><?php esc_html_e( 'Delete', 'hippoo' ); ?></button>
                </div>
            </div>
        </div>
    </div>
    <?php
}
