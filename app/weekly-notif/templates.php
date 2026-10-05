<?php
/**
 * Hippoo Weekly Notification – notification templates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Notification templates
// ---------------------------------------------------------------------------

define( 'HIPPOO_WEEKLY_NOTIF_TITLE_TEMPLATE', '📊 Weekly Sales — %s' );
define( 'HIPPOO_WEEKLY_NOTIF_SUMMARY_TEMPLATE', 'Revenue: %s | Orders: %d | AOV: %s' );
define( 'HIPPOO_WEEKLY_NOTIF_BEST_TEMPLATE', 'Best: %s (%s)' );
define( 'HIPPOO_WEEKLY_NOTIF_COMPARISON_TEMPLATE', '%s %s%% vs last week' );
define( 'HIPPOO_WEEKLY_NOTIF_CHURN_TEMPLATE', "⚠️ %d customers haven't bought in 80+ days" );
