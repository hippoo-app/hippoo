<?php
/**
 * Hippoo Event Notification – event hooks and notification handling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Event registration
// ---------------------------------------------------------------------------

/** Register notification hooks from the database. */
add_action( 'plugins_loaded', 'hippoo_event_notification_register_hooks', 20 );

function hippoo_event_notification_register_hooks() {
    $notifications = hippoo_event_notification_get_all_for_hooks();

    foreach ( $notifications as $notification ) {
        $event           = $notification['event'];
        $notification_id = (int) $notification['id'];

        add_action( $event, function ( ...$args ) use ( $event, $notification_id ) {
            hippoo_event_notification_send( $event, $notification_id, $args );
        }, 10, 10 );
    }
}


// ---------------------------------------------------------------------------
// Event groups
// ---------------------------------------------------------------------------

function hippoo_event_notification_get_hook_groups() {
    $hook_groups = array(
        'order' => array(
            'woocommerce_order_status_pending',
            'woocommerce_order_status_processing',
            'woocommerce_order_status_completed',
            'woocommerce_order_status_failed',
        ),
        'product' => array(
            'woocommerce_low_stock',
            'woocommerce_no_stock',
        ),
        'user' => array(
            'user_register',
            'profile_update',
        ),
        'comment' => array(
            'comment_post',
        ),
        'post' => array(
            'post_updated',
        ),
        'custom' => array(),
    );

    return apply_filters( 'hippoo_event_notification_hooks', $hook_groups );
}


// ---------------------------------------------------------------------------
// Variable definitions
// ---------------------------------------------------------------------------

function hippoo_event_notification_get_variable_definitions() {
    $variable_definitions = array(
        'order' => array(
            '{{order_id}}' => array(
                'callback' => 'hippoo_event_notification_get_order_id',
                'args'     => array( 'order' ),
            ),
            '{{billing_first_name}}' => array(
                'callback' => 'hippoo_event_notification_get_billing_first_name',
                'args'     => array( 'order' ),
            ),
            '{{billing_last_name}}' => array(
                'callback' => 'hippoo_event_notification_get_billing_last_name',
                'args'     => array( 'order' ),
            ),
            '{{billing_email}}' => array(
                'callback' => 'hippoo_event_notification_get_billing_email',
                'args'     => array( 'order' ),
            ),
            '{{order_total}}' => array(
                'callback' => 'hippoo_event_notification_get_order_total',
                'args'     => array( 'order' ),
            ),
            '{{order_status}}' => array(
                'callback' => 'hippoo_event_notification_get_order_status',
                'args'     => array( 'order' ),
            ),
        ),

        'product' => array(
            '{{product_id}}' => array(
                'callback' => 'hippoo_event_notification_get_product_id',
                'args'     => array( 'product' ),
            ),
            '{{product_name}}' => array(
                'callback' => 'hippoo_event_notification_get_product_name',
                'args'     => array( 'product' ),
            ),
            '{{stock_quantity}}' => array(
                'callback' => 'hippoo_event_notification_get_stock_quantity',
                'args'     => array( 'product' ),
            ),
        ),

        'user' => array(
            '{{user_id}}' => array(
                'callback' => 'hippoo_event_notification_get_user_id',
                'args'     => array( 'user' ),
            ),
            '{{user_login}}' => array(
                'callback' => 'hippoo_event_notification_get_user_login',
                'args'     => array( 'user' ),
            ),
            '{{user_email}}' => array(
                'callback' => 'hippoo_event_notification_get_user_email',
                'args'     => array( 'user' ),
            ),
        ),

        'comment' => array(
            '{{comment_content}}' => array(
                'callback' => 'hippoo_event_notification_get_comment_content',
                'args'     => array( 'comment' ),
            ),
            '{{comment_author}}' => array(
                'callback' => 'hippoo_event_notification_get_comment_author',
                'args'     => array( 'comment' ),
            ),
            '{{comment_post_ID}}' => array(
                'callback' => 'hippoo_event_notification_get_comment_post_id',
                'args'     => array( 'comment' ),
            ),
        ),

        'post' => array(
            '{{post_id}}' => array(
                'callback' => 'hippoo_event_notification_get_post_id',
                'args'     => array( 'post' ),
            ),
            '{{post_title}}' => array(
                'callback' => 'hippoo_event_notification_get_post_title',
                'args'     => array( 'post' ),
            ),
            '{{post_status}}' => array(
                'callback' => 'hippoo_event_notification_get_post_status',
                'args'     => array( 'post' ),
            ),
        ),

        'custom' => array(),
    );

    return apply_filters( 'hippoo_event_notification_definitions', $variable_definitions );
}


// ---------------------------------------------------------------------------
// Variable callbacks
// ---------------------------------------------------------------------------

function hippoo_event_notification_get_order_id( $order ) {
    return $order ? $order->get_id() : '';
}

function hippoo_event_notification_get_billing_first_name( $order ) {
    return $order ? $order->get_billing_first_name() : '';
}

function hippoo_event_notification_get_billing_last_name( $order ) {
    return $order ? $order->get_billing_last_name() : '';
}

function hippoo_event_notification_get_billing_email( $order ) {
    return $order ? $order->get_billing_email() : '';
}

function hippoo_event_notification_get_order_total( $order ) {
    return $order ? $order->get_total() : '';
}

function hippoo_event_notification_get_order_status( $order ) {
    return $order ? $order->get_status() : '';
}

function hippoo_event_notification_get_product_id( $product ) {
    return $product ? $product->get_id() : '';
}

function hippoo_event_notification_get_product_name( $product ) {
    return $product ? $product->get_name() : '';
}

function hippoo_event_notification_get_stock_quantity( $product ) {
    return $product ? $product->get_stock_quantity() : '';
}

function hippoo_event_notification_get_user_id( $user ) {
    return $user ? $user->ID : '';
}

function hippoo_event_notification_get_user_login( $user ) {
    return $user ? $user->user_login : '';
}

function hippoo_event_notification_get_user_email( $user ) {
    return $user ? $user->user_email : '';
}

function hippoo_event_notification_get_comment_content( $comment ) {
    return $comment ? $comment->comment_content : '';
}

function hippoo_event_notification_get_comment_author( $comment ) {
    return $comment ? $comment->comment_author : '';
}

function hippoo_event_notification_get_comment_post_id( $comment ) {
    return $comment ? $comment->comment_post_ID : '';
}

function hippoo_event_notification_get_post_id( $post ) {
    return $post ? $post->ID : '';
}

function hippoo_event_notification_get_post_title( $post ) {
    return $post ? $post->post_title : '';
}

function hippoo_event_notification_get_post_status( $post ) {
    return $post ? $post->post_status : '';
}


// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Send an event notification. */
function hippoo_event_notification_send( $event, $notification_id, $args ) {
    $notification = hippoo_event_notification_get( $notification_id );
    if ( ! $notification ) {
        return;
    }

    $variables = hippoo_event_notification_get_hook_variables( $event );
    $replacements = hippoo_event_notification_get_variable_replacements( $event, $args );

    $title = hippoo_event_notification_replace_variables( $notification['title'], $variables, $replacements );
    $description = hippoo_event_notification_replace_variables( $notification['description'], $variables, $replacements );

    hippoo_push_notification( $title, $description );
}

/** Get all available event hooks. */
function hippoo_event_notification_get_available_hooks() {
    $hook_groups = hippoo_event_notification_get_hook_groups();
    $hooks       = array();

    foreach ( $hook_groups as $group_hooks ) {
        $hooks = array_merge( $hooks, $group_hooks );
    }

    return array_unique( $hooks );
}

/** Get the group for an event hook. */
function hippoo_event_notification_get_hook_group( $event ) {
    $hook_groups = hippoo_event_notification_get_hook_groups();

    foreach ( $hook_groups as $group_name => $hooks ) {
        if ( in_array( $event, $hooks, true ) ) {
            return $group_name;
        }
    }

    return 'custom';
}

/** Get variables available for an event. */
function hippoo_event_notification_get_hook_variables( $event = '' ) {
    $definitions = hippoo_event_notification_get_variable_definitions();

    if ( $event ) {
        $group     = hippoo_event_notification_get_hook_group( $event );
        $variables = array();

        if ( $group && isset( $definitions[ $group ] ) ) {
            $variables = array_keys( $definitions[ $group ] );
        }

        $custom_variables = apply_filters( 'hippoo_event_notification_variables', array(), $event, $group );
        if ( is_array( $custom_variables ) && ! empty( $custom_variables ) ) {
            $variables = array_merge( $variables, array_keys( $custom_variables ) );
        }

        return array_unique( $variables );
    }

    $all_variables = array();

    foreach ( $definitions as $variables ) {
        $all_variables = array_merge( $all_variables, array_keys( $variables ) );
    }

    $custom_variables = apply_filters( 'hippoo_event_notification_variables', array(), '', 'custom' );
    if ( is_array( $custom_variables ) && ! empty( $custom_variables ) ) {
        $all_variables = array_merge( $all_variables, array_keys( $custom_variables ) );
    }

    return array_unique( $all_variables );
}

/** Get variable replacements for an event. */
function hippoo_event_notification_get_variable_replacements( $event, $args ) {
    $replacements = array();
    $group       = hippoo_event_notification_get_hook_group( $event );
    $definitions = hippoo_event_notification_get_variable_definitions();

    if ( $group && isset( $definitions[ $group ] ) ) {
        $variables = $definitions[ $group ];
        $arg_map   = array();

        if ( 'comment_post' === $event && isset( $args[0] ) && is_numeric( $args[0] ) ) {
            $comment_id = $args[0];
            $arg_map['comment'] = get_comment( $comment_id );
            if ( ! $arg_map['comment'] && isset( $args[2] ) && is_array( $args[2] ) ) {
                $arg_map['comment'] = (object) $args[2];
            }
        } else {
            foreach ( $args as $arg ) {
                if ( is_a( $arg, 'WC_Order' ) ) {
                    $arg_map['order'] = $arg;
                } elseif ( is_a( $arg, 'WC_Product' ) ) {
                    $arg_map['product'] = $arg;
                } elseif ( is_a( $arg, 'WP_Comment' ) ) {
                    $arg_map['comment'] = $arg;
                } elseif ( is_a( $arg, 'WP_Post' ) ) {
                    $arg_map['post'] = $arg;
                } elseif ( is_a( $arg, 'WP_User' ) ) {
                    $arg_map['user'] = $arg;
                } elseif ( is_numeric( $arg ) ) {
                    $hook_groups = hippoo_event_notification_get_hook_groups();

                    if ( in_array( $event, $hook_groups['user'], true ) && 'comment_post' !== $event ) {
                        $arg_map['user'] = get_user_by( 'id', $arg );
                    } elseif (
                        in_array( $event, $hook_groups['post'], true ) && 'comment_post' !== $event ) {
                        $arg_map['post'] = get_post( $arg );
                    }
                }
            }
        }

        foreach ( $variables as $variable => $config ) {
            $callback     = $config['callback'];
            $required_arg = $config['args'][0];

            if ( isset( $arg_map[ $required_arg ] ) ) {
                $replacements[ $variable ] = call_user_func( $callback, $arg_map[ $required_arg ] );
            } else {
                $replacements[ $variable ] = '';
            }
        }
    }

    $custom_variables = apply_filters( 'hippoo_event_notification_variables', array(), $event, $group );

    if ( is_array( $custom_variables ) && ! empty( $custom_variables ) ) {
        foreach ( $custom_variables as $variable => $config ) {
            if ( isset( $config['callback'] ) && is_callable( $config['callback'] ) ) {
                $required_arg = isset( $config['args'][0] ) ? $config['args'][0] : null;
                $index = isset( $config['arg_index'] ) ? $config['arg_index'] : 0;

                if ( $required_arg && isset( $args[ $index ] ) ) {
                    $replacements[ $variable ] = call_user_func( $config['callback'], $args[ $index ] );
                } else {
                    $replacements[ $variable ] = call_user_func( $config['callback'], $args );
                }
            } else {
                $replacements[ $variable ] = '';
            }
        }
    }

    return $replacements;
}

/** Replace variables in notification text. */
function hippoo_event_notification_replace_variables( $text, $variables, $replacements ) {
    foreach ( $variables as $variable ) {
        $value = isset( $replacements[ $variable ] ) ? $replacements[ $variable ] : '';
        $text = str_replace( $variable, $value, $text );
    }

    return $text;
}
