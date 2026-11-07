<?php
/**
 * Hippoo BI – traffic reports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Build the BI traffic overview report. */
function hippoo_bi_reports_traffic_overview( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_traffic_overview_' . md5( $period . $date_from . $date_to );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $summary = hippoo_bi_reports_traffic_summary( $args );

    if ( is_wp_error( $summary ) ) {
        return $summary;
    }

    $result = array_merge(
        $summary,
        array(
            'top_pages'        => hippoo_bi_reports_traffic_top_pages( $args ),
            'traffic_sources'  => hippoo_bi_reports_traffic_sources( $args ),
            'device_breakdown' => hippoo_bi_reports_traffic_device_breakdown( $args ),
            'chart'            => hippoo_bi_reports_traffic_chart( $args ),
        )
    );

    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

/** Get the traffic metrics required by summary reports. */
function hippoo_bi_reports_traffic_summary( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_traffic_summary_' . md5( $period . $date_from . $date_to );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $date_range = hippoo_bi_reports_date_range( $period, $date_from, $date_to );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;

    $stats = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            SUM(views) as total_views,
            SUM(unique_sessions) as unique_sessions,
            SUM(bounce_sessions) as bounce_sessions
        FROM $table
        WHERE date BETWEEN %s AND %s
    ", $date_range['from'], $date_range['to'] ) );

    $visitors = hippoo_bi_reports_traffic_visitors( $args );

    $unique_sessions = (int) ( $stats->unique_sessions ?? 0 );
    $bounce_sessions = (int) ( $stats->bounce_sessions ?? 0 );
    $bounce_rate     = $unique_sessions > 0 ? round( ( $bounce_sessions / $unique_sessions ) * 100, 1 ) : 0;

    $result = array(
        'total_views'        => (int) ( $stats->total_views ?? 0 ),
        'unique_sessions'    => $unique_sessions,
        'bounce_rate'        => $bounce_rate,
        'new_visitors'       => (int) ( $visitors->new_visitors ?? 0 ),
        'returning_visitors' => (int) ( $visitors->returning_visitors ?? 0 ),
    );

    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

/** Unique new / returning visitors for a report period (raw pageviews). */
function hippoo_bi_reports_traffic_visitors( $args = array() ) {
    $date_range = hippoo_bi_reports_date_range(
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
        ) fv
    ", $date_range['from'], $date_range['from'], $date_range['to'], $date_range['from'] ) );

    return $visitors;
}

/** Get the most viewed pages for a report period. */
function hippoo_bi_reports_traffic_top_pages( $args = array() ) {
    $date_range = hippoo_bi_reports_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table_ps = $wpdb->prefix . HIPPOO_BI_TABLE_POST_STATS;
    $table_p  = $wpdb->prefix . HIPPOO_BI_TABLE_PATHS;

    $top_pages = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            p.path as url,
            SUM(ps.views) as views,
            MAX(ps.post_id) as product_id
        FROM $table_ps ps
        INNER JOIN $table_p p ON p.id = ps.path_id
        WHERE ps.date BETWEEN %s AND %s
        GROUP BY ps.path_id
        ORDER BY views DESC
        LIMIT 10
    ", $date_range['from'], $date_range['to'] ) );

    foreach ( $top_pages as &$page ) {
        $page->title = hippoo_bi_reports_traffic_page_title( $page->url, (int) $page->product_id );
    }
    unset( $page );

    return $top_pages;
}

/** Get traffic sources for a report period. */
function hippoo_bi_reports_traffic_sources( $args = array() ) {
    $date_range = hippoo_bi_reports_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table_pv = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $table_r  = $wpdb->prefix . HIPPOO_BI_TABLE_REFERRERS;

    $sources = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            r.source,
            COUNT(*) as cnt
        FROM $table_pv pv
        INNER JOIN $table_r r ON r.id = pv.referrer_id
        WHERE pv.created_at BETWEEN %s AND %s
        GROUP BY pv.referrer_id
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
function hippoo_bi_reports_traffic_device_breakdown( $args = array() ) {
    $date_range = hippoo_bi_reports_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;

    $devices = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            SUM(mobile_views) as mobile,
            SUM(tablet_views) as tablet,
            SUM(desktop_views) as desktop
        FROM $table
        WHERE date BETWEEN %s AND %s
    ", $date_range['from'], $date_range['to'] ) );

    $device_breakdown = array(
        'mobile'  => (int) ( $devices->mobile ?? 0 ),
        'tablet'  => (int) ( $devices->tablet ?? 0 ),
        'desktop' => (int) ( $devices->desktop ?? 0 ),
    );

    return $device_breakdown;
}

/** Get daily traffic chart data. */
function hippoo_bi_reports_traffic_chart( $args = array() ) {
    $date_range = hippoo_bi_reports_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_SITE_STATS;

    $rows = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            date,
            views,
            unique_sessions as sessions
        FROM $table
        WHERE date BETWEEN %s AND %s
        ORDER BY date ASC
    ", $date_range['from'], $date_range['to'] ) );

    $chart = array();
    foreach ( $rows as $row ) {
        $chart[] = (object) array(
            'date'     => $row->date,
            'views'    => $row->views,
            'sessions' => $row->sessions,
        );
    }

    return $chart;
}

/** Get a report page title from its product or WordPress URL. */
function hippoo_bi_reports_traffic_page_title( $url, $post_id = 0 ) {
    if ( $post_id > 0 ) {
        $product = wc_get_product( $post_id );
        if ( $product ) {
            return $product->get_name();
        }
        
        $title = get_the_title( $post_id );
        if ( '' !== $title ) {
            return $title;
        }
    }

    if ( $url ) {
        $resolved = url_to_postid( home_url( $url ) );
        if ( $resolved ) {
            $title = get_the_title( $resolved );
            if ( '' !== $title ) {
                return $title;
            }
        }

        return basename( $url ) ?: $url;
    }

    return '';
}
