<?php
/**
 * Hippoo AI – settings tab and AJAX callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Settings tab
// ---------------------------------------------------------------------------

/** Add the AI tab to Hippoo settings. */
add_filter( 'hippoo_settings_tabs', 'hippoo_ai_add_settings_tab' );

function hippoo_ai_add_settings_tab( $tabs ) {
    $tabs['ai'] = array(
        'label'    => esc_html__( 'Hippoo AI', 'hippoo' ),
        'priority' => 20,
    );
    return $tabs;
}

/** Render the AI settings tab. */
add_filter( 'hippoo_settings_tab_contents', 'hippoo_ai_add_settings_tab_content' );

function hippoo_ai_add_settings_tab_content( $contents ) {
    $contents['ai'] = function() {
        $license_status = hippoo_check_user_license();
        ob_start();
        ?>
        <div class="hippoo-ai-tab <?php echo ( 'basic' === $license_status ) ? 'is-locked' : ''; ?>">
            <form action="options.php" method="post">
                <?php
                settings_fields( 'hippoo_ai_settings' );
                do_settings_sections( 'hippoo_ai_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    };
    return $contents;
}


// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/** Register AI settings section and fields. */
add_action( 'admin_init', 'hippoo_ai_register_settings' );

function hippoo_ai_register_settings() {
    register_setting(
        'hippoo_ai_settings',
        'hippoo_ai_settings',
        array(
            'sanitize_callback' => 'hippoo_ai_sanitize_settings',
        )
    );

    add_settings_section(
        'hippoo_ai_connect_section',
        null,
        'hippoo_ai_section_connect',
        'hippoo_ai_settings'
    );

    add_settings_field(
        'ai_provider',
        __( 'Select AI Provider', 'hippoo' ),
        'hippoo_ai_field_provider',
        'hippoo_ai_settings',
        'hippoo_ai_connect_section'
    );

    add_settings_field(
        'ai_model',
        __( 'Select model', 'hippoo' ),
        'hippoo_ai_field_model',
        'hippoo_ai_settings',
        'hippoo_ai_connect_section'
    );

    add_settings_field(
        'api_token',
        __( 'Your API Token', 'hippoo' ),
        'hippoo_ai_field_api_token',
        'hippoo_ai_settings',
        'hippoo_ai_connect_section',
        array(
            'class' => 'inline-row',
        )
    );

    add_settings_section(
        'hippoo_ai_product_reader_section',
        null,
        'hippoo_ai_section_product_reader',
        'hippoo_ai_settings'
    );

    add_settings_field(
        'system_prompt',
        __( 'System Prompt', 'hippoo' ),
        'hippoo_ai_field_system_prompt',
        'hippoo_ai_settings',
        'hippoo_ai_product_reader_section',
        array(
            'class' => 'inline-row',
        )
    );

    add_settings_field(
        'description_prompt',
        __( 'Description Prompt', 'hippoo' ),
        'hippoo_ai_field_description_prompt',
        'hippoo_ai_settings',
        'hippoo_ai_product_reader_section',
        array(
            'class' => 'inline-row',
        )
    );

    add_settings_field(
        'max_tokens',
        __( 'Max Tokens', 'hippoo' ),
        'hippoo_ai_field_max_tokens',
        'hippoo_ai_settings',
        'hippoo_ai_product_reader_section'
    );
}


// ---------------------------------------------------------------------------
// Settings sanitization
// ---------------------------------------------------------------------------

/** Sanitize AI settings. */
function hippoo_ai_sanitize_settings( $new_settings ) {
    $old_settings = get_option( 'hippoo_ai_settings', array() );
    return array_merge( $old_settings, $new_settings );
}


// ---------------------------------------------------------------------------
// Settings sections
// ---------------------------------------------------------------------------

/** Render AI connection section. */
function hippoo_ai_section_connect() {
    ?>
    <h3 class="section-title"><?php esc_html_e( 'Connect the AI', 'hippoo' ); ?></h3>
    <p><?php esc_html_e( 'Generate product descriptions automatically from images using AI. Configure your preferred model and prompts in the settings below.', 'hippoo' ); ?></p>
    <?php
}

/** Render AI product reader section. */
function hippoo_ai_section_product_reader() {
    ?>
    <h3 class="section-title"><?php esc_html_e( 'AI Product Reader', 'hippoo' ); ?></h3>
    <p><?php esc_html_e( 'Generate product descriptions automatically from images using AI. Configure your preferred model and prompts in the settings below.', 'hippoo' ); ?></p>

    <h3 class="section-title"><?php esc_html_e( 'Prompt settings', 'hippoo' ); ?></h3>
    <p><?php esc_html_e( 'Customize how the AI generates text by editing the prompts below. Each prompt guides the AI to produce the desired type of content for your products. You can adjust these to change tone, style, or structure of the generated descriptions.', 'hippoo' ); ?></p>
    <?php
}


// ---------------------------------------------------------------------------
// Settings fields
// ---------------------------------------------------------------------------

/** Render AI provider field. */
function hippoo_ai_field_provider() {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $value    = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'gpt';
    ?>
    <select class="select" name="hippoo_ai_settings[ai_provider]">
        <option value="gpt" <?php selected( $value, 'gpt' ); ?>>
            <?php esc_html_e( 'Chat GPT', 'hippoo' ); ?>
        </option>

        <option value="gemini" <?php selected( $value, 'gemini' ); ?>>
            <?php esc_html_e( 'Gemini', 'hippoo' ); ?>
        </option>
    </select>
    <?php
}

/** Render AI model field. */
function hippoo_ai_field_model() {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $value    = isset( $settings['ai_model'] ) ? $settings['ai_model'] : 'gpt-4o';
    $provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'gpt';

    if ( 'gpt' === $provider ) {
        $models = hippoo_ai_get_gpt_models();
    } elseif ( 'gemini' === $provider ) {
        $models = hippoo_ai_get_gemini_models();
    } else {
        $models = array();
    }
    ?>
    <select class="select" name="hippoo_ai_settings[ai_model]">
        <?php foreach ( $models as $model ) : ?>
            <option value="<?php echo esc_attr( $model ); ?>" <?php selected( $value, $model ); ?>>
                <?php echo esc_html( $model ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

/** Render API token field. */
function hippoo_ai_field_api_token() {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $value    = isset( $settings['api_token'] ) ? $settings['api_token'] : '';
    ?>
    <div class="input-group">
        <input
            type="password"
            class="input"
            name="hippoo_ai_settings[api_token]"
            value="<?php echo esc_attr( $value ); ?>"
            placeholder="<?php esc_attr_e( 'Enter your API key', 'hippoo' ); ?>"
        >

        <a
            href="https://hippoo.app/how-can-i-provide-the-token-for-hippoo-ai/"
            target="_blank"
            class="field-hint"
        >
            <?php esc_html_e( 'How can I provide the token?', 'hippoo' ); ?>
        </a>
    </div>

    <button
        type="button"
        class="button button-outline test-button"
        id="test-ai-connection"
    >
        <?php esc_html_e( 'Test connection', 'hippoo' ); ?>
    </button>
    <?php
}

/** Render system prompt field. */
function hippoo_ai_field_system_prompt() {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $value    = isset( $settings['system_prompt'] ) ? $settings['system_prompt'] : hippoo_ai_get_default_system_prompt();
    ?>
    <div class="input-group">
        <textarea
            class="textarea"
            name="hippoo_ai_settings[system_prompt]"
        ><?php echo esc_textarea( $value ); ?></textarea>

        <p class="input-hint">
            <?php esc_html_e( 'Sets the general behavior or role of the AI (e.g., “You are a product description writer for an online store.)', 'hippoo' ); ?>
        </p>
    </div>
    <?php
}

/** Render description prompt field. */
function hippoo_ai_field_description_prompt() {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $value    = isset( $settings['description_prompt'] ) ? $settings['description_prompt'] : hippoo_ai_get_default_description_prompt();
    ?>
    <div class="input-group">
        <textarea
            class="textarea"
            name="hippoo_ai_settings[description_prompt]"
        ><?php echo esc_textarea( $value ); ?></textarea>

        <p class="input-hint">
            <?php esc_html_e( 'Defines how the main product description should be written (e.g., length, tone, structure)', 'hippoo' ); ?>
        </p>
    </div>
    <?php
}

/** Render max tokens field. */
function hippoo_ai_field_max_tokens() {
    $settings = get_option( 'hippoo_ai_settings', array() );
    $value    = isset( $settings['max_tokens'] ) ? intval( $settings['max_tokens'] ) : 800;
    ?>
    <div class="input-group">
        <input
            type="number"
            class="input"
            name="hippoo_ai_settings[max_tokens]"
            value="<?php echo esc_attr( $value ); ?>"
            min="1"
        >
    </div>
    <?php
}


// ---------------------------------------------------------------------------
// AJAX
// ---------------------------------------------------------------------------

/** Test AI connection from admin. */
add_action( 'wp_ajax_hippoo_test_ai_connection', 'hippoo_ai_ajax_test_connection' );

function hippoo_ai_ajax_test_connection() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'hippoo' ), 403 );
    }

    $api_token = sanitize_text_field( wp_unslash( $_POST['api_token'] ?? '' ) );
    $provider = sanitize_text_field( wp_unslash( $_POST['ai_provider'] ?? '' ) );

    if ( empty( $api_token ) ) {
        wp_send_json_error( __( 'You haven’t provided an API key. Please go to the Hippoo settings page and add your API key to connect to the AI.', 'hippoo' ) );
    }

    $result = hippoo_ai_test_provider_connection( $provider, $api_token );

    if ( true === $result ) {
        wp_send_json_success();
    }

    wp_send_json_error( __( 'Failed to connect to AI service.', 'hippoo' ) );
}

/** Return models for selected provider. */
add_action( 'wp_ajax_hippoo_get_models_by_provider', 'hippoo_ai_ajax_get_models' );

function hippoo_ai_ajax_get_models() {
    check_ajax_referer( 'hippoo_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'hippoo' ), 403 );
    }

    $provider = sanitize_text_field( wp_unslash( $_POST['ai_provider'] ?? '' ) );

    $models = hippoo_ai_get_models( $provider );

    if ( is_wp_error( $models ) ) {
        wp_send_json_error( $models->get_error_message() );
    }

    wp_send_json_success( $models );
}
