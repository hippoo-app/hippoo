<?php
/**
 * Plugin Name: Hippoo Mobile app for WooCommerce
 * Version: 1.12.1
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

define( 'HIPPOO_VERSION', '1.12.1' );
define( 'HIPPOO_MAIN_FILE_PATH', __FILE__ );
define( 'HIPPOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'HIPPOO_URL', plugin_dir_url( __FILE__ ) . 'assets/' );
define( 'HIPPOO_PROXY_NOTIFICTION_URL', 'https://hippoo.app/wp-json/woohouse/v1/fb/proxy_notification' );


require_once HIPPOO_PATH . 'vendor/autoload.php';

// Core
require_once HIPPOO_PATH . 'app/utils.php';
require_once HIPPOO_PATH . 'app/ability_catalog.php';
require_once HIPPOO_PATH . 'app/ability.php';
require_once HIPPOO_PATH . 'app/settings.php';
require_once HIPPOO_PATH . 'app/notifications.php';
require_once HIPPOO_PATH . 'app/rest.php';
require_once HIPPOO_PATH . 'app/app.php';

// Feature modules
require_once HIPPOO_PATH . 'app/pwa/pwa.php';
require_once HIPPOO_PATH . 'app/ai/ai.php';
require_once HIPPOO_PATH . 'app/bi/bi.php';
require_once HIPPOO_PATH . 'app/permissions/permissions.php';
require_once HIPPOO_PATH . 'app/integrations/integrations.php';
require_once HIPPOO_PATH . 'app/compatibility/compatibility.php';
require_once HIPPOO_PATH . 'app/event-notif/event-notif.php';
require_once HIPPOO_PATH . 'app/weekly-notif/weekly-notif.php';
