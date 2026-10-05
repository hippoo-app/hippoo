<?php
/**
 * Hippoo Compatibility – logging
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HIPPOO_COMPATIBILITY_LOG_FILE' ) ) {
    define( 'HIPPOO_COMPATIBILITY_LOG_FILE', 'api-compatibility.log' );
}

$GLOBALS['hippoo_compatibility_pending_logs'] = null;


// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

/** Log a Compatibility issue. */
function hippoo_compatibility_log_issue( $endpoint, $action, $field_name, $field_value, $object_id = null ) {
    if ( is_array( $field_name ) ) {
        $field_name = wp_json_encode( $field_name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    $field_name = (string) $field_name;
    $field_type = gettype( $field_value );

    if ( is_array( $field_value ) ) {
        $field_value = wp_json_encode( $field_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    } elseif ( is_object( $field_value ) ) {
        $field_value = '(object) ' . get_class( $field_value );
    } elseif ( is_null( $field_value ) ) {
        $field_value = 'NULL';
    } elseif ( is_bool( $field_value ) ) {
        $field_value = $field_value ? 'true' : 'false';
    }

    $field_value = (string) $field_value;

    $id_info = null !== $object_id ? " #{$object_id}" : '';
    $now     = current_time( 'mysql' );

    $unique_key = sprintf( '[%s] [%s] %s', strtoupper( $endpoint ), strtoupper( $action ), $field_name );

    // Initialize pending logs if first call
    if ( null === $GLOBALS['hippoo_compatibility_pending_logs'] ) {
        $GLOBALS['hippoo_compatibility_pending_logs'] = hippoo_get_log_content( HIPPOO_COMPATIBILITY_LOG_FILE );

        // Register shutdown to write logs once at the end
        register_shutdown_function( 'hippoo_compatibility_write_pending_logs' );
    }

    $pattern = '/^(\[.*?\])? ?' . preg_quote( $unique_key, '/' ) . ' \(occurred (\d+) times?, last seen: .*?\).*$/m';

    $pending_logs = $GLOBALS['hippoo_compatibility_pending_logs'];

    if ( ! empty( $pending_logs ) && preg_match( $pattern, $pending_logs, $matches ) ) {
        $current_count = (int) $matches[2];
        $new_count     = $current_count + 1;

        $new_line = sprintf(
            '%s (occurred %d times, last seen: %s) | Sample: %s (%s) %s',
            $unique_key,
            $new_count,
            $now,
            $id_info,
            $field_type,
            $field_value
        );

        // Preserve timestamp if it existed, otherwise without
        $full_new_line = ! empty( $matches[1] ) ? $matches[1] . ' ' . $new_line : $new_line;

        $GLOBALS['hippoo_compatibility_pending_logs'] = preg_replace( $pattern, $full_new_line, $pending_logs, 1 );
        return;
    }

    $new_line = sprintf(
        '%s (occurred 1 time, last seen: %s) | Sample: %s (%s) %s',
        $unique_key,
        $now,
        $id_info,
        $field_type,
        $field_value
    );

    $GLOBALS['hippoo_compatibility_pending_logs'] .= "[{$now}] " . $new_line . PHP_EOL;
}

/** Write pending Compatibility logs once at shutdown. */
function hippoo_compatibility_write_pending_logs() {
    if ( null !== $GLOBALS['hippoo_compatibility_pending_logs'] ) {
        hippoo_put_log_content(
            HIPPOO_COMPATIBILITY_LOG_FILE,
            $GLOBALS['hippoo_compatibility_pending_logs']
        );

        $GLOBALS['hippoo_compatibility_pending_logs'] = null;
    }
}

/** Return the formatted Compatibility debug log. */
function hippoo_compatibility_get_log_content() {
    $raw_log = hippoo_get_log_content( HIPPOO_COMPATIBILITY_LOG_FILE );

    if ( empty( $raw_log ) ) {
        return __( "No API compatibility issues logged yet.\n", 'hippoo' );
    }

    $header  = "=== " . __( 'Hippoo API Compatibility Debug Log', 'hippoo' ) . " ===\n";
    $header .= sprintf(
        __( 'Generated: %s', 'hippoo' ) . "\n",
        current_time( 'mysql' )
    );
    $header .= "==========================================\n\n";

    return $header . $raw_log;
}
