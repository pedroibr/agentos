<?php
/**
 * Plugin Name: AgentOS – Dynamic AI Agents for WordPress
 * Description: Load customizable AI agents (text/voice) per post type. Map ACF/meta fields to agent parameters. Provides shortcodes and REST endpoints.
 * Version: 0.4.1
 * Author: Pedro Raimundo
 * Text Domain: agentos
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AGENTOS_PLUGIN_FILE', __FILE__);
define('AGENTOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AGENTOS_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function ($class) {
    if (strpos($class, 'AgentOS\\') !== 0) {
        return;
    }

    $relative = substr($class, strlen('AgentOS\\'));
    $relativePath = str_replace('\\', '/', $relative);
    $file = AGENTOS_PLUGIN_DIR . 'src/' . $relativePath . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

function agentos(): \AgentOS\Plugin
{
    static $instance = null;

    if ($instance === null) {
        $instance = new \AgentOS\Plugin();
        $instance->register();
    }

    return $instance;
}

agentos();
