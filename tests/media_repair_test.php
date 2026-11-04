<?php
// Offline test for the one-time repair of attachments that 1.12.0/1.12.1 saved with an empty MIME
// type. Stubs WP; only files that really are images may be changed.
if ( 'cli' !== PHP_SAPI ) {
    exit;
}

$abspath = sys_get_temp_dir() . '/hippoo-media-repair-test-' . getmypid() . '/';
mkdir( $abspath . 'wp-admin/includes', 0777, true );
file_put_contents( $abspath . 'wp-admin/includes/image.php', '<?php' );
define( 'ABSPATH', $abspath );

$png = $abspath . 'photo.png';
$txt = $abspath . 'notes.txt';
file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) );
file_put_contents( $txt, 'not an image' );

// --- WP stubs ---
class FakeWpdb {
    public $posts = 'wp_posts';
    public $rows  = array();
    public $updates = array();
    public $last_after = null;
    public $last_limit = null;
    function prepare( $query, ...$args ) { return array( $query, $args ); }
    function get_col( $prepared ) {
        list( , $args ) = $prepared;
        list( $this->last_after, $this->last_limit ) = $args;
        $ids = array_values( array_filter( array_keys( $this->rows ), function ( $id ) { return $id > $this->last_after; } ) );
        sort( $ids );
        return array_map( 'strval', array_slice( $ids, 0, $this->last_limit ) );
    }
    function update( $table, $data, $where ) { $this->updates[ $where['ID'] ] = $data['post_mime_type']; return 1; }
}
$wpdb = new FakeWpdb();
$GLOBALS['__options']   = array();
$GLOBALS['__scheduled'] = array();
$GLOBALS['__schedule_result'] = true;
$GLOBALS['__metadata']  = array();
$GLOBALS['__cleaned']   = array();

function add_action() {}
function absint( $value ) { return abs( (int) $value ); }
function get_option( $name, $default = false ) { return $GLOBALS['__options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['__options'][ $name ] = $value; return true; }
function wp_schedule_single_event( $time, $hook, $args = array() ) {
    if ( $GLOBALS['__schedule_result'] ) {
        $GLOBALS['__scheduled'][] = array( 'hook' => $hook, 'args' => $args );
    }
    return $GLOBALS['__schedule_result'];
}
function get_attached_file( $id ) { return $GLOBALS['wpdb']->rows[ $id ] ?? false; }
function wp_get_image_mime( $file ) { $info = @getimagesize( $file ); return $info ? $info['mime'] : false; }
function clean_post_cache( $id ) { $GLOBALS['__cleaned'][] = $id; }
function wp_generate_attachment_metadata( $id, $file ) { return array( 'sizes' => array( 'thumbnail' => $file ) ); }
function wp_update_attachment_metadata( $id, $data ) { $GLOBALS['__metadata'][ $id ] = $data; }

require __DIR__ . '/../app/media-repair.php';

$fail = 0;
function ok( $condition, $message ) {
    global $fail;
    echo ( $condition ? 'ok   ' : 'FAIL ' ) . $message . "\n";
    if ( ! $condition ) {
        $fail++;
    }
}
function reset_state() {
    global $wpdb;
    $wpdb->rows = array();
    $wpdb->updates = array();
    $GLOBALS['__scheduled'] = array();
    $GLOBALS['__metadata']  = array();
    $GLOBALS['__cleaned']   = array();
    $GLOBALS['__schedule_result'] = true;
}

// A real image gets its MIME type and thumbnails back.
$wpdb->rows = array( 10 => $png );
hippoo_media_repair_run_batch();
ok( 'image/png' === ( $wpdb->updates[10] ?? null ), 'image attachment gets the MIME type read from its file' );
ok( isset( $GLOBALS['__metadata'][10]['sizes'] ), 'image attachment gets its metadata regenerated' );
ok( in_array( 10, $GLOBALS['__cleaned'], true ), 'post cache is cleared so regeneration sees the new MIME type' );

// Anything that is not provably an image is left alone.
reset_state();
$wpdb->rows = array( 11 => $txt, 12 => $abspath . 'missing.jpg', 13 => false );
hippoo_media_repair_run_batch();
ok( array() === $wpdb->updates, 'non-image, missing and pathless attachments are not updated' );
ok( array() === $GLOBALS['__metadata'], 'non-image, missing and pathless attachments are not regenerated' );

// Batching: a full batch queues the next one after its last ID, a short batch ends the job.
reset_state();
foreach ( range( 1, HIPPOO_MEDIA_REPAIR_BATCH_SIZE + 2 ) as $id ) {
    $wpdb->rows[ $id ] = $png;
}
hippoo_media_repair_run_batch();
ok( HIPPOO_MEDIA_REPAIR_BATCH_SIZE === count( $wpdb->updates ), 'one run repairs at most one batch' );
ok( array( HIPPOO_MEDIA_REPAIR_BATCH_SIZE ) === ( $GLOBALS['__scheduled'][0]['args'] ?? null ), 'a full batch schedules the next run after its last ID' );

$GLOBALS['__scheduled'] = array();
hippoo_media_repair_run_batch( HIPPOO_MEDIA_REPAIR_BATCH_SIZE );
ok( HIPPOO_MEDIA_REPAIR_BATCH_SIZE + 2 === count( $wpdb->updates ), 'the next run continues after the given ID' );
ok( array() === $GLOBALS['__scheduled'], 'a short batch ends the job' );

// Skipped rows keep their empty MIME type, so the walk must not restart and loop forever.
reset_state();
foreach ( range( 1, HIPPOO_MEDIA_REPAIR_BATCH_SIZE ) as $id ) {
    $wpdb->rows[ $id ] = $txt;
}
hippoo_media_repair_run_batch();
ok( array( HIPPOO_MEDIA_REPAIR_BATCH_SIZE ) === ( $GLOBALS['__scheduled'][0]['args'] ?? null ), 'a batch of skipped rows still moves forward' );

// Scheduling: once per install, and retried if WP-Cron refused the event.
reset_state();
$GLOBALS['__schedule_result'] = false;
hippoo_media_repair_schedule();
ok( false === get_option( 'hippoo_media_repair_version' ), 'job is not marked as scheduled when scheduling fails' );

$GLOBALS['__schedule_result'] = true;
hippoo_media_repair_schedule();
ok( 'hippoo_media_repair_batch' === ( $GLOBALS['__scheduled'][0]['hook'] ?? null ), 'job is scheduled on the first load' );

hippoo_media_repair_schedule();
ok( 1 === count( $GLOBALS['__scheduled'] ), 'job is not scheduled again on later loads' );

unlink( $png );
unlink( $txt );
unlink( $abspath . 'wp-admin/includes/image.php' );
rmdir( $abspath . 'wp-admin/includes' );
rmdir( $abspath . 'wp-admin' );
rmdir( $abspath );

echo $fail ? "\nFAIL ($fail)\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
