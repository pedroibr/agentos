<?php

namespace AgentOS;

use AgentOS\Admin\AdminController;
use AgentOS\Admin\View;
use AgentOS\Assets\Registrar;
use AgentOS\Analysis\Analyzer;
use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;
use AgentOS\Frontend\Shortcode;
use AgentOS\Rest\RestController;

class Plugin
{
    private Settings $settings;
    private AgentRepository $agents;
    private AdminController $admin;
    private Registrar $assets;
    private Shortcode $shortcode;
    private RestController $rest;
    private TranscriptRepository $transcripts;
    private Analyzer $analyzer;

    public function __construct()
    {
        $templateDir = Config::pluginDir() . 'templates';

        $this->settings = new Settings();
        $this->agents = new AgentRepository();
        $this->assets = new Registrar();
        $view = new View($templateDir);
        $this->transcripts = new TranscriptRepository();
        $this->analyzer = new Analyzer($this->settings, $this->agents, $this->transcripts);

        $this->admin = new AdminController($this->settings, $this->agents, $view, $this->transcripts, $this->analyzer);
        $this->shortcode = new Shortcode($this->agents, $this->settings, $this->assets, $templateDir);
        $this->rest = new RestController($this->settings, $this->agents, $this->transcripts, $this->analyzer);
    }

    public function register(): void
    {
        add_action('plugins_loaded', [$this, 'loadTextdomain']);

        add_action('admin_init', [$this->settings, 'register']);
        add_action('admin_menu', [$this->admin, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this->admin, 'enqueueAssets']);
        add_action('admin_post_agentos_save_agent', [$this->admin, 'handleSave']);
        add_action('admin_post_agentos_delete_agent', [$this->admin, 'handleDelete']);
        add_action('admin_post_agentos_run_analysis', [$this->admin, 'handleAnalysisRequest']);

        add_action('wp_enqueue_scripts', [$this->assets, 'registerFrontend']);

        add_action('init', [$this->shortcode, 'register']);
        add_action('rest_api_init', [$this->rest, 'registerRoutes']);
        $this->analyzer->register();

        register_activation_hook(Config::pluginFile(), [$this, 'activate']);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('agentos', false, dirname(plugin_basename(Config::pluginFile())) . '/languages');
    }

    public function activate(): void
    {
        $this->transcripts->install();
    }
}
