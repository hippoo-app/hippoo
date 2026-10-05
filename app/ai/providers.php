<?php
/**
 * Hippoo AI – provider integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Provider dispatcher
// ---------------------------------------------------------------------------

/** Generate content through selected provider. */
function hippoo_ai_generate_with_provider( $provider, $data ) {
    switch ( $provider ) {
        case 'gpt':
            return hippoo_ai_openai_generate_description( $data );

        case 'gemini':
            return hippoo_ai_gemini_generate_description( $data );

        default:
            return new WP_Error( 'unsupported_provider', __( 'Unsupported AI provider.', 'hippoo' ), array( 'status' => 400 ) );
    }
}

/** Test connection to selected AI provider. */
function hippoo_ai_test_provider_connection( $provider, $api_token ) {
    switch ( $provider ) {
        case 'gpt':
            return hippoo_ai_openai_test_connection( $api_token );

        case 'gemini':
            return hippoo_ai_gemini_test_connection( $api_token );

        default:
            return new WP_Error( 'unsupported_provider', __( 'Unsupported AI provider.', 'hippoo' ), array( 'status' => 400 ) );
    }
}


// ---------------------------------------------------------------------------
// OpenAI
// ---------------------------------------------------------------------------

/** Generate product description through OpenAI. */
function hippoo_ai_openai_generate_description( $data ) {
    $api_token          = $data['api_token'] ?? '';
    $model              = $data['model'] ?? '';
    $system_prompt      = $data['system_prompt'] ?? '';
    $description_prompt = $data['description_prompt'] ?? '';
    $temperature        = $data['temperature'] ?? 1;
    $max_tokens         = $data['max_tokens'] ?? 800;

    $messages = array();
    $messages[] = array(
        'role'    => 'system',
        'content' => $system_prompt . "\n\n" . __( 'You MUST only respond with a clean HTML block suitable for WordPress editor, no markdown, no backticks, no explanations.', 'hippoo' ),
    );

    $content_blocks = array();
    $content_blocks[] = array(
        'type' => 'text',
        'text' => $description_prompt,
    );

    foreach ( $data['images'] as $img ) {
        $mime = mime_content_type( $img );
        $b64  = base64_encode( file_get_contents( $img ) );
        $content_blocks[] = array(
            'type'      => 'image_url',
            'image_url' => array(
                'url' => "data:$mime;base64,$b64",
            ),
        );
    }

    $messages[] = array(
        'role'    => 'user',
        'content' => $content_blocks,
    );

    $body = array(
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
    );

    if ( hippoo_ai_openai_uses_max_completion_tokens( $model ) ) {
        $body['max_completion_tokens'] = $max_tokens;
    } else {
        $body['max_tokens'] = $max_tokens;
    }

    $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_token,
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 120,
        )
    );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'openai_request_failed', $response->get_error_message() );
    }

    $status        = wp_remote_retrieve_response_code( $response );
    $data_response = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== $status ) {
        $msg = $data_response['error']['message'] ?? __( 'Unexpected response from OpenAI.', 'hippoo' );
        return new WP_Error( 'openai_error', $msg, array( 'status' => $status ) );
    }

    if (
        ! empty( $data_response['choices'][0]['finish_reason'] )
        && 'length' === $data_response['choices'][0]['finish_reason']
    ) {
        return new WP_Error( 'max_tokens_exceeded', __( 'Max token limit reached for this prompt.', 'hippoo' ) );
    }

    $html = $data_response['choices'][0]['message']['content'] ?? '';

    $html = trim( preg_replace( '/^```html|```$/m', '', $html ) );
    $html = str_replace( array( "\\n", "\\r", ), array( "\n", "", ), $html );
    $html = str_replace( array( "\r", "\n", ), '', $html );
    $html = trim( $html );
    $html = wp_kses_post( $html );

    if ( ! $html ) {
        return new WP_Error( 'openai_empty_output', __( 'OpenAI did not return any text. Try increasing max_tokens.', 'hippoo' ) );
    }

    $usage = $data_response['usage'] ?? array();

    return array(
        'html'     => $html,
        'provider' => 'gpt',
        'model'    => $model,
        'usage'    => $usage,
    );
}

/** Test OpenAI connection. */
function hippoo_ai_openai_test_connection( $api_token ) {
    $response = wp_remote_get(
        'https://api.openai.com/v1/models',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_token,
            ),
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    return 200 === wp_remote_retrieve_response_code( $response );
}

/** Determine whether OpenAI model uses max_completion_tokens. */
function hippoo_ai_openai_uses_max_completion_tokens( $model ) {
    $models = array(
        'gpt-5',
    );

    return in_array( $model, $models, true );
}


// ---------------------------------------------------------------------------
// Gemini
// ---------------------------------------------------------------------------

/** Generate product description through Gemini. */
function hippoo_ai_gemini_generate_description( $data ) {
    $model = $data['model'] ?? 'gemini-2.5-flash';
    $key = $data['api_token'];

    $image_parts = array_map( function( $img ) {
        return array(
            'inline_data' => array(
                'mime_type' => mime_content_type( $img ),
                'data'      => base64_encode( file_get_contents( $img ) ),
            ),
        );
    }, $data['images'] );

    $body = array(
        'contents' => array(
            array(
                'role'  => 'model',
                'parts' => array(
                    array( 'text' => $data['system_prompt'] . "\n\n" . __('You MUST only respond with a clean HTML block suitable for WordPress editor, no markdown, no backticks, no explanations.', 'hippoo'), ),
                ),
            ),
            array(
                'role'  => 'user',
                'parts' => array_merge(
                    array(
                        array( 'text' => $data['description_prompt'], ),
                    ),
                    $image_parts
                ),
            ),
        ),
        'generationConfig' => array(
            'temperature'     => (float) $data['temperature'],
            'maxOutputTokens' => (int) $data['max_tokens'],
        ),
    );

    $response = wp_remote_post(
        "https://generativelanguage.googleapis.com/v1/models/$model:generateContent?key=$key",
        array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 120,
        )
    );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'gemini_request_failed', $response->get_error_message() );
    }

    $status = wp_remote_retrieve_response_code( $response );
    $body   = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== $status ) {
        $msg = $body['error']['message'] ?? __( 'Unexpected response from Gemini.', 'hippoo' );
        return new WP_Error( 'gemini_error', $msg, array( 'status' => $status ) );
    }

    $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if ( ! $text ) {
        return new WP_Error( 'gemini_empty_output', __( 'Gemini did not return any text. Try increasing max_tokens.', 'hippoo' ) );
    }

    $html = wp_kses_post( trim( $text ) );
    $usage = $body['usageMetadata'] ?? array();

    return array(
        'html'     => $html,
        'provider' => 'gemini',
        'model'    => $model,
        'usage'    => $usage,
    );
}

/** Test Gemini connection. */
function hippoo_ai_gemini_test_connection( $api_token ) {
    $response = wp_remote_get(
        "https://generativelanguage.googleapis.com/v1/models?key=$api_token&pageSize=1",
        array(
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    return 200 === wp_remote_retrieve_response_code( $response );
}
