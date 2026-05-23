<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action('rest_api_init', function () {
    require_once __DIR__ . '/web_api_auth.php';
    $controller = new HippooInvoiceControllerWithAuth();
    $controller->register_routes();
});