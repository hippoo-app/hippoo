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

define( 'HIPPOO_BI_DB_VERSION', '1.3.0' );

define( 'HIPPOO_BI_TABLE_PATHS', 'hippoo_bi_paths' );
define( 'HIPPOO_BI_TABLE_REFERRERS', 'hippoo_bi_referrers' );

define( 'HIPPOO_BI_TABLE_PAGEVIEWS', 'hippoo_bi_pageviews' );
define( 'HIPPOO_BI_TABLE_ADD_TO_CARTS', 'hippoo_bi_add_to_carts' );

define( 'HIPPOO_BI_TABLE_CHURN_SCORES', 'hippoo_bi_churn_scores' );
define( 'HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP', 'hippoo_bi_order_product_lookup' );
define( 'HIPPOO_BI_TABLE_ORDER_STATS', 'hippoo_bi_order_stats' );

define( 'HIPPOO_BI_TABLE_SITE_STATS', 'hippoo_bi_site_stats' );
define( 'HIPPOO_BI_TABLE_POST_STATS', 'hippoo_bi_post_stats' );


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

    // Rename legacy BI tables before dbDelta creates their new names, preserving existing rows.
    $legacy_tables = array(
        'hippoo_pageviews'             => HIPPOO_BI_TABLE_PAGEVIEWS,
        'hippoo_add_to_carts'          => HIPPOO_BI_TABLE_ADD_TO_CARTS,
        'hippoo_churn_scores'          => HIPPOO_BI_TABLE_CHURN_SCORES,
        'hippoo_order_product_lookup'  => HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP,
        'hippoo_order_stats'           => HIPPOO_BI_TABLE_ORDER_STATS,
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
                return;
            }
        }
        if ( false === $wpdb->query( "RENAME TABLE $legacy_table TO $new_table" ) ) {
            return;
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
        KEY idx_path_created (path_id, created_at),
        KEY idx_post_created (post_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $table_atc = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $sql_atc = "CREATE TABLE IF NOT EXISTS $table_atc (
        id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id          CHAR(16) NOT NULL,
        product_id          BIGINT(20) UNSIGNED NOT NULL,
        created_at          DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_product (product_id, created_at)
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
        KEY idx_product_date (product_id, date_created),
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
        post_id             BIGINT(20) UNSIGNED DEFAULT NULL,
        path_id             INT UNSIGNED NOT NULL DEFAULT 0,
        views               INT UNSIGNED NOT NULL DEFAULT 0,
        unique_sessions     INT UNSIGNED NOT NULL DEFAULT 0,
        add_to_cart         INT UNSIGNED NOT NULL DEFAULT 0,
        atc_sessions        INT UNSIGNED NOT NULL DEFAULT 0,
        orders              INT UNSIGNED NOT NULL DEFAULT 0,
        revenue             DECIMAL(13,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (date, post_id, path_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( $sql_paths );
    dbDelta( $sql_refs );
    dbDelta( $sql_pageviews );
    dbDelta( $sql_atc );
    dbDelta( $sql_churn );
    dbDelta( $sql_order_lookup );
    dbDelta( $sql_order_stats );
    dbDelta( $sql_site_stats );
    dbDelta( $sql_post_stats );

    // Migrations
    if ( version_compare( $current_version, '1.1.0', '<' ) ) {
        hippoo_bi_migrate_to_110();
    }
    if ( version_compare( $current_version, '1.2.0', '<' ) ) {
        hippoo_bi_migrate_to_120();
    }
    if ( version_compare( $current_version, '1.3.0', '<' ) ) {
        try {
            hippoo_bi_migrate_to_130();
        } catch ( RuntimeException $e ) {
            error_log( 'Hippoo BI migration 1.3.0 failed: ' . $e->getMessage() );
            return;
        }
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

/** Consolidate BI path, referrer and daily-summary changes. */
function hippoo_bi_migrate_to_130() {
    global $wpdb;

    $paths        = $wpdb->prefix . HIPPOO_BI_TABLE_PATHS;
    $refs         = $wpdb->prefix . HIPPOO_BI_TABLE_REFERRERS;
    $pageviews    = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $add_to_carts = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $order_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $site_stats   = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;
    $post_stats   = $wpdb->prefix . HIPPOO_BI_TABLE_POST_STATS;

    // pageviews: add missing columns and indexes
    $page_columns = $wpdb->get_col( "SHOW COLUMNS FROM $pageviews", 0 );
    if ( null === $page_columns ) {
        throw new RuntimeException( 'Cannot read pageviews columns.' );
    }
    $page_adds = array(
        'post_id'     => "ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER session_id",
        'path_id'     => "ADD COLUMN path_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER post_id",
        'referrer_id' => "ADD COLUMN referrer_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER path_id",
    );
    foreach ( $page_adds as $col => $sql ) {
        if ( ! in_array( $col, $page_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $pageviews $sql" ) ) {
                throw new RuntimeException( "Failed adding pageviews.$col" );
            }
        }
    }

    $page_columns = $wpdb->get_col( "SHOW COLUMNS FROM $pageviews", 0 );
    $page_indexes = $wpdb->get_col( "SHOW INDEX FROM $pageviews", 2 );
    if ( null === $page_columns || null === $page_indexes ) {
        throw new RuntimeException( 'Cannot read pageviews structure.' );
    }
    if ( ! in_array( 'idx_path_created', $page_indexes, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $pageviews ADD KEY idx_path_created (path_id, created_at)" ) ) {
            throw new RuntimeException( 'Failed adding idx_path_created on pageviews.' );
        }
    }
    if ( ! in_array( 'idx_post_created', $page_indexes, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $pageviews ADD KEY idx_post_created (post_id, created_at)" ) ) {
            throw new RuntimeException( 'Failed adding idx_post_created on pageviews.' );
        }
    }

    // pageviews: convert page_url to path_id
    if ( in_array( 'page_url', $page_columns, true ) ) {
        $path_expr = "CASE
            WHEN LOCATE('://', page_url) > 0 THEN
                CASE WHEN LOCATE('/', page_url, LOCATE('://', page_url) + 3) = 0
                    THEN '/' ELSE SUBSTRING(page_url, LOCATE('/', page_url, LOCATE('://', page_url) + 3)) END
            ELSE page_url
        END";
        $path_expr = "SUBSTRING_INDEX(SUBSTRING_INDEX($path_expr, '?', 1), '#', 1)";
        $path_expr = "TRIM(TRAILING '/' FROM $path_expr)";
        $path_expr = "LEFT(CASE WHEN $path_expr = '' THEN '/' ELSE $path_expr END, 255)";
        $raw_path_expr = str_replace( 'page_url', 'raw.page_url', $path_expr );

        $batch  = 5000;
        $offset = 0;
        do {
            $count = $wpdb->query(
                "INSERT IGNORE INTO $paths (path)
                 SELECT DISTINCT $path_expr FROM $pageviews
                 WHERE path_id = 0
                 ORDER BY $path_expr
                 LIMIT $batch OFFSET $offset"
            );
            if ( false === $count ) {
                throw new RuntimeException( 'Failed inserting paths from page_url.' );
            }
            $offset += $batch;
        } while ( $count > 0 );

        if ( false === $wpdb->query(
            "UPDATE $pageviews raw
             INNER JOIN $paths p ON p.path = $raw_path_expr
             SET raw.path_id = p.id
             WHERE raw.path_id = 0"
        ) ) {
            throw new RuntimeException( 'Failed updating path_id on pageviews.' );
        }
    }

    if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $pageviews WHERE path_id = 0" ) > 0 ) {
        throw new RuntimeException( 'Some pageviews still have path_id = 0.' );
    }

    // pageviews: map product_id to post_id
    if ( in_array( 'product_id', $page_columns, true ) ) {
        if ( false === $wpdb->query(
            "UPDATE $pageviews SET post_id = product_id WHERE post_id IS NULL AND product_id IS NOT NULL"
        ) ) {
            throw new RuntimeException( 'Failed mapping product_id to post_id on pageviews.' );
        }
    }

    // pageviews: convert referrer_source to referrer_id
    if ( false === $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO $refs (source) VALUES (%s)",
        'direct'
    ) ) ) {
        throw new RuntimeException( 'Failed ensuring direct referrer label.' );
    }

    if ( in_array( 'referrer_source', $page_columns, true ) ) {
        $source_expr     = "LEFT(COALESCE(NULLIF(LOWER(TRIM(referrer_source)), ''), 'direct'), 255)";
        $raw_source_expr = str_replace( 'referrer_source', 'raw.referrer_source', $source_expr );

        $batch  = 5000;
        $offset = 0;
        do {
            $count = $wpdb->query(
                "INSERT IGNORE INTO $refs (source)
                 SELECT DISTINCT $source_expr FROM $pageviews
                 ORDER BY $source_expr
                 LIMIT $batch OFFSET $offset"
            );
            if ( false === $count ) {
                throw new RuntimeException( 'Failed inserting referrer labels.' );
            }
            $offset += $batch;
        } while ( $count > 0 );

        if ( false === $wpdb->query(
            "UPDATE $pageviews raw
             INNER JOIN $refs label ON label.source = $raw_source_expr
             SET raw.referrer_id = label.id
             WHERE raw.referrer_id = 0"
        ) ) {
            throw new RuntimeException( 'Failed updating referrer_id on pageviews.' );
        }
    }

    $direct_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $refs WHERE source = %s",
        'direct'
    ) );
    if ( ! $direct_id || false === $wpdb->query( $wpdb->prepare(
        "UPDATE $pageviews SET referrer_id = %d WHERE referrer_id = 0",
        $direct_id
    ) ) ) {
        throw new RuntimeException( 'Failed setting default direct referrer_id.' );
    }

    // pageviews: drop legacy columns and indexes
    $page_columns = $wpdb->get_col( "SHOW COLUMNS FROM $pageviews", 0 );
    $page_indexes = $wpdb->get_col( "SHOW INDEX FROM $pageviews", 2 );
    if ( null === $page_columns || null === $page_indexes ) {
        throw new RuntimeException( 'Cannot re-read pageviews structure.' );
    }
    foreach ( array( 'idx_product', 'idx_session' ) as $index ) {
        if ( in_array( $index, $page_indexes, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $pageviews DROP INDEX $index" ) ) {
                throw new RuntimeException( 'Failed dropping legacy pageviews indexes.' );
            }
        }
    }
    foreach ( array( 'page_url', 'referrer_source', 'product_id' ) as $column ) {
        if ( in_array( $column, $page_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $pageviews DROP COLUMN $column" ) ) {
                throw new RuntimeException( 'Failed dropping legacy pageviews columns.' );
            }
        }
    }

    // add_to_carts: drop quantity and session index, ensure created_at index
    $cart_columns = $wpdb->get_col( "SHOW COLUMNS FROM $add_to_carts", 0 );
    $cart_indexes = $wpdb->get_col( "SHOW INDEX FROM $add_to_carts", 2 );
    if ( null === $cart_columns || null === $cart_indexes ) {
        throw new RuntimeException( 'Cannot read add_to_carts structure.' );
    }
    if ( in_array( 'quantity', $cart_columns, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $add_to_carts DROP COLUMN quantity" ) ) {
            throw new RuntimeException( 'Failed dropping quantity from add_to_carts.' );
        }
    }
    if ( in_array( 'idx_session', $cart_indexes, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $add_to_carts DROP INDEX idx_session" ) ) {
            throw new RuntimeException( 'Failed dropping idx_session from add_to_carts.' );
        }
    }
    if ( ! in_array( 'idx_created', $cart_indexes, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $add_to_carts ADD KEY idx_created (created_at)" ) ) {
            throw new RuntimeException( 'Failed adding idx_created on add_to_carts.' );
        }
    }

    // order_product_lookup: drop legacy indexes, add product_date composite
    $lookup_indexes = $wpdb->get_col( "SHOW INDEX FROM $order_lookup", 2 );
    if ( null === $lookup_indexes ) {
        throw new RuntimeException( 'Cannot read order_product_lookup indexes.' );
    }
    if ( is_array( $lookup_indexes ) ) {
        if ( in_array( 'idx_product_customer', $lookup_indexes, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $order_lookup DROP INDEX idx_product_customer" ) ) {
                throw new RuntimeException( 'Failed dropping idx_product_customer on order_product_lookup.' );
            }
        }
        if ( in_array( 'idx_product_id', $lookup_indexes, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $order_lookup DROP INDEX idx_product_id" ) ) {
                throw new RuntimeException( 'Failed dropping idx_product_id on order_product_lookup.' );
            }
        }
        if ( ! in_array( 'idx_product_date', $lookup_indexes, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $order_lookup ADD KEY idx_product_date (product_id, date_created)" ) ) {
                throw new RuntimeException( 'Failed adding idx_product_date on order_product_lookup.' );
            }
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
    foreach ( $site_adds as $col => $sql ) {
        if ( ! in_array( $col, $site_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $site_stats $sql" ) ) {
                throw new RuntimeException( "Failed adding site_stats.$col" );
            }
        }
    }

    // site_stats: drop calculated_at, new/returning visitors & customers
    $site_columns = $wpdb->get_col( "SHOW COLUMNS FROM $site_stats", 0 );
    if ( null === $site_columns ) {
        throw new RuntimeException( 'Cannot re-read site_stats columns.' );
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
        'post_id' => "ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER date",
        'path_id' => "ADD COLUMN path_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER post_id",
    );
    foreach ( $post_adds as $col => $sql ) {
        if ( ! in_array( $col, $post_columns, true ) ) {
            if ( false === $wpdb->query( "ALTER TABLE $post_stats $sql" ) ) {
                throw new RuntimeException( "Failed adding post_stats.$col" );
            }
        }
    }

    // post_stats: map product_id to post_id and path_id
    if ( in_array( 'product_id', $post_columns, true ) ) {
        if ( false === $wpdb->query(
            "UPDATE $post_stats SET post_id = product_id WHERE post_id IS NULL AND product_id IS NOT NULL"
        ) ) {
            throw new RuntimeException( 'Failed mapping product_id to post_id on post_stats.' );
        }

        if ( false === $wpdb->query(
            "UPDATE $post_stats ps
             INNER JOIN (
                 SELECT post_id, MIN(path_id) AS path_id
                 FROM $pageviews
                 WHERE post_id IS NOT NULL AND path_id > 0
                 GROUP BY post_id
             ) src ON src.post_id = ps.post_id
             SET ps.path_id = src.path_id
             WHERE ps.path_id = 0 AND ps.post_id IS NOT NULL"
        ) ) {
            throw new RuntimeException( 'Failed mapping path_id from pageviews onto post_stats.' );
        }

        if ( false === $wpdb->query( "DELETE FROM $post_stats WHERE path_id = 0" ) ) {
            throw new RuntimeException( 'Failed removing post_stats rows without path_id.' );
        }
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
    if ( in_array( 'product_id', $post_columns, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $post_stats DROP COLUMN product_id" ) ) {
            throw new RuntimeException( 'Failed dropping product_id from post_stats.' );
        }
    }
    if ( in_array( 'calculated_at', $post_columns, true ) ) {
        if ( false === $wpdb->query( "ALTER TABLE $post_stats DROP COLUMN calculated_at" ) ) {
            throw new RuntimeException( 'Failed dropping calculated_at from post_stats.' );
        }
    }

    // Reset data
    $wpdb->query( "TRUNCATE TABLE $site_stats" );
    $wpdb->query( "TRUNCATE TABLE $post_stats" );
}
