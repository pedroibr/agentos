<?php

namespace AgentOS\Assets;

use AgentOS\Core\Config;

class Registrar
{
    public function registerFrontend(): void
    {
        wp_register_script(
            'agentos-embed',
            plugins_url('assets/agentos-embed.js', Config::pluginFile()),
            [],
            Config::VERSION,
            true
        );
    }
}
