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

        $source = $out['api_key_source'] ?? 'constant';
        $out['api_key_source'] = in_array($source, ['env', 'constant', 'manual'], true) ? $source : 'constant';
        $out['api_key_manual'] = sanitize_text_field($out['api_key_manual'] ?? '');
        $out['enable_logging'] = !empty($out['enable_logging']) ? 1 : 0;

        $ctx = $out['context_params'] ?? ['nome', 'produto', 'etapa'];
        if (is_string($ctx)) {
            $ctx = array_map('trim', explode(',', $ctx));
        }
        $ctx = (array) $ctx;
        $out['context_params'] = array_values(array_filter(array_map(static function ($key) {
            $sanitized = preg_replace('/[^a-z0-9_\-]/i', '', $key);
            return $sanitized;
        }, $ctx)));

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
}
