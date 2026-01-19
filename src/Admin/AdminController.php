<?php

namespace AgentOS\Admin;

use AgentOS\Analysis\Analyzer;
use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;
use AgentOS\Database\UsageRepository;
use AgentOS\Subscriptions\SubscriptionRepository;
use AgentOS\Subscriptions\UserSubscriptionRepository;

class AdminController
{
    private Settings $settings;
    private AgentRepository $agents;
    private View $view;
    private TranscriptRepository $transcripts;
    private Analyzer $analyzer;
    private SubscriptionRepository $subscriptions;
    private UserSubscriptionRepository $userSubscriptions;
    private UsageRepository $usage;

    public function __construct(
        Settings $settings,
        AgentRepository $agents,
        View $view,
        TranscriptRepository $transcripts,
        Analyzer $analyzer,
        SubscriptionRepository $subscriptions,
        UserSubscriptionRepository $userSubscriptions,
        UsageRepository $usage
    ) {
        $this->settings = $settings;
        $this->agents = $agents;
        $this->view = $view;
        $this->transcripts = $transcripts;
        $this->analyzer = $analyzer;
        $this->subscriptions = $subscriptions;
        $this->userSubscriptions = $userSubscriptions;
        $this->usage = $usage;
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
            __('Subscriptions', 'agentos'),
            __('Subscriptions', 'agentos'),
            $capability,
            'agentos-subscriptions',
            [$this, 'renderSubscriptionsPage']
        );

        add_submenu_page(
            'agentos',
            __('Users', 'agentos'),
            __('Users', 'agentos'),
            $capability,
            'agentos-users',
            [$this, 'renderUsersPage']
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

    public function renderSubscriptionsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'edit' || $action === 'new') {
            $this->renderSubscriptionForm($action);
            return;
        }

        $message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';
        $plans = $this->subscriptions->all();

        $this->view->render('admin/subscriptions-list', [
            'plans' => $plans,
            'agents' => $this->agents->all(),
            'message' => $message,
        ]);
    }

    private function renderSubscriptionForm(string $action): void
    {
        $isEdit = ($action === 'edit');
        $plan = $this->subscriptions->blank();
        $planSlug = '';

        if ($isEdit) {
            $planSlug = isset($_GET['subscription']) ? sanitize_key($_GET['subscription']) : '';
            $existing = $planSlug ? $this->subscriptions->get($planSlug) : null;
            if (!$existing) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('Subscription not found.', 'agentos'));
                return;
            }
            $plan = wp_parse_args($existing, $plan);
        }

        $this->view->render('admin/subscription-form', [
            'is_edit' => $isEdit,
            'plan' => $plan,
            'plan_slug' => $planSlug,
            'agents' => $this->agents->all(),
        ]);
    }

    private function mergeUsers(array $usageUsers, array $manualUsers, string $search, int $limit): array
    {
        $merged = [];

        foreach ($usageUsers as $row) {
            $key = $row['user_key'];
            $merged[$key] = [
                'user_key' => $key,
                'user_email' => $row['user_email'] ?? '',
                'sessions' => (int) ($row['sessions'] ?? 0),
                'last_activity' => $row['last_activity'] ?? '',
                'subscriptions' => [],
                'source' => 'usage',
                'tokens_realtime' => (int) ($row['tokens_realtime'] ?? 0),
                'tokens_text' => (int) ($row['tokens_text'] ?? 0),
                'tokens_total' => (int) ($row['tokens_total'] ?? 0),
                'meta' => $this->normalizeUserMeta([]),
            ];
        }

        foreach ($manualUsers as $key => $meta) {
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'user_key' => $key,
                    'user_email' => $meta['email'] ?? '',
                    'sessions' => 0,
                    'last_activity' => '',
                    'subscriptions' => [],
                    'source' => 'manual',
                    'tokens_realtime' => 0,
                    'tokens_text' => 0,
                    'tokens_total' => 0,
                    'meta' => $this->normalizeUserMeta([]),
                ];
            }
            $merged[$key]['meta'] = $this->normalizeUserMeta($meta);
        }

        foreach ($merged as $key => &$row) {
            $meta = $row['meta'];
            if (!empty($meta['wp_user_id'])) {
                $wpUser = get_user_by('id', (int) $meta['wp_user_id']);
                if ($wpUser instanceof \WP_User) {
                    if (empty($meta['name'])) {
                        $meta['name'] = $wpUser->display_name ?: $wpUser->user_login;
                    }
                    if (empty($meta['email'])) {
                        $meta['email'] = sanitize_email($wpUser->user_email);
                    }
                    $row['meta'] = $meta;
                }
            }

            if (empty($row['user_email']) && !empty($meta['email'])) {
                $row['user_email'] = $meta['email'];
            }
        }
        unset($row);

        if ($search !== '') {
            $needle = strtolower($search);
            $merged = array_filter($merged, static function ($row) use ($needle) {
                $fields = [
                    $row['user_key'] ?? '',
                    $row['user_email'] ?? '',
                    $row['meta']['name'] ?? '',
                    $row['meta']['email'] ?? '',
                    $row['meta']['notes'] ?? '',
                ];
                foreach ($fields as $field) {
                    if ($field && strpos(strtolower($field), $needle) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        uasort($merged, static function ($a, $b) {
            $aTime = !empty($a['last_activity']) ? strtotime($a['last_activity']) : 0;
            $bTime = !empty($b['last_activity']) ? strtotime($b['last_activity']) : 0;
            return $bTime <=> $aTime;
        });

        if ($limit > 0) {
            $merged = array_slice($merged, 0, $limit);
        }

        return array_values($merged);
    }

    private function normalizeUserMeta(array $meta): array
    {
        $defaults = [
            'name' => '',
            'email' => '',
            'notes' => '',
            'wp_user_id' => 0,
        ];

        if (isset($meta['label']) && empty($meta['name'])) {
            $meta['name'] = $meta['label'];
        }

        $meta = array_merge($defaults, array_intersect_key($meta, $defaults));
        $meta['name'] = sanitize_text_field($meta['name']);
        $meta['email'] = sanitize_email($meta['email']);
        $meta['notes'] = sanitize_textarea_field($meta['notes']);
        $meta['wp_user_id'] = (int) $meta['wp_user_id'];

        return $meta;
    }

    public function handleSubscriptionSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_save_subscription');

        $payload = isset($_POST['subscription']) ? (array) $_POST['subscription'] : [];
        $original = isset($_POST['original_slug']) ? sanitize_key($_POST['original_slug']) : '';

        $slug = $this->subscriptions->upsert($payload, $original);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'agentos-subscriptions',
                    'message' => 'saved',
                    'action' => 'edit',
                    'subscription' => $slug,
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function handleSubscriptionDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_delete_subscription');

        $slug = isset($_GET['subscription']) ? sanitize_key($_GET['subscription']) : '';
        if ($slug) {
            $this->subscriptions->delete($slug);
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'agentos-subscriptions', 'message' => 'deleted'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function renderUsersPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'add' || $action === 'edit') {
            $this->renderUserForm($action);
            return;
        }

        if ($action === 'view') {
            $this->renderUserDetail();
            return;
        }

        $message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $limit = isset($_GET['limit']) ? max(1, min(500, intval($_GET['limit']))) : 100;

        $this->userSubscriptions->clearExpired();

        $usageUsers = $this->usage->listUsers($limit, ['search' => $search]);
        $manualUsers = $this->userSubscriptions->getUserMeta();
        $assignments = $this->userSubscriptions->all();
        $plans = $this->subscriptions->all();

        $users = $this->mergeUsers($usageUsers, $manualUsers, $search, $limit);

        $this->view->render('admin/users-list', [
            'users' => $users,
            'assignments' => $assignments,
            'plans' => $plans,
            'search' => $search,
            'limit' => $limit,
            'message' => $message,
        ]);
    }

    private function renderUserForm(string $action): void
    {
        $isEdit = ($action === 'edit');
        $plans = $this->subscriptions->all();
        $message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';
        $metaAll = $this->userSubscriptions->getUserMeta();
        $assignments = $this->userSubscriptions->all();

        $userKey = '';
        $meta = $this->normalizeUserMeta([]);
        $subscriptions = [];
        $wpUser = null;

        if ($isEdit) {
            $userKey = isset($_GET['user']) ? sanitize_text_field($_GET['user']) : '';
            if (!$userKey || !isset($metaAll[$userKey])) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('User not found.', 'agentos'));
                return;
            }
            $meta = $this->normalizeUserMeta($metaAll[$userKey]);
            $subscriptions = $assignments[$userKey] ?? [];
            if (!empty($meta['wp_user_id'])) {
                $wpUser = get_user_by('id', (int) $meta['wp_user_id']);
            }
        }

        $this->view->render('admin/user-form', [
            'is_edit' => $isEdit,
            'user_key' => $userKey,
            'meta' => $meta,
            'plans' => $plans,
            'subscriptions' => $subscriptions,
            'wp_user' => $wpUser,
        ]);
    }

    private function renderUserDetail(): void
    {
        $userKey = isset($_GET['user']) ? sanitize_text_field($_GET['user']) : '';
        if (!$userKey) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('User key missing.', 'agentos'));
            return;
        }

        $metaAll = $this->userSubscriptions->getUserMeta();
        $meta = $this->normalizeUserMeta($metaAll[$userKey] ?? []);

        $assignments = $this->userSubscriptions->get($userKey, true);
        $plans = $this->subscriptions->all();

        $subscriptionRows = [];
        foreach ($assignments as $assignment) {
            $slug = $assignment['subscription_slug'];
            $plan = $plans[$slug] ?? null;
            $periodHours = $plan['period_hours'] ?? 24;
            $usage = $this->usage->summarizeUsage($slug, $userKey, $periodHours);
            $subscriptionRows[] = [
                'slug' => $slug,
                'label' => $plan['label'] ?? $slug,
                'plan' => $plan,
                'assignment' => $assignment,
                'usage' => $usage,
            ];
        }

        $usageSummary = $this->usage->summarizeUsage('', $userKey, 720);
        $usageEntries = $this->usage->listForUser($userKey, 25);
        $transcripts = $this->transcripts->query(['user_key' => $userKey], 10);
        $wpUser = !empty($meta['wp_user_id']) ? get_user_by('id', (int) $meta['wp_user_id']) : null;

        $this->view->render('admin/user-detail', [
            'user_key' => $userKey,
            'meta' => $meta,
            'subscriptions' => $subscriptionRows,
            'plans' => $plans,
            'usage_summary' => $usageSummary,
            'usage_entries' => $usageEntries,
            'transcripts' => $transcripts,
            'wp_user' => $wpUser,
            'message' => $message,
        ]);
    }

    public function handleUserSubscriptionAssign(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_assign_subscription');

        $userKey = isset($_POST['user_key']) ? sanitize_text_field(wp_unslash($_POST['user_key'])) : '';
        $subscription = isset($_POST['subscription_slug']) ? sanitize_key($_POST['subscription_slug']) : '';
        $expiresAt = isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : null;

        if ($userKey && $subscription) {
            $this->userSubscriptions->ensureUser($userKey, []);
            $this->userSubscriptions->assign($userKey, $subscription, [], $expiresAt ?: null);
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'agentos-users',
                    'action' => 'view',
                    'user' => $userKey,
                    'message' => 'user_saved',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function handleUserSubscriptionRemove(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_remove_subscription');

        $userKey = isset($_POST['user_key']) ? sanitize_text_field(wp_unslash($_POST['user_key'])) : '';
        $subscription = isset($_POST['subscription_slug']) ? sanitize_key($_POST['subscription_slug']) : '';

        if ($userKey && $subscription) {
            $this->userSubscriptions->remove($userKey, $subscription);
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'agentos-users',
                    'action' => 'view',
                    'user' => $userKey,
                    'message' => 'user_removed',
                ],
                admin_url('admin.php')
            )
        );
        exit;
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

    public function handleUserSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_save_user');

        $originalKey = isset($_POST['user']['original_key']) ? sanitize_text_field(wp_unslash($_POST['user']['original_key'])) : '';
        $email = isset($_POST['user']['email']) ? sanitize_email(wp_unslash($_POST['user']['email'])) : '';
        $notes = isset($_POST['user']['notes']) ? sanitize_textarea_field(wp_unslash($_POST['user']['notes'])) : '';
        $subscription = isset($_POST['user']['subscription']) ? sanitize_key(wp_unslash($_POST['user']['subscription'])) : '';

        if (!$email) {
            wp_safe_redirect(
                add_query_arg(
                    ['page' => 'agentos-users', 'message' => 'user_invalid'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $plans = $this->subscriptions->all();
        if ($subscription && !isset($plans[$subscription])) {
            $subscription = '';
        }

        $emailLower = strtolower($email);
        $wpUser = get_user_by('email', $emailLower);

        if (!$wpUser instanceof \WP_User || !$wpUser->exists()) {
            wp_safe_redirect(
                add_query_arg(
                    ['page' => 'agentos-users', 'message' => 'user_missing'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $targetKey = 'user_' . (int) $wpUser->ID;

        if ($originalKey && $originalKey !== $targetKey) {
            $this->userSubscriptions->moveUser($originalKey, $targetKey);
            $this->usage->reassignUser($originalKey, $targetKey);
            $this->transcripts->reassignUserKey($originalKey, $targetKey);
        }

        $name = $wpUser->display_name ?: $wpUser->user_login;
        $wpUserId = (int) $wpUser->ID;

        $meta = [
            'name' => $name,
            'email' => sanitize_email($wpUser->user_email),
            'notes' => $notes,
            'wp_user_id' => $wpUserId,
        ];

        $this->userSubscriptions->ensureUser($targetKey, $meta);
        $this->userSubscriptions->saveUserMeta($targetKey, $meta);

        if ($subscription) {
            $this->userSubscriptions->assign($targetKey, $subscription);
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'agentos-users',
                    'action' => 'view',
                    'user' => $targetKey,
                    'message' => 'user_saved',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function handleUserDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'agentos'));
        }

        check_admin_referer('agentos_delete_user');

        $userKey = isset($_POST['user_key']) ? sanitize_text_field(wp_unslash($_POST['user_key'])) : '';
        if ($userKey) {
            $this->userSubscriptions->deleteUser($userKey);
            $this->usage->deleteForUser($userKey);
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'agentos-users', 'message' => 'user_deleted'],
                admin_url('admin.php')
            )
        );
        exit;
    }
}
