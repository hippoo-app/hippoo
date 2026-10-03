<?php
/**
 * Hippoo BI – traffic reports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/** Build the BI traffic overview report. */
function hippoo_bi_get_traffic_overview( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_traffic_overview_' . md5( $period . $date_from . $date_to );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $summary = hippoo_bi_get_traffic_summary( $args );

    if ( is_wp_error( $summary ) ) {
        return $summary;
    }

    $result = array_merge(
        $summary,
        array(
            'top_pages'        => hippoo_bi_get_top_pages( $args ),
            'traffic_sources'  => hippoo_bi_get_traffic_sources( $args ),
            'device_breakdown' => hippoo_bi_get_device_breakdown( $args ),
            'chart'            => hippoo_bi_get_traffic_chart( $args ),
        )
    );

    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

/** Get the traffic metrics required by summary reports. */
function hippoo_bi_get_traffic_summary( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_traffic_summary_' . md5( $period . $date_from . $date_to );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $date_range = hippoo_bi_get_date_range( $period, $date_from, $date_to );

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $stats = hippoo_bi_get_site_stats_from_summary( $date_range['from'], $date_range['to'] );

        $unique_sessions = (int) ( $stats->unique_sessions ?? 0 );
        $bounce_sessions = (int) ( $stats->bounce_sessions ?? 0 );
        $bounce_rate     = $unique_sessions > 0 ? round( ( $bounce_sessions / $unique_sessions ) * 100, 1 ) : 0;

        $result = array(
            'total_views'        => (int) ( $stats->total_views ?? 0 ),
            'unique_sessions'    => $unique_sessions,
            'new_visitors'       => (int) ( $stats->new_visitors ?? 0 ),
            'returning_visitors' => (int) ( $stats->returning_visitors ?? 0 ),
            'bounce_rate'        => $bounce_rate,
        );

        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
        return $result;
    }

    $stats = hippoo_bi_get_traffic_stats( $args );

    $visitors = hippoo_bi_get_visitors( $args );

    $single_sessions = hippoo_bi_get_single_sessions_count( $args );

    $unique_sessions = (int) ( $stats->unique_sessions ?? 0 );

    $bounce_rate = $unique_sessions > 0 ? round( ( $single_sessions / $unique_sessions ) * 100, 1 ) : 0;

    $result = array(
        'total_views'        => (int) ( $stats->total_views ?? 0 ),
        'unique_sessions'    => $unique_sessions,
        'new_visitors'       => (int) ( $visitors->new_visitors ?? 0 ),
        'returning_visitors' => (int) ( $visitors->returning_visitors ?? 0 ),
        'bounce_rate'        => $bounce_rate,
    );

    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

/** Get total views and unique sessions for a report period. */
function hippoo_bi_get_traffic_stats( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $stats = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            COUNT(*) as total_views,
            COUNT(DISTINCT session_id) as unique_sessions
        FROM $table
        WHERE created_at BETWEEN %s AND %s
    ", $date_range['from'], $date_range['to'] ) );
    
    return $stats;
}

/** Get new and returning visitors for a report period. */
function hippoo_bi_get_visitors( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );
    
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $visitors = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            SUM(CASE WHEN first_seen >= %s THEN 1 ELSE 0 END) as new_visitors,
            SUM(CASE WHEN first_seen < %s THEN 1 ELSE 0 END) as returning_visitors
        FROM (
            SELECT
                session_id,
                MIN(created_at) as first_seen
            FROM $table
            WHERE created_at <= %s
            GROUP BY session_id
            HAVING MAX(created_at) >= %s
        ) as fv
    ", $date_range['from'], $date_range['from'], $date_range['to'], $date_range['from'] ) );

    return $visitors;
}

/** Get new and returning visitors for a report period. */
function hippoo_bi_get_single_sessions_count( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );
    
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $single_sessions = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(*) FROM (
            SELECT session_id
            FROM $table
            WHERE created_at BETWEEN %s AND %s
            GROUP BY session_id
            HAVING COUNT(*) = 1
        ) as b
    ", $date_range['from'], $date_range['to'] ) );

    return $single_sessions;
}

/** Get the most viewed pages for a report period. */
function hippoo_bi_get_top_pages( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $top_pages = $wpdb->get_results( $wpdb->prepare( "
        SELECT 
            page_url as url,
            COUNT(*) as views,
            MAX(product_id) as product_id
        FROM $table 
        WHERE created_at BETWEEN %s AND %s
        GROUP BY page_url
        ORDER BY views DESC 
        LIMIT 10
    ", $date_range['from'], $date_range['to'] ) );

    foreach ( $top_pages as &$page ) {
        $page->title = hippoo_bi_get_page_title( $page->url, (int) $page->product_id );
    }
    unset( $page );

    return $top_pages;
}

/** Get traffic sources for a report period. */
function hippoo_bi_get_traffic_sources( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $sources = $wpdb->get_results( $wpdb->prepare( "
        SELECT 
            COALESCE(NULLIF(referrer_source, ''), 'direct') as source,
            COUNT(*) as cnt
        FROM $table 
        WHERE created_at BETWEEN %s AND %s
        GROUP BY COALESCE(NULLIF(referrer_source, ''), 'direct')
        ORDER BY cnt DESC 
        LIMIT 10
    ", $date_range['from'], $date_range['to'] ) );

    $traffic_sources = array();

    foreach ( $sources as $source ) {
        $traffic_sources[ $source->source ] = (int) $source->cnt;
    }

    return $traffic_sources;
}

/** Get traffic device breakdown for a report period. */
function hippoo_bi_get_device_breakdown( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $devices = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            SUM(CASE WHEN device_type = 'm' THEN 1 ELSE 0 END) as mobile,
            SUM(CASE WHEN device_type = 't' THEN 1 ELSE 0 END) as tablet,
            SUM(CASE WHEN device_type = 'd' THEN 1 ELSE 0 END) as desktop,
            COUNT(*) as total
        FROM $table
        WHERE created_at BETWEEN %s AND %s
    ", $date_range['from'], $date_range['to'] ) );

    $device_breakdown = array(
        'mobile'  => (int) ( $devices->mobile ?? 0 ),
        'tablet'  => (int) ( $devices->tablet ?? 0 ),
        'desktop' => (int) ( $devices->desktop ?? 0 ),
    );

    return $device_breakdown;
}

/** Get daily traffic chart data. */
function hippoo_bi_get_traffic_chart( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $rows  = hippoo_bi_get_site_chart_from_summary( $date_range['from'], $date_range['to'] );
        $chart = array();
        foreach ( $rows as $row ) {
            $chart[] = (object) array(
                'date'     => $row->date,
                'views'    => (int) $row->views,
                'sessions' => (int) $row->sessions,
            );
        }
        return $chart;
    }

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $chart = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            DATE(created_at) as date,
            COUNT(*) as views,
            COUNT(DISTINCT session_id) as sessions
        FROM $table
        WHERE created_at BETWEEN %s AND %s
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", $date_range['from'], $date_range['to'] ) );

    return $chart;
}

/** Get a report page title from its product or WordPress URL. */
function hippoo_bi_get_page_title( $url, $product_id = 0 ) {
    if ( $product_id > 0 ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            return $product->get_name();
        }
    }

    if ( $url ) {
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $title = get_the_title( $post_id );
            if ( '' !== $title ) {
                return $title;
            }
        }

        $path = parse_url( $url, PHP_URL_PATH );
        if ( $path ) {
            return basename( $path );
        }
    }

    return $url ?: '';
}
