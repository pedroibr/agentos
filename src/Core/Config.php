<?php

namespace AgentOS\Core;

class Config
{
    public const VERSION = '0.5.0';

    public const OPTION_SETTINGS = 'agentos_settings';
    public const OPTION_AGENTS   = 'agentos_agents';

    public const FALLBACK_MODEL  = 'gpt-realtime-mini-2025-10-06';
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
