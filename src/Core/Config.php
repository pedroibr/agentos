<?php

namespace AgentOS\Core;

class Config
{
    public const VERSION = '10.5.3';

    public const OPTION_SETTINGS = 'agentos_settings';
    public const OPTION_AGENTS   = 'agentos_agents';
    public const OPTION_SUBSCRIPTIONS = 'agentos_subscriptions';
    public const OPTION_USER_SUBSCRIPTIONS = 'agentos_user_subscriptions';
    public const OPTION_USER_SUBSCRIPTION_META = 'agentos_user_subscription_meta';

    public const FALLBACK_MODEL  = 'gpt-realtime-2.1-mini';
    public const FALLBACK_REALTIME_MODEL = self::FALLBACK_MODEL;
    public const FALLBACK_TEXT_MODEL = 'gpt-4.1-mini';
    public const FALLBACK_TRANSCRIBE_MODEL = 'gpt-4o-mini-transcribe';
    public const FALLBACK_REALTIME_TRANSCRIBE_MODEL = 'gpt-live-transcribe';
    public const RESPONSES_MIN_OUTPUT_TOKENS = 16;
    public const FALLBACK_VOICE  = 'alloy';
    public const FALLBACK_PROMPT = 'You are a helpful, concise AI agent. Speak naturally.';

    /**
     * Upgrade the original Mini defaults while preserving other selected models.
     */
    public static function resolveRealtimeModel(string $model): string
    {
        $model = trim($model);
        if ($model === '' || in_array($model, ['gpt-realtime-mini', 'gpt-realtime-mini-2025-10-06'], true)) {
            return self::FALLBACK_REALTIME_MODEL;
        }

        return $model;
    }

    /** Build the input transcription options for a Realtime conversation. */
    public static function realtimeTranscriptionPayload(string $languageHint = '', string $prompt = '', string $delay = ''): array
    {
        $payload = ['model' => self::FALLBACK_REALTIME_TRANSCRIBE_MODEL];
        if (in_array($languageHint, ['en', 'pt'], true)) {
            $payload['languages'] = [$languageHint];
        }
        if ($prompt !== '') {
            $payload['prompt'] = $prompt;
        }
        if (in_array($delay, ['minimal', 'low', 'medium', 'high', 'xhigh'], true)) {
            $payload['delay'] = $delay;
        }

        return $payload;
    }

    public static function pluginFile(): string
    {
        return AGENTOS_PLUGIN_FILE;
    }

    public static function pluginDir(): string
    {
        return AGENTOS_PLUGIN_DIR;
    }

    public static function pluginUrl(): string
    {
        return AGENTOS_PLUGIN_URL;
    }
}
