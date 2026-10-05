<?php
/**
 * Hippoo AI – product description generator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Models
// ---------------------------------------------------------------------------

/** Return supported GPT models. */
function hippoo_ai_get_gpt_models() {
    return array(
        'gpt-5',
        'gpt-4',
        'gpt-4o',
        'gpt-4o-mini',
    );
}

/** Return supported Gemini models. */
function hippoo_ai_get_gemini_models() {
    return array(
        'gemini-2.5-flash',
        'gemini-2.5-pro',
    );
}

/** Return models for a provider. */
function hippoo_ai_get_models( $provider ) {
    switch ( $provider ) {
        case 'gpt':
            return hippoo_ai_get_gpt_models();

        case 'gemini':
            return hippoo_ai_get_gemini_models();

        default:
            return new WP_Error( 'unsupported_provider', __( 'Unsupported AI provider.', 'hippoo' ), array( 'status' => 400 ) );
    }
}


// ---------------------------------------------------------------------------
// Default prompts
// ---------------------------------------------------------------------------

/** Return default system prompt. */
function hippoo_ai_get_default_system_prompt() {
    return __( 'Analyze product images and related descriptions to generate high-quality, engaging, and SEO-optimized product content. Focus on identifying key visual details such as type, color, material, and purpose to create accurate, natural, and persuasive descriptions suitable for e-commerce platforms.', 'hippoo' );
}

/** Return default description prompt. */
function hippoo_ai_get_default_description_prompt() {
    return __( 'Review the provided product image and its details to understand the item’s appearance, features, and intended use. Then generate a compelling product title, short summary, detailed description, and a list of relevant SEO keywords based on your analysis.', 'hippoo' );
}


// ---------------------------------------------------------------------------
// Product description generation
// ---------------------------------------------------------------------------

/** Generate a product description from supplied images. */
function hippoo_ai_generate_description( $params = [] ) {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $provider = $settings['ai_provider'] ?? 'gpt';
    $api_token = $settings['api_token'] ?? '';
    $model = $settings['ai_model'] ?? '';
    $system_prompt = $settings['system_prompt'] ?? hippoo_ai_get_default_system_prompt();
    $description_prompt = $settings['description_prompt'] ?? hippoo_ai_get_default_description_prompt();

    if ( empty( $api_token ) ) {
        return new WP_Error( 'missing_token', __( 'You haven’t provided an API key. Please go to the Hippoo settings page and add your API key to connect to the AI.', 'hippoo' ), array( 'status' => 400 ) );
    }

    $files = isset( $_FILES['images'] ) ? $_FILES['images'] : null;
    $urls  = isset( $params['image_urls'] ) ? (array) $params['image_urls'] : array();

    $images = hippoo_ai_parse_input_images( $files, $urls );

    if ( is_wp_error( $images ) ) {
        return $images;
    }

    $optimized_images = array();
    foreach ( $images as $image_path ) {
        $optimized = hippoo_ai_optimize_image( $image_path );
        if ( is_wp_error( $optimized ) ) {
            hippoo_ai_cleanup_images( $optimized_images );
            return $optimized;
        }
        $optimized_images[] = $optimized;
    }

    $cache_key = hippoo_ai_generate_cache_key( $optimized_images, $model, $provider );

    $cached = get_transient( $cache_key );

    if ( $cached ) {
        hippoo_ai_cleanup_images( $optimized_images );
        return array_merge( $cached, array( 'cache_hit' => true ) );
    }

    $temperature = isset( $settings['temperature'] ) ? floatval( $settings['temperature'] ) : 1;
    $max_tokens  = isset( $settings['max_tokens'] ) ? intval( $settings['max_tokens'] ) : 800;

    $data = array(
        'api_token'         => $api_token,
        'model'             => $model,
        'system_prompt'     => $system_prompt,
        'description_prompt'=> $description_prompt,
        'images'            => $optimized_images,
        'temperature'       => $temperature,
        'max_tokens'        => $max_tokens,
    );

    $result = hippoo_ai_generate_with_provider( $provider, $data );

    if ( is_wp_error( $result ) ) {
        hippoo_ai_cleanup_images( $optimized_images );
        return $result;
    }

    set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

    hippoo_ai_cleanup_images( $optimized_images );

    return array_merge( $result, array( 'cache_hit' => false ) );
}

/** Generate AI response cache key. */
function hippoo_ai_generate_cache_key( $images, $model, $provider ) {
    $hash_input = $provider . $model;

    foreach ( $images as $path ) {
        $hash_input .= md5_file( $path );
    }

    return 'hippoo_ai_cache_' . md5( $hash_input );
}


// ---------------------------------------------------------------------------
// Images
// ---------------------------------------------------------------------------

/** Parse uploaded files and image URLs. */
function hippoo_ai_parse_input_images( $files, $urls ) {
    if ( ! function_exists( 'download_url' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $paths = array();

    // multipart file(s)
    if ( $files && isset( $files['tmp_name'] ) ) {
        if ( is_array( $files['tmp_name'] ) ) {
            foreach ( $files['tmp_name'] as $tmp ) {
                if ( ! is_uploaded_file( $tmp ) ) {
                    continue;
                }
                $paths[] = $tmp;
            }
        } elseif ( is_uploaded_file( $files['tmp_name'] ) ) {
            $paths[] = $files['tmp_name'];
        }
    }

    // image URLs
    foreach ( $urls as $url ) {
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            return new WP_Error( 'download_failed', __( 'Failed to download image: ', 'hippoo' ) . $url . ', ' . $tmp->get_error_message(), array( 'status' => 400 ) );
        }
        $paths[] = $tmp;
    }

    if ( empty( $paths ) ) {
        return new WP_Error( 'no_image', __( 'No valid image provided.', 'hippoo' ), array( 'status' => 400 ) );
    }

    return $paths;
}

/** Optimize an image for AI processing. */
function hippoo_ai_optimize_image( $path ) {
    $info = getimagesize( $path );

    if ( ! $info ) {
        return new WP_Error( 'invalid_image', __( 'Invalid image file.', 'hippoo' ), array( 'status' => 400 ) );
    }

    $mime = $info['mime'];
    $allowed_mime = array(
        'image/jpeg',
        'image/png',
        'image/webp',
    );

    if ( ! in_array( $mime, $allowed_mime, true ) ) {
        return new WP_Error( 'unsupported_format', __( 'Supported formats: jpg, png, webp', 'hippoo' ), array( 'status' => 400 ) );
    }

    $editor = wp_get_image_editor( $path );

    if ( is_wp_error( $editor ) ) {
        return new WP_Error( 'image_editor_error', __( 'Failed to open image editor.', 'hippoo' ) );
    }

    $size = $editor->get_size();
    if ( $size['width'] > 2048 || $size['height'] > 2048 ) {
        $editor->resize( 2048, 2048, false );
    }
    $editor->set_quality( 85 );

    $upload_dir = wp_upload_dir();
    $dest = trailingslashit( $upload_dir['basedir'] ) . 'hippoo-cache-' . md5( $path ) . '.jpg';
    $saved = $editor->save( $dest );

    if ( is_wp_error( $saved ) ) {
        return new WP_Error( 'save_failed', __( 'Could not save optimized image.', 'hippoo' ) );
    }

    if ( filesize( $dest ) > 2 * 1024 * 1024 ) {
        return new WP_Error( 'too_large', __( 'Optimized image exceeds 2MB.', 'hippoo' ), array( 'status' => 413 ) );
    }

    return $dest;
}

/** Remove temporary image files. */
function hippoo_ai_cleanup_images( $images ) {
    foreach ( $images as $image ) {
        if ( file_exists( $image ) ) {
            wp_delete_file( $image );
        }
    }
}
