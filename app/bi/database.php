<?php
/**
 * Hippoo BI – database tables and migrations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Database constants
// ---------------------------------------------------------------------------

define( 'HIPPOO_BI_DB_VERSION', '1.1.0' );

define( 'HIPPOO_BI_TABLE_PAGEVIEWS', 'hippoo_pageviews' );
define( 'HIPPOO_BI_TABLE_ADD_TO_CARTS', 'hippoo_add_to_carts' );
define( 'HIPPOO_BI_TABLE_CHURN_SCORES', 'hippoo_churn_scores' );
define( 'HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP', 'hippoo_order_product_lookup' );
define( 'HIPPOO_BI_TABLE_ORDER_STATS', 'hippoo_order_stats' );


// ---------------------------------------------------------------------------
// Database initialization
// ---------------------------------------------------------------------------

/** Register BI database initialization. */
add_action( 'plugins_loaded', 'hippoo_bi_init_database' );

function hippoo_bi_init_database() {
    $current_version = get_option( 'hippoo_bi_db_version', '0' );

    if ( $current_version === HIPPOO_BI_DB_VERSION ) {
        return;
    }

    global $wpdb;

    $table_pageviews = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $sql_pageviews = "CREATE TABLE IF NOT EXISTS $table_pageviews (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id CHAR(16) NOT NULL,
        page_url VARCHAR(500) NOT NULL,
        referrer_source VARCHAR(100) DEFAULT NULL,
        device_type ENUM('m','t','d') DEFAULT 'd',
        country CHAR(2) DEFAULT NULL,
        product_id BIGINT(20) UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_product (product_id, created_at),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_atc = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $sql_atc = "CREATE TABLE IF NOT EXISTS $table_atc (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id CHAR(16) NOT NULL,
        product_id BIGINT(20) UNSIGNED NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_product (product_id, created_at),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_churn = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;
    $sql_churn = "CREATE TABLE IF NOT EXISTS $table_churn (
        email VARCHAR(100) NOT NULL,
        customer_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        churn_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('active','at_risk','high_risk','churned') NOT NULL,
        first_order_date DATE DEFAULT NULL,
        last_order_date DATE DEFAULT NULL,
        total_orders SMALLINT UNSIGNED DEFAULT 0,
        total_spent DECIMAL(13,2) DEFAULT 0.00,
        clv DECIMAL(13,2) DEFAULT 0.00,
        avg_days_between DECIMAL(8,1) DEFAULT 0.0,
        calculated_at DATETIME NOT NULL,
        PRIMARY KEY (email),
        KEY idx_customer_id (customer_id),
        KEY idx_status (status),
        KEY idx_score (churn_score DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $sql_lookup = "CREATE TABLE IF NOT EXISTS $table_lookup (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT(20) UNSIGNED NOT NULL,
        product_id BIGINT(20) UNSIGNED NOT NULL,
        customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
        date_created DATETIME NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        revenue DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        KEY idx_order_id (order_id),
        KEY idx_product_id (product_id),
        KEY idx_customer_id (customer_id),
        KEY idx_product_customer (product_id, customer_id, date_created),
        KEY idx_date_created (date_created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_stats = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $sql_stats = "CREATE TABLE IF NOT EXISTS $table_stats (
        order_id BIGINT(20) UNSIGNED NOT NULL,
        customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
        billing_email VARCHAR(100) DEFAULT NULL,
        order_status VARCHAR(20) DEFAULT NULL,
        date_created DATETIME NOT NULL,
        total DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        net DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        refund DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        shipping DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (order_id),
        KEY idx_customer_id (customer_id),
        KEY idx_billing_email (billing_email),
        KEY idx_date_created (date_created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( $sql_pageviews );
    dbDelta( $sql_atc );
    dbDelta( $sql_churn );
    dbDelta( $sql_lookup );
    dbDelta( $sql_stats );

    // Migrations
    if ( version_compare( $current_version, '1.1.0', '<' ) ) {
        hippoo_bi_migrate_to_110();
    }

    update_option( 'hippoo_bi_db_version', HIPPOO_BI_DB_VERSION );

    do_action( 'hippoo_bi_database_migrated', HIPPOO_BI_DB_VERSION, $current_version );
}


// ---------------------------------------------------------------------------
// Migrations
// ---------------------------------------------------------------------------

/** Migrate BI database to version 1.1.0. */
function hippoo_bi_migrate_to_110() {
    global $wpdb;

    $churn  = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;
    $stats  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;

    // order_stats: add missing columns
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM $stats", 0 );
    $add = array(
        'billing_email' => "ADD COLUMN billing_email VARCHAR(100) DEFAULT NULL AFTER customer_id, ADD KEY idx_billing_email (billing_email)",
        'order_status'  => "ADD COLUMN order_status VARCHAR(20) DEFAULT NULL AFTER billing_email",
        'discount'      => "ADD COLUMN discount DECIMAL(13,2) NOT NULL DEFAULT 0.00 AFTER refund",
        'shipping'      => "ADD COLUMN shipping DECIMAL(13,2) NOT NULL DEFAULT 0.00 AFTER discount",
        'tax'           => "ADD COLUMN tax DECIMAL(13,2) NOT NULL DEFAULT 0.00 AFTER shipping",
    );
    foreach ( $add as $column => $sql ) {
        if ( ! in_array( $column, $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $stats $sql" );
        }
    }

    // churn_scores: switch PK to email
    $churn_cols = $wpdb->get_col( "SHOW COLUMNS FROM $churn", 0 );
    if ( ! in_array( 'email', $churn_cols, true ) ) {
        $wpdb->query( "DROP TABLE IF EXISTS $churn" );
        $wpdb->query( "CREATE TABLE $churn (
            email VARCHAR(100) NOT NULL,
            customer_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            churn_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','at_risk','high_risk','churned') NOT NULL,
            first_order_date DATE DEFAULT NULL,
            last_order_date DATE DEFAULT NULL,
            total_orders SMALLINT UNSIGNED DEFAULT 0,
            total_spent DECIMAL(13,2) DEFAULT 0.00,
            clv DECIMAL(13,2) DEFAULT 0.00,
            avg_days_between DECIMAL(8,1) DEFAULT 0.0,
            calculated_at DATETIME NOT NULL,
            PRIMARY KEY (email),
            KEY idx_customer_id (customer_id),
            KEY idx_status (status),
            KEY idx_score (churn_score DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
    } else {
        if ( ! in_array( 'first_order_date', $churn_cols, true ) ) {
            $wpdb->query( "ALTER TABLE $churn ADD COLUMN first_order_date DATE DEFAULT NULL AFTER status" );
        }
        if ( ! in_array( 'avg_days_between', $churn_cols, true ) ) {
            $wpdb->query( "ALTER TABLE $churn ADD COLUMN avg_days_between DECIMAL(8,1) DEFAULT 0.0 AFTER clv" );
        }
    }

    // Reset data
    $wpdb->query( "TRUNCATE TABLE $stats" );
    $wpdb->query( "TRUNCATE TABLE $lookup" );
    $wpdb->query( "TRUNCATE TABLE $churn" );
}
