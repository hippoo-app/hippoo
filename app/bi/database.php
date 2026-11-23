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

define( 'HIPPOO_BI_DB_VERSION', '1.3.1' );

define( 'HIPPOO_BI_TABLE_PATHS', 'hippoo_bi_paths' );
define( 'HIPPOO_BI_TABLE_REFERRERS', 'hippoo_bi_referrers' );

define( 'HIPPOO_BI_TABLE_PAGEVIEWS', 'hippoo_bi_pageviews' );
define( 'HIPPOO_BI_TABLE_ADD_TO_CARTS', 'hippoo_bi_add_to_carts' );

define( 'HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP', 'hippoo_bi_order_product_lookup' );
define( 'HIPPOO_BI_TABLE_ORDER_STATS', 'hippoo_bi_order_stats' );
define( 'HIPPOO_BI_TABLE_CHURN_SCORES', 'hippoo_bi_churn_scores' );

define( 'HIPPOO_BI_TABLE_SITE_STATS', 'hippoo_bi_site_stats' );
define( 'HIPPOO_BI_TABLE_POST_STATS', 'hippoo_bi_post_stats' );
define( 'HIPPOO_BI_TABLE_REFERRER_STATS', 'hippoo_bi_referrer_stats' );
define( 'HIPPOO_BI_TABLE_VISITORS', 'hippoo_bi_visitors' );


// ---------------------------------------------------------------------------
// Database initialization
// ---------------------------------------------------------------------------

/** Schema migrations, keyed by version. */
function hippoo_bi_database_schema_migrations() {
    return array(
        '1.1.0' => 'hippoo_bi_migrate_to_110',
        '1.2.0' => 'hippoo_bi_migrate_to_120',
        '1.3.0' => 'hippoo_bi_migrate_to_130',
    );
}

/** Background data migrations, keyed by version. */
function hippoo_bi_database_data_migrations() {
    return array(
        '1.3.1' => 'hippoo_bi_migrate_to_131',
    );
}

/** Register BI database initialization. */
add_action( 'plugins_loaded', 'hippoo_bi_database_init' );

function hippoo_bi_database_init() {
    $current_version = get_option( 'hippoo_bi_db_version', '0' );

    if ( $current_version === HIPPOO_BI_DB_VERSION ) {
        return;
    }

    // Prevent concurrent schema changes.
    if ( get_transient( 'hippoo_bi_db_lock' ) ) {
        return;
    }
    set_transient( 'hippoo_bi_db_lock', 1, 5 * MINUTE_IN_SECONDS );

    $schema_versions = array_keys( hippoo_bi_database_schema_migrations() );
    $schema_version  = end( $schema_versions );
    if ( version_compare( $current_version, $schema_version, '<' )
        && ! hippoo_bi_database_create_tables() ) {
        delete_transient( 'hippoo_bi_db_lock' );
        return;
    }

    // Schema only; data runs in the background.
    try {
        foreach ( hippoo_bi_database_schema_migrations() as $version => $callback ) {
            if ( version_compare( $current_version, $version, '<' ) ) {
                $callback();
            }
        }
    } catch ( RuntimeException $e ) {
        error_log( 'Hippoo BI database migration failed: ' . $e->getMessage() );
        delete_transient( 'hippoo_bi_db_lock' );
        return;
    }

    // Write the version early so no request repeats this.
    update_option( 'hippoo_bi_db_version', HIPPOO_BI_DB_VERSION );
    delete_transient( 'hippoo_bi_db_lock' );

    do_action( 'hippoo_bi_database_schema_migrated', HIPPOO_BI_DB_VERSION, $current_version );
}

/** Create or update the BI tables, preserving rows from legacy table names. */
function hippoo_bi_database_create_tables() {
    global $wpdb;

    // Rename legacy BI tables before dbDelta creates their new names, preserving existing rows.
    $legacy_tables = array(
        'hippoo_pageviews'             => HIPPOO_BI_TABLE_PAGEVIEWS,
        'hippoo_add_to_carts'          => HIPPOO_BI_TABLE_ADD_TO_CARTS,
        'hippoo_order_product_lookup'  => HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP,
        'hippoo_order_stats'           => HIPPOO_BI_TABLE_ORDER_STATS,
        'hippoo_churn_scores'          => HIPPOO_BI_TABLE_CHURN_SCORES,
        'hippoo_daily_stats'           => HIPPOO_BI_TABLE_SITE_STATS,
        'hippoo_daily_product_stats'   => HIPPOO_BI_TABLE_POST_STATS,
    );
    foreach ( $legacy_tables as $legacy_name => $table_constant ) {
        $legacy_table = $wpdb->prefix . $legacy_name;
        $new_table    = $wpdb->prefix . $table_constant;
        $legacy_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy_table ) ) );
        if ( ! $legacy_exists || $legacy_table === $new_table ) {
            continue;
        }
        $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new_table ) ) );
        if ( $new_exists ) {
            $new_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $new_table" );
            if ( $new_rows > 0 || false === $wpdb->query( "DROP TABLE $new_table" ) ) {
                return false;
            }
        }
        if ( false === $wpdb->query( "RENAME TABLE $legacy_table TO $new_table" ) ) {
            return false;
        }
    }

    $table_paths = $wpdb->prefix . HIPPOO_BI_TABLE_PATHS;
    $sql_paths = "CREATE TABLE IF NOT EXISTS $table_paths (
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        path                VARCHAR(255) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_path (path)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_refs = $wpdb->prefix . HIPPOO_BI_TABLE_REFERRERS;
    $sql_refs = "CREATE TABLE IF NOT EXISTS $table_refs (
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        source              VARCHAR(255) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_source (source)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_pageviews = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $sql_pageviews = "CREATE TABLE IF NOT EXISTS $table_pageviews (
        id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id          CHAR(16) NOT NULL,
        post_id             BIGINT(20) UNSIGNED DEFAULT NULL,
        path_id             INT UNSIGNED NOT NULL DEFAULT 0,
        referrer_id         INT UNSIGNED NOT NULL DEFAULT 0,
        device_type         ENUM('m','t','d') DEFAULT 'd',
        country             CHAR(2) DEFAULT NULL,
        created_at          DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_session (session_id),
        KEY idx_path (path_id),
        KEY idx_post (post_id),
        KEY idx_referrer (referrer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_atc = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $sql_atc = "CREATE TABLE IF NOT EXISTS $table_atc (
        id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id          CHAR(16) NOT NULL,
        product_id          BIGINT(20) UNSIGNED NOT NULL,
        created_at          DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_session (session_id),
        KEY idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_order_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $sql_order_lookup = "CREATE TABLE IF NOT EXISTS $table_order_lookup (
        id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id            BIGINT(20) UNSIGNED NOT NULL,
        product_id          BIGINT(20) UNSIGNED NOT NULL,
        customer_id         BIGINT(20) UNSIGNED DEFAULT NULL,
        date_created        DATETIME NOT NULL,
        quantity            INT UNSIGNED NOT NULL DEFAULT 1,
        revenue             DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        KEY idx_order_id (order_id),
        KEY idx_product_id (product_id),
        KEY idx_customer_id (customer_id),
        KEY idx_date_created (date_created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_order_stats = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $sql_order_stats = "CREATE TABLE IF NOT EXISTS $table_order_stats (
        order_id            BIGINT(20) UNSIGNED NOT NULL,
        customer_id         BIGINT(20) UNSIGNED DEFAULT NULL,
        billing_email       VARCHAR(100) DEFAULT NULL,
        order_status        VARCHAR(20) DEFAULT NULL,
        date_created        DATETIME NOT NULL,
        total               DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        net                 DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        refund              DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        discount            DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        shipping            DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        tax                 DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (order_id),
        KEY idx_customer_id (customer_id),
        KEY idx_billing_email (billing_email),
        KEY idx_date_created (date_created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_churn = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;
    $sql_churn = "CREATE TABLE IF NOT EXISTS $table_churn (
        email               VARCHAR(100) NOT NULL,
        customer_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        churn_score         TINYINT UNSIGNED NOT NULL DEFAULT 0,
        status              ENUM('active','at_risk','high_risk','churned') NOT NULL,
        first_order_date    DATE DEFAULT NULL,
        last_order_date     DATE DEFAULT NULL,
        total_orders        SMALLINT UNSIGNED DEFAULT 0,
        total_spent         DECIMAL(13,2) DEFAULT 0.00,
        clv DECIMAL(13,2)   DEFAULT 0.00,
        avg_days_between    DECIMAL(8,1) DEFAULT 0.0,
        calculated_at       DATETIME NOT NULL,
        PRIMARY KEY (email),
        KEY idx_customer_id (customer_id),
        KEY idx_status (status),
        KEY idx_score (churn_score DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_site_stats = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;
    $sql_site_stats = "CREATE TABLE IF NOT EXISTS $table_site_stats (
        date                DATE NOT NULL,
        views               INT UNSIGNED NOT NULL DEFAULT 0,
        unique_sessions     INT UNSIGNED NOT NULL DEFAULT 0,
        bounce_sessions     INT UNSIGNED NOT NULL DEFAULT 0,
        orders              INT UNSIGNED NOT NULL DEFAULT 0,
        total               DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        net                 DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        refund              DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        mobile_views        INT UNSIGNED NOT NULL DEFAULT 0,
        tablet_views        INT UNSIGNED NOT NULL DEFAULT 0,
        desktop_views       INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_post_stats = $wpdb->prefix . HIPPOO_BI_TABLE_POST_STATS;
    $sql_post_stats = "CREATE TABLE IF NOT EXISTS $table_post_stats (
        date                DATE NOT NULL,
        post_id             BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        path_id             INT UNSIGNED NOT NULL DEFAULT 0,
        views               INT UNSIGNED NOT NULL DEFAULT 0,
        unique_sessions     INT UNSIGNED NOT NULL DEFAULT 0,
        add_to_cart         INT UNSIGNED NOT NULL DEFAULT 0,
        atc_sessions        INT UNSIGNED NOT NULL DEFAULT 0,
        orders              INT UNSIGNED NOT NULL DEFAULT 0,
        revenue             DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (date, post_id, path_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_ref_stats = $wpdb->prefix . HIPPOO_BI_TABLE_REFERRER_STATS;
    $sql_ref_stats = "CREATE TABLE IF NOT EXISTS $table_ref_stats (
        date                DATE NOT NULL,
        referrer_id         INT UNSIGNED NOT NULL,
        views               INT UNSIGNED NOT NULL DEFAULT 0,
        unique_sessions     INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (date, referrer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_visitors = $wpdb->prefix . HIPPOO_BI_TABLE_VISITORS;
    $sql_visitors = "CREATE TABLE IF NOT EXISTS $table_visitors (
        id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        visitor_key         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        first_seen          DATETIME NOT NULL,
        last_seen           DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_visitor_key (visitor_key),
        KEY idx_last_first_seen (last_seen, first_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( $sql_paths );
    dbDelta( $sql_refs );
    dbDelta( $sql_pageviews );
    dbDelta( $sql_atc );
    dbDelta( $sql_order_lookup );
    dbDelta( $sql_order_stats );
    dbDelta( $sql_churn );
    dbDelta( $sql_site_stats );
    dbDelta( $sql_post_stats );
    dbDelta( $sql_ref_stats );
    dbDelta( $sql_visitors );

    return true;
}


// ---------------------------------------------------------------------------
// Schema migrations
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
            email               VARCHAR(100) NOT NULL,
            customer_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            churn_score         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status              ENUM('active','at_risk','high_risk','churned') NOT NULL,
            first_order_date    DATE DEFAULT NULL,
            last_order_date     DATE DEFAULT NULL,
            total_orders        SMALLINT UNSIGNED DEFAULT 0,
            total_spent         DECIMAL(13,2) DEFAULT 0.00,
            clv DECIMAL(13,2)   DEFAULT 0.00,
            avg_days_between    DECIMAL(8,1) DEFAULT 0.0,
            calculated_at       DATETIME NOT NULL,
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

/** Migrate BI database to version 1.2.0 – daily stats tables. */
function hippoo_bi_migrate_to_120() {
    // Intentionally empty – kept for consistency with other migrate_* helpers.
}

/** Migrate BI database to version 1.3.0 – schema only. */
function hippoo_bi_migrate_to_130() {
    global $wpdb;

    $pageviews    = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $add_to_carts = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $order_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $site_stats   = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;
    $post_stats   = $wpdb->prefix . HIPPOO_BI_TABLE_POST_STATS;

    // Reset data
    $wpdb->query( "TRUNCATE TABLE $site_stats" );
    $wpdb->query( "TRUNCATE TABLE $post_stats" );

    // pageviews: add missing columns
    $page_columns = $wpdb->get_col( "SHOW COLUMNS FROM $pageviews", 0 );
    if ( null === $page_columns ) {
        throw new RuntimeException( 'Cannot read pageviews columns.' );
    }
    $page_adds = array(
        'post_id'     => "ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER session_id",
        'path_id'     => "ADD COLUMN path_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER post_id",
        'referrer_id' => "ADD COLUMN referrer_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER path_id",
    );
    foreach ( $page_adds as $column => $sql ) {
        if ( ! in_array( $column, $page_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $pageviews $sql" ) ) {
                throw new RuntimeException( "Failed adding pageviews.$column" );
            }
        }
    }

    // pageviews: indexes
    $page_indexes = $wpdb->get_col( "SHOW INDEX FROM $pageviews", 2 );
    if ( null === $page_indexes ) {
        throw new RuntimeException( 'Cannot read pageviews indexes.' );
    }
    $page_adds = array(
        'idx_created'  => "ADD KEY idx_created (created_at)",
        'idx_session'  => "ADD KEY idx_session (session_id)",
        'idx_path'     => "ADD KEY idx_path (path_id)",
        'idx_post'     => "ADD KEY idx_post (post_id)",
        'idx_referrer' => "ADD KEY idx_referrer (referrer_id)",
    );
    foreach ( $page_adds as $index => $sql ) {
        if ( ! in_array( $index, $page_indexes, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $pageviews $sql" ) ) {
                throw new RuntimeException( "Failed adding pageviews.$index" );
            }
        }
    }

    // add_to_carts: drop legacy columns
    $cart_columns = $wpdb->get_col( "SHOW COLUMNS FROM $add_to_carts", 0 );
    if ( null === $cart_columns ) {
        throw new RuntimeException( 'Cannot read add_to_carts columns.' );
    }
    if ( in_array( 'quantity', $cart_columns, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $add_to_carts DROP COLUMN quantity" ) ) {
            throw new RuntimeException( 'Failed dropping quantity from add_to_carts.' );
        }
    }

    // add_to_carts: indexes
    $cart_indexes = $wpdb->get_col( "SHOW INDEX FROM $add_to_carts", 2 );
    if ( null === $cart_indexes ) {
        throw new RuntimeException( 'Cannot read add_to_carts indexes.' );
    }
    if ( in_array( 'idx_product', $cart_indexes, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $add_to_carts DROP INDEX idx_product" ) ) {
            throw new RuntimeException( 'Failed dropping idx_product from add_to_carts.' );
        }
    }
    $cart_indexes = $wpdb->get_col( "SHOW INDEX FROM $add_to_carts", 2 );
    $cart_adds = array(
        'idx_created' => "ADD KEY idx_created (created_at)",
        'idx_session' => "ADD KEY idx_session (session_id)",
        'idx_product' => "ADD KEY idx_product (product_id)",
    );
    foreach ( $cart_adds as $index => $sql ) {
        if ( ! in_array( $index, $cart_indexes, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $add_to_carts $sql" ) ) {
                throw new RuntimeException( "Failed adding add_to_carts.$index" );
            }
        }
    }

    // order_product_lookup: drop legacy indexes
    $lookup_indexes = $wpdb->get_col( "SHOW INDEX FROM $order_lookup", 2 );
    if ( null === $lookup_indexes ) {
        throw new RuntimeException( 'Cannot read order_product_lookup indexes.' );
    }
    if ( in_array( 'idx_product_customer', $lookup_indexes, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $order_lookup DROP INDEX idx_product_customer" ) ) {
            throw new RuntimeException( 'Failed dropping idx_product_customer on order_product_lookup.' );
        }
    }

    // site_stats: add device columns
    $site_columns = $wpdb->get_col( "SHOW COLUMNS FROM $site_stats", 0 );
    if ( null === $site_columns ) {
        throw new RuntimeException( 'Cannot read site_stats columns.' );
    }
    $site_adds = array(
        'mobile_views'  => "ADD COLUMN mobile_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund",
        'tablet_views'  => "ADD COLUMN tablet_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER mobile_views",
        'desktop_views' => "ADD COLUMN desktop_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER tablet_views",
    );
    foreach ( $site_adds as $column => $sql ) {
        if ( ! in_array( $column, $site_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $site_stats $sql" ) ) {
                throw new RuntimeException( "Failed adding site_stats.$column" );
            }
        }
    }

    // site_stats: drop calculated_at, new/returning visitors & customers
    $site_columns = $wpdb->get_col( "SHOW COLUMNS FROM $site_stats", 0 );
    if ( null === $site_columns ) {
        throw new RuntimeException( 'Cannot read site_stats columns.' );
    }
    foreach ( array( 'calculated_at', 'new_visitors', 'returning_visitors', 'new_customers', 'returning_customers' ) as $column ) {
        if ( in_array( $column, $site_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $site_stats DROP COLUMN $column" ) ) {
                throw new RuntimeException( 'Failed dropping legacy columns from site_stats.' );
            }
        }
    }

    // post_stats: add missing columns
    $post_columns = $wpdb->get_col( "SHOW COLUMNS FROM $post_stats", 0 );
    if ( null === $post_columns ) {
        throw new RuntimeException( 'Cannot read post_stats columns.' );
    }
    $post_adds = array(
        'post_id' => "ADD COLUMN post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER date",
        'path_id' => "ADD COLUMN path_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER post_id",
    );
    foreach ( $post_adds as $column => $sql ) {
        if ( ! in_array( $column, $post_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $post_stats $sql" ) ) {
                throw new RuntimeException( "Failed adding post_stats.$column" );
            }
        }
    }

    // post_stats: primary key columns cannot be nullable
    if ( in_array( 'post_id', $post_columns, true )
        && false === $wpdb->query( "ALTER TABLE $post_stats MODIFY COLUMN post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0" ) ) {
        throw new RuntimeException( 'Failed making post_stats.post_id not nullable.' );
    }

    // post_stats: switch primary key
    $post_indexes = $wpdb->get_col( "SHOW INDEX FROM $post_stats", 2 );
    $post_primary = $wpdb->get_col( "SHOW KEYS FROM $post_stats WHERE Key_name = 'PRIMARY'", 4 );
    if ( null === $post_indexes || null === $post_primary ) {
        throw new RuntimeException( 'Cannot read post_stats indexes.' );
    }
    foreach ( array( 'idx_product_date', 'idx_date' ) as $index ) {
        if ( in_array( $index, $post_indexes, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $post_stats DROP INDEX $index" ) ) {
                throw new RuntimeException( "Failed dropping index $index on post_stats." );
            }
        }
    }
    if ( array( 'date', 'post_id', 'path_id' ) !== $post_primary ) {
        $primary_sql = $post_primary
            ? 'DROP PRIMARY KEY, ADD PRIMARY KEY (date, post_id, path_id)'
            : 'ADD PRIMARY KEY (date, post_id, path_id)';

        if ( false === $wpdb->query( "ALTER TABLE $post_stats $primary_sql" ) ) {
            throw new RuntimeException( 'Failed changing primary key on post_stats.' );
        }
    }

    // post_stats: drop product_id and calculated_at
    $post_columns = $wpdb->get_col( "SHOW COLUMNS FROM $post_stats", 0 );
    foreach ( array( 'product_id', 'calculated_at' ) as $column ) {
        if ( in_array( $column, $post_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $post_stats DROP COLUMN $column" ) ) {
                throw new RuntimeException( 'Failed dropping legacy columns from post_stats.' );
            }
        }
    }
}

/** Migrate BI database to version 1.3.1 – background data conversion. */
function hippoo_bi_migrate_to_131( $progress ) {
    global $wpdb;

    $pageviews  = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $paths      = $wpdb->prefix . HIPPOO_BI_TABLE_PATHS;
    $refs       = $wpdb->prefix . HIPPOO_BI_TABLE_REFERRERS;
    $site_stats = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;
    $post_stats = $wpdb->prefix . HIPPOO_BI_TABLE_POST_STATS;

    $columns = $wpdb->get_col( "SHOW COLUMNS FROM $pageviews", 0 );
    if ( null === $columns ) {
        throw new RuntimeException( 'Cannot read pageviews structure.' );
    }

    $has_product     = in_array( 'product_id', $columns, true );
    $has_url         = in_array( 'page_url', $columns, true );
    $has_source      = in_array( 'referrer_source', $columns, true );
    $has_legacy_data = $has_product || $has_url || $has_source;

    // First call: seed the default labels and reset aggregates once.
    if ( ! $progress ) {
        $wpdb->query( "INSERT IGNORE INTO $paths (path) VALUES ('/')" );
        if ( false === $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $refs (source) VALUES (%s)",
            'direct'
        ) ) ) {
            throw new RuntimeException( 'Failed ensuring direct referrer label.' );
        }
        if ( $has_legacy_data ) {
            $wpdb->query( "TRUNCATE TABLE $site_stats" );
            $wpdb->query( "TRUNCATE TABLE $post_stats" );
        }
        $progress = array( 'started' => 1 );
    }

    $batch = 200;
    $needs = array( 'path_id = 0', 'referrer_id = 0' );
    if ( $has_product ) {
        $needs[] = '(post_id IS NULL AND product_id IS NOT NULL)';
    }

    $ids = $wpdb->get_col( "SELECT id FROM $pageviews WHERE " . implode( ' OR ', $needs ) . " ORDER BY id LIMIT $batch" );
    if ( null === $ids ) {
        throw new RuntimeException( 'Failed reading pageviews batch.' );
    }

    if ( $ids ) {
        $ids       = implode( ',', array_map( 'absint', $ids ) );
        $where     = "id IN ($ids)";
        $raw_where = "raw.id IN ($ids)";

        $path_id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $paths WHERE path = %s", '/' ) );
        $direct_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $refs WHERE source = %s", 'direct' ) );
        if ( ! $path_id || ! $direct_id ) {
            throw new RuntimeException( 'Failed reading default path or referrer.' );
        }

        // pageviews: map product_id to post_id
        if ( $has_product ) {
            if ( false === $wpdb->query(
                "UPDATE $pageviews SET post_id = product_id WHERE $where AND post_id IS NULL AND product_id IS NOT NULL"
            ) ) {
                throw new RuntimeException( 'Failed mapping product_id to post_id on pageviews.' );
            }
        }

        // pageviews: convert page_url to path_id
        if ( $has_url ) {
            $path_expr = "CASE
                WHEN LOCATE('://', page_url) > 0 THEN
                    CASE WHEN LOCATE('/', page_url, LOCATE('://', page_url) + 3) = 0
                        THEN '/' ELSE SUBSTRING(page_url, LOCATE('/', page_url, LOCATE('://', page_url) + 3)) END
                ELSE page_url
            END";
            $path_expr = "SUBSTRING_INDEX(SUBSTRING_INDEX($path_expr, '?', 1), '#', 1)";
            $path_expr = "TRIM(TRAILING '/' FROM $path_expr)";
            $path_expr = "LEFT(CASE WHEN $path_expr = '' THEN '/' ELSE $path_expr END, 255)";
            // Legacy and lookup tables may use different charsets/collations.
            $path_label_expr = 'CONVERT(' . $path_expr . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
            $raw_path_expr   = 'CONVERT(' . str_replace( 'page_url', 'raw.page_url', $path_expr ) . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';

            if ( false === $wpdb->query(
                "INSERT IGNORE INTO $paths (path)
                 SELECT DISTINCT $path_label_expr FROM $pageviews
                 WHERE path_id = 0 AND $where"
            ) ) {
                throw new RuntimeException( 'Failed inserting paths from page_url.' );
            }
            if ( false === $wpdb->query(
                "UPDATE $pageviews raw
                 INNER JOIN $paths p ON CONVERT(p.path USING utf8mb4) COLLATE utf8mb4_unicode_ci = $raw_path_expr
                 SET raw.path_id = p.id
                 WHERE $raw_where AND raw.path_id = 0"
            ) ) {
                throw new RuntimeException( 'Failed updating path_id on pageviews.' );
            }
        }

        // pageviews: convert referrer_source to referrer_id
        if ( $has_source ) {
            $source_expr     = "LEFT(COALESCE(NULLIF(LOWER(TRIM(referrer_source)), ''), 'direct'), 255)";
            $label_expr      = 'CONVERT(' . $source_expr . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
            $raw_source_expr = 'CONVERT(' . str_replace( 'referrer_source', 'raw.referrer_source', $source_expr ) . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';

            if ( false === $wpdb->query(
                "INSERT IGNORE INTO $refs (source)
                 SELECT DISTINCT $label_expr FROM $pageviews
                 WHERE referrer_id = 0 AND $where"
            ) ) {
                throw new RuntimeException( 'Failed inserting referrer labels.' );
            }
            if ( false === $wpdb->query(
                "UPDATE $pageviews raw
                 INNER JOIN $refs label ON CONVERT(label.source USING utf8mb4) COLLATE utf8mb4_unicode_ci = $raw_source_expr
                 SET raw.referrer_id = label.id
                 WHERE $raw_where AND raw.referrer_id = 0"
            ) ) {
                throw new RuntimeException( 'Failed updating referrer_id on pageviews.' );
            }
        }

        if ( false === $wpdb->query( "UPDATE $pageviews SET path_id = $path_id WHERE $where AND path_id = 0" )
            || false === $wpdb->query( "UPDATE $pageviews SET referrer_id = $direct_id WHERE $where AND referrer_id = 0" ) ) {
            throw new RuntimeException( 'Failed setting default path_id or referrer_id.' );
        }

        return $progress;
    }

    if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $pageviews WHERE path_id = 0" ) > 0 ) {
        throw new RuntimeException( 'Some pageviews still have path_id = 0.' );
    }

    // pageviews: drop legacy columns
    foreach ( array( 'page_url', 'referrer_source', 'product_id' ) as $column ) {
        if ( in_array( $column, $columns, true )
            && false === $wpdb->query( "ALTER TABLE $pageviews DROP COLUMN $column" ) ) {
            throw new RuntimeException( "Failed dropping pageviews.$column." );
        }
    }

    $indexes = $wpdb->get_col( "SHOW INDEX FROM $pageviews", 2 );
    if ( null === $indexes ) {
        throw new RuntimeException( 'Cannot read pageviews indexes.' );
    }
    if ( in_array( 'idx_product', $indexes, true )
        && false === $wpdb->query( "ALTER TABLE $pageviews DROP INDEX idx_product" ) ) {
        throw new RuntimeException( 'Failed dropping idx_product from pageviews.' );
    }

    $progress['done'] = 1;
    return $progress;
}


// ---------------------------------------------------------------------------
// Background data migrations
// ---------------------------------------------------------------------------

/** Process one batch of the next pending data migration. */
add_action( 'hippoo_bi_database_migrate', 'hippoo_bi_database_migrate' );

function hippoo_bi_database_migrate() {
    $progress = get_option( 'hippoo_bi_db_migrations' );
    if ( ! is_array( $progress ) ) {
        $progress = array();
    }

    foreach ( hippoo_bi_database_data_migrations() as $version => $callback ) {
        if ( ! empty( $progress[ $version ]['done'] ) ) {
            continue;
        }

        $current = isset( $progress[ $version ] ) ? $progress[ $version ] : array();
        try {
            $progress[ $version ] = call_user_func( $callback, $current );
        } catch ( RuntimeException $e ) {
            error_log( 'Hippoo BI data migration ' . $version . ' failed: ' . $e->getMessage() );
            $progress[ $version ] = $current;
        }

        update_option( 'hippoo_bi_db_migrations', $progress );

        // Move to the next migration once this one is done; otherwise continue its batches.
        if ( empty( $progress[ $version ]['done'] ) ) {
            wp_schedule_single_event( time() + 1, 'hippoo_bi_database_migrate' );
            return;
        }
    }
}
