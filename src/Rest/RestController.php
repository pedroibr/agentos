<?php

namespace AgentOS\Rest;

use AgentOS\Analysis\Analyzer;
use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;
use AgentOS\Database\UsageRepository;
use AgentOS\Subscriptions\UsageLimiter;
use AgentOS\Subscriptions\UserSubscriptionRepository;
use AgentOS\Subscriptions\SubscriptionRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class RestController
{
    private Settings $settings;
    private AgentRepository $agents;
    private TranscriptRepository $transcripts;
    private Analyzer $analyzer;
    private UsageLimiter $limiter;
    private UserSubscriptionRepository $userSubscriptions;
    private SubscriptionRepository $subscriptions;
    private UsageRepository $usage;

    public function __construct(
        Settings $settings,
        AgentRepository $agents,
        TranscriptRepository $transcripts,
        Analyzer $analyzer,
        UsageLimiter $limiter,
        UserSubscriptionRepository $userSubscriptions,
        SubscriptionRepository $subscriptions,
        UsageRepository $usage
    )
    {
        $this->settings = $settings;
        $this->agents = $agents;
        $this->transcripts = $transcripts;
        $this->analyzer = $analyzer;
        $this->limiter = $limiter;
        $this->userSubscriptions = $userSubscriptions;
        $this->subscriptions = $subscriptions;
        $this->usage = $usage;
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'agentos/v1',
            '/realtime-token',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionNonce'],
                'callback' => [$this, 'routeRealtimeToken'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/transcript-db',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionNonce'],
                'callback' => [$this, 'routeTranscriptSave'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/transcript-db',
            [
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => [$this, 'permissionTranscriptList'],
                'callback' => [$this, 'routeTranscriptList'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/usage/session',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionNonce'],
                'callback' => [$this, 'routeUsageUpdate'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/subscriptions/user',
            [
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => [$this, 'permissionManage'],
                'callback' => [$this, 'routeUserSubscriptionsGet'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/subscriptions/user',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionManage'],
                'callback' => [$this, 'routeUserSubscriptionsSet'],
            ]
        );
    }

    public function permissionNonce(WP_REST_Request $request)
    {
        if ($this->checkNonce($request)) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __('Invalid or missing security nonce.', 'agentos'),
            [
                'status' => rest_authorization_required_code(),
            ]
        );
    }

    public function permissionTranscriptList(WP_REST_Request $request)
    {
        if (!$this->checkNonce($request)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid or missing security nonce.', 'agentos'),
                [
                    'status' => rest_authorization_required_code(),
                ]
            );
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $anonId = sanitize_text_field($request->get_param('anon_id') ?? '');
        $requestedEmail = sanitize_email($request->get_param('user_email') ?? '');
        $currentUser = wp_get_current_user();
        $currentEmail = ($currentUser && $currentUser instanceof \WP_User && $currentUser->exists()) ? sanitize_email($currentUser->user_email) : '';

        if ($anonId) {
            return true;
        }

        if ($currentEmail) {
            return true;
        }

        if ($requestedEmail) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to view transcripts for that user.', 'agentos'),
                [
                    'status' => 403,
                ]
            );
        }

        return new WP_Error(
            'rest_forbidden',
            __('You do not have permission to view transcripts.', 'agentos'),
            [
                'status' => 403,
            ]
        );
    }

    public function permissionManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function routeRealtimeToken(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id'));

        if (!$postId) {
            return new WP_Error('no_post', __('post_id required', 'agentos'), ['status' => 400]);
        }

        if (!$agentId) {
            return new WP_Error('no_agent', __('agent_id required', 'agentos'), ['status' => 400]);
        }

        $post = get_post($postId);
        if (!$post || $post->post_status === 'trash') {
            return new WP_Error('invalid_post', __('Post not found.', 'agentos'), ['status' => 404]);
        }

        if (post_password_required($post)) {
            return new WP_Error('forbidden_post', __('You do not have access to this content.', 'agentos'), ['status' => 403]);
        }

        if (!current_user_can('read_post', $postId) && !is_post_publicly_viewable($post)) {
            return new WP_Error('forbidden_post', __('You do not have access to this content.', 'agentos'), ['status' => 403]);
        }

        $agent = $this->agents->get($agentId);
        if (!$agent) {
            return new WP_Error('invalid_agent', __('Agent not found', 'agentos'), ['status' => 404]);
        }

        $postType = get_post_type($postId);
        if ($agent['post_types'] && !in_array($postType, $agent['post_types'], true)) {
            return new WP_Error('agent_unavailable', __('Agent not available for this post type.', 'agentos'), ['status' => 403]);
        }

        $config = $this->agents->collectPostConfig($agent, $postId);

        $sessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        if (!$sessionId) {
            $sessionId = wp_generate_password(16, false);
        }

        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $userKey = $this->resolveUserKey($anonId);

        $limitResult = $this->limiter->evaluate($userKey, $agent);
        if (empty($limitResult['allowed'])) {
            $status = isset($limitResult['status']) ? (int) $limitResult['status'] : 402;
            $message = $limitResult['message'] ?? __('Usage limit reached for this subscription.', 'agentos');
            $code = $limitResult['error_code'] ?? 'subscription_limit';
            return new WP_Error($code, $message, ['status' => $status]);
        }

        $subscriptionSlug = $limitResult['subscription_slug'] ?? '';
        $sessionCap = isset($limitResult['session_cap']) ? (int) $limitResult['session_cap'] : 0;

        if ($userKey) {
            $this->userSubscriptions->ensureUser($userKey, []);
        }

        $this->usage->logStart([
            'session_id' => $sessionId,
            'user_key' => $userKey,
            'subscription_slug' => $subscriptionSlug,
            'agent_id' => $agentId,
            'post_id' => $postId,
            'status' => 'pending',
            'metadata' => [
                'origin' => 'realtime_token',
            ],
        ]);

        $apiKey = $this->settings->resolveApiKey();
        if (!$apiKey) {
            return new WP_Error('no_key', __('OpenAI key not configured', 'agentos'), ['status' => 500]);
        }

        $settings = $this->settings->get();
        $ctxIn = $request->get_param('ctx');
        $ctxPairs = [];

        if (is_array($ctxIn)) {
            $allowed = $settings['context_params'] ?? [];
            foreach ($ctxIn as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    continue;
                }
                $cleanKey = preg_replace('/[^a-z0-9_\-]/i', '', $key);
                $cleanValue = sanitize_text_field($value);
                if ($cleanKey && $cleanValue !== '') {
                    $ctxPairs[] = "{$cleanKey}={$cleanValue}";
                }
            }
        }

        $instructions = $config['instructions'] ?: Config::FALLBACK_PROMPT;
        if ($ctxPairs) {
            $instructions .= "\n\nContext: " . implode(', ', $ctxPairs) . '.';
        }

        $payload = [
            'model' => $config['model'],
            'voice' => $config['voice'],
            'modalities' => ['audio', 'text'],
            'instructions' => $instructions,
        ];

        $response = wp_remote_post(
            'https://api.openai.com/v1/realtime/sessions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 25,
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('openai_transport', $response->get_error_message(), ['status' => 500]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($code >= 400 || !is_array($json)) {
            return new WP_Error('openai_http', $body ?: __('Unknown error', 'agentos'), ['status' => $code ?: 500]);
        }

        $clientSecret = $json['client_secret']['value'] ?? null;
        if (!$clientSecret) {
            return new WP_Error('openai_no_secret', __('Ephemeral token missing', 'agentos'), ['status' => 500]);
        }

        return [
            'client_secret' => $clientSecret,
            'id' => $json['id'] ?? null,
            'model' => $config['model'],
            'voice' => $config['voice'],
            'mode' => $config['mode'],
            'user_prompt' => $config['user_prompt'],
            'subscription' => $subscriptionSlug,
            'session_cap' => $sessionCap,
            'warnings' => $limitResult['warnings'] ?? [],
            'session_id' => $sessionId,
        ];
    }

    public function routeTranscriptSave(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $sessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $model = sanitize_text_field($request->get_param('model') ?: '');
        $voice = sanitize_text_field($request->get_param('voice') ?: '');
        $userAgent = $request->get_param('user_agent') ?: '';
        $transcript = $request->get_param('transcript');
        $subscriptionSlug = sanitize_key($request->get_param('subscription_slug') ?: '');
        $tokensRealtime = intval($request->get_param('tokens_realtime') ?: 0);
        $tokensText = intval($request->get_param('tokens_text') ?: 0);
        $tokensTotal = intval($request->get_param('tokens_total') ?: 0);
        $durationSeconds = intval($request->get_param('duration_seconds') ?: 0);

        $currentUser = wp_get_current_user();
        $userEmail = '';
        if ($currentUser && $currentUser instanceof \WP_User && $currentUser->exists()) {
            $userEmail = sanitize_email($currentUser->user_email);
        }

        $userKey = $this->resolveUserKey($anonId);

        if (!$postId || !$sessionId || !$agentId || !is_array($transcript)) {
            return new WP_Error('bad_payload', __('Missing fields', 'agentos'), ['status' => 400]);
        }

        $agent = $this->agents->get($agentId);
        if (!$agent) {
            return new WP_Error('invalid_agent', __('Agent not found', 'agentos'), ['status' => 404]);
        }

        $analysisEnabled = !empty($agent['analysis_enabled']);
        $analysisAuto = !empty($agent['analysis_auto_run']);
        $analysisModel = sanitize_text_field($agent['analysis_model'] ?? '');

        $id = $this->transcripts->insert([
            'post_id' => $postId,
            'agent_id' => $agentId,
            'session_id' => $sessionId,
            'anon_id' => $anonId,
            'model' => $model,
            'voice' => $voice,
            'user_email' => $userEmail,
            'user_agent' => maybe_serialize($userAgent),
            'transcript' => $transcript,
            'analysis_model' => $analysisModel,
            'user_key' => $userKey,
            'subscription_slug' => $subscriptionSlug,
            'tokens_realtime' => max(0, $tokensRealtime),
            'tokens_text' => max(0, $tokensText),
            'tokens_total' => max(0, $tokensTotal),
            'duration_seconds' => max(0, $durationSeconds),
        ]);

        if (!$id) {
            global $wpdb;
            $reason = $wpdb->last_error ? sanitize_text_field($wpdb->last_error) : __('Database insert failed', 'agentos');
            return new WP_Error('db_insert', $reason, ['status' => 500]);
        }

        $this->usage->logStart([
            'session_id' => $sessionId,
            'user_key' => $userKey,
            'subscription_slug' => $subscriptionSlug,
            'agent_id' => $agentId,
            'post_id' => $postId,
            'status' => $tokensTotal > 0 ? 'final' : 'pending',
            'metadata' => [
                'origin' => 'transcript_save',
            ],
        ]);

        $this->usage->updateBySession($sessionId, [
            'user_key' => $userKey,
            'subscription_slug' => $subscriptionSlug,
            'agent_id' => $agentId,
            'post_id' => $postId,
            'tokens_realtime' => $tokensRealtime,
            'tokens_text' => $tokensText,
            'tokens_total' => $tokensTotal,
            'duration_seconds' => $durationSeconds,
            'status' => 'final',
            'metadata' => [
                'transcript_saved' => true,
            ],
        ]);

        if ($analysisEnabled && $analysisAuto) {
            $this->analyzer->enqueue($id);
        }

        return ['id' => $id];
    }

    public function routeTranscriptList(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $limit = min(100, max(1, intval($request->get_param('limit') ?: 10)));
        $status = sanitize_key($request->get_param('status') ?: '');
        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $requestedEmail = sanitize_email($request->get_param('user_email') ?: '');

        if (!$postId) {
            return new WP_Error('no_post', __('post_id required', 'agentos'), ['status' => 400]);
        }

        $filters = [];

        if ($status) {
            $filters['status'] = $status;
        }

        if ($anonId) {
            $filters['anon_id'] = $anonId;
        }

        if (current_user_can('manage_options')) {
            if ($requestedEmail) {
                $filters['user_email'] = $requestedEmail;
            }
        } else {
            $currentUser = wp_get_current_user();
            if ($currentUser && $currentUser instanceof \WP_User && $currentUser->exists()) {
                $filters['user_email'] = sanitize_email($currentUser->user_email);
            } elseif ($requestedEmail) {
                // Non-admins cannot request arbitrary emails.
                return new WP_Error('rest_forbidden', __('Email filter not permitted.', 'agentos'), ['status' => 403]);
            }
        }

        $rows = $this->transcripts->list($postId, $agentId, $limit, $filters);

        if (current_user_can('manage_options')) {
            return $rows;
        }

        return array_map(static function ($row) {
            unset($row['user_email'], $row['user_agent'], $row['analysis_prompt']);
            return $row;
        }, $rows);
    }

    private function checkNonce(WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce) {
            $nonce = $request->get_param('_wpnonce');
        }

        if (!$nonce) {
            return false;
        }

        $nonce = sanitize_text_field($nonce);
        return (bool) wp_verify_nonce($nonce, 'wp_rest');
    }

    private function resolveUserKey(string $anonId): string
    {
        $currentUser = wp_get_current_user();
        if ($currentUser && $currentUser instanceof \WP_User && $currentUser->exists()) {
            return 'user_' . (int) $currentUser->ID;
        }

        return '';
    }

    public function routeUserSubscriptionsGet(WP_REST_Request $request)
    {
        $userKey = sanitize_text_field($request->get_param('user_key') ?: '');
        if (!$userKey) {
            $email = sanitize_email($request->get_param('email') ?: '');
            if ($email) {
                $user = get_user_by('email', $email);
                if ($user instanceof \WP_User && $user->exists()) {
                    $userKey = 'user_' . (int) $user->ID;
                }
            }
        }

        if (!$userKey) {
            return new WP_Error('missing_user_key', __('user_key or email parameter required.', 'agentos'), ['status' => 400]);
        }

        return $this->prepareUserSubscriptionResponse($userKey);
    }

    public function routeUserSubscriptionsSet(WP_REST_Request $request)
    {
        $userKey = sanitize_text_field($request->get_param('user_key') ?: '');
        if (!$userKey) {
            return new WP_Error('missing_user_key', __('user_key is required.', 'agentos'), ['status' => 400]);
        }

        $payload = $request->get_param('subscriptions');
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', __('subscriptions must be an array.', 'agentos'), ['status' => 400]);
        }

        $replace = !empty($request->get_param('replace'));
        $slugs = [];
        $plans = $this->subscriptions->all();

        $metaPayload = $request->get_param('meta');
        if (is_array($metaPayload)) {
            $this->userSubscriptions->ensureUser($userKey, $metaPayload);
            $this->userSubscriptions->saveUserMeta($userKey, $metaPayload);
        } else {
            $this->userSubscriptions->ensureUser($userKey, []);
        }

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = sanitize_key($item['slug'] ?? ($item['subscription_slug'] ?? ''));
            if (!$slug) {
                continue;
            }
            if (!isset($plans[$slug])) {
                continue;
            }

            $expiresAt = isset($item['expires_at']) ? sanitize_text_field($item['expires_at']) : null;
            $overrides = isset($item['overrides']) && is_array($item['overrides']) ? $item['overrides'] : [];

            $this->userSubscriptions->assign($userKey, $slug, $overrides, $expiresAt ?: null);
            $slugs[] = $slug;
        }

        if ($replace) {
            $existing = $this->userSubscriptions->get($userKey, true);
            foreach ($existing as $entry) {
                if (!in_array($entry['subscription_slug'], $slugs, true)) {
                    $this->userSubscriptions->remove($userKey, $entry['subscription_slug']);
                }
            }
        }

        return $this->prepareUserSubscriptionResponse($userKey);
    }

    private function prepareUserSubscriptionResponse(string $userKey): array
    {
        $assignments = $this->userSubscriptions->get($userKey, true);
        $plans = $this->subscriptions->all();
        $subscriptions = [];
        $meta = $this->userSubscriptions->getUserMeta();

        foreach ($assignments as $entry) {
            $slug = $entry['subscription_slug'];
            $plan = $plans[$slug] ?? null;
            $periodHours = $plan['period_hours'] ?? 24;
            $usage = $this->usage->summarizeUsage($slug, $userKey, $periodHours);

            $subscriptions[] = [
                'subscription_slug' => $slug,
                'label' => $plan['label'] ?? $slug,
                'assigned_at' => $entry['assigned_at'] ?? '',
                'expires_at' => $entry['expires_at'] ?? null,
                'overrides' => $entry['overrides'] ?? [],
                'limits' => $plan['limits'] ?? [],
                'period_hours' => $periodHours,
                'session_token_cap' => $plan['session_token_cap'] ?? 0,
                'usage' => $usage,
            ];
        }

        return [
            'user_key' => $userKey,
            'meta' => $meta[$userKey] ?? [],
            'subscriptions' => $subscriptions,
        ];
    }

    public function routeUsageUpdate(WP_REST_Request $request)
    {
        $sessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        if (!$sessionId) {
            return new WP_Error('missing_session', __('session_id required.', 'agentos'), ['status' => 400]);
        }

        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $postId = intval($request->get_param('post_id') ?: 0);
        $subscriptionSlug = sanitize_key($request->get_param('subscription_slug') ?: '');
        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $userKey = $this->resolveUserKey($anonId);
        if (!$userKey) {
            $userKey = sanitize_text_field($request->get_param('user_key') ?: '');
        }

        $tokensRealtime = max(0, intval($request->get_param('tokens_realtime') ?: 0));
        $tokensText = max(0, intval($request->get_param('tokens_text') ?: 0));
        $tokensTotal = max($tokensRealtime + $tokensText, intval($request->get_param('tokens_total') ?: 0));
        $durationSeconds = max(0, intval($request->get_param('duration_seconds') ?: 0));
        $status = sanitize_key($request->get_param('status') ?: 'final');
        $mode = sanitize_key($request->get_param('mode') ?: '');
        $reason = sanitize_key($request->get_param('reason') ?: '');

        if ($userKey) {
            $this->userSubscriptions->ensureUser($userKey, []);
        }

        $this->usage->logStart([
            'session_id' => $sessionId,
            'user_key' => $userKey,
            'subscription_slug' => $subscriptionSlug,
            'agent_id' => $agentId,
            'post_id' => $postId,
            'status' => $status,
            'metadata' => [
                'origin' => 'session_update',
            ],
        ]);

        $this->usage->updateBySession($sessionId, [
            'user_key' => $userKey,
            'subscription_slug' => $subscriptionSlug,
            'agent_id' => $agentId,
            'post_id' => $postId,
            'tokens_realtime' => $tokensRealtime,
            'tokens_text' => $tokensText,
            'tokens_total' => $tokensTotal,
            'duration_seconds' => $durationSeconds,
            'status' => $status,
            'metadata' => [
                'mode' => $mode,
                'reason' => $reason,
            ],
        ]);

        return [
            'session_id' => $sessionId,
            'tokens' => [
                'realtime' => $tokensRealtime,
                'text' => $tokensText,
                'total' => $tokensTotal,
            ],
        ];
    }
}
