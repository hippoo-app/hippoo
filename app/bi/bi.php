<?php
/**
 * Hippoo BI – module entry
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/cron.php';
require_once __DIR__ . '/rest.php';
