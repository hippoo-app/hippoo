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
