<?php
/**
 * Hippoo BI – churn reports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/** Number of inactive days used by BI churn scoring. */
define( 'HIPPOO_BI_CHURN_THRESHOLD', 90 );


/** Keep the legacy churn overview function. */
function hippoo_bi_get_churn_overview() {
    return hippoo_bi_get_churn_summary();
}

/** Build the BI churn summary report. */
function hippoo_bi_get_churn_summary() {
    $cache_key = 'hippoo_bi_churn_summary';
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;

    $stats = $wpdb->get_row( "
        SELECT
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
            COUNT(CASE WHEN status = 'at_risk' THEN 1 END) as at_risk,
            COUNT(CASE WHEN status = 'high_risk' THEN 1 END) as high_risk,
            COUNT(CASE WHEN status = 'churned' THEN 1 END) as churned,
            COUNT(*) as total
        FROM $table
    " );

    $active    = (int) ( $stats->active ?? 0 );
    $at_risk   = (int) ( $stats->at_risk ?? 0 );
    $high_risk = (int) ( $stats->high_risk ?? 0 );
    $churned   = (int) ( $stats->churned ?? 0 );
    $total     = (int) ( $stats->total ?? 0 );

    $churn_rate = $total > 0 ? round( ( $churned / $total ) * 100, 1 ) : 0;

    $chart = $wpdb->get_results( "
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
    ", ARRAY_A );

    $response = array(
        'churn_rate'           => $churn_rate,
        'active_customers'     => $active,
        'at_risk_customers'    => $at_risk,
        'high_risk_customers'  => $high_risk,
        'churned_customers'    => $churned,
        'churn_threshold_days' => HIPPOO_BI_CHURN_THRESHOLD,
        'chart'                => $chart,
    );

    set_transient( $cache_key, $response, HOUR_IN_SECONDS );
    return $response;
}

/** Build the BI churn customers report or customer detail report. */
function hippoo_bi_get_churn_customers( $args = array() ) {
    $lookup_email = sanitize_email( $args['email'] ?? '' );
    $lookup_id    = absint( $args['customer_id'] ?? ( $args['id'] ?? 0 ) );
    $status       = $args['status'] ?? '';
    $page         = max( 1, (int) ( $args['page'] ?? 1 ) );
    $per_page     = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
    $offset       = ( $page - 1 ) * $per_page;

    if ( $lookup_email || $lookup_id ) {
        return hippoo_bi_get_churn_customer_detail( $lookup_email, $lookup_id );
    }

    $cache_key = 'hippoo_bi_churn_customers_' . md5( $status . $page . $per_page );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    global $wpdb;

    $table  = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;
    $where  = '';
    $params = array();

    if ( in_array( $status, array( 'active', 'at_risk', 'high_risk', 'churned' ), true ) ) {
        $where    = ' WHERE status = %s';
        $params[] = $status;
    }

    $total_sql = "SELECT COUNT(*) FROM $table $where";

    $total = $params
        ? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) )
        : (int) $wpdb->get_var( $total_sql );

    $customers = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            email,
            customer_id,
            churn_score,
            status,
            first_order_date,
            last_order_date,
            total_orders,
            total_spent,
            clv,
            avg_days_between,
            TIMESTAMPDIFF(DAY, last_order_date, CURDATE()) as days_since_last
        FROM $table
        $where
        ORDER BY churn_score DESC, last_order_date DESC
        LIMIT %d OFFSET %d
    ", array_merge( $params, array( $per_page, $offset ) ) ) );

    $result = array();

    foreach ( $customers as $customer ) {
        $name  = '';
        $email = $customer->email ?: '';

        if ( $customer->customer_id > 0 ) {
            $user = get_user_by( 'id', $customer->customer_id );
            if ( $user ) {
                $name = $user->display_name;
                if ( empty( $email ) ) {
                    $email = $user->user_email;
                }
            }
        }

        $masked = $email ? hippoo_mask_email( $email ) : '';
        $aov    = $customer->total_orders > 0 ? round( (float) $customer->total_spent / $customer->total_orders, 2 ) : 0;

        $result[] = array(
            'customer_id'           => (int) $customer->customer_id,
            'email'                 => $masked,
            'name'                  => $name,
            'churn_score'           => (int) $customer->churn_score,
            'status'                => $customer->status,
            'total_orders'          => (int) $customer->total_orders,
            'total_spent'           => round( (float) $customer->total_spent ),
            'first_order_date'      => $customer->first_order_date,
            'last_order_date'       => $customer->last_order_date,
            'days_since_last_order' => (int) ( $customer->days_since_last ?? 0 ),
            'clv'                   => round( (float) $customer->clv ),
            'aov'                   => $aov,
            'avg_days_between'      => (float) $customer->avg_days_between,
        );
    }

    $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

    $response = array(
        'data'        => $result,
        'total'       => $total,
        'total_pages' => $total_pages,
    );

    set_transient( $cache_key, $response, HOUR_IN_SECONDS );
    return $response;
}

/** Build the BI detail report for one churn customer. */
function hippoo_bi_get_churn_customer_detail( $email, $customer_id ) {
    global $wpdb;

    $table_churn  = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;
    $table_stats  = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_STATS;
    $table_lookup = $wpdb->prefix . HIPPOO_BI_TABLE_ORDER_PRODUCT_LOOKUP;

    $customer = null;

    if ( $email ) {
        $customer = $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM $table_churn WHERE email = %s
        ", $email ) );
    }

    if ( ! $customer && $customer_id > 0 ) {
        $customer = $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM $table_churn WHERE customer_id = %d LIMIT 1
        ", $customer_id ) );
    }

    if ( ! $customer ) {
        return new WP_Error( 'not_found', __( 'Customer not found.', 'hippoo' ), array( 'status' => 404 ) );
    }

    $email = $customer->email;
    $cid   = (int) $customer->customer_id;
    $name  = '';

    if ( $cid > 0 ) {
        $user = get_user_by( 'id', $cid );
        if ( $user ) {
            $name = $user->display_name;
        }
    }

    $masked     = $email ? hippoo_mask_email( $email ) : '';
    $aov        = $customer->total_orders > 0 ? round( (float) $customer->total_spent / $customer->total_orders, 2 ) : 0;
    $days_since = $customer->last_order_date
        ? (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT TIMESTAMPDIFF(DAY, %s, CURDATE())',
            $customer->last_order_date
        ) )
        : 0;

    // Revenue breakdown
    $where_rev = $email
        ? $wpdb->prepare( 'billing_email = %s', $email )
        : $wpdb->prepare( 'customer_id = %d', $cid );

    $rev = $wpdb->get_row( "
        SELECT
            COALESCE(SUM(total), 0) as gross_sales,
            COALESCE(SUM(discount), 0) as discounts,
            COALESCE(SUM(refund), 0) as refunds,
            COALESCE(SUM(shipping), 0) as shipping,
            COALESCE(SUM(tax), 0) as tax,
            COALESCE(SUM(net), 0) as net_revenue
        FROM $table_stats
        WHERE $where_rev
    " );

    // Favorite product
    $favorite_where = $email
        ? $wpdb->prepare(
            "order_id IN (SELECT order_id FROM $table_stats WHERE billing_email = %s)",
            $email
        )
        : $wpdb->prepare( 'customer_id = %d', $cid );

    $favorite = $wpdb->get_row( "
        SELECT product_id, SUM(quantity) as qty
        FROM $table_lookup
        WHERE $favorite_where
        GROUP BY product_id
        ORDER BY qty DESC
        LIMIT 1
    " );

    $favorite_product = null;
    if ( $favorite && $favorite->product_id ) {
        $product = wc_get_product( $favorite->product_id );

        $favorite_product = array(
            'product_id' => (int) $favorite->product_id,
            'name'       => $product ? $product->get_name() : '',
            'quantity'   => (int) $favorite->qty,
        );
    }

    // Order status summary
    $status_rows = $wpdb->get_results("
        SELECT order_status, COUNT(*) as cnt
        FROM $table_stats
        WHERE $where_rev
        GROUP BY order_status
    ");

    $order_status_summary = array();
    foreach ( $status_rows as $status_row ) {
        if ( $status_row->order_status ) {
            $order_status_summary[ $status_row->order_status ] = (int) $status_row->cnt;
        }
    }

    return array(
        'customer_id'           => $cid,
        'email'                 => $masked,
        'name'                  => $name,
        'churn_score'           => (int) $customer->churn_score,
        'status'                => $customer->status,
        'total_orders'          => (int) $customer->total_orders,
        'total_spent'           => round( (float) $customer->total_spent ),
        'first_order_date'      => $customer->first_order_date,
        'last_order_date'       => $customer->last_order_date,
        'days_since_last_order' => $days_since,
        'clv'                   => round( (float) $customer->clv ),
        'aov'                   => $aov,
        'avg_days_between'      => (float) $customer->avg_days_between,
        'revenue_breakdown'     => array(
            'gross_sales' => round( (float) ( $rev->gross_sales ?? 0 ) ),
            'discounts'   => round( (float) ( $rev->discounts ?? 0 ) ),
            'refunds'     => round( (float) ( $rev->refunds ?? 0 ) ),
            'shipping'    => round( (float) ( $rev->shipping ?? 0 ) ),
            'tax'         => round( (float) ( $rev->tax ?? 0 ) ),
            'net_revenue' => round( (float) ( $rev->net_revenue ?? 0 ) ),
        ),
        'favorite_product'     => $favorite_product,
        'order_status_summary' => $order_status_summary,
    );
}

/** Get churned customers prepared for CSV export. */
function hippoo_bi_export_churn_customers() {
    global $wpdb;

    $table = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;

    $customers = $wpdb->get_results( "
        SELECT c.*, u.display_name,
               TIMESTAMPDIFF(DAY, c.last_order_date, CURDATE()) as days_since_last
        FROM $table c
        LEFT JOIN {$wpdb->users} u ON u.ID = c.customer_id
        WHERE c.status = 'churned'
        ORDER BY c.churn_score DESC
    " );

    if ( empty( $customers ) ) {
        return new WP_Error( 'not_found', __( 'No churned customers found.', 'hippoo' ) );
    }

    return array(
        'customers' => $customers,
        'filename'  => 'churn-customers-' . date( 'Y-m-d' ) . '.csv',
    );
}

/** Get customers prepared for CSV export by status. */
function hippoo_bi_export_churn_customers_by_status( $args = array() ) {
    global $wpdb;

    $table  = $wpdb->prefix . HIPPOO_BI_TABLE_CHURN_SCORES;
    $status = sanitize_text_field( $args['status'] ?? '' );
    $where  = '';
    $params = array();

    if ( in_array( $status, array( 'active', 'at_risk', 'high_risk', 'churned' ), true ) ) {
        $where    = ' WHERE c.status = %s';
        $params[] = $status;
    }

    $sql = "
        SELECT c.*, u.display_name,
               TIMESTAMPDIFF(DAY, c.last_order_date, CURDATE()) as days_since_last
        FROM $table c
        LEFT JOIN {$wpdb->users} u ON u.ID = c.customer_id
        $where
        ORDER BY c.churn_score DESC
    ";

    $customers = $params
        ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
        : $wpdb->get_results( $sql );

    if ( empty( $customers ) ) {
        return new WP_Error( 'not_found',  __( 'No customers found for the selected status.', 'hippoo' ) );
    }

    return array(
        'customers' => $customers,
        'filename'  => 'churn-customers-' . ( $status ?: 'all' ) . '-' . date( 'Y-m-d' ) . '.csv',
    );
}
