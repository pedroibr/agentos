<?php

namespace AgentOS\Frontend;

use AgentOS\Assets\Registrar;
use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Core\Settings;

class Shortcode
{
    private AgentRepository $agents;
    private Settings $settings;
    private Registrar $assets;
    private string $templateDir;

    public function __construct(AgentRepository $agents, Settings $settings, Registrar $assets, string $templateDir)
    {
        $this->agents = $agents;
        $this->settings = $settings;
        $this->assets = $assets;
        $this->templateDir = rtrim($templateDir, '/');
    }

    public function register(): void
    {
        add_shortcode('agentos', [$this, 'render']);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts(
            [
                'id' => '',
                'mode' => '',
                'height' => '70vh',
            ],
            $atts,
            'agentos'
        );

        $agentId = sanitize_key($atts['id']);
        if (!$agentId) {
            return '<!-- agentos: missing id -->';
        }

        $agents = $this->agents->all();
        if (!isset($agents[$agentId])) {
            return '<!-- agentos: unknown agent -->';
        }

        $agent = $agents[$agentId];

        $postId = get_the_ID();
        if (!$postId) {
            return '<!-- agentos: invalid context -->';
        }

        $postType = get_post_type($postId);
        if ($agent['post_types'] && !in_array($postType, $agent['post_types'], true)) {
            return '<!-- agentos: agent not enabled for this post type -->';
        }

        $this->assets->registerFrontend();
        wp_enqueue_script('agentos-embed');
        wp_enqueue_style('agentos-embed');

        $mode = $atts['mode'] ? sanitize_key($atts['mode']) : $agent['default_mode'];
        if (!in_array($mode, ['voice', 'text', 'both'], true)) {
            $mode = $agent['default_mode'];
        }

        $settings = $this->settings->get();
        $contextParams = !empty($agent['context_params']) ? (array) $agent['context_params'] : (array) ($settings['context_params'] ?? []);

        $config = [
            'agent_id' => $agent['slug'],
            'mode' => $mode,
            'rest' => esc_url_raw(rest_url('agentos/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'post_id' => $postId,
            'viewer_is_authenticated' => is_user_logged_in(),
            'context_params' => $contextParams,
            'logging' => !empty($settings['enable_logging']),
            'show_transcript' => !empty($agent['show_transcript']),
            'analysis_enabled' => !empty($agent['analysis_enabled']),
            'require_subscription' => !empty($agent['require_subscription']),
            'session_token_cap' => isset($agent['session_token_cap']) ? (int) $agent['session_token_cap'] : 0,
            'transcription_hint' => sanitize_textarea_field($agent['transcription_hint'] ?? ''),
        ];

        $template = $this->templateDir . '/frontend/shortcode.php';
        if (!file_exists($template)) {
            return '<!-- agentos: template missing -->';
        }

        $height = $atts['height'];
        $configAttr = esc_attr(wp_json_encode($config));
        $agentData = $agent;
        $modeValue = $mode;
        $heightValue = $height;
        $transcriptEnabled = !empty($agent['show_transcript']);
        $sidebarImageUrl = '';
        $sidebarImageAlt = '';
        if (!empty($agent['show_post_image']) && has_post_thumbnail($postId)) {
            $sidebarImageUrl = (string) get_the_post_thumbnail_url($postId, 'medium_large');
            $sidebarImageAlt = (string) get_post_meta((int) get_post_thumbnail_id($postId), '_wp_attachment_image_alt', true);
            if ($sidebarImageAlt === '') {
                $sidebarImageAlt = (string) get_the_title($postId);
            }
        }
        $sidebarPostTitle = (string) get_the_title($postId);
        $sidebarBackUrl = !empty($agent['sidebar_back_url']) ? esc_url_raw($agent['sidebar_back_url']) : '';
        $sidebarBackLabel = trim((string) ($agent['sidebar_back_label'] ?? ''));
        if ($sidebarBackLabel === '') {
            $sidebarBackLabel = __('Go back', 'agentos');
        }

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
