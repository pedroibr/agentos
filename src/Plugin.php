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
use AgentOS\Database\UsageRepository;
use AgentOS\Blocks\AgentBlock;
use AgentOS\Frontend\Shortcode;
use AgentOS\Rest\RestController;
use AgentOS\Subscriptions\SubscriptionRepository;
use AgentOS\Subscriptions\UserSubscriptionRepository;
use AgentOS\Subscriptions\UsageLimiter;

class Plugin
{
    private Settings $settings;
    private AgentRepository $agents;
    private AdminController $admin;
    private Registrar $assets;
    private Shortcode $shortcode;
    private RestController $rest;
    private TranscriptRepository $transcripts;
    private UsageRepository $usage;
    private Analyzer $analyzer;
    private AgentBlock $block;
    private SubscriptionRepository $subscriptions;
    private UserSubscriptionRepository $userSubscriptions;
    private UsageLimiter $usageLimiter;

    public function __construct()
    {
        $templateDir = Config::pluginDir() . 'templates';

        $this->settings = new Settings();
        $this->agents = new AgentRepository();
        $this->assets = new Registrar();
        $view = new View($templateDir);
        $this->transcripts = new TranscriptRepository();
        $this->usage = new UsageRepository();
        $this->usage->maybeCreateTable();
        $this->subscriptions = new SubscriptionRepository();
        $this->userSubscriptions = new UserSubscriptionRepository();
        $this->usageLimiter = new UsageLimiter($this->subscriptions, $this->userSubscriptions, $this->usage);
        $this->analyzer = new Analyzer($this->settings, $this->agents, $this->transcripts);

        $this->admin = new AdminController(
            $this->settings,
            $this->agents,
            $view,
            $this->transcripts,
            $this->analyzer,
            $this->subscriptions,
            $this->userSubscriptions,
            $this->usage
        );
        $this->shortcode = new Shortcode($this->agents, $this->settings, $this->assets, $templateDir);
        $this->rest = new RestController(
            $this->settings,
            $this->agents,
            $this->transcripts,
            $this->analyzer,
            $this->usageLimiter,
            $this->userSubscriptions,
            $this->subscriptions,
            $this->usage
        );
        $this->block = new AgentBlock($this->shortcode, $this->agents);
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
        add_action('admin_post_agentos_save_subscription', [$this->admin, 'handleSubscriptionSave']);
        add_action('admin_post_agentos_delete_subscription', [$this->admin, 'handleSubscriptionDelete']);
        add_action('admin_post_agentos_assign_subscription', [$this->admin, 'handleUserSubscriptionAssign']);
        add_action('admin_post_agentos_remove_subscription', [$this->admin, 'handleUserSubscriptionRemove']);
        add_action('admin_post_agentos_save_user', [$this->admin, 'handleUserSave']);
        add_action('admin_post_agentos_delete_user', [$this->admin, 'handleUserDelete']);

        add_action('wp_enqueue_scripts', [$this->assets, 'registerFrontend']);

        add_action('init', [$this->shortcode, 'register']);
        add_action('rest_api_init', [$this->rest, 'registerRoutes']);
        $this->analyzer->register();
        $this->block->register();

        register_activation_hook(Config::pluginFile(), [$this, 'activate']);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('agentos', false, dirname(plugin_basename(Config::pluginFile())) . '/languages');
    }

    public function activate(): void
    {
        $this->transcripts->install();
        if (get_option(Config::OPTION_SUBSCRIPTIONS, null) === null) {
            add_option(Config::OPTION_SUBSCRIPTIONS, []);
        }
        if (get_option(Config::OPTION_USER_SUBSCRIPTIONS, null) === null) {
            add_option(Config::OPTION_USER_SUBSCRIPTIONS, []);
        }
        if (get_option(Config::OPTION_USER_SUBSCRIPTION_META, null) === null) {
            add_option(Config::OPTION_USER_SUBSCRIPTION_META, []);
        }
        $this->usage->install();
    }
}
