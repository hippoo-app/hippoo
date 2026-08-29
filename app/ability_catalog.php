<?php
/**
 * Hippoo ability layer — pure catalog + validation (NO WordPress calls here).
 * Safe to load standalone (php -r) for unit checks.
 */

if ( ! defined( 'HIPPOO_ABILITY_SCHEMA_DIR' ) ) {
    define( 'HIPPOO_ABILITY_SCHEMA_DIR', __DIR__ . '/abilities/schemas' );
}

/** Static descriptor metadata; schemas are loaded from bundled JSON. */
function hippoo_ability_descriptors() {
    return array(
        'get_sales_summary' => array(
            'description' => 'Return aggregated sales totals for a time period.',
            'write'       => false,
            'capability'  => 'read_reports',
        ),
        'list_orders' => array(
            'description' => 'List or search store orders, optionally filtered by status.',
            'write'       => false,
            'capability'  => 'read_orders',
        ),
        'get_product' => array(
            'description' => 'Look up a product and its stock by id or SKU.',
            'write'       => false,
            'capability'  => 'read_products',
        ),
    );
}

/** Load a bundled schema ("input"|"output"); returns decoded array or null. */
function hippoo_ability_load_schema( $name, $io ) {
    $path = HIPPOO_ABILITY_SCHEMA_DIR . '/' . $name . '.' . $io . '.schema.json';
    if ( ! is_readable( $path ) ) {
        return null;
    }
    $raw = file_get_contents( $path );
    if ( $raw === false ) {
        return null;
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : null;
}

/** Descriptor list for ability/list, matching the frozen ability-descriptor shape. */
function hippoo_ability_catalog_for_list() {
    $out = array();
    foreach ( hippoo_ability_descriptors() as $name => $meta ) {
        $out[] = array(
            'name'          => $name,
            'description'   => $meta['description'],
            'input_schema'  => hippoo_ability_load_schema( $name, 'input' ),
            'output_schema' => hippoo_ability_load_schema( $name, 'output' ),
            'write'         => $meta['write'],
            'capability'    => $meta['capability'],
        );
    }
    return $out;
}

/** Validate execute() input against the frozen input schemas (hand-rolled). Returns error strings. */
function hippoo_ability_validate_input( $name, $input ) {
    if ( ! is_array( $input ) ) {
        return array( 'input must be an object' );
    }
    switch ( $name ) {
        case 'get_sales_summary':
            return hippoo_ability_validate_get_sales_summary( $input );
        case 'list_orders':
            return hippoo_ability_validate_list_orders( $input );
        case 'get_product':
            return hippoo_ability_validate_get_product( $input );
        default:
            return array( 'unknown ability: ' . $name );
    }
}

function hippoo_ability_reject_unknown_keys( $input, array $allowed ) {
    $errors = array();
    foreach ( array_keys( $input ) as $k ) {
        if ( ! in_array( $k, $allowed, true ) ) {
            $errors[] = 'unexpected property: ' . $k;
        }
    }
    return $errors;
}

function hippoo_ability_validate_get_sales_summary( $in ) {
    $errors = hippoo_ability_reject_unknown_keys( $in, array( 'period', 'date_from', 'date_to' ) );
    if ( ! isset( $in['period'] ) ) {
        $errors[] = 'period is required';
    } elseif ( ! in_array( $in['period'], array( 'day', 'week', 'month', 'custom' ), true ) ) {
        $errors[] = 'period must be one of day|week|month|custom';
    }
    foreach ( array( 'date_from', 'date_to' ) as $d ) {
        if ( isset( $in[ $d ] ) && ( ! is_string( $in[ $d ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in[ $d ] ) ) ) {
            $errors[] = $d . ' must be a YYYY-MM-DD date';
        } elseif ( isset( $in[ $d ] ) && is_string( $in[ $d ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in[ $d ] ) ) {
            // Shape is valid; now check if it's a real calendar date.
            $parts = explode( '-', $in[ $d ] );
            if ( count( $parts ) === 3 ) {
                $y = (int) $parts[0];
                $m = (int) $parts[1];
                $d_val = (int) $parts[2];
                if ( ! checkdate( $m, $d_val, $y ) ) {
                    $errors[] = $d . ' is not a valid calendar date';
                }
            }
        }
    }
    // Decision 4: custom period requires both dates.
    if ( ( $in['period'] ?? '' ) === 'custom' && ( ! isset( $in['date_from'] ) || ! isset( $in['date_to'] ) ) ) {
        $errors[] = 'custom period requires date_from and date_to';
    }
    return $errors;
}

function hippoo_ability_validate_list_orders( $in ) {
    $errors = hippoo_ability_reject_unknown_keys( $in, array( 'status', 'search', 'page', 'per_page' ) );
    $statuses = array( 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
    if ( isset( $in['status'] ) && ! in_array( $in['status'], $statuses, true ) ) {
        $errors[] = 'status is not a recognized order status';
    }
    if ( isset( $in['search'] ) && ! is_string( $in['search'] ) ) {
        $errors[] = 'search must be a string';
    }
    if ( isset( $in['page'] ) && ( ! is_int( $in['page'] ) || $in['page'] < 1 ) ) {
        $errors[] = 'page must be an integer >= 1';
    }
    if ( isset( $in['per_page'] ) && ( ! is_int( $in['per_page'] ) || $in['per_page'] < 1 || $in['per_page'] > 100 ) ) {
        $errors[] = 'per_page must be an integer between 1 and 100';
    }
    return $errors;
}

function hippoo_ability_validate_get_product( $in ) {
    $errors = hippoo_ability_reject_unknown_keys( $in, array( 'product_id', 'sku' ) );
    if ( ! isset( $in['product_id'] ) && ! isset( $in['sku'] ) ) {
        $errors[] = 'at least one of product_id or sku is required';
    }
    if ( isset( $in['product_id'] ) && ( ! is_int( $in['product_id'] ) || $in['product_id'] < 1 ) ) {
        $errors[] = 'product_id must be an integer >= 1';
    }
    if ( isset( $in['sku'] ) && ( ! is_string( $in['sku'] ) || $in['sku'] === '' ) ) {
        $errors[] = 'sku must be a non-empty string';
    }
    return $errors;
}

/** Write-guard: a write ability requires confirmed:true. Pure — no WP. */
function hippoo_ability_is_blocked_write( array $descriptor, $confirmed ) {
    return ! empty( $descriptor['write'] ) && $confirmed !== true;
}
