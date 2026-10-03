<?php
/**
 * Hippoo BI – product reports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/** Build the BI product overview report. */
function hippoo_bi_get_products_overview( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';
    $min_views = isset( $args['min_views'] ) ? (int) $args['min_views'] : 30;
    $limit     = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
    $sort_by   = ! empty( $args['sort_by'] ) ? $args['sort_by'] : 'conv_rate';
    $page      = max( 1, (int) ( $args['page'] ?? 1 ) );
    $per_page  = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );

    $cache_key = 'hippoo_bi_products_intel_' . md5( $period . $date_from . $date_to . $min_views . $limit . $sort_by . $page . $per_page );

    $cached = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $traffic = hippoo_bi_get_product_traffic_stats( $args );

    if ( empty( $traffic ) ) {
        $response = array(
            'data'        => array(),
            'total'       => 0,
            'total_pages' => 0,
        );

        set_transient( $cache_key, $response, 15 * MINUTE_IN_SECONDS );
        return $response;
    }

    $product_ids = array_map( 'intval', wp_list_pluck( $traffic, 'product_id' ) );

    $sales = hippoo_bi_get_product_sales_stats( $args, $product_ids );
    $atc   = hippoo_bi_get_product_atc_stats( $args, $product_ids );

    $products = array();

    foreach ( $traffic as $item ) {
        $product_id = (int) $item['product_id'];

        $sales_data = $sales[ $product_id ] ?? array(
            'orders'  => 0,
            'revenue' => 0,
        );

        $atc_data = $atc[ $product_id ] ?? array(
            'add_to_cart'  => 0,
            'atc_sessions' => 0,
        );

        $views        = (int) $item['views'];
        $sessions     = (int) $item['unique_sessions'];
        $orders       = (int) $sales_data['orders'];
        $revenue      = (float) $sales_data['revenue'];
        $add_to_cart  = (int) $atc_data['add_to_cart'];
        $atc_sessions = (int) $atc_data['atc_sessions'];

        $revenue_per_view   = $views > 0 ? round( $revenue / $views, 2 ) : 0;
        $cart_to_order_rate = $atc_sessions > 0 ? round( ( $orders / $atc_sessions ) * 100, 2 ) : 0;
        $conversion_rate    = $sessions > 0 ? round( ( $orders / $sessions ) * 100, 2 ) : 0;
        $atc_rate           = $sessions > 0 ? round( ( $atc_sessions / $sessions ) * 100, 2 ) : 0;

        $insight = hippoo_bi_get_insight_tag( $views, $orders, $conversion_rate, $atc_rate, $cart_to_order_rate );

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            continue;
        }

        $thumbnail = '';
        $image_id  = $product->get_image_id();

        if ( $image_id ) {
            $thumbnail = wp_get_attachment_image_url( $image_id, 'thumbnail' ) ?: '';
        }

        $products[] = array(
            'product_id'         => $product_id,
            'product_name'       => $product->get_name(),
            'product_url'        => $product->get_permalink(),
            'thumbnail'          => $thumbnail,
            'views'              => $views,
            'unique_sessions'    => $sessions,
            'add_to_cart'        => $add_to_cart,
            'orders'             => $orders,
            'revenue'            => round( $revenue ),
            'revenue_per_view'   => $revenue_per_view,
            'conversion_rate'    => $conversion_rate,
            'atc_rate'           => $atc_rate,
            'cart_to_order_rate' => $cart_to_order_rate,
            'insight_tag'        => $insight['tag'],
            'insight_message'    => $insight['message'],
        );
    }

    usort( $products, function ( $a, $b ) use ( $sort_by ) {
        switch ( $sort_by ) {
            case 'conv_rate':
                return ( $b['conversion_rate'] ?? 0 ) <=> ( $a['conversion_rate'] ?? 0 );

            case 'revenue_per_view':
                return ( $b['revenue_per_view'] ?? 0 ) <=> ( $a['revenue_per_view'] ?? 0 );

            case 'views':
                return ( $b['views'] ?? 0 ) <=> ( $a['views'] ?? 0 );

            case 'revenue':
                return ( $b['revenue'] ?? 0 ) <=> ( $a['revenue'] ?? 0 );

            default: // insight_priority - smaller number = higher priority
                $priority = array(
                    'dead_product'          => 1,
                    'cart_drop'             => 2,
                    'hidden_gem'            => 3,
                    'high_traffic_low_conv' => 4,
                    'strong_performer'      => 5,
                    'normal'                => 6,
                    'insufficient_data'     => 7,
                );

                return ( $priority[ $a['insight_tag'] ] ?? 99 ) <=> ( $priority[ $b['insight_tag'] ] ?? 99 );
        }
    } );

    $total = count( $products );

    if ( $limit > 0 ) {
        $products   = array_slice( $products, 0, $limit );
        $total      = count( $products );
        $page       = 1;
        $per_page   = $total;
    }

    $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
    $offset      = ( $page - 1 ) * $per_page;
    $paged_products = array_slice( $products, $offset, $per_page );

    $response = array(
        'data'        => $paged_products,
        'total'       => $total,
        'total_pages' => $total_pages,
    );

    set_transient( $cache_key, $response, 15 * MINUTE_IN_SECONDS );

    return $response;
}

/** Build the BI report for a single product. */
function hippoo_bi_get_product_overview( $args = array() ) {
    $product_id = absint( $args['id'] ?? 0 );
    $period     = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from  = $args['date_from'] ?? '';
    $date_to    = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_product_intel_' . md5( $product_id . $period . $date_from . $date_to );
    $cached = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return new WP_Error( 'not_found', __( 'Product not found.', 'hippoo' ), array( 'status' => 404 ) );
    }

    $traffic = hippoo_bi_get_product_traffic_stats( array_merge( $args, array( 'min_views' => 0 ) ) );
    $traffic_data = array(
        'views'           => 0,
        'unique_sessions' => 0,
    );
    foreach ( $traffic as $item ) {
        if ( (int) $item['product_id'] === $product_id ) {
            $traffic_data = array(
                'views'           => (int) $item['views'],
                'unique_sessions' => (int) $item['unique_sessions'],
            );
            break;
        }
    }

    $sales = hippoo_bi_get_product_sales_stats( $args, array( $product_id ) );
    $sales_data = $sales[ $product_id ] ?? array(
        'orders'  => 0,
        'revenue' => 0,
    );

    $atc = hippoo_bi_get_product_atc_stats( $args, array( $product_id ) );
    $atc_data = $atc[ $product_id ] ?? array(
        'add_to_cart'  => 0,
        'atc_sessions' => 0,
    );

    $views        = (int) $traffic_data['views'];
    $sessions     = (int) $traffic_data['unique_sessions'];
    $orders       = (int) $sales_data['orders'];
    $revenue      = (float) $sales_data['revenue'];
    $add_to_cart  = (int) $atc_data['add_to_cart'];
    $atc_sessions = (int) $atc_data['atc_sessions'];

    $revenue_per_view   = $views > 0 ? round( $revenue / $views, 2 ) : 0;
    $cart_to_order_rate = $atc_sessions > 0 ? round( ( $orders / $atc_sessions ) * 100, 2 ) : 0;
    $conversion_rate    = $sessions > 0 ? round( ( $orders / $sessions ) * 100, 2 ) : 0;
    $atc_rate           = $sessions > 0 ? round( ( $atc_sessions / $sessions ) * 100, 2 ) : 0;

    $insight = hippoo_bi_get_insight_tag( $views, $orders, $conversion_rate, $atc_rate, $cart_to_order_rate );

    $chart = hippoo_bi_get_product_chart( $args, $product_id );

    $thumbnail = '';
    $image_id  = $product->get_image_id();

    if ( $image_id ) {
        $thumbnail = wp_get_attachment_image_url( $image_id, 'thumbnail' ) ?: '';
    }

    $response = array(
        'product_id'         => $product_id,
        'product_name'       => $product->get_name(),
        'product_url'        => $product->get_permalink(),
        'thumbnail'          => $thumbnail,
        'views'              => $views,
        'unique_sessions'    => $sessions,
        'add_to_cart'        => $add_to_cart,
        'orders'             => $orders,
        'revenue'            => round( $revenue ),
        'revenue_per_view'   => $revenue_per_view,
        'conversion_rate'    => $conversion_rate,
        'atc_rate'           => $atc_rate,
        'cart_to_order_rate' => $cart_to_order_rate,
        'insight_tag'        => $insight['tag'],
        'insight_message'    => $insight['message'],
        'chart'              => $chart,
    );

    set_transient( $cache_key, $response, 15 * MINUTE_IN_SECONDS );

    return $response;
}

/** Build the BI product highlights report. */
function hippoo_bi_get_product_highlights( $args = array() ) {
    $period    = ! empty( $args['period'] ) ? $args['period'] : 'this_month';
    $date_from = $args['date_from'] ?? '';
    $date_to   = $args['date_to'] ?? '';

    $cache_key = 'hippoo_bi_product_highlights_' . md5( $period . $date_from . $date_to );
    $cached = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $traffic = hippoo_bi_get_product_traffic_stats( $args );

    if ( empty( $traffic ) ) {
        set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
        return array();
    }

    $product_ids = array_map( 'intval', wp_list_pluck( $traffic, 'product_id' ) );

    $sales = hippoo_bi_get_product_sales_stats( $args, $product_ids );
    $atc   = hippoo_bi_get_product_atc_stats( $args, $product_ids );

    $highlights = array();

    foreach ( $traffic as $item ) {
        $product_id = (int) $item['product_id'];

        $sales_data = $sales[ $product_id ] ?? array(
            'orders'  => 0,
            'revenue' => 0,
        );

        $atc_data = $atc[ $product_id ] ?? array(
            'add_to_cart'  => 0,
            'atc_sessions' => 0,
        );

        $views        = (int) $item['views'];
        $sessions     = (int) $item['unique_sessions'];
        $orders       = (int) $sales_data['orders'];
        $revenue      = (float) $sales_data['revenue'];
        $add_to_cart  = (int) $atc_data['add_to_cart'];
        $atc_sessions = (int) $atc_data['atc_sessions'];

        $revenue_per_view = $views > 0 ? round( $revenue / $views, 2 ) : 0;
        $cart_to_order_rate = $atc_sessions > 0 ? round( ( $orders / $atc_sessions ) * 100, 2 ) : 0;
        $conversion_rate = $sessions > 0 ? round( ( $orders / $sessions ) * 100, 2 ) : 0;
        $atc_rate = $sessions > 0 ? round( ( $atc_sessions / $sessions ) * 100, 2 ) : 0;

        $insight = hippoo_bi_get_insight_tag( $views, $orders, $conversion_rate, $atc_rate, $cart_to_order_rate );

        $product = array(
            'product_id'         => $product_id,
            'views'              => $views,
            'unique_sessions'    => $sessions,
            'add_to_cart'        => $add_to_cart,
            'orders'             => $orders,
            'revenue'            => $revenue,
            'revenue_per_view'   => $revenue_per_view,
            'conversion_rate'    => $conversion_rate,
            'atc_rate'           => $atc_rate,
            'cart_to_order_rate' => $cart_to_order_rate,
            'insight_tag'        => $insight['tag'],
            'insight_message'    => $insight['message'],
        );

        $tag = $insight['tag'];

        if ( ! isset( $highlights[ $tag ] ) ) {
            $highlights[ $tag ] = $product;
            continue;
        }

        $current = $highlights[ $tag ];

        switch ( $tag ) {
            case 'strong_performer':
                if ( $product['revenue'] > $current['revenue'] ) {
                    $highlights[ $tag ] = $product;
                }
                break;

            case 'high_traffic_low_conv':
                if (
                    $product['views'] > $current['views']
                    || (
                        $product['views'] === $current['views']
                        && $product['conversion_rate'] < $current['conversion_rate']
                    )
                ) {
                    $highlights[ $tag ] = $product;
                }
                break;

            case 'hidden_gem':
            case 'normal':
                if ( $product['conversion_rate'] > $current['conversion_rate'] ) {
                    $highlights[ $tag ] = $product;
                }
                break;

            case 'cart_drop':
                if ( $product['cart_to_order_rate'] < $current['cart_to_order_rate'] ) {
                    $highlights[ $tag ] = $product;
                }
                break;

            case 'dead_product':
            case 'insufficient_data':
                if ( $product['views'] > $current['views'] ) {
                    $highlights[ $tag ] = $product;
                }
                break;
        }
    }

    foreach ( $highlights as $tag => &$item ) {
        $product = wc_get_product( (int) $item['product_id'] );

        if ( ! $product ) {
            unset( $highlights[ $tag ] );
            continue;
        }

        $thumbnail = '';
        $image_id  = $product->get_image_id();

        if ( $image_id ) {
            $thumbnail = wp_get_attachment_image_url( $image_id, 'thumbnail' ) ?: '';
        }

        $item['product_name'] = $product->get_name();
        $item['product_url']  = $product->get_permalink();
        $item['thumbnail']    = $thumbnail;
    }

    unset( $item );

    set_transient( $cache_key, $highlights, 15 * MINUTE_IN_SECONDS );

    return $highlights;
}

/** Get product traffic data for a report period. */
function hippoo_bi_get_product_traffic_stats( $args = array() ) {
    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    $min_views = isset( $args['min_views'] ) ? (int) $args['min_views'] : 30;

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $all     = hippoo_bi_get_product_stats_from_summary( $date_range['from'], $date_range['to'] );
        $results = array();
        foreach ( $all as $pid => $data ) {
            if ( $min_views > 0 && $data['views'] < $min_views ) {
                continue;
            }
            $results[] = array(
                'product_id'      => $pid,
                'views'           => $data['views'],
                'unique_sessions' => $data['unique_sessions'],
            );
        }
        usort( $results, function ( $a, $b ) {
            return $b['views'] <=> $a['views'];
        } );
        return $results;
    }

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;

    $sql = "
        SELECT
            product_id,
            COUNT(*) as views,
            COUNT(DISTINCT session_id) as unique_sessions
        FROM $table
        WHERE product_id IS NOT NULL
          AND created_at BETWEEN %s AND %s
        GROUP BY product_id
    ";

    $params = array(
        $date_range['from'],
        $date_range['to'],
    );

    if ( $min_views > 0 ) {
        $sql     .= ' HAVING views >= %d';
        $params[] = $min_views;
    }

    $sql .= ' ORDER BY views DESC';

    $results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

    return $results;
}

/** Get product sales data for a report period. */
function hippoo_bi_get_product_sales_stats( $args = array(), $product_ids = array() ) {
    if ( empty( $product_ids ) ) {
        return array();
    }

    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $all   = hippoo_bi_get_product_stats_from_summary( $date_range['from'], $date_range['to'], $product_ids );
        $sales = array();
        foreach ( $all as $pid => $data ) {
            $sales[ $pid ] = array(
                'orders'  => $data['orders'],
                'revenue' => $data['revenue'],
            );
        }
        return $sales;
    }

    global $wpdb;

    $table        = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;
    $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

    $results = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            product_id,
            COUNT(DISTINCT order_id) as orders,
            SUM(quantity) as total_quantity,
            SUM(revenue) as revenue
        FROM $table
        WHERE product_id IN ($placeholders)
          AND date_created BETWEEN %s AND %s
        GROUP BY product_id
    ", array_merge( $product_ids, array( $date_range['from'], $date_range['to'] ) ) ), ARRAY_A );

    $sales = array();

    foreach ( $results as $row ) {
        $product_id = (int) $row['product_id'];

        $sales[ $product_id ] = array(
            'orders'  => (int) $row['orders'],
            'revenue' => (float) $row['revenue'],
        );
    }

    return $sales;
}

/** Get product add-to-cart data for a report period. */
function hippoo_bi_get_product_atc_stats( $args = array(), $product_ids = array() ) {
    if ( empty( $product_ids ) ) {
        return array();
    }

    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $all = hippoo_bi_get_product_stats_from_summary( $date_range['from'], $date_range['to'], $product_ids );
        $atc = array();
        foreach ( $all as $pid => $data ) {
            $atc[ $pid ] = array(
                'add_to_cart'  => $data['add_to_cart'],
                'atc_sessions' => $data['atc_sessions'],
            );
        }
        return $atc;
    }

    global $wpdb;

    $table        = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

    $results = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            product_id,
            SUM(quantity) as total_atc,
            COUNT(DISTINCT session_id) as atc_sessions
        FROM $table
        WHERE product_id IN ($placeholders)
          AND created_at BETWEEN %s AND %s
        GROUP BY product_id
    ", array_merge( $product_ids, array( $date_range['from'], $date_range['to'] ) ) ), ARRAY_A );

    $atc = array();

    foreach ( $results as $row ) {
        $product_id = (int) $row['product_id'];

        $atc[ $product_id ] = array(
            'add_to_cart'  => (int) $row['total_atc'],
            'atc_sessions' => (int) $row['atc_sessions'],
        );
    }

    return $atc;
}

/** Get daily chart data for a single product. */
function hippoo_bi_get_product_chart( $args = array(), $product_id = 0 ) {
    if ( ! $product_id ) {
        return array();
    }

    $date_range = hippoo_bi_get_date_range(
        $args['period'] ?? 'this_month',
        $args['date_from'] ?? '',
        $args['date_to'] ?? ''
    );

    if ( hippoo_bi_use_summary( $date_range['from'], $date_range['to'] ) && hippoo_bi_summary_is_ready( $date_range['from'], $date_range['to'] ) ) {
        $rows  = hippoo_bi_get_product_chart_from_summary( $date_range['from'], $date_range['to'], $product_id );
        $chart = array();
        foreach ( $rows as $row ) {
            $chart[] = array(
                'date'        => $row->date,
                'views'       => (int) $row->views,
                'sessions'    => (int) $row->sessions,
                'add_to_cart' => (int) $row->add_to_cart,
                'orders'      => (int) $row->orders,
                'revenue'     => (float) $row->revenue,
            );
        }
        return $chart;
    }

    global $wpdb;

    $table_pv     = $wpdb->prefix . HIPPOO_BI_TABLE_PAGEVIEWS;
    $table_atc    = $wpdb->prefix . HIPPOO_BI_TABLE_ADD_TO_CARTS;
    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;

    $views = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            DATE(created_at) AS date,
            COUNT(*) AS views,
            COUNT(DISTINCT session_id) AS sessions
        FROM $table_pv
        WHERE product_id = %d
          AND created_at BETWEEN %s AND %s
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", $product_id, $date_range['from'], $date_range['to'] ) );

    $atc = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            DATE(created_at) AS date,
            SUM(quantity) AS add_to_cart
        FROM $table_atc
        WHERE product_id = %d
          AND created_at BETWEEN %s AND %s
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", $product_id, $date_range['from'], $date_range['to'] ) );

    $sales = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            DATE(date_created) AS date,
            COUNT(DISTINCT order_id) AS orders,
            SUM(revenue) AS revenue
        FROM $table_lookup
        WHERE product_id = %d
          AND date_created BETWEEN %s AND %s
        GROUP BY DATE(date_created)
        ORDER BY date ASC
    ", $product_id, $date_range['from'], $date_range['to'] ) );

    $chart = array();

    foreach ( $views as $row ) {
        $chart[ $row->date ] = array(
            'date'        => $row->date,
            'views'       => (int) $row->views,
            'sessions'    => (int) $row->sessions,
            'add_to_cart' => 0,
            'orders'      => 0,
            'revenue'     => 0,
        );
    }

    foreach ( $atc as $row ) {
        if ( ! isset( $chart[ $row->date ] ) ) {
            $chart[ $row->date ] = array(
                'date'        => $row->date,
                'views'       => 0,
                'sessions'    => 0,
                'add_to_cart' => 0,
                'orders'      => 0,
                'revenue'     => 0,
            );
        }

        $chart[ $row->date ]['add_to_cart'] = (int) $row->add_to_cart;
    }

    foreach ( $sales as $row ) {
        if ( ! isset( $chart[ $row->date ] ) ) {
            $chart[ $row->date ] = array(
                'date'        => $row->date,
                'views'       => 0,
                'sessions'    => 0,
                'add_to_cart' => 0,
                'orders'      => 0,
                'revenue'     => 0,
            );
        }

        $chart[ $row->date ]['orders']  = (int) $row->orders;
        $chart[ $row->date ]['revenue'] = (float) $row->revenue;
    }

    ksort( $chart );
    return array_values( $chart );
}

/** Resolve the insight tag and message for a product. */
function hippoo_bi_get_insight_tag( $views, $orders, $conv_rate, $atc_rate, $checkout_rate ) {
    if ( $views < 50 ) {
        return array(
            'tag'     => 'insufficient_data',
            'message' => __( 'Not enough data for analysis.', 'hippoo' ),
        );
    }

    if ( 0 === $orders && $views > 50 ) {
        return array(
            'tag'     => 'dead_product',
            'message' => __( 'This product has views but zero sales.', 'hippoo' ),
        );
    }

    if ( $atc_rate > 8 && $checkout_rate > 0 && $checkout_rate < 15 ) {
        return array(
            'tag'     => 'cart_drop',
            'message' => __( 'Users add to cart but don\'t complete checkout — Check checkout process.', 'hippoo' ),
        );
    }

    if ( $views < 100 && $conv_rate > 5 ) {
        return array(
            'tag'     => 'hidden_gem',
            'message' => __( 'Excellent conversion with low views — This product needs more promotion.', 'hippoo' ),
        );
    }

    if ( $views > 300 && $conv_rate < 0.5 ) {
        return array(
            'tag'     => 'high_traffic_low_conv',
            'message' => sprintf(
                __( '%d views, %d sales — Product page needs improvement.', 'hippoo' ),
                $views,
                $orders
            ),
        );
    }

    if ( $conv_rate > 3 ) {
        return array(
            'tag'     => 'strong_performer',
            'message' => __( 'Strong performer — Replicate this pattern in other products.', 'hippoo' ),
        );
    }

    return array(
        'tag'     => 'normal',
        'message' => __( 'Normal performance.', 'hippoo' ),
    );
}
