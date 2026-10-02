<?php
/**
 * Hippoo Event Notification – database tables
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Database constants
// ---------------------------------------------------------------------------

define( 'HIPPOO_EVENT_NOTIFICATION_DB_VERSION', '1.0.0' );

define( 'HIPPOO_EVENT_NOTIFICATION_TABLE', 'hippoo_event_notifications' );


// ---------------------------------------------------------------------------
// Database initialization
// ---------------------------------------------------------------------------

/** Register event notification database initialization. */
add_action( 'plugins_loaded', 'hippoo_event_notification_init_database' );

function hippoo_event_notification_init_database() {
    $current_version = get_option( 'hippoo_event_notification_db_version', '0' );

    if ( $current_version === HIPPOO_EVENT_NOTIFICATION_DB_VERSION ) {
        return;
    }

    global $wpdb;

    $table_name      = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        event VARCHAR(255) NOT NULL,
        sound VARCHAR(255) DEFAULT '',
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event (event),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( $sql );

    update_option( 'hippoo_event_notification_db_version', HIPPOO_EVENT_NOTIFICATION_DB_VERSION );

    do_action( 'hippoo_event_notification_database_migrated', HIPPOO_EVENT_NOTIFICATION_DB_VERSION, $current_version );
}


// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

/** Get all event notifications. */
function hippoo_event_notification_get_all( $page = 1, $per_page = 10 ) {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;

    $page     = max( 1, (int) $page );
    $per_page = max( 1, min( 100, (int) $per_page ) );
    $offset   = ( $page - 1 ) * $per_page;

    $notifications = $wpdb->get_results( $wpdb->prepare( "
        SELECT * FROM $table
        ORDER BY created_at DESC
        LIMIT %d OFFSET %d
    ", $per_page, $offset ), ARRAY_A );

    return $notifications;
}

/** Get all event notifications for hook registration. */
function hippoo_event_notification_get_all_for_hooks() {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;

    $notifications = $wpdb->get_results( "SELECT id, event FROM $table", ARRAY_A );

    return $notifications;
}

/** Get event notification by ID. */
function hippoo_event_notification_get( $id ) {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;

    $notification = $wpdb->get_row( $wpdb->prepare( "
        SELECT * FROM $table WHERE id = %d
    ", (int) $id ), ARRAY_A );

    return $notification;
}

/** Get event notification count. */
function hippoo_event_notification_count() {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

    return $count;
}

/** Insert event notification. */
function hippoo_event_notification_insert( $data ) {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;

    $inserted = $wpdb->insert( $table, $data );

    if ( ! $inserted ) {
        return 0;
    }

    return (int) $wpdb->insert_id;
}

/** Update event notification. */
function hippoo_event_notification_update( $id, $data ) {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;

    $updated = $wpdb->update( $table, $data, array( 'id' => (int) $id ) );

    return $updated;
}

/** Delete event notification. */
function hippoo_event_notification_delete( $id ) {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_EVENT_NOTIFICATION_TABLE;

    $deleted = $wpdb->delete( $table, array( 'id' => (int) $id ) );

    return $deleted;
}
