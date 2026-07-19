<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooBI
{
    const DB_VERSION = '1.0.0';
    const TABLE_PAGEVIEWS = 'hippoo_pageviews';
    const TABLE_CHURN_SCORES = 'hippoo_churn_scores';
    const TABLE_ADD_TO_CARTS = 'hippoo_add_to_carts';
    const TABLE_ORDER_PRODUCT_LOOKUP = 'hippoo_order_product_lookup';
    const TABLE_ORDER_STATS = 'hippoo_order_stats';

    const CHURN_THRESHOLD = 90;

    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'init_database'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_filter('woocommerce_rest_is_request_to_rest_api', array($this, 'rest_use_wc_authentication')); // todo: fix duplicate
        
        add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 10, 6);

        add_action('woocommerce_order_status_changed', array($this, 'update_order_lookup'), 20);
        add_action('woocommerce_new_order', array($this, 'update_order_lookup'), 20);
        add_action('woocommerce_delete_order', array($this, 'delete_order_lookup'), 20);

        add_action('hippoo_bi_sync_lookup', array($this, 'sync_orders_lookup'));
        add_action('hippoo_bi_daily_churn', array($this, 'calculate_churn_scores'));
        add_action('hippoo_bi_weekly_pruning', array($this, 'prune_old_pageviews'));
        add_action('plugins_loaded', array($this, 'schedule_cron_events'));
        register_deactivation_hook(HIPPOO_MAIN_FILE_PATH, array($this, 'clear_cron_events'));
    }

    public function init_database()
    {
        if (get_option('hippoo_bi_db_version') === self::DB_VERSION) {
            return;
        }

        global $wpdb;

        $table_pageviews = $wpdb->prefix . self::TABLE_PAGEVIEWS;
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

        $table_atc = $wpdb->prefix . self::TABLE_ADD_TO_CARTS;
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

        $table_churn = $wpdb->prefix . self::TABLE_CHURN_SCORES;
        $sql_churn = "CREATE TABLE IF NOT EXISTS $table_churn (
            customer_id BIGINT(20) UNSIGNED NOT NULL,
            churn_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','at_risk','high_risk','churned') NOT NULL,
            last_order_date DATE DEFAULT NULL,
            total_orders SMALLINT UNSIGNED DEFAULT 0,
            total_spent DECIMAL(13,2) DEFAULT 0.00,
            clv DECIMAL(13,2) DEFAULT 0.00,
            calculated_at DATETIME NOT NULL,
            PRIMARY KEY (customer_id),
            KEY idx_status (status),
            KEY idx_score (churn_score DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $table_lookup = $wpdb->prefix . self::TABLE_ORDER_PRODUCT_LOOKUP;
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

        $table_stats = $wpdb->prefix . self::TABLE_ORDER_STATS;
        $sql_stats = "CREATE TABLE IF NOT EXISTS $table_stats (
            order_id BIGINT(20) UNSIGNED NOT NULL,
            customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            date_created DATETIME NOT NULL,
            total DECIMAL(13,2) NOT NULL DEFAULT 0.00,
            net DECIMAL(13,2) NOT NULL DEFAULT 0.00,
            refund DECIMAL(13,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (order_id),
            KEY idx_customer_id (customer_id),
            KEY idx_date_created (date_created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_pageviews);
        dbDelta($sql_atc);
        dbDelta($sql_churn);
        dbDelta($sql_lookup);
        dbDelta($sql_stats);

        update_option('hippoo_bi_db_version', self::DB_VERSION);
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('hippoo-bi-tracker', HIPPOO_URL . 'js/bi-tracker.js', [], HIPPOO_VERSION, true);

        wp_localize_script('hippoo-bi-tracker', 'hippooBI', [
            'rest_url' => rest_url('hippoo/v1/bi/track'),
            'product_id' => is_product() ? get_the_ID() : null,
        ]);
    }

    public function register_rest_routes()
    {
        register_rest_route('hippoo/v1', '/bi/track', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_track_pageview'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('hippoo/v1', '/bi/overview', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_general_overview'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);

        register_rest_route('hippoo/v1', '/bi/traffic/overview', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_traffic_overview'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);

        register_rest_route('hippoo/v1', '/bi/products/intelligence', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_products_intelligence'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);

        register_rest_route('hippoo/v1', '/bi/products/(?P<id>\d+)/intelligence', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_product_intelligence'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);

        register_rest_route('hippoo/v1', '/bi/sales/overview', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_sales_overview'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);

        register_rest_route('hippoo/v1', '/bi/churn/overview', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_churn_overview'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);

        register_rest_route('hippoo/v1', '/bi/churn/customers', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_churn_customers'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);

        register_rest_route('hippoo/v1', '/bi/churn/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_churn_export'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);
    }

    public function rest_use_wc_authentication($condition)
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $rest_prefix = trailingslashit(rest_get_url_prefix());
        $request_uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        
        // Allow the plugin use wc authentication methods.
        $hippoo = (false !== strpos($request_uri, $rest_prefix . 'hippoo/v1/bi'));
        
        return $condition || $hippoo;
    }

    public function rest_permission_check($request)
    {
        return apply_filters('hippoo_bi_permission_check', current_user_can('manage_options'));
    }

    public function rest_track_pageview($request)
    {
        if (!$this->check_rate_limit()) {
            return new WP_Error('rate_limited', __('Too many requests. Please try again later.', 'hippoo'), ['status' => 429]);
        }

        $body = $request->get_body();
        $params = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($params)) {
            $params = $request->get_json_params();
        }

        if (empty($params['pid'])) {
            return new WP_Error('missing_pid', __('Missing product_id parameter.', 'hippoo'), ['status' => 400]);
        }

        $data = [
            'session_id'      => sanitize_text_field($params['sid'] ?? $this->get_current_session_id()),
            'page_url'        => sanitize_text_field($params['url'] ?? ''),
            'referrer_source' => $this->get_referrer_source($params['ref'] ?? ''),
            'device_type'     => $this->get_device_type(),
            'country'         => $this->get_country_from_ip(),
            'product_id'      => absint($params['pid']),
            'created_at'      => current_time('mysql'),
        ];

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PAGEVIEWS;
        $result = $wpdb->insert($table, $data);
    }

    public function rest_general_overview($request)
    {
        $period    = $request->get_param('period') ?: 'this_month';
        $date_from = $request->get_param('date_from') ?? '';
        $date_to   = $request->get_param('date_to') ?? '';

        $traffic_response  = $this->rest_traffic_overview($request);
        $sales_response = $this->rest_sales_overview($request);
        $churn_response = $this->rest_churn_overview($request);
        $products_response = $this->rest_products_intelligence($request);

        $traffic = $traffic_response->get_data();
        $sales = $sales_response->get_data();
        $churn = $churn_response->get_data();
        $products = $products_response->get_data();

        // CHART
        $chart = [];
        if (!empty($sales['revenue_chart'])) {
            foreach ($sales['revenue_chart'] as $item) {
                $date = $item->date;
                $chart[$date] = [
                    'date'     => $date,
                    'revenue'  => (float)($item->revenue ?? 0),
                    'orders'   => (int)($item->orders ?? 0),
                    'views'    => 0,
                    'sessions' => 0,
                ];
            }
        }

        if (!empty($traffic['chart'])) {
            foreach ($traffic['chart'] as $item) {
                $date = $item->date;
                if (isset($chart[$date])) {
                    $chart[$date]['views'] = (int)$item->views;
                    $chart[$date]['sessions'] = (int)$item->sessions;
                } else {
                    $chart[$date] = [
                        'date'     => $date,
                        'revenue'  => 0,
                        'orders'   => 0,
                        'views'    => (int)$item->views,
                        'sessions' => (int)$item->sessions,
                    ];
                }
            }
        }

        $chart = array_values($chart);
        usort($chart, function ($a, $b) {
            return strtotime($a['date']) <=> strtotime($b['date']);
        });

        // PRODUCT HIGHLIGHTS
        $product_highlights = [];

        if (!empty($products)) {

            // Strong Performer
            $top = array_reduce(
                array_filter($products, fn($p) => ($p['insight_tag'] ?? '') === 'strong_performer'),
                fn($carry, $p) => (!$carry || ($p['revenue'] ?? 0) > ($carry['revenue'] ?? 0)) ? $p : $carry
            );
            if ($top) $product_highlights['strong_performer'] = $top;

            // High Traffic Low Conversion
            $needs = array_reduce(
                array_filter($products, fn($p) => ($p['insight_tag'] ?? '') === 'high_traffic_low_conv'),
                fn($carry, $p) => (!$carry || 
                    ($p['views'] ?? 0) > ($carry['views'] ?? 0) ||
                    (($p['views'] ?? 0) === ($carry['views'] ?? 0) && ($p['conversion_rate'] ?? 100) < ($carry['conversion_rate'] ?? 100))
                ) ? $p : $carry
            );
            if ($needs) $product_highlights['high_traffic_low_conv'] = $needs;

            // Hidden Gem
            $gem = array_reduce(
                array_filter($products, fn($p) => ($p['insight_tag'] ?? '') === 'hidden_gem'),
                fn($carry, $p) => (!$carry || ($p['conversion_rate'] ?? 0) > ($carry['conversion_rate'] ?? 0)) ? $p : $carry
            );
            if ($gem) $product_highlights['hidden_gem'] = $gem;

            // Normal
            $normal = array_reduce(
                array_filter($products, fn($p) => ($p['insight_tag'] ?? '') === 'normal'),
                fn($carry, $p) => (!$carry || ($p['conversion_rate'] ?? 0) > ($carry['conversion_rate'] ?? 0)) ? $p : $carry
            );
            if ($normal) $product_highlights['normal'] = $normal;

            // Cart Drop
            $drop = array_reduce(
                array_filter($products, fn($p) => ($p['insight_tag'] ?? '') === 'cart_drop'),
                fn($carry, $p) => (!$carry || ($p['cart_to_order_rate'] ?? 100) < ($carry['cart_to_order_rate'] ?? 100)) ? $p : $carry
            );
            if ($drop) $product_highlights['cart_drop'] = $drop;

            // Dead Product
            $dead = array_reduce(
                array_filter($products, fn($p) => ($p['insight_tag'] ?? '') === 'dead_product'),
                fn($carry, $p) => (!$carry || ($p['views'] ?? 0) > ($carry['views'] ?? 0)) ? $p : $carry
            );
            if ($dead) $product_highlights['dead_product'] = $dead;

            // Insufficient Data
            $nodata = array_reduce(
                array_filter($products, fn($p) => ($p['insight_tag'] ?? '') === 'insufficient_data'),
                fn($carry, $p) => (!$carry || ($p['views'] ?? 0) > ($carry['views'] ?? 0)) ? $p : $carry
            );
            if ($nodata) $product_highlights['insufficient_data'] = $nodata;
        }

        $response = [
            'net_revenue'         => $sales['net_revenue'] ?? 0,
            'avg_order_value'     => $sales['avg_order_value'] ?? 0,
            'conversion_rate'     => $sales['conversion_rate'] ?? 0,
            'order_count'         => $sales['order_count'] ?? 0,
            'revenue_per_visit'   => $sales['revenue_per_visit'] ?? 0,
            'new_customers'       => $sales['new_customers'] ?? 0,
            'returning_customers' => $sales['returning_customers'] ?? 0,

            'total_views'         => $traffic['total_views'] ?? 0,
            'unique_sessions'     => $traffic['unique_sessions'] ?? 0,
            'new_visitors'        => $traffic['new_visitors'] ?? 0,
            'returning_visitors'  => $traffic['returning_visitors'] ?? 0,
            'bounce_rate'         => $traffic['bounce_rate'] ?? 0,

            'chart'               => $chart,

            'churn_summary' => [
                'active_count'    => $churn['active_customers'] ?? 0,
                'at_risk_count'   => $churn['at_risk_customers'] ?? 0,
                'high_risk_count' => $churn['high_risk_customers'] ?? 0,
                'churned_count'   => $churn['churned_customers'] ?? 0,
                'churn_rate'      => $churn['churn_rate'] ?? 0,
            ],

            'product_highlights' => $product_highlights,

            'comparison' => $sales['comparison'] ?? [
                'vs_previous_period' => '+0%',
                'previous_revenue'   => 0
            ],
        ];

        return rest_ensure_response($response);
    }

    public function rest_traffic_overview($request)
    {
        $period    = $request->get_param('period') ?: 'this_month';
        $date_from = $request->get_param('date_from') ?? '';
        $date_to   = $request->get_param('date_to') ?? '';

        $cache_key = 'hippoo_bi_traffic_overview_' . md5($period . $date_from . $date_to);

        if ($cached = get_transient($cache_key)) {
            return rest_ensure_response($cached);
        }

        $date_range = $this->get_date_range($period, $date_from, $date_to);

        global $wpdb;
        $table_pv = $wpdb->prefix . self::TABLE_PAGEVIEWS;

        // TOTAL VIEWS & UNIQUE SESSIONS
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_views,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM $table_pv 
            WHERE created_at BETWEEN %s AND %s
        ", $date_range['from'], $date_range['to']));

        // NEW vs RETURNING VISITORS
        $visitors = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(CASE WHEN first_seen >= %s THEN 1 ELSE 0 END) as new_visitors,
                SUM(CASE WHEN first_seen < %s THEN 1 ELSE 0 END) as returning_visitors
            FROM (
                SELECT 
                    session_id,
                    MIN(created_at) as first_seen
                FROM $table_pv
                WHERE created_at <= %s
                GROUP BY session_id
                HAVING MAX(created_at) >= %s
            ) as fv
        ", $date_range['from'], $date_range['from'], $date_range['to'], $date_range['from']));

        // BOUNCE RATE
        $single_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id)
            FROM $table_pv 
            WHERE created_at BETWEEN %s AND %s
            GROUP BY session_id 
            HAVING COUNT(*) = 1
        ", $date_range['from'], $date_range['to']));

        $bounce_rate = $stats->unique_sessions > 0 
            ? round(((int)$single_sessions / $stats->unique_sessions) * 100, 1) 
            : 0;

        // TOP PAGES
        $top_pages = $wpdb->get_results($wpdb->prepare("
            SELECT 
                page_url as url,
                COUNT(*) as views
            FROM $table_pv 
            WHERE created_at BETWEEN %s AND %s
            GROUP BY page_url
            ORDER BY views DESC 
            LIMIT 10
        ", $date_range['from'], $date_range['to']));

        // TRAFFIC SOURCES
        $sources = $wpdb->get_results($wpdb->prepare("
            SELECT 
                COALESCE(NULLIF(referrer_source, ''), 'direct') as source,
                COUNT(*) as cnt
            FROM $table_pv 
            WHERE created_at BETWEEN %s AND %s
            GROUP BY COALESCE(NULLIF(referrer_source, ''), 'direct')
            ORDER BY cnt DESC 
            LIMIT 10
        ", $date_range['from'], $date_range['to']));

        $traffic_sources = [];
        foreach ($sources as $s) {
            $traffic_sources[$s->source] = (int)$s->cnt;
        }

        // DEVICE BREAKDOWN
        $devices = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(CASE WHEN device_type = 'm' THEN 1 ELSE 0 END) as mobile,
                SUM(CASE WHEN device_type = 't' THEN 1 ELSE 0 END) as tablet,
                SUM(CASE WHEN device_type = 'd' THEN 1 ELSE 0 END) as desktop,
                COUNT(*) as total
            FROM $table_pv 
            WHERE created_at BETWEEN %s AND %s
        ", $date_range['from'], $date_range['to']));

        $device_breakdown = [
            'mobile' => (int) ($devices->mobile ?? 0),
            'tablet' => (int) ($devices->tablet ?? 0),
            'desktop' => (int) ($devices->desktop ?? 0),
        ];

        // CHART
        $chart = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as sessions
            FROM $table_pv 
            WHERE created_at BETWEEN %s AND %s
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", $date_range['from'], $date_range['to']));
        
        $response = [
            'total_views'        => (int)($stats->total_views ?? 0),
            'unique_sessions'    => (int)($stats->unique_sessions ?? 0),
            'new_visitors'       => (int)($visitors->new_visitors ?? 0),
            'returning_visitors' => (int)($visitors->returning_visitors ?? 0),
            'bounce_rate'        => $bounce_rate,
            'top_pages'          => $top_pages,
            'traffic_sources'    => $traffic_sources,
            'device_breakdown'   => $device_breakdown,
            'chart'              => $chart,
        ];

        set_transient($cache_key, $response, 5 * MINUTE_IN_SECONDS);
        return rest_ensure_response($response);
    }

    public function rest_products_intelligence($request)
    {
        $period    = $request->get_param('period') ?: 'this_month';
        $date_from = $request->get_param('date_from') ?? '';
        $date_to   = $request->get_param('date_to') ?? '';
        $min_views = $request->get_param('min_views') ?: 30;
        $limit     = $request->get_param('limit') ?? '';
        $sort_by   = $request->get_param('sort_by') ?: 'conv_rate';

        $cache_key = 'hippoo_bi_products_intel_' . md5($period . $date_from . $date_to . $min_views . $limit . $sort_by);

        if ($cached = get_transient($cache_key)) {
            return rest_ensure_response($cached);
        }

        $date_range = $this->get_date_range($period, $date_from, $date_to);

        global $wpdb;
        $table_pv = $wpdb->prefix . self::TABLE_PAGEVIEWS;
        $table_atc = $wpdb->prefix . self::TABLE_ADD_TO_CARTS;
        $table_lookup = $wpdb->prefix . self::TABLE_ORDER_PRODUCT_LOOKUP;

        // SALES TRAFFIC
        $traffic = $wpdb->get_results($wpdb->prepare("
            SELECT 
                product_id,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM $table_pv 
            WHERE product_id IS NOT NULL 
              AND created_at BETWEEN %s AND %s
            GROUP BY product_id
            HAVING views >= %d
            ORDER BY views DESC
        ", $date_range['from'], $date_range['to'], $min_views), ARRAY_A);

        if (empty($traffic)) {
            set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
            return rest_ensure_response([]);
        }

        $product_ids = array_map('intval', array_unique(wp_list_pluck($traffic, 'product_id')));

        // SALES DATA
        $sales_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                product_id,
                COUNT(DISTINCT order_id) as orders,
                SUM(quantity) as total_quantity,
                SUM(revenue) as revenue
            FROM $table_lookup
            WHERE product_id IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")
              AND date_created BETWEEN %s AND %s
            GROUP BY product_id
        ", array_merge($product_ids, [$date_range['from'], $date_range['to']])), ARRAY_A);

        $sales = [];
        foreach ($sales_data as $row) {
            $product_id = (int)$row['product_id'];
            $sales[$product_id] = [
                'orders'  => (int)$row['orders'],
                'revenue' => (float)$row['revenue'],
            ];
        }

        // ADD TO CART
        $atc_results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                product_id, 
                SUM(quantity) as total_atc,
                COUNT(DISTINCT session_id) as atc_sessions
            FROM $table_atc 
            WHERE product_id IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")
              AND created_at BETWEEN %s AND %s
            GROUP BY product_id
        ", array_merge($product_ids, [$date_range['from'], $date_range['to']])), ARRAY_A);

        foreach ($atc_results as $row) {
            $product_id = (int)$row['product_id'];
            if (isset($sales[$product_id])) {
                $sales[$product_id]['add_to_cart'] = (int)$row['total_atc'];
                $sales[$product_id]['atc_sessions'] = (int)$row['atc_sessions'];
            } else {
                $sales[$product_id] = [
                    'orders' => 0, 
                    'revenue' => 0, 
                    'add_to_cart' => (int)$row['total_atc'], 
                    'atc_sessions' => (int)$row['atc_sessions']
                ];
            }
        }

        $result = [];
        foreach ($traffic as $item) {
            $product_id = (int)$item['product_id'];
            
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $s = $sales[$product_id] ?? ['orders' => 0, 'revenue' => 0, 'add_to_cart' => 0, 'atc_sessions' => 0];

            $views = (int)$item['views'];
            $sessions = (int)$item['unique_sessions'];
            $orders = (int)$s['orders'];
            $revenue = (float)$s['revenue'];
            $add_to_cart = (int)$s['add_to_cart'];
            $atc_sessions = (int)$s['atc_sessions'];

            $revenue_per_view = $views > 0 ? round($revenue / $views, 2) : 0;
            $cart_to_order_rate = $atc_sessions > 0 ? round(($orders / $atc_sessions) * 100, 2) : 0;
            $conversion_rate = $sessions > 0 ? round(($orders / $sessions) * 100, 2) : 0;
            $atc_rate = $sessions > 0 ? round(($atc_sessions / $sessions) * 100, 2) : 0;

            $insight = $this->get_insight_tag($views, $orders, $conversion_rate, $atc_rate, $cart_to_order_rate);

            $result[] = [
                'product_id'         => $product_id,
                'product_name'       => $product->get_name(),
                'product_url'        => $product->get_permalink(),
                'views'              => $views,
                'unique_sessions'    => $sessions,
                'add_to_cart'        => $add_to_cart,
                'orders'             => $orders,
                'revenue'            => round($revenue),
                'revenue_per_view'   => $revenue_per_view,
                'conversion_rate'    => $conversion_rate,
                'atc_rate'           => $atc_rate,
                'cart_to_order_rate' => $cart_to_order_rate,
                'insight_tag'        => $insight['tag'],
                'insight_message'    => $insight['message'],
            ];
        }

        usort($result, function ($a, $b) use ($sort_by) {
            switch ($sort_by) {
                case 'conv_rate':
                    return $b['conversion_rate'] <=> $a['conversion_rate'];
                case 'revenue_per_view':
                    return $b['revenue_per_view'] <=> $a['revenue_per_view'];
                case 'views':
                    return $b['views'] <=> $a['views'];
                default: // insight_priority - smaller number = higher priority
                    $priority = [
                        'dead_product'          => 1,
                        'cart_drop'             => 2,
                        'hidden_gem'            => 3,
                        'high_traffic_low_conv' => 4,
                        'strong_performer'      => 5,
                        'normal'                => 6,
                        'insufficient_data'     => 7,
                    ];
                    return ($priority[$a['insight_tag']] ?? 99) <=> ($priority[$b['insight_tag']] ?? 99);
            }
        });

        $response = $result;
        if ($limit > 0) {
            $response = array_slice($response, 0, $limit);
        }

        set_transient($cache_key, $response, 15 * MINUTE_IN_SECONDS);
        return rest_ensure_response($response);
    }

    public function rest_product_intelligence($request)
    {
        $product_id = $request->get_param('id');
        $period     = $request->get_param('period') ?: 'this_month';
        $date_from  = $request->get_param('date_from') ?? '';
        $date_to    = $request->get_param('date_to') ?? '';

        $cache_key = 'hippoo_bi_product_intel_' . md5($product_id . $period . $date_from . $date_to);

        if ($cached = get_transient($cache_key)) {
            return rest_ensure_response($cached);
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('not_found', __('Product not found.', 'hippoo'), ['status' => 404]);
        }

        $date_range = $this->get_date_range($period, $date_from, $date_to);

        global $wpdb;
        $table_pv = $wpdb->prefix . self::TABLE_PAGEVIEWS;
        $table_atc = $wpdb->prefix . self::TABLE_ADD_TO_CARTS;
        $table_lookup = $wpdb->prefix . self::TABLE_ORDER_PRODUCT_LOOKUP;

        // SALES TRAFFIC
        $traffic = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM $table_pv 
            WHERE product_id = %d 
              AND created_at BETWEEN %s AND %s
        ", $product_id, $date_range['from'], $date_range['to']));

        // SALES DATA
        $sales_data = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(DISTINCT order_id) as orders,
                SUM(quantity) as total_quantity,
                SUM(revenue) as revenue
            FROM $table_lookup
            WHERE product_id = %d 
              AND date_created BETWEEN %s AND %s
        ", $product_id, $date_range['from'], $date_range['to']));

        // ADD TO CART
        $atc_data = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(quantity) as total_atc,
                COUNT(DISTINCT session_id) as atc_sessions
            FROM $table_atc 
            WHERE product_id = %d 
              AND created_at BETWEEN %s AND %s
        ", $product_id, $date_range['from'], $date_range['to']));

        // CHART
        $views_chart = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE(created_at) AS date,
                COUNT(*) AS views,
                COUNT(DISTINCT session_id) AS sessions
            FROM $table_pv
            WHERE product_id = %d
            AND created_at BETWEEN %s AND %s
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", $product_id, $date_range['from'], $date_range['to']));

        $atc_chart = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE(created_at) AS date,
                SUM(quantity) AS add_to_cart
            FROM $table_atc
            WHERE product_id = %d
            AND created_at BETWEEN %s AND %s
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", $product_id, $date_range['from'], $date_range['to']));

        $sales_chart = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE(date_created) AS date,
                COUNT(DISTINCT order_id) AS orders,
                SUM(revenue) AS revenue
            FROM $table_lookup
            WHERE product_id = %d
            AND date_created BETWEEN %s AND %s
            GROUP BY DATE(date_created)
            ORDER BY date ASC
        ", $product_id, $date_range['from'], $date_range['to']));

        // RATES
        $views = (int)($traffic->views ?? 0);
        $sessions = (int)($traffic->unique_sessions ?? 0);
        $orders = (int)($sales_data->orders ?? 0);
        $revenue = (float)($sales_data->revenue ?? 0);
        $add_to_cart = (int)($atc_data->total_atc ?? 0);
        $atc_sessions = (int)($atc_data->atc_sessions ?? 0);

        $revenue_per_view = $views > 0 ? round($revenue / $views, 2) : 0;
        $cart_to_order_rate = $atc_sessions > 0 ? round(($orders / $atc_sessions) * 100, 2) : 0;
        $conversion_rate = $sessions > 0 ? round(($orders / $sessions) * 100, 2) : 0;
        $atc_rate = $sessions > 0 ? round(($atc_sessions / $sessions) * 100, 2) : 0;

        $insight = $this->get_insight_tag($views, $orders, $conversion_rate, $atc_rate, $cart_to_order_rate);

        $chart = [];

        foreach ($views_chart as $row) {
            $chart[$row->date] = [
                'date'         => $row->date,
                'views'        => (int)$row->views,
                'sessions'     => (int)$row->sessions,
                'add_to_cart'  => 0,
                'orders'       => 0,
                'revenue'      => 0,
            ];
        }

        foreach ($atc_chart as $row) {
            if (!isset($chart[$row->date])) {
                $chart[$row->date] = [
                    'date' => $row->date,
                    'views' => 0,
                    'sessions' => 0,
                    'add_to_cart' => 0,
                    'orders' => 0,
                    'revenue' => 0,
                ];
            }

            $chart[$row->date]['add_to_cart'] = (int)$row->add_to_cart;
        }

        foreach ($sales_chart as $row) {
            if (!isset($chart[$row->date])) {
                $chart[$row->date] = [
                    'date' => $row->date,
                    'views' => 0,
                    'sessions' => 0,
                    'add_to_cart' => 0,
                    'orders' => 0,
                    'revenue' => 0,
                ];
            }

            $chart[$row->date]['orders'] = (int)$row->orders;
            $chart[$row->date]['revenue'] = (float)$row->revenue;
        }

        ksort($chart);
        $chart = array_values($chart);

        $response = [
            'product_id'         => $product_id,
            'product_name'       => $product->get_name(),
            'product_url'        => $product->get_permalink(),
            'views'              => $views,
            'unique_sessions'    => $sessions,
            'add_to_cart'        => $add_to_cart,
            'orders'             => $orders,
            'revenue'            => round($revenue),
            'revenue_per_view'   => $revenue_per_view,
            'conversion_rate'    => $conversion_rate,
            'atc_rate'           => $atc_rate,
            'cart_to_order_rate' => $cart_to_order_rate,
            'insight_tag'        => $insight['tag'],
            'insight_message'    => $insight['message'],
            'chart'              => $chart,
        ];

        set_transient($cache_key, $response, 15 * MINUTE_IN_SECONDS);
        return rest_ensure_response($response);
    }

    public function rest_sales_overview($request)
    {
        $period    = $request->get_param('period') ?: 'this_month';
        $date_from = $request->get_param('date_from') ?? '';
        $date_to   = $request->get_param('date_to') ?? '';

        $cache_key = 'hippoo_bi_sales_overview_' . md5($period . $date_from . $date_to);

        if ($cached = get_transient($cache_key)) {
            return rest_ensure_response($cached);
        }

        $date_range = $this->get_date_range($period, $date_from, $date_to);
        $prev_range = $this->get_previous_date_range($period, $date_from, $date_to);

        global $wpdb;
        $table_pv = $wpdb->prefix . self::TABLE_PAGEVIEWS;
        $table_stats = $wpdb->prefix . self::TABLE_ORDER_STATS;

        // SALES TRAFFIC
        $traffic_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_views,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM $table_pv 
            WHERE created_at BETWEEN %s AND %s
        ", $date_range['from'], $date_range['to']));

        // SALES DATA
        $sales_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(order_id) as order_count,
                SUM(total) as total_revenue,
                SUM(net) as net_revenue,
                SUM(refund) as total_refund
            FROM $table_stats
            WHERE date_created BETWEEN %s AND %s
        ", $date_range['from'], $date_range['to']));

        $total_views = (int)($traffic_stats->total_views ?? 0);
        $unique_sessions = (int)($traffic_stats->unique_sessions ?? 0);
        $order_count   = (int)($sales_stats->order_count ?? 0);
        $total_revenue = (float)($sales_stats->total_revenue ?? 0);
        $net_revenue = (float)($sales_stats->net_revenue ?? 0);
        $refund_amount = (float)($sales_stats->total_refund ?? 0);

        $net_revenue = $net_revenue - $refund_amount;
        $avg_order_value = $order_count > 0 ? round($net_revenue / $order_count, 2) : 0;
        $refund_rate = $total_revenue > 0 ? round(($refund_amount / $total_revenue) * 100, 2) : 0;
        $conversion_rate = $unique_sessions > 0 ? round(($order_count / $unique_sessions) * 100, 2) : 0;
        $revenue_per_visit = $total_views > 0 ? round($net_revenue / $total_views, 2) : 0;

        // NEW vs RETURNING CUSTOMERS
        $customers = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN first_order >= %s THEN customer_id END) as new_customers,
                COUNT(DISTINCT CASE WHEN first_order < %s THEN customer_id END) as returning_customers
            FROM (
                SELECT 
                    customer_id, 
                    MIN(date_created) as first_order
                FROM $table_stats
                WHERE customer_id IS NOT NULL
                  AND date_created <= %s
                GROUP BY customer_id
            ) as customer_first
            WHERE EXISTS (
                SELECT 1 
                FROM $table_stats t 
                WHERE t.customer_id = customer_first.customer_id 
                  AND t.date_created BETWEEN %s AND %s
                LIMIT 1
            )
        ", $date_range['from'], $date_range['from'], $date_range['to'], $date_range['from'], $date_range['to']));

        $new_customers = (int)($customers->new_customers ?? 0);
        $returning_customers = (int)($customers->returning_customers ?? 0);

        // REVENUE CHART
        $revenue_chart = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(date_created) as date,
                SUM(total) as revenue,
                COUNT(order_id) as orders
            FROM $table_stats
            WHERE date_created BETWEEN %s AND %s
            GROUP BY DATE(date_created)
            ORDER BY date ASC
        ", $date_range['from'], $date_range['to']));

        // PREVIOUS PERIOD COMPARISON
        $prev_total_revenue = (float)$wpdb->get_var($wpdb->prepare("
            SELECT SUM(total)
            FROM $table_stats
            WHERE date_created BETWEEN %s AND %s
        ", $prev_range['from'], $prev_range['to']));

        $change = $prev_total_revenue > 0 
            ? round((($total_revenue - $prev_total_revenue) / $prev_total_revenue) * 100, 1) 
            : ($total_revenue > 0 ? 100 : 0);

        $response = [
            'total_revenue'       => round($total_revenue),
            'net_revenue'         => round($net_revenue),
            'refund_amount'       => round($refund_amount),
            'order_count'         => $order_count,
            'avg_order_value'     => round($avg_order_value),
            'refund_rate'         => $refund_rate,
            'conversion_rate'     => $conversion_rate,
            'revenue_per_visit'   => $revenue_per_visit,
            'new_customers'       => $new_customers,
            'returning_customers' => $returning_customers,
            'revenue_chart'       => $revenue_chart,
            'comparison'          => [
                'vs_previous_period' => ($change >= 0 ? '+' : '') . $change . '%',
                'previous_revenue'   => round($prev_total_revenue),
            ],
        ];

        set_transient($cache_key, $response, 15 * MINUTE_IN_SECONDS);
        return rest_ensure_response($response);
    }

    public function rest_churn_overview($request)
    {
        $cache_key = 'hippoo_bi_churn_overview';

        if ($cached = get_transient($cache_key)) {
            return rest_ensure_response($cached);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHURN_SCORES;

        $stats = $wpdb->get_row("
            SELECT 
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
                COUNT(CASE WHEN status = 'at_risk' THEN 1 END) as at_risk,
                COUNT(CASE WHEN status = 'high_risk' THEN 1 END) as high_risk,
                COUNT(CASE WHEN status = 'churned' THEN 1 END) as churned,
                COUNT(*) as total
            FROM $table
        ");

        $active = (int)($stats->active ?? 0);
        $at_risk = (int)($stats->at_risk ?? 0);
        $high_risk = (int)($stats->high_risk ?? 0);
        $churned = (int)($stats->churned ?? 0);
        $total = (int)($stats->total ?? 0);

        $churn_rate = $total > 0 ? round(($churned / $total) * 100, 1) : 0;

        $chart = $wpdb->get_results("
            SELECT 
                DATE_FORMAT(calculated_at, '%Y-%m') as month,
                COUNT(CASE WHEN status = 'churned' THEN 1 END) as churned_count,
                ROUND(
                    COUNT(CASE WHEN status = 'churned' THEN 1 END) * 100.0 / 
                    NULLIF(COUNT(*), 0), 
                1) as churn_rate
            FROM $table
            GROUP BY DATE_FORMAT(calculated_at, '%Y-%m')
            ORDER BY month ASC
        ", ARRAY_A);

        $response = [
            'churn_rate'           => $churn_rate,
            'active_customers'     => $active,
            'at_risk_customers'    => $at_risk,
            'high_risk_customers'  => $high_risk,
            'churned_customers'    => $churned,
            'churn_threshold_days' => self::CHURN_THRESHOLD,
            'chart'                => $chart,
        ];

        set_transient($cache_key, $response, HOUR_IN_SECONDS);
        return rest_ensure_response($response);
    }

    public function rest_churn_customers($request)
    {
        $status   = $request->get_param('status') ?: '';
        $page     = max(1, (int)$request->get_param('page'));
        $per_page = max(1, min(100, (int)$request->get_param('per_page') ?: 50));

        $offset = ($page - 1) * $per_page;

        $cache_key = 'hippoo_bi_churn_customers_' . md5($status . $page . $per_page);

        if ($cached = get_transient($cache_key)) {
            return rest_ensure_response($cached);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHURN_SCORES;

        $where = '';
        $params = [];
        if (in_array($status, ['active', 'at_risk', 'high_risk', 'churned'], true)) {
            $where = " WHERE status = %s";
            $params[] = $status;
        }

        $customers = $wpdb->get_results($wpdb->prepare("
            SELECT 
                customer_id,
                churn_score,
                status,
                last_order_date,
                total_orders,
                total_spent,
                clv,
                TIMESTAMPDIFF(DAY, last_order_date, CURDATE()) as days_since_last
            FROM $table
            $where
            ORDER BY churn_score DESC, last_order_date DESC
            LIMIT %d OFFSET %d
        ", array_merge($params, [$per_page, $offset])));

        $result = [];
        foreach ($customers as $c) {
            $user = get_user_by('id', $c->customer_id);
            $name = $user ? $user->display_name : '';
            $email = $user ? $user->user_email : '';
            $masked = $email ? hippoo_mask_email($email) : '';

            $result[] = [
                'customer_id'          => (int)$c->customer_id,
                'email'                => $masked,
                'name'                 => $name,
                'churn_score'          => (int)$c->churn_score,
                'status'               => $c->status,
                'total_orders'         => (int)$c->total_orders,
                'total_spent'          => round((float)$c->total_spent),
                'last_order_date'      => $c->last_order_date,
                'days_since_last_order'=> (int)($c->days_since_last ?? 0),
                'clv'                  => round((float)$c->clv),
            ];
        }

        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        return rest_ensure_response($result);
    }

    public function rest_churn_export($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_CHURN_SCORES;

        $customers = $wpdb->get_results("
            SELECT c.*, u.user_email, u.display_name 
            FROM $table c
            LEFT JOIN {$wpdb->users} u ON u.ID = c.customer_id
            WHERE c.status = 'churned'
            ORDER BY c.churn_score DESC
        ");

        if (empty($customers)) {
            wp_send_json_error(__('No churned customers found.', 'hippoo'), 404);
        }

        $filename = 'churned-customers-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        
        fputcsv($output, [
            __('Customer ID', 'hippoo'),
            __('Name', 'hippoo'),
            __('Email', 'hippoo'),
            __('Churn Score', 'hippoo'),
            __('Status', 'hippoo'),
            __('Last Order', 'hippoo'),
            __('Days Since', 'hippoo'),
            __('Total Orders', 'hippoo'),
            __('Total Spent', 'hippoo'),
            __('CLV', 'hippoo')
        ], ',', '"', '\\');

        foreach ($customers as $c) {
            $user = get_user_by('id', $c->customer_id);
            $name = $user ? $user->display_name : '';
            $email = $user ? $user->user_email : '';

            fputcsv($output, [
                $c->customer_id,
                $name,
                $email,
                $c->churn_score,
                $c->status,
                $c->last_order_date,
                $c->days_since_last ?? 0,
                $c->total_orders,
                $c->total_spent,
                $c->clv
            ], ',', '"', '\\');
        }

        fclose($output);
        exit;
    }

    public function track_add_to_cart($cart_id, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        if (empty($product_id)) {
            return false;
        }

        $data = [
            'session_id' => $this->get_current_session_id(),
            'product_id' => absint($product_id),
            'quantity'   => absint($quantity),
            'created_at' => current_time('mysql'),
        ];

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ADD_TO_CARTS;
        $wpdb->insert($table, $data);
    }

    public function update_order_lookup($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        global $wpdb;
        $table_lookup = $wpdb->prefix . self::TABLE_ORDER_PRODUCT_LOOKUP;
        $table_stats = $wpdb->prefix . self::TABLE_ORDER_STATS;

        $wpdb->delete($table_lookup, ['order_id' => $order_id]);
        $wpdb->delete($table_stats, ['order_id' => $order_id]);

        if (!in_array($order->get_status(), wc_get_is_paid_statuses(), true)) {
            return;
        }

        if ('shop_order' !== $order->get_type()) {
            return;
        }

        $order_items = $order->get_items();
        $order_total = (float)$order->get_total();
        $order_net = (float)(floatval($order->get_total()) - floatval($order->get_total_tax()) - floatval($order->get_shipping_total()));
        $order_refund = (float)$order->get_total_refunded();
        $customer_id = $order->get_customer_id();
        $date_created = $order->get_date_created()->date('Y-m-d H:i:s');

        foreach ($order_items as $item) {
            $product_id = $item->get_product_id();
            if (!$product_id) continue;

            $wpdb->insert($table_lookup, [
                'order_id'     => $order_id,
                'product_id'   => $product_id,
                'customer_id'  => $customer_id > 0 ? $customer_id : null,
                'date_created' => $date_created,
                'quantity'     => (int)$item->get_quantity(),
                'revenue'      => (float)$item->get_total(),
            ]);
        }

        $wpdb->insert($table_stats, [
            'order_id'     => $order_id,
            'customer_id'  => $customer_id > 0 ? $customer_id : null,
            'date_created' => $date_created,
            'total'        => $order_total,
            'net'          => $order_net,
            'refund'       => $order_refund,
        ]);
        
        $this->clear_bi_caches();
    }

    public function delete_order_lookup($order_id)
    {
        global $wpdb;
        $table_lookup = $wpdb->prefix . self::TABLE_ORDER_PRODUCT_LOOKUP;
        $table_stats = $wpdb->prefix . self::TABLE_ORDER_STATS;

        $wpdb->delete($table_lookup, ['order_id' => $order_id]);
        $wpdb->delete($table_stats, ['order_id' => $order_id]);

        $this->clear_bi_caches();
    }

    public function sync_orders_lookup($batch_size = 500)
    {
        global $wpdb;
        $table_stats = $wpdb->prefix . self::TABLE_ORDER_STATS;

        $processed_orders = $wpdb->get_col("
            SELECT DISTINCT order_id FROM $table_stats
        ");

        $args = [
            'type'    => 'shop_order',
            'limit'   => -1,
            'orderby' => 'date_created',
            'order'   => 'ASC',
            'return'  => 'ids',
            'status'  => wc_get_is_paid_statuses(),
        ];

        $orders = wc_get_orders($args);

        $unprocessed_orders = array_diff($orders, $processed_orders);

        if (empty($unprocessed_orders)) {
            return;
        }

        $batch_orders = array_slice($unprocessed_orders, 0, $batch_size);

        $count = 0;
        foreach ($batch_orders as $order_id) {
            $this->update_order_lookup($order_id);
            $count++;
        }

        $remaining = count($unprocessed_orders) - $count;
        if ($remaining > 0) {
            wp_schedule_single_event(time() + 10, 'hippoo_bi_sync_lookup', [$batch_size]);
        }
    }

    public function calculate_churn_scores()
    {
        global $wpdb;
        $table_churn = $wpdb->prefix . self::TABLE_CHURN_SCORES;
        $table_stats = $wpdb->prefix . self::TABLE_ORDER_STATS;

        $customer_orders = $wpdb->get_results($wpdb->prepare("
            SELECT 
                customer_id,
                COUNT(order_id) as total_orders,
                SUM(total) as total_spent,
                MIN(date_created) as first_order,
                MAX(date_created) as last_order
            FROM $table_stats
            WHERE customer_id IS NOT NULL
            GROUP BY customer_id
            ORDER BY last_order DESC
        "));

        if (empty($customer_orders)) {
            return;
        }

        $customer_data = [];
        foreach ($customer_orders as $row) {
            $cid = (int)$row->customer_id;
            if ($cid <= 0) continue;

            $customer_data[$cid] = [
                'total_orders' => (int)$row->total_orders,
                'total_spent'  => (float)$row->total_spent,
                'first_order'  => $row->first_order,
                'last_order'   => $row->last_order,
            ];
        }
        
        $all_spent = wp_list_pluck($customer_data, 'total_spent');
        $max_orders = !empty($customer_data) ? max(wp_list_pluck($customer_data, 'total_orders')) : 1;
        $avg_spent = !empty($all_spent) ? array_sum($all_spent) / count($all_spent) : 1;

        foreach ($customer_data as $cid => $data) {
            $days_since_last = (time() - strtotime($data['last_order'])) / DAY_IN_SECONDS;

            $first_ts = strtotime($data['first_order']);
            $last_ts  = strtotime($data['last_order']);
            $span_days = max(1, ($last_ts - $first_ts) / DAY_IN_SECONDS);

            $avg_days_between = $data['total_orders'] > 1 
                ? $span_days / ($data['total_orders'] - 1) 
                : $span_days;

            $freq_score = min(1, $avg_days_between / 45);

            $score = (
                ($days_since_last / self::CHURN_THRESHOLD) * 40
                - (log($data['total_orders'] + 1) / log($max_orders + 1)) * 25
                - ($data['total_spent'] / $avg_spent) * 20
                - $freq_score * 15
            );

            $final_score = max(0, min(100, (int)round($score)));

            $status = 'active';
            if ($final_score >= 81) {
                $status = 'churned';
            } elseif ($final_score >= 61) {
                $status = 'high_risk';
            } elseif ($final_score >= 31) {
                $status = 'at_risk';
            }

            $aov = $data['total_orders'] > 0 ? $data['total_spent'] / $data['total_orders'] : $data['total_spent'];
            $clv = round(($aov * ($data['total_orders'] / max(1, $span_days / 365)) * 1.2), 2);

            $wpdb->replace($table_churn, [
                'customer_id'     => $cid,
                'churn_score'     => $final_score,
                'status'          => $status,
                'last_order_date' => $data['last_order'],
                'total_orders'    => $data['total_orders'],
                'total_spent'     => $data['total_spent'],
                'clv'             => $clv,
                'calculated_at'   => current_time('mysql'),
            ]);
        }
    }

    public function prune_old_pageviews()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PAGEVIEWS;

        $wpdb->query($wpdb->prepare("
            DELETE FROM $table 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 13 MONTH)
            LIMIT 5000
        "));
    }

    public function schedule_cron_events()
    {
        if (!wp_next_scheduled('hippoo_bi_daily_churn')) {
            $timezone = wp_timezone();
            $datetime = new \DateTime('tomorrow 2:00 AM', $timezone);
            $timestamp = $datetime->getTimestamp();
            
            wp_schedule_event($timestamp, 'daily', 'hippoo_bi_daily_churn');
        }

        if (!wp_next_scheduled('hippoo_bi_weekly_pruning')) {
            wp_schedule_event(time(), 'weekly', 'hippoo_bi_weekly_pruning');
        }

        if (!wp_next_scheduled('hippoo_bi_sync_lookup')) {
            wp_schedule_event(time(), 'hourly', 'hippoo_bi_sync_lookup');
        }
    }

    public function clear_cron_events()
    {
        $timestamp = wp_next_scheduled('hippoo_bi_daily_churn');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hippoo_bi_daily_churn');
        }

        $timestamp = wp_next_scheduled('hippoo_bi_weekly_pruning');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hippoo_bi_weekly_pruning');
        }

        $timestamp = wp_next_scheduled('hippoo_bi_sync_lookup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hippoo_bi_sync_lookup');
        }
    }

    public function clear_bi_caches()
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hippoo_bi_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_hippoo_bi_%'");
    }
    
    private function check_rate_limit()
    {
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $action_id = 'hippoo_bi_track_' . md5($ip);
        $delay_in_seconds = round(MINUTE_IN_SECONDS / 200, 2);

        try {
            if (\WC_Rate_Limiter::retried_too_soon($action_id)) {
                return false;
            }
            \WC_Rate_Limiter::set_rate_limit($action_id, $delay_in_seconds);
            return true;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('HippooBI Rate Limiter Error: ' . $e->getMessage());
            }
            return true;
        }
    }

    private function get_current_session_id()
    {
        $cookie_name = 'hpbi';

        if (!empty($_COOKIE[$cookie_name])) {
            return sanitize_text_field($_COOKIE[$cookie_name]);
        }

        $sid = substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', 8)), 0, 16);
        $expire = time() + (2 * YEAR_IN_SECONDS);

        setcookie($cookie_name, $sid, $expire, '/', '', is_ssl(), false);
        $_COOKIE[$cookie_name] = $sid;

        return $sid;
    }

    private function get_referrer_source($ref)
    {
        if (empty($ref)) {
            return null;
        }
        $host = wp_parse_url($ref, PHP_URL_HOST);
        return $host ? $host : null;
    }

    private function get_device_type()
    {
        try {
            $detect = new \Detection\MobileDetect();
            if ($detect->isTablet()) return 't';
            if ($detect->isMobile()) return 'm';
            return 'd';
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('HippooBI Mobile Detection Error: ' . $e->getMessage());
            }
            return 'd';
        }
    }

    private function get_country_from_ip()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (empty($ip) || $ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            return null;
        }

        $mmdb_path = HIPPOO_PATH . 'assets/geoip/GeoLite2-Country.mmdb';

        if (!file_exists($mmdb_path)) {
            return null;
        }

        try {
            $reader = new \GeoIp2\Database\Reader($mmdb_path);
            $record = $reader->country($ip);
            return $record->country->isoCode;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GeoIP Error: ' . $e->getMessage());
            }
            return null;
        }
    }

    private function get_date_range($period, $date_from = null, $date_to = null)
    {
        $period = sanitize_text_field(strtolower($period));
        $now = current_time('mysql');

        switch ($period) {
            case 'today':
                $from = date('Y-m-d 00:00:00', strtotime('today'));
                $to = $now;
                break;

            case 'yesterday':
                $from = date('Y-m-d 00:00:00', strtotime('yesterday'));
                $to   = date('Y-m-d 23:59:59', strtotime('yesterday'));
                break;

            case 'this_week':
                $from = date('Y-m-d 00:00:00', strtotime('this week'));
                $to = $now;
                break;

            case 'last_week':
                $from = date('Y-m-d 00:00:00', strtotime('-1 week'));
                $to = date('Y-m-d 23:59:59', strtotime('-1 week + 6 days'));
                break;

            case 'this_month':
                $from = date('Y-m-01 00:00:00');
                $to = $now;
                break;

            case 'last_month':
                $from = date('Y-m-01 00:00:00', strtotime('first day of last month'));
                $to = date('Y-m-t 23:59:59', strtotime('last day of last month'));
                break;

            case 'last_3_months':
                $from = date('Y-m-d 00:00:00', strtotime('-3 months'));
                $to = $now;
                break;

            case 'last_6_months':
                $from = date('Y-m-d 00:00:00', strtotime('-6 months'));
                $to = $now;
                break;

            case 'this_year':
                $from = date('Y-01-01 00:00:00');
                $to = $now;
                break;
            
            case 'last_year':
                $from = date('Y-01-01 00:00:00', strtotime('first day of last year'));
                $to = date('Y-12-t 23:59:59', strtotime('last day of last year'));
                break;

            case 'custom':
                $from = !empty($date_from) ? sanitize_text_field($date_from) . ' 00:00:00' : date('Y-m-01 00:00:00');
                $to = !empty($date_to) ? sanitize_text_field($date_to) . ' 23:59:59' : $now;
                break;

            default:
                $from = date('Y-m-01 00:00:00');
                $to = $now;
        }

        return [
            'from' => $from,
            'to' => $to,
        ];
    }

    private function get_previous_date_range($period, $date_from = null, $date_to = null)
    {
        $current = $this->get_date_range($period, $date_from, $date_to);
        
        $from = strtotime($current['from']);
        $to = strtotime($current['to']);

        $diff = $to - $from;

        return [
            'from' => date('Y-m-d H:i:s', $from - $diff),
            'to' => date('Y-m-d H:i:s', $to - $diff),
        ];
    }

    private function get_insight_tag($views, $orders, $conv_rate, $atc_rate, $checkout_rate)
    {
        if ($views < 50) {
            return [
                'tag'     => 'insufficient_data',
                'message' => __('Not enough data for analysis.', 'hippoo')
            ];
        }

        if ($orders == 0 && $views > 50) {
            return [
                'tag'     => 'dead_product',
                'message' => __('This product has views but zero sales.', 'hippoo')
            ];
        }

        if ($atc_rate > 8 && $checkout_rate > 0 && $checkout_rate < 15) {
            return [
                'tag'     => 'cart_drop',
                'message' => __('Users add to cart but don\'t complete checkout — Check checkout process.', 'hippoo')
            ];
        }

        if ($views < 100 && $conv_rate > 5) {
            return [
                'tag'     => 'hidden_gem',
                'message' => __('Excellent conversion with low views — This product needs more promotion.', 'hippoo')
            ];
        }

        if ($views > 300 && $conv_rate < 0.5) {
            return [
                'tag'     => 'high_traffic_low_conv',
                'message' => sprintf(
                    __('%d views, %d sales — Product page needs improvement.', 'hippoo'), 
                    $views, 
                    $orders
                )
            ];
        }

        if ($conv_rate > 3) {
            return [
                'tag'     => 'strong_performer',
                'message' => __('Strong performer — Replicate this pattern in other products.', 'hippoo')
            ];
        }

        return [
            'tag'     => 'normal',
            'message' => __('Normal performance.', 'hippoo')
        ];
    }

    private function mask_email($email)
    {
        return preg_replace('/^(.{2})(.*)@(.{2})(.*)\.(.+)$/', '$1***@$3***.$5', $email);
    }
}

new HippooBI();