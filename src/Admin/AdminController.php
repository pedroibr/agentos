<?php

namespace AgentOS\Admin;

use AgentOS\Analysis\Analyzer;
use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;

class AdminController
{
    private Settings $settings;
    private AgentRepository $agents;
    private View $view;
    private TranscriptRepository $transcripts;
    private Analyzer $analyzer;

    public function __construct(
        Settings $settings,
        AgentRepository $agents,
        View $view,
        TranscriptRepository $transcripts,
        Analyzer $analyzer
    ) {
        $this->settings = $settings;
        $this->agents = $agents;
        $this->view = $view;
        $this->transcripts = $transcripts;
        $this->analyzer = $analyzer;
    }

    public function registerMenu(): void
    {
        $capability = 'manage_options';

        add_menu_page(
            __('AgentOS', 'agentos'),
            __('AgentOS', 'agentos'),
            $capability,
            'agentos',
            [$this, 'renderAgentsPage'],
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'agentos',
            __('Agents', 'agentos'),
            __('Agents', 'agentos'),
            $capability,
            'agentos',
            [$this, 'renderAgentsPage']
        );

        add_submenu_page(
            'agentos',
            __('Sessions', 'agentos'),
            __('Sessions', 'agentos'),
            $capability,
            'agentos-sessions',
            [$this, 'renderSessionsPage']
        );

        add_submenu_page(
            'agentos',
            __('Settings', 'agentos'),
            __('Settings', 'agentos'),
            $capability,
            'agentos-settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function renderAgentsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'edit' || $action === 'new') {
            $this->renderAgentForm($action);
            return;
        }

        $message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';
        $this->view->render('admin/agents-list', [
            'agents' => $this->agents->all(),
            'message' => $message,
        ]);
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->view->render('admin/settings', [
            'option_key' => Config::OPTION_SETTINGS,
            'settings' => $this->settings->get(),
        ]);
    }

    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'agentos') === false) {
            return;
        }

        wp_register_script(
            'agentos-admin',
            plugins_url('assets/agentos-admin.js', Config::pluginFile()),
            ['jquery'],
            Config::VERSION,
            true
        );
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_save_agent');

        $payload = isset($_POST['agent']) ? (array) $_POST['agent'] : [];
        $original = isset($_POST['original_slug']) ? sanitize_key($_POST['original_slug']) : '';

        $this->agents->upsert($payload, $original);

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'agentos', 'message' => 'saved'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function handleDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_delete_agent');

        $slug = isset($_GET['agent']) ? sanitize_key($_GET['agent']) : '';
        if ($slug) {
            $this->agents->delete($slug);
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'agentos', 'message' => 'deleted'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function renderSessionsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        $message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';

        if ($action === 'view') {
            $id = isset($_GET['transcript']) ? intval($_GET['transcript']) : 0;
            $transcript = $id ? $this->transcripts->find($id) : null;

            if (!$transcript) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('Transcript not found.', 'agentos'));
                $this->renderSessionsList($message);
                return;
            }

            $post = get_post($transcript['post_id']);
            $agent = $this->agents->get($transcript['agent_id']);

            $this->view->render('admin/session-detail', [
                'transcript' => $transcript,
                'post' => $post ?: null,
                'agent' => $agent,
                'message' => $message,
            ]);
            return;
        }

        $this->renderSessionsList($message);
    }

    private function renderSessionsList(string $message = ''): void
    {
        $filters = [
            'agent_id' => isset($_GET['agent']) ? sanitize_key($_GET['agent']) : '',
            'status' => isset($_GET['status']) ? sanitize_key($_GET['status']) : '',
            'user_email' => isset($_GET['user_email']) ? sanitize_email($_GET['user_email']) : '',
            'post_id' => isset($_GET['post_id']) ? intval($_GET['post_id']) : 0,
        ];

        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        if ($limit < 1 || $limit > 200) {
            $limit = 20;
        }

        $transcripts = $this->transcripts->query($filters, $limit);

        $this->view->render('admin/sessions-list', [
            'transcripts' => $transcripts,
            'agents' => $this->agents->all(),
            'filters' => $filters,
            'limit' => $limit,
            'message' => $message,
        ]);
    }

    public function handleAnalysisRequest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_run_analysis');

        $transcriptId = isset($_POST['transcript_id']) ? intval($_POST['transcript_id']) : 0;
        if (!$transcriptId) {
            wp_safe_redirect(
                add_query_arg(
                    ['page' => 'agentos-sessions', 'message' => 'missing'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $customPrompt = isset($_POST['custom_prompt']) ? wp_kses_post(wp_unslash($_POST['custom_prompt'])) : '';
        $customModel = isset($_POST['custom_model']) ? sanitize_text_field($_POST['custom_model']) : '';

        $success = $this->analyzer->enqueue($transcriptId, [
            'custom_prompt' => $customPrompt,
            'model' => $customModel,
            'force' => true,
        ]);

        $message = $success ? 'analysis_queued' : 'analysis_failed';

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'agentos-sessions',
                    'action' => 'view',
                    'transcript' => $transcriptId,
                    'message' => $message,
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    private function renderAgentForm(string $action): void
    {
        $isEdit = ($action === 'edit');
        $allAgents = $this->agents->all();
        $agentSlug = '';
        $agent = $this->agents->blank();

        if ($isEdit) {
            $agentSlug = isset($_GET['agent']) ? sanitize_key($_GET['agent']) : '';
            if (!$agentSlug || !isset($allAgents[$agentSlug])) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('Agent not found.', 'agentos'));
                return;
            }

            $agent = wp_parse_args($allAgents[$agentSlug], $this->agents->blank());
        }

        $postTypes = get_post_types(['public' => true], 'objects');
        $postTypeLabels = [];
        foreach ($postTypes as $slug => $object) {
            $label = $object->labels->singular_name ?? $object->label ?? $slug;
            $postTypeLabels[$slug] = $label ?: $slug;
        }

        if (!wp_script_is('agentos-admin', 'registered')) {
            wp_register_script(
                'agentos-admin',
                plugins_url('assets/agentos-admin.js', Config::pluginFile()),
                ['jquery'],
                Config::VERSION,
                true
            );
        }

        $settings = $this->settings->get();

        wp_enqueue_script('agentos-admin');

        wp_localize_script(
            'agentos-admin',
            'AgentOSAdminData',
            [
                'postTypes' => $postTypeLabels,
                'fieldOptions' => $this->collectAvailableFields($postTypes),
                'existingMaps' => $agent['field_maps'] ?? [],
                'selectedPostTypes' => $agent['post_types'] ?? [],
                'hasAcf' => function_exists('acf_get_field_groups'),
                'loggingEnabled' => !empty($settings['enable_logging']),
                'strings' => [
                    'noFields' => __('No ACF fields detected for this post type.', 'agentos'),
                    'customLabel' => __('Custom meta key…', 'agentos'),
                    'placeholders' => [
                        'model' => __('Select model field', 'agentos'),
                        'voice' => __('Select voice field', 'agentos'),
                        'system_prompt' => __('Select system prompt field', 'agentos'),
                        'user_prompt' => __('Select user prompt field', 'agentos'),
                    ],
                    'fieldHeadings' => [
                        'model' => __('Model Field', 'agentos'),
                        'voice' => __('Voice Field', 'agentos'),
                        'system_prompt' => __('System Prompt Field', 'agentos'),
                        'user_prompt' => __('User Prompt Field', 'agentos'),
                    ],
                    'customPlaceholder' => __('Meta key (e.g. field_name)', 'agentos'),
                    'postTypePlaceholder' => __('Select post types…', 'agentos'),
                    'emptyState' => __('Select at least one post type to configure field mappings.', 'agentos'),
                ],
            ]
        );

        $this->view->render('admin/agent-form', [
            'is_edit' => $isEdit,
            'agent' => $agent,
            'agent_slug' => $agentSlug,
            'post_types' => $postTypes,
            'settings' => $settings,
        ]);
    }

    private function collectAvailableFields($postTypes): array
    {
        $fields = [];
        foreach ($postTypes as $slug => $object) {
            $fields[$slug] = [];
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $fields;
        }

        foreach ($postTypes as $slug => $object) {
            $groups = acf_get_field_groups(['post_type' => $slug]);
            if (empty($groups)) {
                continue;
            }

            $list = [];
            $seen = [];
            foreach ($groups as $group) {
                $groupFields = acf_get_fields($group);
                $this->flattenAcfFields($groupFields, $list, $seen);
            }

            $fields[$slug] = array_values($list);
        }

        return $fields;
    }

    private function flattenAcfFields($fields, array &$list, array &$seen): void
    {
        if (empty($fields) || !is_array($fields)) {
            return;
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = $field['name'] ?? '';
            $label = $field['label'] ?? $name;
            if ($name && !isset($seen[$name])) {
                $list[] = [
                    'key' => $name,
                    'label' => $label ?: $name,
                ];
                $seen[$name] = true;
            }

            if (!empty($field['sub_fields'])) {
                $this->flattenAcfFields($field['sub_fields'], $list, $seen);
            }

            if (!empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (!empty($layout['sub_fields'])) {
                        $this->flattenAcfFields($layout['sub_fields'], $list, $seen);
                    }
                }
            }
        }
    }
}
