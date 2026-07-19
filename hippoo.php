<?php
/**
 * Plugin Name: Hippoo Mobile app for WooCommerce
 * Version: 1.10.0
 * Plugin URI: https://Hippoo.app/
 * Description: Best WooCommerce App Alternative – Manage orders and products on the go with real-time notifications, seamless order and product management, and powerful add-ons. Available for Android & iOS. 🚀.
 * Short Description: Best WooCommerce App Alternative – Manage orders and products on the go with real-time notifications, seamless order and product management, and powerful add-ons. Available for Android & iOS. 🚀.
 * Author: Hippoo Team
 * Author URI: https://Hippoo.app/
 * Text Domain: hippoo
 * Domain Path: /languages
 * License: GPL3
 *
 * Hippoo! is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Hippoo! is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Hippoo!.
 **/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('HIPPOO_VERSION', '1.10.0');
define('HIPPOO_MAIN_FILE_PATH', __FILE__);
define('HIPPOO_PATH', plugin_dir_path(__FILE__));
define('HIPPOO_URL', plugin_dir_url(__FILE__) . 'assets/');
define('HIPPOO_PROXY_NOTIFICTION_URL', 'https://hippoo.app/wp-json/woohouse/v1/fb/proxy_notification');

# This is used by hippoo_pif_get_url_attachment
require_once ABSPATH . 'wp-admin/includes/image.php';

require_once HIPPOO_PATH . 'vendor/autoload.php';
include_once HIPPOO_PATH . 'app/utils.php';
include_once HIPPOO_PATH . 'app/web_api.php';
include_once HIPPOO_PATH . 'app/settings.php';
include_once HIPPOO_PATH . 'app/pwa.php';
include_once HIPPOO_PATH . 'app/ai.php';
include_once HIPPOO_PATH . 'app/bi.php';
include_once HIPPOO_PATH . 'app/permissions.php';
include_once HIPPOO_PATH . 'app/integrations.php';
include_once HIPPOO_PATH . 'app/compatibility.php';
include_once HIPPOO_PATH . 'app/app.php';