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

        wp_register_script(
            'agentos-embed',
            plugins_url('assets/agentos-embed.js', Config::pluginFile()),
            [],
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
