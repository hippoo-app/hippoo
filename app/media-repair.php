<?php
/**
 * Hippoo media repair – one-time background job for attachments saved with an empty MIME type
 * by the 1.12.0 and 1.12.1 media upload route.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HIPPOO_MEDIA_REPAIR_VERSION', '1' );
define( 'HIPPOO_MEDIA_REPAIR_BATCH_SIZE', 5 );
define( 'HIPPOO_MEDIA_REPAIR_BATCH_DELAY', 30 );

/** Schedule the repair once per install. */
add_action( 'plugins_loaded', 'hippoo_media_repair_schedule' );

function hippoo_media_repair_schedule() {
    if ( HIPPOO_MEDIA_REPAIR_VERSION === get_option( 'hippoo_media_repair_version' ) ) {
        return;
    }

    if ( wp_schedule_single_event( time() + 60, 'hippoo_media_repair_batch' ) ) {
        update_option( 'hippoo_media_repair_version', HIPPOO_MEDIA_REPAIR_VERSION );
    }
}

/** Repair one batch of attachments after the given ID, then queue the next batch. */
add_action( 'hippoo_media_repair_batch', 'hippoo_media_repair_run_batch' );

function hippoo_media_repair_run_batch( $after_id = 0 ) {
    global $wpdb;

    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type = '' AND ID > %d
             ORDER BY ID ASC LIMIT %d",
            absint( $after_id ),
            HIPPOO_MEDIA_REPAIR_BATCH_SIZE
        )
    );

    // Queue the next batch first, so a fatal on one image cannot stop the rest of the job.
    if ( count( $ids ) === HIPPOO_MEDIA_REPAIR_BATCH_SIZE ) {
        wp_schedule_single_event( time() + HIPPOO_MEDIA_REPAIR_BATCH_DELAY, 'hippoo_media_repair_batch', array( (int) end( $ids ) ) );
    }

    foreach ( $ids as $id ) {
        hippoo_media_repair_attachment( (int) $id );
    }
}

/** Restore the MIME type and thumbnails of an attachment whose file really is an image. */
function hippoo_media_repair_attachment( $attachment_id ) {
    global $wpdb;

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        return false;
    }

    $mime_type = wp_get_image_mime( $file );
    if ( ! $mime_type ) {
        return false;
    }

    $wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime_type ), array( 'ID' => $attachment_id ) );
    clean_post_cache( $attachment_id );

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );

    return true;
}
