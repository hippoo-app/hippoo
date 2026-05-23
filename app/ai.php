<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HippooAI
{
    public $namespace = 'hippoo-ai/v1';

    const GPT_MODELS = ['gpt-5', 'gpt-4', 'gpt-4o', 'gpt-4o-mini'];
    const GEMINI_MODELS = ['gemini-2.5-flash', 'gemini-2.5-pro'];

    public function __construct()
    {
        add_filter('hippoo_settings_tabs', array($this, 'add_settings_tab'));
        add_filter('hippoo_settings_tab_contents', array($this, 'add_settings_tab_content'));
        add_action('admin_init', array($this, 'settings_init'));

        add_action('wp_ajax_hippoo_test_ai_connection', array($this, 'ajax_do_test_connection')); 
        add_action('wp_ajax_hippoo_get_models_by_provider', array($this, 'ajax_get_models_by_provider'));

        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_filter('woocommerce_rest_is_request_to_rest_api', array($this, 'rest_use_wc_authentication'));
    }

    public function add_settings_tab($tabs)
    {
        $tabs['ai'] = [
            'label'    => esc_html__('Hippoo AI', 'hippoo'),
            'priority' => 20,
        ];
        return $tabs;
    }

    public function add_settings_tab_content($contents)
    {
        $contents['ai'] = function() {
            $license_status = hippoo_check_user_license();
            ob_start();
            ?>
            <div class="hippoo-ai-tab <?php echo ($license_status === 'basic') ? 'is-locked' : ''; ?>">
                <form action="options.php" method="post">
                    <?php
                    settings_fields('hippoo_ai_settings');
                    do_settings_sections('hippoo_ai_settings');
                    submit_button();
                    ?>
                </form>
            </div>
            <?php
            return ob_get_clean();
        };
        return $contents;
    }

    public function settings_init()
    {
        register_setting('hippoo_ai_settings', 'hippoo_ai_settings', [
            'sanitize_callback' => [$this, 'sanitize_ai_settings']
        ]);

        add_settings_section(
            'hippoo_ai_connect_section',
            null,
            array($this, 'section_ai_connect_render'),
            'hippoo_ai_settings'
        );

        add_settings_field(
            'ai_provider',
            __('Select AI Provider', 'hippoo'),
            array($this, 'field_ai_provider_render'),
            'hippoo_ai_settings',
            'hippoo_ai_connect_section'
        );

        add_settings_field(
            'ai_model',
            __('Select model', 'hippoo'),
            array($this, 'field_ai_model_render'),
            'hippoo_ai_settings',
            'hippoo_ai_connect_section'
        );

        add_settings_field(
            'api_token',
            __('Your API Token', 'hippoo'),
            array($this, 'field_api_token_render'),
            'hippoo_ai_settings',
            'hippoo_ai_connect_section',
            array('class' => 'inline-row')
        );

        add_settings_section(
            'hippoo_ai_product_reader_section',
            null,
            array($this, 'section_ai_product_reader_render'),
            'hippoo_ai_settings'
        );

        add_settings_field(
            'system_prompt',
            __('System Prompt', 'hippoo'),
            array($this, 'field_system_prompt_render'),
            'hippoo_ai_settings',
            'hippoo_ai_product_reader_section',
            array('class' => 'inline-row')
        );

        add_settings_field(
            'description_prompt',
            __('Description Prompt', 'hippoo'),
            array($this, 'field_description_prompt_render'),
            'hippoo_ai_settings',
            'hippoo_ai_product_reader_section',
            array('class' => 'inline-row')
        );

        add_settings_field(
            'max_tokens',
            __('Max Tokens', 'hippoo'),
            array($this, 'field_max_tokens_render'),
            'hippoo_ai_settings',
            'hippoo_ai_product_reader_section'
        );
    }

    public function sanitize_ai_settings($new_settings)
    {
        $old_settings = get_option('hippoo_ai_settings', []);
        $merged = array_merge($old_settings, $new_settings);
        return $merged;
    }

    public function section_ai_connect_render()
    {
        ?>
        <h3 class="section-title"><?php esc_html_e('Connect the AI', 'hippoo'); ?></h2>
        <p><?php esc_html_e('Generate product descriptions automatically from images using AI. Configure your preferred model and prompts in the settings below.', 'hippoo'); ?></p>
        <?php
    }

    public function field_ai_provider_render()
    {
        $settings = get_option('hippoo_ai_settings', []);
        $value = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'gpt';
        ?>
        <select class="select" name="hippoo_ai_settings[ai_provider]">
            <option value="gpt" <?php selected($value, 'gpt'); ?>><?php esc_html_e('Chat GPT', 'hippoo'); ?></option>
            <option value="gemini" <?php selected($value, 'gemini'); ?>><?php esc_html_e('Gemini', 'hippoo'); ?></option>
        </select>
        <?php
    }

    public function field_ai_model_render()
    {
        $settings = get_option('hippoo_ai_settings', []);
        $value = isset($settings['ai_model']) ? $settings['ai_model'] : 'gpt-4o';
        $provider = isset($settings['ai_provider']) ? $settings['ai_provider'] : 'gpt';
        
        if ($provider === 'gpt') {
            ?>
            <select class="select" name="hippoo_ai_settings[ai_model]">
                <?php foreach (self::GPT_MODELS as $model): ?>
                    <option value="<?php echo esc_attr($model); ?>" <?php selected($value, $model); ?>>
                        <?php echo esc_html($model); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php
        } elseif ($provider === 'gemini') {
            ?>
            <select class="select" name="hippoo_ai_settings[ai_model]">
                <?php foreach (self::GEMINI_MODELS as $model): ?>
                    <option value="<?php echo esc_attr($model); ?>" <?php selected($value, $model); ?>>
                        <?php echo esc_html($model); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php
        } else {
        }
    }

    public function field_api_token_render()
    {
        $settings = get_option('hippoo_ai_settings', []);
        $value = isset($settings['api_token']) ? $settings['api_token'] : '';
        ?>
        <div class="input-group">
            <input type="password" class="input" name="hippoo_ai_settings[api_token]" value="<?php echo esc_attr($value); ?>" placeholder="<?php esc_html_e('Enter your API key', 'hippoo'); ?>">
            <a href="https://hippoo.app/how-can-i-provide-the-token-for-hippoo-ai/" target="_blank" class="field-hint"><?php esc_html_e('How can I provide the token?', 'hippoo'); ?></a>
        </div>
        <button type="button" class="button button-outline test-button" id="test-ai-connection"><?php esc_html_e('Test connection', 'hippoo'); ?></button>
        <?php
    }

    public function section_ai_product_reader_render()
    {
        ?>
        <h3 class="section-title"><?php esc_html_e('AI Product Reader', 'hippoo'); ?></h2>
        <p><?php esc_html_e('Generate product descriptions automatically from images using AI. Configure your preferred model and prompts in the settings below.', 'hippoo'); ?></p>
        <h3 class="section-title"><?php esc_html_e('Prompt settings', 'hippoo'); ?></h4>
        <p><?php esc_html_e('Customize how the AI generates text by editing the prompts below. Each prompt guides the AI to produce the desired type of content for your products. You can adjust these to change tone, style, or structure of the generated descriptions.', 'hippoo'); ?></p>
        <?php
    }

    public function field_system_prompt_render()
    {
        $settings = get_option('hippoo_ai_settings', []);
        $value = isset($settings['system_prompt']) ? $settings['system_prompt'] : self::get_default_system_prompt();
        ?>
        <div class="input-group">
            <textarea class="textarea" name="hippoo_ai_settings[system_prompt]"><?php echo esc_textarea($value); ?></textarea>
            <p class="input-hint"><?php esc_html_e('Sets the general behavior or role of the AI (e.g., “You are a product description writer for an online store.)', 'hippoo'); ?></p>
        </div>
        <?php
    }

    public function field_description_prompt_render()
    {
        $settings = get_option('hippoo_ai_settings', []);
        $value = isset($settings['description_prompt']) ? $settings['description_prompt'] : self::get_default_description_prompt();
        ?>
        <div class="input-group">
            <textarea class="textarea" name="hippoo_ai_settings[description_prompt]"><?php echo esc_textarea($value); ?></textarea>
            <p class="input-hint"><?php esc_html_e('Defines how the main product description should be written (e.g., length, tone, structure)', 'hippoo'); ?></p>
        </div>
        <?php
    }

    public function field_max_tokens_render()
    {
        $settings = get_option('hippoo_ai_settings', []);
        $value = isset($settings['max_tokens']) ? intval($settings['max_tokens']) : 800;
        ?>
        <div class="input-group">
            <input type="number" class="input" name="hippoo_ai_settings[max_tokens]" value="<?php echo esc_attr($value); ?>" min="1">
        </div>
        <?php
    }

    public function ajax_do_test_connection()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');
        
        $api_token = sanitize_text_field($_POST['api_token']);
        $provider = sanitize_text_field($_POST['ai_provider']);
        
        if (empty($api_token)) {
            wp_send_json_error(__('You haven’t provided an API key. Please go to the Hippoo settings page and add your API key to connect to the AI.', 'hippoo'));
        }
        
        if ($provider === 'gpt') {
            $result = $this->openai_test_connection($api_token);
        } elseif ($provider === 'gemini') {
            $result = $this->gemini_test_connection($api_token);
        } else {
            wp_send_json_error(__('Unsupported AI provider.', 'hippoo'));
        }
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error(__('Failed to connect to AI service.', 'hippoo'));
        }
    }

    public function ajax_get_models_by_provider()
    {
        check_ajax_referer('hippoo_nonce', 'nonce');

        $provider = sanitize_text_field($_POST['ai_provider']);

        if ($provider === 'gpt') {
            wp_send_json_success(self::GPT_MODELS);
        } elseif ($provider === 'gemini') {
            wp_send_json_success(self::GEMINI_MODELS);
        } else {
            wp_send_json_error(__('Invalid provider', 'hippoo'));
        }
    }

    public function register_rest_routes()
    {
        register_rest_route($this->namespace, '/generate-description', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_generate_description'),
            'permission_callback' => array($this, 'rest_permission_check'),
        ));
        
        register_rest_route($this->namespace, '/test-connection', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_test_connection'),
            'permission_callback' => array($this, 'rest_permission_check'),
        ));

        register_rest_route($this->namespace, '/models', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_get_models'),
            'permission_callback' => array($this, 'rest_permission_check'),
        ));

        register_rest_route($this->namespace, '/prompts', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'rest_get_prompts'),
                'permission_callback' => array($this, 'rest_permission_check'),
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array($this, 'rest_update_prompts'),
                'permission_callback' => array($this, 'rest_permission_check'),
            ),
        ));

        register_rest_route($this->namespace, '/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'rest_get_settings'),
                'permission_callback' => array($this, 'rest_permission_check'),
            ),
            array(
                'methods'             => 'PATCH',
                'callback'            => array($this, 'rest_update_settings'),
                'permission_callback' => array($this, 'rest_permission_check'),
            ),
        ));
    }

    public function rest_use_wc_authentication($condition)
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $rest_prefix = trailingslashit(rest_get_url_prefix());
        $request_uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        
        // Allow the plugin use wc authentication methods.
        $hippoo = (false !== strpos($request_uri, $rest_prefix . $this->namespace));
        
        return $condition || $hippoo;
    }

    public function rest_permission_check($request)
    {
        return current_user_can('manage_options');
    }

    public function rest_generate_description($request)
    {
        if (!$this->check_rate_limit()) {
            return new WP_Error('rate_limited', __('Too many requests. Please try again later.', 'hippoo'), ['status' => 429]);
        }

        $settings = get_option('hippoo_ai_settings', []);
        $provider = $settings['ai_provider'] ?? 'gpt';
        $api_token = $settings['api_token'] ?? '';
        $model = $settings['ai_model'] ?? '';
        $system_prompt = $settings['system_prompt'] ?? self::get_default_system_prompt();
        $description_prompt = $settings['description_prompt'] ?? self::get_default_description_prompt();

        if (empty($api_token)) {
            return new WP_Error('missing_token', __('You haven’t provided an API key. Please go to the Hippoo settings page and add your API key to connect to the AI.', 'hippoo'), ['status' => 400]);
        }

        $params = $request->get_json_params();
        $files = isset($_FILES['images']) ? $_FILES['images'] : null;
        $urls = isset($params['image_urls']) ? (array)$params['image_urls'] : [];

        $images = $this->parse_input_images($files, $urls);
        if (is_wp_error($images)) {
            return $images;
        }

        $optimized_images = [];
        foreach ($images as $image_path) {
            $optimized = $this->optimize_image($image_path);
            if (is_wp_error($optimized)) {
                return $optimized;
            }
            $optimized_images[] = $optimized;
        }

        $cache_key = $this->generate_cache_key($optimized_images, $model, $provider);
        $cached = get_transient($cache_key);
        if ($cached) {
            return rest_ensure_response(array_merge($cached, ['cache_hit' => true]));
        }

        $data = [
            'api_token'      => $api_token,
            'model'          => $model,
            'system_prompt'  => $system_prompt,
            'description_prompt' => $description_prompt,
            'images'         => $optimized_images,
            'temperature'    => isset($settings['temperature']) ? floatval($settings['temperature']) : 1,
            'max_tokens'     => isset($settings['max_tokens']) ? intval($settings['max_tokens']) : 800,
        ];

        switch ($provider) {
            case 'gpt':
                $result = $this->openai_generate_description($data);
                break;

            case 'gemini':
                $result = $this->gemini_generate_description($data);
                break;
            
            default:
                return new WP_Error('unsupported_provider', __('Unsupported AI provider.', 'hippoo'), ['status' => 400]);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);

        foreach ($optimized_images as $img) {
            if (file_exists($img)) {
                wp_delete_file($img);
            }
        }

        return rest_ensure_response(array_merge($result, ['cache_hit' => false]));
    }

    public function rest_test_connection($request)
    {
        $settings = get_option('hippoo_ai_settings', []);
        $provider = $settings['ai_provider'] ?? 'gpt';
        $model = $settings['ai_model'] ?? '';
        $api_token = $settings['api_token'] ?? '';

        if (empty($api_token)) {
            return new WP_Error('missing_token', __('You haven’t provided an API key. Please go to the Hippoo settings page and add your API key to connect to the AI.', 'hippoo'), ['status' => 400]);
        }

        $start_time = microtime(true);
        switch ($provider) {
            case 'gpt':
                $result = $this->openai_test_connection($api_token);
                break;

            case 'gemini':
                $result = $this->gemini_test_connection($api_token);
                break;

            default:
                return new WP_Error('unsupported_provider', __('Unsupported AI provider.', 'hippoo'), ['status' => 400]);
        }
        $roundtrip_ms = (int)round((microtime(true) - $start_time) * 1000);

        if ($result) {
            return rest_ensure_response([
                'ok' => true,
                'roundtrip_ms' => $roundtrip_ms,
                'provider' => $provider,
                'model' => $model,
            ]);
        } else {
            return new WP_Error('connection_failed', __('Failed to connect to AI service.', 'hippoo'), ['status' => 500]);
        }
    }

    public function rest_get_models($request)
    {
        $settings = get_option('hippoo_ai_settings', []);
        $provider = $settings['ai_provider'] ?? 'gpt';

        switch ($provider) {
            case 'gpt':
                $result = self::GPT_MODELS;
                break;

            case 'gemini':
                $result = self::GEMINI_MODELS;
                break;

            default:
                return new WP_Error('unsupported_provider', __('Unsupported AI provider.', 'hippoo'), ['status' => 400]);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'provider' => $provider,
            'models' => $result,
        ]);
    }

    public function rest_get_prompts($request)
    {
        $settings = get_option('hippoo_ai_settings', []);
        return rest_ensure_response([
            'system' => $settings['system_prompt'] ?? '',
            'description' => $settings['description_prompt'] ?? '',
        ]);
    }

    public function rest_update_prompts($request)
    {
        $params = $request->get_json_params();
        $settings = get_option('hippoo_ai_settings', []);
        
        if (isset($params['system'])) {
            $settings['system_prompt'] = sanitize_textarea_field($params['system']);
        }
        if (isset($params['description'])) {
            $settings['description_prompt'] = sanitize_textarea_field($params['description']);
        }

        update_option('hippoo_ai_settings', $settings);
        return $this->rest_get_prompts($request);
    }

    public function rest_get_settings($request)
    {
        $settings = get_option('hippoo_ai_settings', []);
        
        $token_status = !empty($settings['api_token']);

        unset($settings['api_token']); // hide token
        unset($settings['system_prompt']);
        unset($settings['description_prompt']);

        $settings = array_map(function($value) {
            if ($value === 'true') return true;
            if ($value === 'false') return false;
            if (is_numeric($value)) {
                $float_val = floatval($value);
                if ($float_val != intval($float_val)) {
                    return floatval(number_format($float_val, 2, '.', ''));
                }
                return intval($value);
            }
            return $value;
        }, $settings);
        
        $settings['api_token_status'] = $token_status;

        return rest_ensure_response($settings);
    }

    public function rest_update_settings($request)
    {
        $params = $request->get_json_params();

        if (!is_array($params) || empty($params)) {
            return new WP_Error('invalid_params', __('Invalid parameters provided', 'hippoo'), ['status' => 400]);
        }

        $settings = get_option('hippoo_ai_settings', []);

        $sanitized_params = array_map(function($value) {
            if ($value === 'true') return true;
            if ($value === 'false') return false;
            if (is_numeric($value)) {
                $float_val = floatval($value);
                if ($float_val != intval($float_val)) {
                    return floatval(number_format($float_val, 2, '.', ''));
                }
                return intval($value);
            }
            if (is_string($value)) {
                return sanitize_text_field($value);
            }
            return $value;
        }, $params);

        $settings = array_merge($settings, $sanitized_params);
        update_option('hippoo_ai_settings', $settings);
        return $this->rest_get_settings($request);
    }

    public static function get_default_system_prompt() {
        return __( 
            'Analyze product images and related descriptions to generate high-quality, engaging, and SEO-optimized product content. Focus on identifying key visual details such as type, color, material, and purpose to create accurate, natural, and persuasive descriptions suitable for e-commerce platforms.',
            'hippoo'
        );
    }

    public static function get_default_description_prompt() {
        return __(
            'Review the provided product image and its details to understand the item’s appearance, features, and intended use. Then generate a compelling product title, short summary, detailed description, and a list of relevant SEO keywords based on your analysis.',
            'hippoo'
        );
    }

    private function parse_input_images($files, $urls)
    {
        if (!function_exists('download_url') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $paths = [];

        // multipart file(s)
        if ($files && isset($files['tmp_name'])) {
            if (is_array($files['tmp_name'])) {
                foreach ($files['tmp_name'] as $tmp) {
                    if (!is_uploaded_file($tmp)) continue;
                    $paths[] = $tmp;
                }
            } elseif (is_uploaded_file($files['tmp_name'])) {
                $paths[] = $files['tmp_name'];
            }
        }

        // image URLs
        foreach ($urls as $url) {
            $tmp = download_url($url);
            if (is_wp_error($tmp)) {
                return new WP_Error('download_failed', __('Failed to download image: ', 'hippoo') . $url . ', ' . $tmp->get_error_message(), ['status' => 400]);
            }
            $paths[] = $tmp;
        }

        if (empty($paths)) {
            return new WP_Error('no_image', __('No valid image provided.', 'hippoo'), ['status' => 400]);
        }

        return $paths;
    }

    private function optimize_image($path)
    {
        $info = getimagesize($path);
        if (!$info) {
            return new WP_Error('invalid_image', __('Invalid image file.', 'hippoo'), ['status' => 400]);
        }

        $mime = $info['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            return new WP_Error('unsupported_format', __('Supported formats: jpg, png, webp', 'hippoo'), ['status' => 400]);
        }

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return new WP_Error('image_editor_error', __('Failed to open image editor.', 'hippoo'));
        }

        $size = $editor->get_size();
        if ($size['width'] > 2048 || $size['height'] > 2048) {
            $editor->resize(2048, 2048, false);
        }
        $editor->set_quality(85);

        $upload_dir = wp_upload_dir();
        $dest = trailingslashit($upload_dir['basedir']) . 'hippo-cache-' . md5($path) . '.jpg';
        $saved = $editor->save($dest);

        if (is_wp_error($saved)) {
            return new WP_Error('save_failed', __('Could not save optimized image.', 'hippoo'));
        }

        if (filesize($dest) > 2 * 1024 * 1024) { // >2MB
            return new WP_Error('too_large', __('Optimized image exceeds 2MB.', 'hippoo'), ['status' => 413]);
        }

        return $dest;
    }

    private function generate_cache_key($images, $model, $provider)
    {
        $hash_input = $provider . $model;
        foreach ($images as $path) {
            $hash_input .= md5_file($path);
        }
        return 'hippoo_ai_cache_' . md5($hash_input);
    }

    private function check_rate_limit()
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        $cache_key = 'hippoo_ai_rate_limit_' . $ip;
        $requests = get_transient($cache_key) ?: 0;
        
        if ($requests >= 30) { // 30 requests per minute
            return false;
        }
        
        set_transient($cache_key, $requests + 1, MINUTE_IN_SECONDS);
        return true;
    }

    /* OpenAI */
    private function openai_generate_description($data)
    {
        $api_token = $data['api_token'] ?? '';
        $model = $data['model'] ?? '';
        $system_prompt = $data['system_prompt'] ?? '';
        $description_prompt = $data['description_prompt'] ?? '';
        $temperature = $data['temperature'] ?? 1;
        $max_tokens = $data['max_tokens'] ?? 800;

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $system_prompt . "\n\n" . __('You MUST only respond with a clean HTML block suitable for WordPress editor, no markdown, no backticks, no explanations.', 'hippoo'),
        ];

        $content_blocks = [];
        $content_blocks[] = [
            'type' => 'text',
            'text' => $description_prompt,
        ];

        foreach ($data['images'] as $img) {
            $mime = mime_content_type($img);
            $b64 = base64_encode(file_get_contents($img));
            $content_blocks[] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:$mime;base64,$b64"],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $content_blocks,
        ];

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        if ($this->uses_max_completion_tokens($model)) {
            $body['max_completion_tokens'] = $max_tokens;
        } else {
            $body['max_tokens'] = $max_tokens;
        }

        $url = 'https://api.openai.com/v1/chat/completions';
        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer $api_token",
            ],
            'body' => wp_json_encode($body),
            'timeout' => 120,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error('openai_request_failed', $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $data_response = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            $msg = $data_response['error']['message'] ?? __('Unexpected response from OpenAI.', 'hippoo');
            return new WP_Error('openai_error', $msg, ['status' => $status]);
        }

        if (!empty($data_response['choices'][0]['finish_reason']) && $data_response['choices'][0]['finish_reason'] === 'length') {
            return new WP_Error('max_tokens_exceeded', __('Max token limit reached for this prompt.', 'hippoo'));
        }

        $html = $data_response['choices'][0]['message']['content'] ?? '';
        $html = trim(preg_replace('/^```html|```$/m', '', $html));

        $html = str_replace(["\\n", "\\r"], ["\n", ""], $html);
        $html = str_replace(["\r", "\n"], "", $html);
        $html = trim($html);
        $html = wp_kses_post($html);

        if (!$html) {
            return new WP_Error('openai_empty_output', __('OpenAI did not return any text. Try increasing max_tokens.', 'hippoo'));
        }

        $usage = $data_response['usage'] ?? [];

        return [
            'html'     => $html,
            'provider' => 'gpt',
            'model'    => $model,
            'usage'    => $usage,
        ];
    }

    private function openai_test_connection($api_token)
    {
        $url = 'https://api.openai.com/v1/models';
        $args = [
            'headers' => ['Authorization' => 'Bearer ' . $api_token],
            'timeout' => 30,
        ];

        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code === 200;
    }

    private function uses_max_completion_tokens($model)
    {
        if (in_array($model, array('gpt-5'))){
            return true;
        }
        return false;
    }

    /* Gemini */
    private function gemini_generate_description($data)
    {
        $model = $data['model'] ?: 'gemini-2.5-flash';
        $key   = $data['api_token'];

        $image_parts = array_map(function ($img) {
            return [
                'inline_data' => [
                    'mime_type' => mime_content_type($img),
                    'data' => base64_encode(file_get_contents($img)),
                ],
            ];
        }, $data['images']);

        $body = [
            'contents' => [
                [
                    "role"  => "model",
                    "parts" => [
                        ["text" => $data['system_prompt'] . "\n\n" . __('You MUST only respond with a clean HTML block suitable for WordPress editor, no markdown, no backticks, no explanations.', 'hippoo')]
                    ]
                ],
                [
                    "role"  => "user",
                    "parts" => array_merge(
                        [
                            ["text" => $data['description_prompt']]
                        ],
                        $image_parts
                    )
                ]
            ],

            "generationConfig" => [
                "temperature"     => (float)$data['temperature'],
                "maxOutputTokens" => (int)$data['max_tokens']
            ]
        ];

        $url = "https://generativelanguage.googleapis.com/v1/models/$model:generateContent?key=$key";
        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
            'timeout' => 120,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error('gemini_request_failed', $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            $msg = $body['error']['message'] ?? __('Unexpected response from Gemini.', 'hippoo');
            return new WP_Error('gemini_error', $msg, ['status' => $status]);
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (!$text) {
            return new WP_Error('gemini_empty_output', __('Gemini did not return any text. Try increasing max_tokens.', 'hippoo'));
        }

        $html = wp_kses_post(trim($text));
        $usage = $body['usageMetadata'] ?? [];

        return [
            'html'     => $html,
            'provider' => 'gemini',
            'model'    => $model,
            'usage'    => $usage,
        ];
    }

    private function gemini_test_connection($api_token)
    {
        $url = "https://generativelanguage.googleapis.com/v1/models?key=$api_token&pageSize=1";
        $args = ['timeout' => 30];
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        return wp_remote_retrieve_response_code($response) === 200;
    }
}

new HippooAI();