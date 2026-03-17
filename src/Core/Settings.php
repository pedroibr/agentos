<?php

namespace AgentOS\Core;

class Settings
{
    public function register(): void
    {
        register_setting(
            Config::OPTION_SETTINGS,
            Config::OPTION_SETTINGS,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'default' => [
                    'api_key_source' => 'constant',
                    'api_key_manual' => '',
                    'context_params' => ['nome', 'produto', 'etapa'],
                    'enable_logging' => false,
                    'integration_api_enabled' => false,
                    'integration_api_key_hash' => '',
                    'integration_api_key_plain' => '',
                    'integration_api_key_prefix' => '',
                    'integration_api_key_generated_at' => '',
                ],
            ]
        );
    }

    /**
     * Sanitize incoming settings payload.
     */
    public function sanitize($input): array
    {
        $out = is_array($input) ? $input : [];
        $existing = $this->get();
        $shouldGenerateIntegrationKey = !empty($_POST['agentos_generate_integration_api_key']);

        $source = $out['api_key_source'] ?? 'constant';
        $out['api_key_source'] = in_array($source, ['env', 'constant', 'manual'], true) ? $source : 'constant';
        $out['api_key_manual'] = sanitize_text_field($out['api_key_manual'] ?? '');
        $out['enable_logging'] = !empty($out['enable_logging']) ? 1 : 0;
        $out['integration_api_enabled'] = !empty($out['integration_api_enabled']) ? 1 : 0;
        $out['integration_api_key_hash'] = sanitize_text_field($existing['integration_api_key_hash'] ?? '');
        $out['integration_api_key_plain'] = sanitize_text_field($existing['integration_api_key_plain'] ?? '');
        $out['integration_api_key_prefix'] = sanitize_text_field($existing['integration_api_key_prefix'] ?? '');
        $out['integration_api_key_generated_at'] = sanitize_text_field($existing['integration_api_key_generated_at'] ?? '');

        $ctx = $out['context_params'] ?? ['nome', 'produto', 'etapa'];
        if (is_string($ctx)) {
            $ctx = array_map('trim', explode(',', $ctx));
        }
        $ctx = (array) $ctx;
        $out['context_params'] = array_values(array_filter(array_map(static function ($key) {
            $sanitized = preg_replace('/[^a-z0-9_\-]/i', '', $key);
            return $sanitized;
        }, $ctx)));

        if ($shouldGenerateIntegrationKey) {
            $plain = 'agtos_' . wp_generate_password(40, false, false);
            $out['integration_api_key_hash'] = wp_hash_password($plain);
            $out['integration_api_key_plain'] = $plain;
            $out['integration_api_key_prefix'] = substr($plain, 0, 12);
            $out['integration_api_key_generated_at'] = current_time('mysql');
        }

        return $out;
    }

    public function get(): array
    {
        $raw = get_option(Config::OPTION_SETTINGS, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        return wp_parse_args(
            $raw,
            [
                'api_key_source' => 'constant',
                'api_key_manual' => '',
                'context_params' => ['nome', 'produto', 'etapa'],
                'enable_logging' => false,
                'integration_api_enabled' => false,
                'integration_api_key_hash' => '',
                'integration_api_key_plain' => '',
                'integration_api_key_prefix' => '',
                'integration_api_key_generated_at' => '',
            ]
        );
    }

    public function resolveApiKey(): string
    {
        $settings = $this->get();
        $source = $settings['api_key_source'] ?? 'constant';

        if ($source === 'env') {
            return getenv('OPENAI_API_KEY') ?: '';
        }

        if ($source === 'manual') {
            return $settings['api_key_manual'] ?? '';
        }

        return defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    }

    public function isIntegrationApiEnabled(): bool
    {
        $settings = $this->get();
        return !empty($settings['integration_api_enabled']);
    }

    public function verifyIntegrationApiKey(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $settings = $this->get();
        $hash = (string) ($settings['integration_api_key_hash'] ?? '');
        if ($hash === '') {
            return false;
        }

        return wp_check_password($token, $hash);
    }

    public function generateIntegrationApiKey(): string
    {
        $plain = 'agtos_' . wp_generate_password(40, false, false);
        $settings = $this->get();
        $settings['integration_api_key_hash'] = wp_hash_password($plain);
        $settings['integration_api_key_plain'] = $plain;
        $settings['integration_api_key_prefix'] = substr($plain, 0, 12);
        $settings['integration_api_key_generated_at'] = current_time('mysql');
        update_option(Config::OPTION_SETTINGS, $settings);

        return $plain;
    }
}
