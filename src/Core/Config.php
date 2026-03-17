<?php

namespace AgentOS\Core;

class Config
{
    public const VERSION = '10.2.0';

    public const OPTION_SETTINGS = 'agentos_settings';
    public const OPTION_AGENTS   = 'agentos_agents';
    public const OPTION_SUBSCRIPTIONS = 'agentos_subscriptions';
    public const OPTION_USER_SUBSCRIPTIONS = 'agentos_user_subscriptions';
    public const OPTION_USER_SUBSCRIPTION_META = 'agentos_user_subscription_meta';

    public const FALLBACK_MODEL  = 'gpt-realtime-mini-2025-10-06';
    public const FALLBACK_REALTIME_MODEL = self::FALLBACK_MODEL;
    public const FALLBACK_TEXT_MODEL = 'gpt-4.1-mini';
    public const FALLBACK_TRANSCRIBE_MODEL = 'gpt-4o-mini-transcribe';
    public const FALLBACK_VOICE  = 'alloy';
    public const FALLBACK_PROMPT = 'You are a helpful, concise AI agent. Speak naturally.';

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
