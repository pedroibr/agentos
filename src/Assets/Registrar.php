<?php

namespace AgentOS\Assets;

use AgentOS\Core\Config;

class Registrar
{
    public function registerFrontend(): void
    {
        $scriptPath = Config::pluginDir() . 'assets/agentos-embed.js';
        $stylePath = Config::pluginDir() . 'assets/agentos-embed.css';
        $scriptVersion = file_exists($scriptPath) ? (string) filemtime($scriptPath) : Config::VERSION;
        $styleVersion = file_exists($stylePath) ? (string) filemtime($stylePath) : Config::VERSION;
        $realtimePath = Config::pluginDir() . 'assets/agentos-realtime.js';
        $realtimeVersion = file_exists($realtimePath) ? (string) filemtime($realtimePath) : Config::VERSION;

        wp_register_script(
            'agentos-realtime',
            plugins_url('assets/agentos-realtime.js', Config::pluginFile()),
            [],
            $realtimeVersion,
            true
        );

        wp_register_script(
            'agentos-tools',
            plugins_url('assets/agentos-tools.js', Config::pluginFile()),
            [],
            file_exists(Config::pluginDir() . 'assets/agentos-tools.js') ? (string) filemtime(Config::pluginDir() . 'assets/agentos-tools.js') : Config::VERSION,
            true
        );

        wp_register_script(
            'agentos-embed',
            plugins_url('assets/agentos-embed.js', Config::pluginFile()),
            ['agentos-realtime', 'agentos-tools'],
            $scriptVersion,
            true
        );

        wp_register_style(
            'agentos-embed',
            plugins_url('assets/agentos-embed.css', Config::pluginFile()),
            [],
            $styleVersion
        );
    }
}
