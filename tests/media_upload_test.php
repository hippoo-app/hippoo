<?php
// Offline regression test for POST wc-hippoo/v1/wp/media/item. 1.12.0/1.12.1 inserted attachments
// with an empty MIME type, so WordPress generated no thumbnails and WooCommerce rejected the IDs.
if ( 'cli' !== PHP_SAPI ) {
    exit;
}

$abspath = sys_get_temp_dir() . '/hippoo-media-upload-test-' . getmypid() . '/';
mkdir( $abspath . 'wp-admin/includes', 0777, true );
file_put_contents( $abspath . 'wp-admin/includes/image.php', '<?php' );
define( 'ABSPATH', $abspath );

// --- WP stubs ---
class WP_Error { public $code; function __construct( $code ) { $this->code = $code; } }
class WP_REST_Response { public $data; function __construct( $data ) { $this->data = $data; } }
function add_action() {}
function add_filter() {}
function __( $text ) { return $text; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function sanitize_file_name( $name ) { return $name; }
function wp_upload_bits( $name ) { return array( 'file' => ABSPATH . $name, 'url' => 'http://x/' . $name ); }
function wp_insert_attachment( $attachment ) { $GLOBALS['__inserted'] = $attachment; return 42; }
function wp_generate_attachment_metadata() { return array(); }
function wp_update_attachment_metadata() {}
function wp_get_attachment_url() { return 'http://x/photo.png'; }

require __DIR__ . '/../app/rest.php';

$fail = 0;
function ok( $condition, $message ) {
    global $fail;
    echo ( $condition ? 'ok   ' : 'FAIL ' ) . $message . "\n";
    if ( ! $condition ) {
        $fail++;
    }
}

$tmp = $abspath . 'upload.tmp';
file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) );
$_FILES['file'] = array( 'name' => 'photo.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK );

$response = hippoo_rest_media_upload();

ok( $response instanceof WP_REST_Response, 'upload returns a success response' );
ok( 'image/png' === ( $GLOBALS['__inserted']['post_mime_type'] ?? null ), 'attachment is inserted with the MIME type of the uploaded file' );

unlink( $tmp );
unlink( $abspath . 'wp-admin/includes/image.php' );
rmdir( $abspath . 'wp-admin/includes' );
rmdir( $abspath . 'wp-admin' );
rmdir( $abspath );

echo $fail ? "\nFAIL ($fail)\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
