<?php

namespace AgentOS\Core;

class AgentRepository
{
    public function blank(): array
    {
        return [
            'label' => '',
            'slug' => '',
            'default_mode' => 'voice',
            'default_model' => Config::FALLBACK_MODEL,
            'default_voice' => Config::FALLBACK_VOICE,
            'base_prompt' => '',
            'post_types' => [],
            'field_maps' => [],
            'show_transcript' => true,
        ];
    }

    public function all(): array
    {
        $agents = get_option(Config::OPTION_AGENTS, []);
        if (!is_array($agents)) {
            return [];
        }

        return array_map(function ($agent) {
            return wp_parse_args($agent, $this->blank());
        }, $agents);
    }

    public function get(string $slug): ?array
    {
        $agents = $this->all();
        return $agents[$slug] ?? null;
    }

    public function saveAll(array $agents): void
    {
        update_option(Config::OPTION_AGENTS, $agents);
    }

    public function delete(string $slug): void
    {
        $agents = $this->all();
        if (isset($agents[$slug])) {
            unset($agents[$slug]);
            $this->saveAll($agents);
        }
    }

    public function upsert(array $input, string $originalSlug = ''): string
    {
        $agents = $this->all();

        $requestedSlug = '';
        if (!empty($input['slug'])) {
            $requestedSlug = sanitize_key($input['slug']);
        }

        if (!$requestedSlug && !empty($input['label'])) {
            $requestedSlug = sanitize_key(sanitize_title($input['label']));
        }

        if (!$requestedSlug) {
            $requestedSlug = 'agent-' . wp_generate_password(6, false, false);
        }

        $slug = $this->ensureUniqueSlug($requestedSlug, $originalSlug, $agents);
        $agent = $this->sanitizeAgentInput($input, $slug);

        if ($originalSlug && $originalSlug !== $slug) {
            unset($agents[$originalSlug]);
        }

        $agents[$slug] = $agent;
        $this->saveAll($agents);

        return $slug;
    }

    private function ensureUniqueSlug(string $slug, string $original, array $agents): string
    {
        $base = $slug;
        $i = 1;
        while (isset($agents[$slug]) && $slug !== $original) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function sanitizeAgentInput(array $input, string $slug): array
    {
        $agent = $this->blank();

        $agent['label'] = sanitize_text_field($input['label'] ?? '');
        $agent['slug'] = $slug;

        $agent['default_model'] = sanitize_text_field($input['default_model'] ?? Config::FALLBACK_MODEL);
        if (!$agent['default_model']) {
            $agent['default_model'] = Config::FALLBACK_MODEL;
        }

        $agent['default_voice'] = sanitize_text_field($input['default_voice'] ?? Config::FALLBACK_VOICE);
        if (!$agent['default_voice']) {
            $agent['default_voice'] = Config::FALLBACK_VOICE;
        }

        $mode = $input['default_mode'] ?? 'voice';
        $agent['default_mode'] = in_array($mode, ['voice', 'text', 'both'], true) ? $mode : 'voice';

        $agent['base_prompt'] = sanitize_textarea_field($input['base_prompt'] ?? '');

        $agent['show_transcript'] = !empty($input['show_transcript']);

        $publicPostTypes = get_post_types(['public' => true], 'names');
        $requested = array_map('sanitize_text_field', (array) ($input['post_types'] ?? []));
        $agent['post_types'] = array_values(array_intersect($requested, $publicPostTypes));

        $maps = [];
        if (!empty($input['field_maps']) && is_array($input['field_maps'])) {
            foreach ($input['field_maps'] as $postType => $map) {
                $postTypeKey = sanitize_text_field($postType);
                if (!in_array($postTypeKey, $publicPostTypes, true)) {
                    continue;
                }
                $maps[$postTypeKey] = [
                    'model' => sanitize_text_field($map['model'] ?? ''),
                    'voice' => sanitize_text_field($map['voice'] ?? ''),
                    'system_prompt' => sanitize_text_field($map['system_prompt'] ?? ''),
                    'user_prompt' => sanitize_text_field($map['user_prompt'] ?? ''),
                ];
            }
        }

        $agent['field_maps'] = $maps;

        return $agent;
    }

    public function collectPostConfig(array $agent, int $postId): array
    {
        $postType = get_post_type($postId);
        $map = $agent['field_maps'][$postType] ?? [];

        $model = $this->getFieldValue($postId, $map['model'] ?? '') ?: ($agent['default_model'] ?: Config::FALLBACK_MODEL);
        $voice = $this->getFieldValue($postId, $map['voice'] ?? '') ?: ($agent['default_voice'] ?: Config::FALLBACK_VOICE);

        $instructions = $this->getFieldValue($postId, $map['system_prompt'] ?? '');
        if (!$instructions) {
            $instructions = $agent['base_prompt'] ?: Config::FALLBACK_PROMPT;
        }

        $userPrompt = $this->getFieldValue($postId, $map['user_prompt'] ?? '');

        return [
            'model' => sanitize_text_field($model),
            'voice' => sanitize_text_field($voice),
            'instructions' => sanitize_textarea_field($instructions),
            'user_prompt' => sanitize_textarea_field($userPrompt),
            'mode' => $agent['default_mode'] ?? 'voice',
            'show_transcript' => !empty($agent['show_transcript']),
        ];
    }

    public function getFieldValue(int $postId, string $key): string
    {
        if (!$key) {
            return '';
        }

        if (function_exists('get_field')) {
            $value = get_field($key, $postId);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        $value = get_post_meta($postId, $key, true);
        return $value ? (string) $value : '';
    }
}
