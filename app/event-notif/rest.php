<?php
/**
 * Hippoo Event Notification – REST API routes and callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

/** Register event notification REST API routes. */
add_action( 'rest_api_init', 'hippoo_event_notification_register_rest_routes' );

function hippoo_event_notification_register_rest_routes() {
    register_rest_route( 'wc-hippoo/v1', '/event-notification', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'hippoo_event_notification_get_notifications',
        'permission_callback' => 'hippoo_rest_permission_manage',
        'args'                => array(
            'page' => array(
                'type'    => 'integer',
                'default' => 1,
                'minimum' => 1,
            ),
            'per_page' => array(
                'type'    => 'integer',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 100,
            ),
        ),
    ) );

    register_rest_route( 'wc-hippoo/v1', '/event-notification/(?P<id>[\d]+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'hippoo_event_notification_get_notification',
        'permission_callback' => 'hippoo_rest_permission_manage',
    ) );

    register_rest_route( 'wc-hippoo/v1', '/event-notification', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'hippoo_event_notification_create_notification',
        'permission_callback' => 'hippoo_rest_permission_manage',
    ) );

    register_rest_route( 'wc-hippoo/v1', '/event-notification/(?P<id>[\d]+)', array(
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => 'hippoo_event_notification_update_notification',
        'permission_callback' => 'hippoo_rest_permission_manage',
    ) );

    register_rest_route( 'wc-hippoo/v1', '/event-notification/(?P<id>[\d]+)', array(
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => 'hippoo_event_notification_delete_notification',
        'permission_callback' => 'hippoo_rest_permission_manage',
    ) );

    register_rest_route( 'wc-hippoo/v1', '/event-notification/all-events', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'hippoo_event_notification_get_all_events',
        'permission_callback' => 'hippoo_rest_permission_manage',
        'args'                => array(
            'page' => array(
                'type'    => 'integer',
                'default' => 1,
                'minimum' => 1,
            ),
            'per_page' => array(
                'type'    => 'integer',
                'default' => 50,
                'minimum' => 1,
                'maximum' => 100,
            ),
        ),
    ) );

    register_rest_route( 'wc-hippoo/v1', '/event-notification/all-variables', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'hippoo_event_notification_get_all_variables',
        'permission_callback' => 'hippoo_rest_permission_manage',
        'args'                => array(
            'event' => array(
                'type' => 'string',
            ),
        ),
    ) );
}


// ---------------------------------------------------------------------------
// Route callbacks
// ---------------------------------------------------------------------------

function hippoo_event_notification_get_notifications( $request ) {
    $page     = max( 1, (int) $request['page'] );
    $per_page = max( 1, min( 100, (int) $request['per_page'] ) );

    $results = hippoo_event_notification_get_all( $page, $per_page );
    $total   = hippoo_event_notification_count();

    $response = rest_ensure_response( $results );

    $response->header( 'X-WP-Total', $total );
    $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

    return $response;
}

function hippoo_event_notification_get_notification( $request ) {
    $id = (int) $request['id'];

    $notification = hippoo_event_notification_get( $id );

    if ( ! $notification ) {
        return new WP_Error( 'not_found', __( 'Notification not found.', 'hippoo' ), array( 'status' => 404 ) );
    }

    return rest_ensure_response( $notification );
}

function hippoo_event_notification_create_notification( $request ) {
    $data = hippoo_event_notification_validate_data( $request );

    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $id = hippoo_event_notification_insert( $data );

    if ( ! $id ) {
        return new WP_Error( 'database_error', __( 'Could not create notification.', 'hippoo' ), array( 'status' => 500 ) );
    }

    $notification = hippoo_event_notification_get( $id );

    return rest_ensure_response( $notification );
}

function hippoo_event_notification_update_notification( $request ) {
    $id = (int) $request['id'];

    $existing = hippoo_event_notification_get( $id );

    if ( ! $existing ) {
        return new WP_Error( 'not_found', __( 'Notification not found.', 'hippoo' ), array( 'status' => 404 ) );
    }

    $data = hippoo_event_notification_validate_data( $request );

    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $updated = hippoo_event_notification_update( $id, $data );

    if ( false === $updated ) {
        return new WP_Error( 'database_error', __( 'Could not update notification.', 'hippoo' ), array( 'status' => 500 ) );
    }

    $notification = hippoo_event_notification_get( $id );

    return rest_ensure_response( $notification );
}

function hippoo_event_notification_delete_notification( $request ) {
    $id = (int) $request['id'];

    $notification = hippoo_event_notification_get( $id );

    if ( ! $notification ) {
        return new WP_Error( 'not_found', __( 'Notification not found.', 'hippoo' ), array( 'status' => 404 ) );
    }

    $deleted = hippoo_event_notification_delete( $id );

    if ( false === $deleted ) {
        return new WP_Error( 'database_error', __( 'Could not delete notification.', 'hippoo' ), array( 'status' => 500 ) );
    }

    return rest_ensure_response( array(
        'status' => 'deleted',
        'id'     => $id,
    ) );
}

function hippoo_event_notification_get_all_events( $request ) {
    $hooks = hippoo_event_notification_get_available_hooks();

    $page     = max( 1, (int) $request['page'] );
    $per_page = max( 1, min( 100, (int) $request['per_page'] ) );
    $offset = ( $page - 1 ) * $per_page;

    $paged_hooks = array_slice( $hooks, $offset, $per_page );
    $total = count( $hooks );

    $response = rest_ensure_response( $paged_hooks );

    $response->header( 'X-WP-Total', $total );
    $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

    return $response;
}

function hippoo_event_notification_get_all_variables( $request ) {
    $event = ! empty( $request['event'] ) ? $request['event'] : '';

    $variables = hippoo_event_notification_get_hook_variables( $event );

    return rest_ensure_response( $variables );
}


// ---------------------------------------------------------------------------
// REST helpers
// ---------------------------------------------------------------------------

function hippoo_event_notification_validate_data( $request ) {
    $data = $request->get_json_params();

    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_data', __( 'Invalid notification data.', 'hippoo' ), array( 'status' => 400 ) );
    }

    $required = array(
        'event',
        'title',
        'description',
    );

    foreach ( $required as $field ) {
        if ( empty( $data[ $field ] ) ) {
            /* translators: %s: missing required field name */
            return new WP_Error( 'missing_field', sprintf( __( 'Missing %s field.', 'hippoo' ), $field ), array( 'status' => 400 ) );
        }
    }

    $available_hooks = hippoo_event_notification_get_available_hooks();

    if ( ! in_array( $data['event'], $available_hooks, true ) ) {
        return new WP_Error( 'invalid_event', __( 'Invalid event hook.', 'hippoo' ), array( 'status' => 400 ) );
    }

    return array(
        'event' => sanitize_text_field( $data['event'] ),
        'sound' => isset( $data['sound'] ) ? sanitize_file_name( $data['sound'] ) : '',
        'title' => sanitize_text_field( $data['title'] ),
        'description' => sanitize_textarea_field( $data['description'] ),
    );
}
