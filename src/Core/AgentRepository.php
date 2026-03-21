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
            'default_model' => Config::FALLBACK_REALTIME_MODEL,
            'realtime_model' => Config::FALLBACK_REALTIME_MODEL,
            'text_model' => Config::FALLBACK_TEXT_MODEL,
            'default_voice' => Config::FALLBACK_VOICE,
            'base_prompt' => '',
            'voice_turn_detection' => '',
            'voice_turn_eagerness' => '',
            'voice_turn_profile' => '',
            'voice_noise_reduction' => '',
            'speech_language_hint' => '',
            'transcription_hint' => '',
            'context_params' => [],
            'show_post_image' => false,
            'show_post_title' => false,
            'sidebar_back_url' => '',
            'sidebar_back_label' => '',
            'post_types' => [],
            'field_maps' => [],
            'show_transcript' => true,
            'analysis_enabled' => false,
            'analysis_model' => '',
            'analysis_system_prompt' => '',
            'analysis_auto_run' => false,
            'require_subscription' => false,
            'session_token_cap' => 0,
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

        $realtimeModel = sanitize_text_field($input['realtime_model'] ?? ($input['default_model'] ?? Config::FALLBACK_REALTIME_MODEL));
        if (!$realtimeModel) {
            $realtimeModel = Config::FALLBACK_REALTIME_MODEL;
        }
        $agent['realtime_model'] = $realtimeModel;
        $agent['default_model'] = $realtimeModel;

        $agent['text_model'] = sanitize_text_field($input['text_model'] ?? Config::FALLBACK_TEXT_MODEL);
        if (!$agent['text_model']) {
            $agent['text_model'] = Config::FALLBACK_TEXT_MODEL;
        }

        $agent['default_voice'] = sanitize_text_field($input['default_voice'] ?? Config::FALLBACK_VOICE);
        if (!$agent['default_voice']) {
            $agent['default_voice'] = Config::FALLBACK_VOICE;
        }

        $mode = $input['default_mode'] ?? 'voice';
        $agent['default_mode'] = in_array($mode, ['voice', 'text', 'both'], true) ? $mode : 'voice';

        $agent['base_prompt'] = sanitize_textarea_field($input['base_prompt'] ?? '');
        $turnDetection = sanitize_key($input['voice_turn_detection'] ?? '');
        $agent['voice_turn_detection'] = in_array($turnDetection, ['', 'semantic_vad', 'server_vad'], true) ? $turnDetection : '';
        $turnEagerness = sanitize_key($input['voice_turn_eagerness'] ?? '');
        $agent['voice_turn_eagerness'] = in_array($turnEagerness, ['', 'low', 'medium', 'high'], true) ? $turnEagerness : '';
        $turnProfile = sanitize_key($input['voice_turn_profile'] ?? '');
        $agent['voice_turn_profile'] = in_array($turnProfile, ['', 'conservative', 'balanced', 'fast'], true) ? $turnProfile : '';
        $noiseReduction = sanitize_key($input['voice_noise_reduction'] ?? '');
        $agent['voice_noise_reduction'] = in_array($noiseReduction, ['', 'near_field', 'far_field', 'off'], true) ? $noiseReduction : '';
        $languageHint = sanitize_key($input['speech_language_hint'] ?? '');
        $agent['speech_language_hint'] = in_array($languageHint, ['', 'en', 'pt'], true) ? $languageHint : '';
        $agent['transcription_hint'] = sanitize_textarea_field($input['transcription_hint'] ?? '');

        $contextParams = $input['context_params'] ?? [];
        if (is_string($contextParams)) {
            $contextParams = array_map('trim', explode(',', $contextParams));
        }
        $contextParams = (array) $contextParams;
        $agent['context_params'] = array_values(array_filter(array_map(static function ($key) {
            return preg_replace('/[^a-z0-9_\-]/i', '', (string) $key);
        }, $contextParams)));

        $agent['show_post_image'] = !empty($input['show_post_image']);
        $agent['show_post_title'] = !empty($input['show_post_title']);
        $agent['sidebar_back_url'] = esc_url_raw($input['sidebar_back_url'] ?? '');
        $agent['sidebar_back_label'] = sanitize_text_field($input['sidebar_back_label'] ?? '');

        $agent['show_transcript'] = !empty($input['show_transcript']);

        $agent['analysis_enabled'] = !empty($input['analysis_enabled']);
        $agent['analysis_auto_run'] = !empty($input['analysis_auto_run']);
        $agent['analysis_model'] = sanitize_text_field($input['analysis_model'] ?? '');
        $agent['analysis_system_prompt'] = sanitize_textarea_field($input['analysis_system_prompt'] ?? '');

        $agent['require_subscription'] = !empty($input['require_subscription']);
        $agent['session_token_cap'] = isset($input['session_token_cap']) ? intval($input['session_token_cap']) : 0;
        if ($agent['session_token_cap'] < 0) {
            $agent['session_token_cap'] = 0;
        }

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

        $model = $this->getFieldValue($postId, $map['model'] ?? '') ?: (($agent['realtime_model'] ?? $agent['default_model'] ?? '') ?: Config::FALLBACK_REALTIME_MODEL);
        $voice = $this->getFieldValue($postId, $map['voice'] ?? '') ?: ($agent['default_voice'] ?: Config::FALLBACK_VOICE);

        $instructions = $this->getFieldValue($postId, $map['system_prompt'] ?? '');
        if (!$instructions) {
            $instructions = $agent['base_prompt'] ?: Config::FALLBACK_PROMPT;
        }

        $userPrompt = $this->getFieldValue($postId, $map['user_prompt'] ?? '');

        return [
            'model' => sanitize_text_field($model),
            'realtime_model' => sanitize_text_field($model),
            'text_model' => sanitize_text_field($agent['text_model'] ?? Config::FALLBACK_TEXT_MODEL),
            'voice' => sanitize_text_field($voice),
            'instructions' => sanitize_textarea_field($instructions),
            'user_prompt' => sanitize_textarea_field($userPrompt),
            'voice_turn_detection' => sanitize_key($agent['voice_turn_detection'] ?? ''),
            'voice_turn_eagerness' => sanitize_key($agent['voice_turn_eagerness'] ?? ''),
            'voice_turn_profile' => sanitize_key($agent['voice_turn_profile'] ?? ''),
            'voice_noise_reduction' => sanitize_key($agent['voice_noise_reduction'] ?? ''),
            'speech_language_hint' => sanitize_key($agent['speech_language_hint'] ?? ''),
            'transcription_hint' => sanitize_textarea_field($agent['transcription_hint'] ?? ''),
            'mode' => $agent['default_mode'] ?? 'voice',
            'show_transcript' => !empty($agent['show_transcript']),
            'analysis_enabled' => !empty($agent['analysis_enabled']),
            'analysis_model' => sanitize_text_field($agent['analysis_model'] ?? ''),
            'analysis_system_prompt' => sanitize_textarea_field($agent['analysis_system_prompt'] ?? ''),
            'analysis_auto_run' => !empty($agent['analysis_auto_run']),
            'require_subscription' => !empty($agent['require_subscription']),
            'session_token_cap' => isset($agent['session_token_cap']) ? (int) $agent['session_token_cap'] : 0,
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
