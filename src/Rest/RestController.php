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
use AgentOS\Mcp\SessionBroker;
use AgentOS\Mcp\TextToolFlow;
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
    private ?SessionBroker $mcpBroker = null;

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
        register_rest_route('agentos/v1', '/mcp-call', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => [$this, 'permissionNonce'],
            'callback' => [$this, 'routeMcpCall'],
        ]);
        register_rest_route('agentos/v1', '/mcp-session', [
            'methods' => WP_REST_Server::DELETABLE,
            'permission_callback' => [$this, 'permissionNonce'],
            'callback' => [$this, 'routeMcpSessionClose'],
        ]);
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
            '/text-response',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionNonce'],
                'callback' => [$this, 'routeTextResponse'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/text-transcription',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionNonce'],
                'callback' => [$this, 'routeTextTranscription'],
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
            '/usage/event',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionNonce'],
                'callback' => [$this, 'routeUsageEvent'],
            ]
        );
        register_rest_route('agentos/v1', '/usage/report', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => [$this, 'permissionNonce'],
            'callback' => [$this, 'routeUsageReport'],
        ]);

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

        register_rest_route(
            'agentos/v1',
            '/provision/user',
            [
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => [$this, 'permissionIntegrationApi'],
                'callback' => [$this, 'routeProvisionUserGet'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/provision/user',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionIntegrationApi'],
                'callback' => [$this, 'routeProvisionUserUpsert'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/provision/user',
            [
                'methods' => WP_REST_Server::DELETABLE,
                'permission_callback' => [$this, 'permissionIntegrationApi'],
                'callback' => [$this, 'routeProvisionUserDelete'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/provision/user/subscriptions',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [$this, 'permissionIntegrationApi'],
                'callback' => [$this, 'routeProvisionUserSubscriptionsSet'],
            ]
        );

        register_rest_route(
            'agentos/v1',
            '/provision/user/subscriptions',
            [
                'methods' => WP_REST_Server::DELETABLE,
                'permission_callback' => [$this, 'permissionIntegrationApi'],
                'callback' => [$this, 'routeProvisionUserSubscriptionsDelete'],
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

        $requestedEmail = sanitize_email($request->get_param('user_email') ?? '');
        $currentUser = wp_get_current_user();
        $currentEmail = ($currentUser && $currentUser instanceof \WP_User && $currentUser->exists()) ? sanitize_email($currentUser->user_email) : '';

        if ($currentEmail) {
            if ($requestedEmail && $requestedEmail !== $currentEmail) {
                return new WP_Error(
                    'rest_forbidden',
                    __('You do not have permission to view transcripts for that user.', 'agentos'),
                    [
                        'status' => 403,
                    ]
                );
            }

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

    public function permissionIntegrationApi(WP_REST_Request $request)
    {
        if (!$this->settings->isIntegrationApiEnabled()) {
            return new WP_Error(
                'rest_forbidden',
                __('Integration API is disabled.', 'agentos'),
                ['status' => 403]
            );
        }

        $token = $this->extractIntegrationApiToken($request);
        if ($token !== '' && $this->settings->verifyIntegrationApiKey($token)) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __('Invalid or missing integration API key.', 'agentos'),
            ['status' => 403]
        );
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

        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $userKey = $this->resolveUserKey($anonId);
        $apiKey = $this->settings->resolveApiKey();
        if (!$apiKey) {
            return new WP_Error('no_key', __('OpenAI key not configured', 'agentos'), ['status' => 500]);
        }

        $clientSessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        $sessionTicket = sanitize_text_field($request->get_param('session_ticket') ?: '');
        $session = $clientSessionId !== '' ? $this->usage->getSession($clientSessionId) : null;
        $isNewSession = !$session;
        if ($session) {
            if (!$this->usage->validateSession($clientSessionId, $sessionTicket, $userKey, $agentId, $postId)) {
                return new WP_Error('usage_session_forbidden', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
            }
            $sessionId = $clientSessionId;
            $limitResult = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'realtime']);
        } else {
            $sessionId = wp_generate_password(32, false, false);
            $sessionTicket = wp_generate_password(48, false, false);
            if ($userKey) {
                $this->userSubscriptions->ensureUser($userKey, []);
            }
            $limitResult = $this->limiter->startSession($userKey, $agent, [
                'session_id' => $sessionId,
                'agent_id' => $agentId,
                'post_id' => $postId,
                'status' => 'pending',
                'metadata' => ['origin' => 'realtime_token', 'mode' => 'realtime'],
            ], $sessionTicket);
            $session = $this->usage->getSession($sessionId);
        }
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

        $ctxIn = $request->get_param('ctx');
        $ctxPairs = [];
        $contextData = [];

        if (is_array($ctxIn)) {
            $settings = $this->settings->get();
            $allowed = !empty($agent['context_params']) ? (array) $agent['context_params'] : (array) ($settings['context_params'] ?? []);
            foreach ($ctxIn as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    continue;
                }
                $cleanKey = preg_replace('/[^a-z0-9_\-]/i', '', $key);
                $cleanValue = sanitize_text_field($value);
                if ($cleanKey && $cleanValue !== '') {
                    $ctxPairs[] = "{$cleanKey}={$cleanValue}";
                    $contextData[$cleanKey] = $cleanValue;
                }
            }
        }

        $instructions = $config['instructions'] ?: Config::FALLBACK_PROMPT;
        if ($ctxPairs) {
            $instructions .= "\n\nContext: " . implode(', ', $ctxPairs) . '.';
        }

        $noiseReduction = sanitize_key($config['voice_noise_reduction'] ?? '');
        if (!in_array($noiseReduction, ['near_field', 'far_field', 'off'], true)) {
            $noiseReduction = 'near_field';
        }

        $turnDetectionType = sanitize_key($config['voice_turn_detection'] ?? '');
        $turnEagerness = sanitize_key($config['voice_turn_eagerness'] ?? '');
        if (!in_array($turnDetectionType, ['semantic_vad', 'server_vad'], true)) {
            $turnProfile = sanitize_key($config['voice_turn_profile'] ?? '');
            if ($turnProfile === 'fast') {
                $turnDetectionType = 'server_vad';
            } else {
                $turnDetectionType = 'semantic_vad';
            }
        }
        if (!in_array($turnEagerness, ['low', 'medium', 'high'], true)) {
            $turnProfile = sanitize_key($config['voice_turn_profile'] ?? '');
            if ($turnProfile === 'conservative') {
                $turnEagerness = 'low';
            } elseif ($turnProfile === 'balanced') {
                $turnEagerness = 'medium';
            } else {
                $turnEagerness = 'medium';
            }
        }

        $turnDetection = [
            'type' => $turnDetectionType,
            'create_response' => true,
            'interrupt_response' => true,
        ];
        if ($turnDetectionType === 'semantic_vad') {
            $turnDetection['eagerness'] = $turnEagerness;
        } else {
            $turnDetection['threshold'] = 0.6;
            $turnDetection['prefix_padding_ms'] = 300;
            $turnDetection['silence_duration_ms'] = 700;
        }

        $speechLanguageHint = sanitize_key($config['speech_language_hint'] ?? '');
        $transcriptionHint = sanitize_textarea_field($config['transcription_hint'] ?? '');
        $audioInput = [
            'transcription' => Config::realtimeTranscriptionPayload($speechLanguageHint, $transcriptionHint, (string) ($config['transcription_delay'] ?? '')),
            'noise_reduction' => $noiseReduction === 'off'
                ? null
                : ['type' => $noiseReduction],
            'turn_detection' => $turnDetection,
        ];

        $sessionPayload = [
            'type' => 'realtime',
            'model' => $config['model'],
            'instructions' => $instructions,
            'output_modalities' => ['audio'],
            'audio' => [
                'input' => $audioInput,
                'output' => [
                    'voice' => $config['voice'],
                ],
            ],
        ];
        // Bound the maximum single-response overrun even when the plan is unlimited.
        $sessionPayload['max_output_tokens'] = 2048;

        $realtimeBudget = $this->realtimeTokenBudget($limitResult, $agent);
        if ($realtimeBudget !== null) {
            if ($realtimeBudget < 1) {
                if ($isNewSession) {
                    $this->usage->releaseEmptySession($sessionId);
                }
                return new WP_Error('subscription_limit', __('The remaining voice token budget is too small to start a response.', 'agentos'), ['status' => 402]);
            }
            $sessionPayload['max_output_tokens'] = min(2048, $realtimeBudget);
        }

        $toolGrant = $this->mcp()->issue($agent, $agentId, $postId, $sessionId);
        if (is_wp_error($toolGrant)) {
            if ($isNewSession) {
                $this->usage->releaseEmptySession($sessionId);
            }
            return $toolGrant;
        }
        if ($toolGrant['tools']) {
            $sessionPayload['tools'] = array_map(static function ($tool) {
                unset($tool['strict']);
                return $tool;
            }, $toolGrant['tools']);
            $sessionPayload['tool_choice'] = 'auto';
            $sessionPayload['instructions'] .= "\n\n" . $toolGrant['instructions'];
        }

        $this->usage->setSessionMetadata($sessionId, [
            'origin' => 'realtime_token',
            'mode' => 'realtime',
            'realtime_control_required' => !empty($limitResult['realtime_control_required']),
            'realtime_session_payload' => $sessionPayload,
        ]);

        $response = wp_remote_post(
            'https://api.openai.com/v1/realtime/client_secrets',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'session' => $sessionPayload,
                ]),
                'timeout' => 25,
            ]
        );

        if (is_wp_error($response)) {
            $this->mcp()->revoke($toolGrant['ticket']);
            if ($isNewSession) {
                $this->usage->releaseEmptySession($sessionId);
            }
            return new WP_Error('openai_transport', $response->get_error_message(), ['status' => 500]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($code >= 400 || !is_array($json)) {
            $this->mcp()->revoke($toolGrant['ticket']);
            if ($isNewSession) {
                $this->usage->releaseEmptySession($sessionId);
            }
            return new WP_Error('openai_http', $body ?: __('Unknown error', 'agentos'), ['status' => $code ?: 500]);
        }

        $clientSecret = $json['value']
            ?? ($json['client_secret']['value'] ?? null)
            ?? ($json['session']['client_secret']['value'] ?? null);
        if (!$clientSecret) {
            $this->mcp()->revoke($toolGrant['ticket']);
            if ($isNewSession) {
                $this->usage->releaseEmptySession($sessionId);
            }
            return new WP_Error('openai_no_secret', __('Ephemeral token missing', 'agentos'), ['status' => 500]);
        }

        return [
            'client_secret' => $clientSecret,
            'tool_ticket' => $toolGrant['ticket'],
            'tool_manifest' => $toolGrant['bindings'],
            'id' => $json['session']['id'] ?? ($json['id'] ?? null),
            'model' => $config['model'],
            'voice' => $config['voice'],
            'mode' => $config['mode'],
            'user_prompt' => $config['user_prompt'],
            'subscription' => $subscriptionSlug,
            'session_cap' => $sessionCap,
            'warnings' => $limitResult['warnings'] ?? [],
            'session_id' => $sessionId,
            'session_ticket' => $sessionTicket,
            'realtime_control' => 'browser_reported',
            'realtime_budget' => $realtimeBudget,
            'remaining' => $limitResult['remaining'] ?? [],
            'context' => $contextData,
        ];
    }

    private function realtimeTokenBudget(array $decision, array $agent): ?int
    {
        $plan = is_array($decision['plan'] ?? null) ? $decision['plan'] : [];
        $limits = $plan['limits'] ?? [];
        $candidates = [];
        if (!empty($plan['block_on_overage']) && (int) ($limits['realtime_tokens'] ?? 0) > 0) {
            $candidates[] = max(0, (int) ($decision['remaining']['realtime_tokens'] ?? 0));
        }
        if ((int) ($decision['session_cap'] ?? ($agent['session_token_cap'] ?? 0)) > 0) {
            $candidates[] = max(0, (int) ($decision['remaining']['session_tokens'] ?? 0));
        }
        return $candidates ? min($candidates) : null;
    }

    public function routeTextResponse(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $clientSessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        $sessionTicket = sanitize_text_field($request->get_param('session_ticket') ?: '');
        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $message = sanitize_textarea_field($request->get_param('message') ?: '');
        $transcript = $request->get_param('transcript');

        if (!$postId) {
            return new WP_Error('no_post', __('post_id required', 'agentos'), ['status' => 400]);
        }

        if (!$agentId) {
            return new WP_Error('no_agent', __('agent_id required', 'agentos'), ['status' => 400]);
        }

        if ($message === '') {
            return new WP_Error('no_message', __('message required', 'agentos'), ['status' => 400]);
        }

        if (!is_array($transcript)) {
            return new WP_Error('bad_payload', __('transcript must be an array.', 'agentos'), ['status' => 400]);
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
        $userKey = $this->resolveUserKey($anonId);
        $apiKey = $this->settings->resolveApiKey();
        if (!$apiKey) {
            return new WP_Error('no_key', __('OpenAI key not configured', 'agentos'), ['status' => 500]);
        }

        if ($userKey) {
            $this->userSubscriptions->ensureUser($userKey, []);
        }

        $isNewSession = false;
        $session = $clientSessionId !== '' ? $this->usage->getSession($clientSessionId) : null;
        if ($session) {
            if (!$this->usage->validateSession($clientSessionId, $sessionTicket, $userKey, $agentId, $postId)) {
                return new WP_Error('usage_session_forbidden', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
            }
            $sessionId = $clientSessionId;
            $limitResult = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'text']);
            if (empty($limitResult['allowed'])) {
                return new WP_Error($limitResult['error_code'] ?? 'subscription_limit', $limitResult['message'] ?? __('Usage limit reached for this subscription.', 'agentos'), ['status' => (int) ($limitResult['status'] ?? 402)]);
            }
        } else {
            $isNewSession = true;
            $sessionId = wp_generate_password(32, false, false);
            $sessionTicket = wp_generate_password(48, false, false);
            $limitResult = $this->limiter->startSession($userKey, $agent, [
                'session_id' => $sessionId,
                'agent_id' => $agentId,
                'post_id' => $postId,
                'status' => 'running',
                'metadata' => ['origin' => 'text_response', 'mode' => 'text'],
            ], $sessionTicket);
            if (empty($limitResult['allowed'])) {
                return new WP_Error($limitResult['error_code'] ?? 'subscription_limit', $limitResult['message'] ?? __('Usage limit reached for this subscription.', 'agentos'), ['status' => (int) ($limitResult['status'] ?? 402)]);
            }
        }

        $subscriptionSlug = (string) ($session['subscription_slug'] ?? $limitResult['subscription_slug'] ?? '');
        $sessionCap = isset($limitResult['session_cap']) ? (int) $limitResult['session_cap'] : 0;

        $settings = $this->settings->get();
        $allowedContext = !empty($agent['context_params']) ? (array) $agent['context_params'] : (array) ($settings['context_params'] ?? []);
        $contextData = $this->sanitizeContextPayload($request->get_param('ctx'), $allowedContext);
        $instructions = $this->buildInstructions($config['instructions'] ?? '', $contextData);
        $input = $this->buildResponsesInput($transcript, (string) ($config['user_prompt'] ?? ''));
        $textModel = sanitize_text_field($config['text_model'] ?? Config::FALLBACK_TEXT_MODEL) ?: Config::FALLBACK_TEXT_MODEL;

        $payload = [
            'model' => $textModel,
            'instructions' => $instructions,
            'input' => $input,
        ];

        $continuation = (string) ($request->get_param('tool_continuation') ?? '');
        $ticket = (string) ($request->get_param('tool_ticket') ?? '');
        if ($continuation !== '') {
            $grant = $this->mcp()->validate($ticket, $agentId, $postId, $sessionId);
            if (is_wp_error($grant)) {
                return $grant;
            }
        } else {
            $toolGrant = $this->mcp()->issue($agent, $agentId, $postId, $sessionId);
            if (is_wp_error($toolGrant)) {
                if ($isNewSession) {
                    $this->usage->releaseEmptySession($sessionId);
                }
                return $toolGrant;
            }
            $ticket = $toolGrant['ticket'];
            if ($toolGrant['tools']) {
                $payload['tools'] = $toolGrant['tools'];
                $payload['tool_choice'] = 'auto';
                $payload['instructions'] .= "\n\n" . $toolGrant['instructions'];
            }
        }

        $plan = $limitResult['plan'] ?? null;
        $planTextCap = is_array($plan) ? (int) ($plan['limits']['text_tokens'] ?? 0) : 0;
        $agentTextCap = max(0, (int) ($agent['session_token_cap'] ?? 0));
        $strictTextBudget = $agentTextCap > 0 || (!empty($plan['block_on_overage']) && ($planTextCap > 0 || (int) ($plan['session_token_cap'] ?? 0) > 0));
        $beforeRequest = function (array $requestPayload) use ($strictTextBudget, $apiKey, $userKey, $agent, $sessionId, $subscriptionSlug, $sessionCap, $plan, $textModel, $postId) {
            if (!$strictTextBudget) {
                return ['payload' => $requestPayload];
            }

            $inputTokens = $this->countResponsesInputTokens($requestPayload, $apiKey);
            if (is_wp_error($inputTokens)) {
                return $inputTokens;
            }

            $lockScope = $userKey !== '' ? 'user:' . $userKey : 'guest:' . sanitize_key($agent['slug'] ?? '') . ':' . $postId;
            $reserved = $this->usage->withUsageLock($lockScope, function () use ($requestPayload, $inputTokens, $userKey, $agent, $sessionId, $subscriptionSlug, $sessionCap, $plan, $textModel) {
                $fresh = $this->limiter->evaluate($userKey, $agent, [
                    'session_id' => $sessionId,
                    'mode' => 'text',
                    'reserve_text_tokens' => $inputTokens,
                    'reserve_tokens' => $inputTokens,
                ]);
                if (empty($fresh['allowed'])) {
                    return new WP_Error($fresh['error_code'] ?? 'subscription_limit', $fresh['message'] ?? __('Usage limit reached for this subscription.', 'agentos'), ['status' => (int) ($fresh['status'] ?? 402)]);
                }

                $available = [];
                if (!empty($plan['block_on_overage']) && (int) ($plan['limits']['text_tokens'] ?? 0) > 0) {
                    $available[] = max(0, (int) ($fresh['remaining']['text_tokens'] ?? 0));
                }
                if ($sessionCap > 0) {
                    $available[] = max(0, (int) ($fresh['remaining']['session_tokens'] ?? 0));
                }
                if (!$available) {
                    return new WP_Error('usage_budget_unknown', __('This session has a finite token cap but its remaining budget could not be calculated.', 'agentos'), ['status' => 503]);
                }
                $budget = min($available);
                $outputLimit = min(2048, $budget - (int) $inputTokens);
                if ($outputLimit < Config::RESPONSES_MIN_OUTPUT_TOKENS) {
                    return new WP_Error('subscription_limit', __('At least 16 output tokens must remain for another Responses API request.', 'agentos'), ['status' => 402]);
                }

                $requestPayload['max_output_tokens'] = $outputLimit;
                $reservationId = wp_generate_password(40, false, false);
                $periodHours = max(1, (int) ($plan['period_hours'] ?? 24));
                if (!$this->usage->reserveBudget($reservationId, $sessionId, $userKey, $subscriptionSlug, 'text', (int) $inputTokens + $outputLimit, $periodHours)) {
                    return new WP_Error('usage_reservation_failed', __('Unable to reserve token budget for this response.', 'agentos'), ['status' => 503]);
                }
                return ['payload' => $requestPayload, 'reservation_id' => $reservationId];
            });

            if ($reserved === false) {
                return new WP_Error('usage_busy', __('Usage is being updated. Please retry in a moment.', 'agentos'), ['status' => 503]);
            }
            return $reserved;
        };
        $onUsage = function (string $reservationId, ?array $usageEvent, bool $unknown) use ($sessionId, $textModel) {
            if ($usageEvent === null) {
                if ($reservationId !== '') {
                    $this->usage->releaseBudget($reservationId, $unknown);
                }
                return true;
            }
            $usage = [
                'input_tokens' => (int) ($usageEvent['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usageEvent['output_tokens'] ?? 0),
                'total_tokens' => (int) ($usageEvent['total_tokens'] ?? 0),
            ];
            $metadata = [
                'response_id' => (string) ($usageEvent['event_key'] ?? ''),
                'cached_input_tokens' => (int) ($usageEvent['cached_input_tokens'] ?? 0),
                'status' => (string) ($usageEvent['status'] ?? 'completed'),
                'model' => $textModel,
            ];
            $eventKey = (string) ($usageEvent['event_key'] ?? '');
            $stored = $reservationId !== ''
                ? $this->usage->settleBudget($reservationId, $sessionId, $eventKey, 'text', $usage, 'server', $metadata)
                : $this->usage->recordEvent($sessionId, $eventKey, 'text', $usage, 'server', $metadata);
            if (!$stored) {
                if ($reservationId !== '') {
                    $this->usage->releaseBudget($reservationId, true);
                }
                $this->usage->updateBySession($sessionId, ['status' => 'usage_error']);
            }
            return $stored;
        };

        $flow = (new TextToolFlow($this->mcp()))->run($payload, $apiKey, $ticket, $continuation, $beforeRequest, $onUsage);
        if (is_wp_error($flow)) {
            $errorCode = $flow->get_error_code();
            $errorData = $flow->get_error_data();
            $providerStatus = is_array($errorData) ? (int) ($errorData['provider_status'] ?? 0) : 0;
            $definiteNoUsage = in_array($errorCode, ['usage_preflight_failed', 'usage_budget_unknown', 'usage_reservation_failed', 'usage_busy', 'subscription_limit'], true)
                || ($errorCode === 'openai_http' && $providerStatus >= 400 && $providerStatus < 500);
            if ($isNewSession && $definiteNoUsage) {
                $this->mcp()->revoke($ticket);
                $this->usage->releaseEmptySession($sessionId);
                return $flow;
            }
            $data = is_array($errorData) ? $errorData : [];
            $data['session_id'] = $sessionId;
            $data['session_ticket'] = $sessionTicket;
            return new WP_Error($errorCode, $flow->get_error_message(), $data);
        }
        $json = $flow['response'];

        $assistantText = $this->extractResponsesText($json);
        if ($assistantText === '' && !$flow['tool_calls']) {
            return new WP_Error('openai_empty', __('The model returned an empty response.', 'agentos'), ['status' => 502]);
        }

        $usage = $flow['usage'];
        $requestTokens = max(0, (int) ($usage['total_tokens'] ?? 0));
        $sessionUsage = $this->usage->sessionUsage($sessionId);

        $this->usage->updateBySession($sessionId, [
            'status' => 'running',
            'metadata' => [
                'mode' => 'text',
                'origin' => 'text_response',
                'input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
                'session_ticket_verified' => true,
            ],
        ]);

        return [
            'text' => $assistantText,
            'tool_ticket' => $ticket,
            'tool_calls' => $flow['tool_calls'],
            'tool_continuation' => $flow['continuation'],
            'model' => $textModel,
            'session_id' => $sessionId,
            'session_ticket' => $sessionTicket,
            'subscription' => $subscriptionSlug,
            'session_cap' => $sessionCap,
            'warnings' => $limitResult['warnings'] ?? [],
            'context' => $contextData,
            'usage' => [
                'input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
                'total_tokens' => $requestTokens,
                'session_text_tokens' => (int) ($sessionUsage['tokens_text'] ?? 0) + (int) ($sessionUsage['tokens_analysis'] ?? 0),
                'session_total_tokens' => (int) ($sessionUsage['tokens_total'] ?? 0),
            ],
        ];
    }

    private function mcp(): SessionBroker
    {
        if ($this->mcpBroker === null) {
            $this->mcpBroker = new SessionBroker();
        }
        return $this->mcpBroker;
    }

    public function routeMcpCall(WP_REST_Request $request)
    {
        $context = $this->mcpRequestContext($request);
        if (is_wp_error($context)) {
            return $context;
        }
        $raw = $request->get_param('arguments');
        if (!is_string($raw) || strlen($raw) > 32768) {
            return new WP_Error('mcp_arguments', __('Tool arguments must be a JSON object.', 'agentos'), ['status' => 400]);
        }
        $arguments = json_decode($raw);
        if (!$arguments instanceof \stdClass) {
            return new WP_Error('mcp_arguments', __('Tool arguments must be a JSON object.', 'agentos'), ['status' => 400]);
        }
        $decision = $request->get_param('approve');
        if ($decision !== null && !is_bool($decision)) {
            return new WP_Error('mcp_approval', __('Invalid approval decision.', 'agentos'), ['status' => 400]);
        }
        return $this->mcp()->invoke(
            $context['agent'], (string) $request->get_param('tool_ticket'),
            $context['agent_id'], $context['post_id'], $context['session_id'],
            (string) $request->get_param('call_id'), (string) $request->get_param('name'),
            (array) $arguments, $decision, (string) $request->get_param('approval_id')
        );
    }

    public function routeMcpSessionClose(WP_REST_Request $request)
    {
        $ticket = (string) $request->get_param('tool_ticket');
        $agentId = sanitize_key((string) $request->get_param('agent_id'));
        $postId = (int) $request->get_param('post_id');
        $sessionId = sanitize_text_field((string) $request->get_param('session_id'));
        $userKey = $this->resolveUserKey(sanitize_text_field((string) $request->get_param('anon_id')));
        $sessionTicket = sanitize_text_field((string) $request->get_param('session_ticket'));
        if (!$this->usage->validateSession($sessionId, $sessionTicket, $userKey, $agentId, $postId)) {
            return new WP_Error('usage_session_forbidden', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
        }
        $grant = $this->mcp()->validate($ticket, $agentId, $postId, $sessionId);
        if (is_wp_error($grant)) {
            return $grant;
        }
        $this->mcp()->revoke($ticket);
        return ['closed' => true];
    }

    private function mcpRequestContext(WP_REST_Request $request)
    {
        $agentId = sanitize_key($request->get_param('agent_id') ?? '');
        $postId = (int) $request->get_param('post_id');
        $sessionId = sanitize_text_field($request->get_param('session_id') ?? '');
        $agent = $this->agents->get($agentId);
        $post = get_post($postId);
        if (!$agent || !$post || $post->post_status === 'trash' || $sessionId === '') {
            return new WP_Error('mcp_context', __('This conversation is unavailable.', 'agentos'), ['status' => 403]);
        }
        if (post_password_required($post) || (!current_user_can('read_post', $postId) && !is_post_publicly_viewable($post))
            || (!empty($agent['post_types']) && !in_array(get_post_type($postId), $agent['post_types'], true))) {
            return new WP_Error('mcp_context', __('You do not have access to this agent or content.', 'agentos'), ['status' => 403]);
        }
        $userKey = $this->resolveUserKey(sanitize_text_field($request->get_param('anon_id') ?? ''));
        if (!$this->usage->validateSession(
            $sessionId,
            sanitize_text_field($request->get_param('session_ticket') ?? ''),
            $userKey,
            $agentId,
            $postId
        )) {
            return new WP_Error('mcp_context', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
        }
        $limits = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'mcp']);
        if (empty($limits['allowed'])) {
            return new WP_Error('mcp_access', __('Tool access is unavailable for this subscription.', 'agentos'), ['status' => 403]);
        }
        return ['agent' => $agent, 'agent_id' => $agentId, 'post_id' => $postId, 'session_id' => $sessionId];
    }

    public function routeTextTranscription(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $language = sanitize_text_field($request->get_param('language') ?: '');

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

        $userKey = $this->resolveUserKey($anonId);
        $files = $request->get_file_params();
        $audio = $files['audio'] ?? null;
        if (!is_array($audio) || empty($audio['tmp_name']) || !is_uploaded_file($audio['tmp_name'])) {
            return new WP_Error('missing_audio', __('An audio file upload is required.', 'agentos'), ['status' => 400]);
        }

        $apiKey = $this->settings->resolveApiKey();
        if (!$apiKey) {
            return new WP_Error('no_key', __('OpenAI key not configured', 'agentos'), ['status' => 500]);
        }

        $clientSessionId = sanitize_text_field((string) $request->get_param('session_id'));
        $sessionTicket = sanitize_text_field((string) $request->get_param('session_ticket'));
        $session = $clientSessionId !== '' ? $this->usage->getSession($clientSessionId) : null;
        $isNewSession = !$session;
        if ($session) {
            if (!$this->usage->validateSession($clientSessionId, $sessionTicket, $userKey, $agentId, $postId)) {
                return new WP_Error('usage_session_forbidden', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
            }
            $sessionId = $clientSessionId;
            $limitResult = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'transcription']);
        } else {
            $sessionId = wp_generate_password(32, false, false);
            $sessionTicket = wp_generate_password(48, false, false);
            if ($userKey) {
                $this->userSubscriptions->ensureUser($userKey, []);
            }
            $limitResult = $this->limiter->startSession($userKey, $agent, [
                'session_id' => $sessionId,
                'agent_id' => $agentId,
                'post_id' => $postId,
                'status' => 'pending',
                'metadata' => ['origin' => 'text_transcription', 'mode' => 'text'],
            ], $sessionTicket);
        }
        if (empty($limitResult['allowed'])) {
            $status = isset($limitResult['status']) ? (int) $limitResult['status'] : 402;
            $messageText = $limitResult['message'] ?? __('Usage limit reached for this subscription.', 'agentos');
            $code = $limitResult['error_code'] ?? 'subscription_limit';
            return new WP_Error($code, $messageText, ['status' => $status]);
        }

        $lockScope = $userKey !== '' ? 'user:' . $userKey : 'guest:' . $agentId . ':' . $postId;
        $reservation = $this->usage->withUsageLock($lockScope, function () use ($userKey, $agent, $sessionId, $session, $limitResult) {
            $fresh = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'transcription']);
            if (empty($fresh['allowed'])) {
                return false;
            }
            $budget = $this->realtimeTokenBudget($fresh, $agent);
            if ($budget === null) {
                return ['reservation_id' => '', 'decision' => $fresh];
            }
            if ($budget < 1) {
                return false;
            }
            $reservationId = wp_generate_password(48, false, false);
            $plan = is_array($fresh['plan'] ?? null) ? $fresh['plan'] : [];
            $slug = (string) ($session['subscription_slug'] ?? $fresh['subscription_slug'] ?? $limitResult['subscription_slug'] ?? '');
            if (!$this->usage->reserveBudget($reservationId, $sessionId, $userKey, $slug, 'realtime', $budget, max(1, (int) ($plan['period_hours'] ?? 24)))) {
                return false;
            }
            return ['reservation_id' => $reservationId, 'decision' => $fresh];
        });
        if ($reservation === false) {
            if ($isNewSession) {
                $this->usage->releaseEmptySession($sessionId);
            }
            return new WP_Error('subscription_limit', __('Unable to reserve the current audio transcription budget.', 'agentos'), ['status' => 402]);
        }
        $reservationId = (string) ($reservation['reservation_id'] ?? '');
        $limitResult = $reservation['decision'] ?? $limitResult;

        $requestId = 'transcription_' . wp_generate_password(32, false, false);
        $response = $this->requestAudioTranscription($audio, $apiKey, $language, $requestId);
        if (is_wp_error($response)) {
            $errorData = $response->get_error_data();
            $providerStatus = is_array($errorData) ? (int) ($errorData['status'] ?? 0) : 0;
            if ($reservationId !== '') {
                $this->usage->releaseBudget($reservationId, $providerStatus === 0 || $providerStatus >= 500);
            } elseif ($response->get_error_code() === 'openai_transport') {
                $this->usage->updateBySession($sessionId, ['status' => 'usage_error']);
            }
            if ($isNewSession && $providerStatus >= 400 && $providerStatus < 500) {
                $this->usage->releaseEmptySession($sessionId);
                return $response;
            }
            if ($providerStatus === 0 || $providerStatus >= 500 || $response->get_error_code() === 'openai_transport') {
                $data = is_array($errorData) ? $errorData : [];
                $data['session_id'] = $sessionId;
                $data['session_ticket'] = $sessionTicket;
                return new WP_Error($response->get_error_code(), $response->get_error_message(), $data);
            }
            return $response;
        }

        $providerUsage = is_array($response['usage'] ?? null) ? $response['usage'] : null;
        if (!$providerUsage) {
            if ($reservationId !== '') {
                $this->usage->releaseBudget($reservationId, true);
            }
            $this->usage->updateBySession($sessionId, ['status' => 'usage_error']);
            return new WP_Error('transcription_usage_missing', __('The transcription service did not return usage data. The result was withheld so this request is not silently treated as free.', 'agentos'), ['status' => 502, 'session_id' => $sessionId, 'session_ticket' => $sessionTicket]);
        }
        $eventKey = sanitize_text_field((string) ($response['request_id'] ?? $requestId)) ?: $requestId;
        $usageData = ['total_tokens' => 0];
        $usageType = sanitize_key((string) ($providerUsage['type'] ?? ''));
        if ($usageType === 'tokens') {
            $usageData = [
                'input_tokens' => max(0, (int) ($providerUsage['input_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($providerUsage['output_tokens'] ?? 0)),
                'total_tokens' => max(0, (int) ($providerUsage['total_tokens'] ?? ((int) ($providerUsage['input_tokens'] ?? 0) + (int) ($providerUsage['output_tokens'] ?? 0)))),
            ];
        } elseif ($usageType === 'duration') {
            $usageData['transcription_seconds'] = max(0, (float) ($providerUsage['seconds'] ?? 0));
        } else {
            if ($reservationId !== '') {
                $this->usage->releaseBudget($reservationId, true);
            }
            $this->usage->updateBySession($sessionId, ['status' => 'usage_error']);
            return new WP_Error('transcription_usage_invalid', __('The transcription service returned an unsupported usage format.', 'agentos'), ['status' => 502, 'session_id' => $sessionId, 'session_ticket' => $sessionTicket]);
        }
        $eventMetadata = ['model' => Config::FALLBACK_TRANSCRIBE_MODEL, 'usage_type' => $usageType, 'request_id' => $eventKey];
        $stored = $reservationId !== ''
            ? $this->usage->settleBudget($reservationId, $sessionId, $eventKey, 'transcription', $usageData, 'server', $eventMetadata)
            : $this->usage->recordEvent($sessionId, $eventKey, 'transcription', $usageData, 'server', $eventMetadata);
        if (!$stored) {
            if ($reservationId !== '') {
                $this->usage->releaseBudget($reservationId, true);
            }
            $this->usage->updateBySession($sessionId, ['status' => 'usage_error']);
            return new WP_Error('transcription_usage_persist_failed', __('Unable to record transcription usage. The transcript was withheld.', 'agentos'), ['status' => 503, 'session_id' => $sessionId, 'session_ticket' => $sessionTicket]);
        }

        $after = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'transcription']);
        if (empty($after['allowed'])) {
            return new WP_Error('subscription_limit', $after['message'] ?? __('The recorded transcription reached the current usage limit.', 'agentos'), [
                'status' => (int) ($after['status'] ?? 402),
                'session_id' => $sessionId,
                'session_ticket' => $sessionTicket,
                'usage' => $this->usage->sessionUsage($sessionId),
            ]);
        }
        if (trim((string) ($response['text'] ?? '')) === '') {
            return new WP_Error('openai_empty', __('The transcription service returned empty text.', 'agentos'), ['status' => 502, 'session_id' => $sessionId, 'session_ticket' => $sessionTicket]);
        }

        return [
            'text' => $response['text'],
            'model' => Config::FALLBACK_TRANSCRIBE_MODEL,
            'session_id' => $sessionId,
            'session_ticket' => $sessionTicket,
            'usage' => $this->usage->sessionUsage($sessionId),
            'warnings' => $after['warnings'] ?? [],
        ];
    }

    public function routeTranscriptSave(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $sessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        $sessionTicket = sanitize_text_field($request->get_param('session_ticket') ?: '');
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
        $contextPayload = $request->get_param('context');

        $currentUser = wp_get_current_user();
        $authenticated = $currentUser && $currentUser instanceof \WP_User && $currentUser->exists();
        $userEmail = \AgentOS\Core\SessionEmail::resolve(
            (bool) $authenticated,
            $authenticated ? (string) $currentUser->user_email : '',
            $request->get_param('guest_email')
        );

        $userKey = $this->resolveUserKey($anonId);

        if (!$postId || !$sessionId || !$agentId || !is_array($transcript)) {
            return new WP_Error('bad_payload', __('Missing fields', 'agentos'), ['status' => 400]);
        }

        $usageSession = $this->usage->validateSession($sessionId, $sessionTicket, $userKey, $agentId, $postId);
        if (!$usageSession) {
            return new WP_Error('usage_session_forbidden', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
        }
        $actualUsage = $this->usage->sessionUsage($sessionId);
        $subscriptionSlug = (string) ($usageSession['subscription_slug'] ?? '');
        $tokensRealtime = (int) ($actualUsage['tokens_realtime'] ?? 0);
        $tokensText = (int) ($actualUsage['tokens_text'] ?? 0)
            + (int) ($actualUsage['tokens_analysis'] ?? 0)
            + (int) ($actualUsage['tokens_transcription'] ?? 0);
        $tokensTotal = (int) ($actualUsage['tokens_total'] ?? 0);
        $durationSeconds = max(0, (int) ($usageSession['duration_seconds'] ?? 0));

        $agent = $this->agents->get($agentId);
        if (!$agent) {
            return new WP_Error('invalid_agent', __('Agent not found', 'agentos'), ['status' => 404]);
        }

        $analysisEnabled = !empty($agent['analysis_enabled']);
        $analysisAuto = !empty($agent['analysis_auto_run']);
        $analysisModel = sanitize_text_field($agent['analysis_model'] ?? '');
        $context = [];

        if (is_array($contextPayload)) {
            $settings = $this->settings->get();
            $allowed = !empty($agent['context_params']) ? (array) $agent['context_params'] : (array) ($settings['context_params'] ?? []);
            foreach ($contextPayload as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    continue;
                }

                $cleanKey = preg_replace('/[^a-z0-9_\-]/i', '', (string) $key);
                $cleanValue = sanitize_text_field($value);
                if ($cleanKey && $cleanValue !== '') {
                    $context[$cleanKey] = $cleanValue;
                }
            }
        }

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
            'context' => $context,
        ]);

        if (!$id) {
            global $wpdb;
            $reason = $wpdb->last_error ? sanitize_text_field($wpdb->last_error) : __('Database insert failed', 'agentos');
            return new WP_Error('db_insert', $reason, ['status' => 500]);
        }

        $this->usage->updateBySession($sessionId, [
            'status' => 'saved',
            'metadata' => ['transcript_saved' => true, 'usage_source' => 'server_ledger'],
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
        $requestedEmail = sanitize_email($request->get_param('user_email') ?: '');

        if (!$postId) {
            return new WP_Error('no_post', __('post_id required', 'agentos'), ['status' => 400]);
        }

        $filters = [];

        if ($status) {
            $filters['status'] = $status;
        }

        $currentUser = wp_get_current_user();
        if ($currentUser && $currentUser instanceof \WP_User && $currentUser->exists()) {
            $currentEmail = sanitize_email($currentUser->user_email);
            if (current_user_can('manage_options') && $requestedEmail) {
                $filters['user_email'] = $requestedEmail;
            } else {
                if ($requestedEmail && $requestedEmail !== $currentEmail) {
                    return new WP_Error('rest_forbidden', __('Email filter not permitted.', 'agentos'), ['status' => 403]);
                }

                $filters['user_key'] = 'user_' . (int) $currentUser->ID;
            }
        } elseif ($requestedEmail) {
            // Non-admins cannot request arbitrary emails.
            return new WP_Error('rest_forbidden', __('Email filter not permitted.', 'agentos'), ['status' => 403]);
        } else {
            return [];
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

    private function sanitizeContextPayload($payload, array $allowed): array
    {
        $context = [];
        if (!is_array($payload)) {
            return $context;
        }

        foreach ($payload as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            $cleanKey = preg_replace('/[^a-z0-9_\-]/i', '', (string) $key);
            $cleanValue = sanitize_text_field($value);
            if ($cleanKey && $cleanValue !== '') {
                $context[$cleanKey] = $cleanValue;
            }
        }

        return $context;
    }

    private function buildInstructions(string $instructions, array $context): string
    {
        $instructions = $instructions ?: Config::FALLBACK_PROMPT;
        if (!$context) {
            return $instructions;
        }

        $pairs = [];
        foreach ($context as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return $instructions . "\n\nContext: " . implode(', ', $pairs) . '.';
    }

    private function buildResponsesInput(array $transcript, string $userPrompt = ''): array
    {
        $items = [];

        if ($userPrompt !== '') {
            $items[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => "User Prompt:\n" . $userPrompt,
                    ],
                ],
            ];
        }

        foreach ($transcript as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Keep interrupted speech in saved history, but do not replay generated
            // words as if the listener had heard the entire message.
            if (!empty($entry['interrupted']) || !empty($entry['partial']) || (array_key_exists('resumeEligible', $entry) && !$entry['resumeEligible'])) {
                continue;
            }

            $role = sanitize_key($entry['role'] ?? '');
            $text = trim((string) ($entry['text'] ?? ''));
            if ($text === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $items[] = [
                'role' => $role,
                'content' => [
                    [
                        'type' => $role === 'assistant' ? 'output_text' : 'input_text',
                        'text' => $text,
                    ],
                ],
            ];
        }

        return $items;
    }

    private function extractResponsesText(array $response): string
    {
        if (!empty($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }

        $parts = [];
        foreach ((array) ($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (!is_array($content)) {
                    continue;
                }

                $type = $content['type'] ?? '';
                if (!in_array($type, ['output_text', 'text'], true)) {
                    continue;
                }

                if (isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
                    continue;
                }

                if (isset($content['text']['value']) && is_string($content['text']['value'])) {
                    $parts[] = $content['text']['value'];
                }
            }
        }

        return trim(implode('', $parts));
    }

    /** Count the exact Responses input with the provider's token-count endpoint. */
    private function countResponsesInputTokens(array $payload, string $apiKey)
    {
        $supportedFields = ['model', 'instructions', 'input', 'tools', 'tool_choice', 'text', 'reasoning', 'include'];
        $countPayload = array_intersect_key($payload, array_flip($supportedFields));
        $response = wp_remote_post('https://api.openai.com/v1/responses/input_tokens', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($countPayload),
            'timeout' => 25,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('usage_preflight_failed', __('Unable to verify the exact input size for this limited response.', 'agentos'), ['status' => 503]);
        }

        $status = wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($json) || !isset($json['input_tokens']) || !is_numeric($json['input_tokens'])) {
            return new WP_Error('usage_preflight_failed', __('The text service could not verify this response budget. Please retry later.', 'agentos'), ['status' => 503]);
        }
        return max(0, (int) $json['input_tokens']);
    }

    private function requestAudioTranscription(array $audio, string $apiKey, string $language = '', string $requestId = '')
    {
        if (!function_exists('curl_init') || !function_exists('curl_file_create')) {
            return new WP_Error(
                'curl_missing',
                __('cURL is required on the server to transcribe audio uploads.', 'agentos'),
                ['status' => 500]
            );
        }

        $mime = !empty($audio['type']) ? sanitize_text_field($audio['type']) : 'audio/webm';
        $filename = !empty($audio['name']) ? sanitize_file_name($audio['name']) : 'recording.webm';
        $file = curl_file_create($audio['tmp_name'], $mime, $filename);

        $postFields = [
            'file' => $file,
            'model' => Config::FALLBACK_TRANSCRIBE_MODEL,
            'response_format' => 'json',
        ];
        if ($language !== '') {
            $postFields['language'] = $language;
        }

        $responseHeaders = [];
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders) {
                if (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return new WP_Error('openai_transport', $error ?: __('Audio transcription failed.', 'agentos'), ['status' => 500]);
        }

        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode((string) $body, true);
        if ($code >= 400 || !is_array($json)) {
            return new WP_Error('openai_http', (string) $body ?: __('Unknown error', 'agentos'), ['status' => $code ?: 500]);
        }

        return [
            'text' => trim((string) ($json['text'] ?? '')),
            'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
            'request_id' => sanitize_text_field((string) ($responseHeaders['x-request-id'] ?? $requestId)),
        ];
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

    public function routeProvisionUserGet(WP_REST_Request $request)
    {
        $resolved = $this->resolveProvisionUser($request, false);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        return $this->prepareProvisionUserResponse($resolved['user_key']);
    }

    public function routeProvisionUserUpsert(WP_REST_Request $request)
    {
        $resolved = $this->resolveProvisionUser($request, true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $this->userSubscriptions->ensureUser($resolved['user_key'], $resolved['meta']);
        $this->userSubscriptions->saveUserMeta($resolved['user_key'], $resolved['meta']);

        return $this->prepareProvisionUserResponse($resolved['user_key']);
    }

    public function routeProvisionUserDelete(WP_REST_Request $request)
    {
        $resolved = $this->resolveProvisionUser($request, false);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $userKey = $resolved['user_key'];
        $this->userSubscriptions->deleteUser($userKey);
        $this->usage->deleteForUser($userKey);
        $this->transcripts->deleteForUser($userKey);

        return [
            'deleted' => true,
            'user_key' => $userKey,
        ];
    }

    public function routeProvisionUserSubscriptionsSet(WP_REST_Request $request)
    {
        $resolved = $this->resolveProvisionUser($request, true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $payload = $request->get_param('subscriptions');
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', __('subscriptions must be an array.', 'agentos'), ['status' => 400]);
        }

        $replace = !empty($request->get_param('replace'));
        $plans = $this->subscriptions->all();
        $userKey = $resolved['user_key'];
        $slugs = [];

        $this->userSubscriptions->ensureUser($userKey, $resolved['meta']);
        $this->userSubscriptions->saveUserMeta($userKey, $resolved['meta']);

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slug = sanitize_key($item['slug'] ?? ($item['subscription_slug'] ?? ''));
            if ($slug === '' || !isset($plans[$slug])) {
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

        return $this->prepareProvisionUserResponse($userKey);
    }

    public function routeProvisionUserSubscriptionsDelete(WP_REST_Request $request)
    {
        $resolved = $this->resolveProvisionUser($request, false);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $userKey = $resolved['user_key'];
        $removed = [];
        $subscriptionSlug = sanitize_key($request->get_param('subscription_slug') ?: '');
        if ($subscriptionSlug !== '') {
            $this->userSubscriptions->remove($userKey, $subscriptionSlug);
            $removed[] = $subscriptionSlug;
        }

        $subscriptionSlugs = $request->get_param('subscription_slugs');
        if (is_array($subscriptionSlugs)) {
            foreach ($subscriptionSlugs as $slug) {
                $cleanSlug = sanitize_key((string) $slug);
                if ($cleanSlug === '' || in_array($cleanSlug, $removed, true)) {
                    continue;
                }
                $this->userSubscriptions->remove($userKey, $cleanSlug);
                $removed[] = $cleanSlug;
            }
        }

        if (!$removed) {
            return new WP_Error(
                'missing_subscription_slug',
                __('subscription_slug or subscription_slugs is required.', 'agentos'),
                ['status' => 400]
            );
        }

        $response = $this->prepareProvisionUserResponse($userKey);
        $response['removed_subscriptions'] = $removed;

        return $response;
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

    private function prepareProvisionUserResponse(string $userKey): array
    {
        $response = $this->prepareUserSubscriptionResponse($userKey);
        $response['usage'] = $this->usage->summarizeUserTotals($userKey);

        return $response;
    }

    public function routeUsageUpdate(WP_REST_Request $request)
    {
        $sessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        if (!$sessionId) {
            return new WP_Error('missing_session', __('session_id required.', 'agentos'), ['status' => 400]);
        }
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $postId = intval($request->get_param('post_id') ?: 0);
        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $userKey = $this->resolveUserKey($anonId);
        $status = sanitize_key($request->get_param('status') ?: 'final');
        $reason = sanitize_key($request->get_param('reason') ?: '');
        $session = $this->usage->validateSession(
            $sessionId,
            sanitize_text_field($request->get_param('session_ticket') ?: ''),
            $userKey,
            $agentId,
            $postId
        );
        if (!$session) {
            return new WP_Error('usage_session_forbidden', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
        }

        if (!in_array($status, ['pending', 'running', 'paused', 'final', 'usage_error'], true)) {
            $status = 'running';
        }
        $this->usage->updateBySession($sessionId, [
            'status' => $status,
        ]);
        $lifecycleMetadata = [
            'origin' => 'session_lifecycle',
            'reason' => $reason,
            'usage_source' => 'server_verified_and_browser_reported',
        ];
        if (in_array($reason, ['stopped_with_unconfirmed_usage', 'saving_with_unconfirmed_usage', 'response_usage_missing', 'disconnected_with_unconfirmed_usage'], true)) {
            $lifecycleMetadata['usage_report_incomplete'] = true;
        }
        $this->usage->setSessionMetadata($sessionId, $lifecycleMetadata);
        $actual = $this->usage->sessionUsage($sessionId);
        return [
            'session_id' => $sessionId,
            'usage' => $actual,
        ];
    }

    public function routeUsageEvent(WP_REST_Request $request)
    {
        $params = $request->get_params();
        $params['events'] = [[
            'event_id' => $params['event_id'] ?? '',
            'category' => $params['category'] ?? '',
            'response_status' => $params['response_status'] ?? '',
            'usage' => $params['usage'] ?? null,
        ]];
        $request->set_body_params($params);
        return $this->routeUsageReport($request);
    }

    /** Record actual provider usage reported by the browser as operational quota usage. */
    public function routeUsageReport(WP_REST_Request $request)
    {
        $sessionId = sanitize_text_field((string) $request->get_param('session_id'));
        $agentId = sanitize_key((string) $request->get_param('agent_id'));
        $postId = max(0, (int) $request->get_param('post_id'));
        $userKey = $this->resolveUserKey(sanitize_text_field((string) $request->get_param('anon_id')));
        $sessionTicket = sanitize_text_field((string) $request->get_param('session_ticket'));
        $session = $this->usage->validateSession($sessionId, $sessionTicket, $userKey, $agentId, $postId);
        if (!$session) {
            return new WP_Error('usage_session_forbidden', __('This conversation session is invalid or belongs to another user.', 'agentos'), ['status' => 403]);
        }

        $events = $request->get_param('events');
        if (!is_array($events) || count($events) > 100) {
            return new WP_Error('bad_usage_batch', __('A batch of up to 100 provider usage events is required.', 'agentos'), ['status' => 400]);
        }
        $validated = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                return new WP_Error('bad_usage_event', __('Each usage event must be an object.', 'agentos'), ['status' => 400]);
            }
            $eventId = sanitize_text_field((string) ($event['event_id'] ?? ''));
            $category = sanitize_key((string) ($event['category'] ?? ''));
            $usage = $event['usage'] ?? null;
            if ($eventId === '' || strlen($eventId) > 190 || !in_array($category, ['realtime', 'transcription'], true) || !is_array($usage)) {
                return new WP_Error('bad_usage_event', __('A provider event ID, supported category, and usage object are required.', 'agentos'), ['status' => 400]);
            }
            if ($category === 'realtime') {
                if (!array_key_exists('input_tokens', $usage) || !array_key_exists('output_tokens', $usage)) {
                    return new WP_Error('bad_usage_event', __('Realtime usage must include provider input and output token counts.', 'agentos'), ['status' => 400]);
                }
                $cleanUsage = [
                    'input_tokens' => max(0, (int) $usage['input_tokens']),
                    'output_tokens' => max(0, (int) $usage['output_tokens']),
                    'total_tokens' => max(0, (int) ($usage['total_tokens'] ?? ((int) $usage['input_tokens'] + (int) $usage['output_tokens']))),
                ];
                $cleanUsage['details'] = $this->cleanRealtimeUsageDetails($usage['details'] ?? []);
            } elseif (($usage['type'] ?? '') === 'duration' && array_key_exists('seconds', $usage)) {
                $cleanUsage = ['total_tokens' => 0, 'transcription_seconds' => max(0, (float) $usage['seconds'])];
            } elseif (($usage['type'] ?? '') === 'tokens' && array_key_exists('input_tokens', $usage)) {
                $cleanUsage = [
                    'input_tokens' => max(0, (int) $usage['input_tokens']),
                    'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
                    'total_tokens' => max(0, (int) ($usage['total_tokens'] ?? ((int) $usage['input_tokens'] + (int) ($usage['output_tokens'] ?? 0)))),
                ];
            } else {
                return new WP_Error('bad_usage_event', __('Transcription usage must report actual provider tokens or duration.', 'agentos'), ['status' => 400]);
            }
            $validated[] = [
                'event_id' => $eventId,
                'category' => $category,
                'usage' => $cleanUsage,
                'response_status' => sanitize_key((string) ($event['response_status'] ?? '')),
                'details' => $category === 'realtime' ? ($cleanUsage['details'] ?? []) : [],
            ];
        }

        $agent = $this->agents->get($agentId);
        if (!$agent) {
            return new WP_Error('invalid_agent', __('Agent not found.', 'agentos'), ['status' => 404]);
        }
        $lockScope = $userKey !== '' ? 'user:' . $userKey : 'guest:' . $agentId . ':' . $postId;
        $result = $this->usage->withUsageLock($lockScope, function () use ($validated, $sessionId, $userKey, $agent) {
            foreach ($validated as $event) {
                $stored = $this->usage->recordEvent(
                    $sessionId,
                    $event['event_id'],
                    $event['category'],
                    $event['usage'],
                    'browser_reported',
                    [
                        'response_status' => $event['response_status'],
                        'reported_by' => 'browser',
                        'provider_usage_details' => $event['details'],
                    ]
                );
                if (!$stored) {
                    return new WP_Error('usage_event_failed', __('Unable to save the provider usage batch. Retry the same batch safely.', 'agentos'), ['status' => 503]);
                }
            }
            $decision = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'realtime']);
            return [
                'allowed' => !empty($decision['allowed']),
                'message' => $decision['message'] ?? '',
                'reason' => $decision['reason'] ?? '',
                'remaining' => $decision['remaining'] ?? [],
                'warnings' => $decision['warnings'] ?? [],
                'realtime_budget' => $this->realtimeTokenBudget($decision, $agent),
                'session_cap' => (int) ($decision['session_cap'] ?? 0),
                'usage_source' => 'browser_reported',
                'usage' => $this->usage->sessionUsage($sessionId),
            ];
        });
        if ($result === false) {
            return new WP_Error('usage_busy', __('Usage is being updated. Retry the same batch shortly.', 'agentos'), ['status' => 503]);
        }
        if (is_wp_error($result)) {
            return $result;
        }
        return $result;
    }

    private function cleanRealtimeUsageDetails($details): array
    {
        if (!is_array($details)) {
            return [];
        }
        $allowed = ['input_token_details', 'output_token_details', 'input_tokens_details', 'output_tokens_details'];
        $clean = [];
        foreach ($allowed as $group) {
            if (empty($details[$group]) || !is_array($details[$group])) {
                continue;
            }
            $clean[$group] = $this->cleanRealtimeUsageDetailValues($details[$group]);
            if (!$clean[$group]) {
                unset($clean[$group]);
            }
        }
        return $clean;
    }

    private function cleanRealtimeUsageDetailValues(array $values, int $depth = 0): array
    {
        if ($depth >= 3) {
            return [];
        }
        $clean = [];
        foreach (array_slice($values, 0, 20, true) as $name => $value) {
            $key = sanitize_key((string) $name);
            if ($key === '') {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $clean[$key] = max(0, (int) $value);
            } elseif (is_array($value)) {
                $nested = $this->cleanRealtimeUsageDetailValues($value, $depth + 1);
                if ($nested) {
                    $clean[$key] = $nested;
                }
            }
        }
        return $clean;
    }

    private function extractIntegrationApiToken(WP_REST_Request $request): string
    {
        $authorization = trim((string) $request->get_header('authorization'));
        if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        return trim((string) $request->get_header('x-agentos-api-key'));
    }

    private function resolveProvisionUser(WP_REST_Request $request, bool $allowMetaUpdates)
    {
        $metaPayload = $request->get_param('meta');
        $metaPayload = is_array($metaPayload) ? $metaPayload : [];

        $userKey = sanitize_text_field($request->get_param('user_key') ?: '');
        $email = sanitize_email($request->get_param('email') ?: '');
        if ($email === '' && isset($metaPayload['email'])) {
            $email = sanitize_email($metaPayload['email']);
        }

        if ($userKey === '' && $email !== '') {
            $user = get_user_by('email', $email);
            if ($user instanceof \WP_User && $user->exists()) {
                $userKey = 'user_' . (int) $user->ID;
            }
        }

        if ($userKey === '') {
            return new WP_Error(
                'missing_user_key',
                __('user_key or email is required.', 'agentos'),
                ['status' => 400]
            );
        }

        if (!preg_match('/^user_(\d+)$/', $userKey, $matches)) {
            return new WP_Error(
                'invalid_user_key',
                __('user_key must use the format user_{wp_user_id}.', 'agentos'),
                ['status' => 400]
            );
        }

        $wpUserId = (int) $matches[1];
        $wpUser = get_user_by('id', $wpUserId);
        if (!$wpUser instanceof \WP_User || !$wpUser->exists()) {
            return new WP_Error(
                'user_missing',
                __('The referenced WordPress user does not exist.', 'agentos'),
                ['status' => 404]
            );
        }

        $existingMetaAll = $this->userSubscriptions->getUserMeta();
        $existingMeta = $existingMetaAll[$userKey] ?? [];
        $meta = [
            'name' => $existingMeta['name'] ?? ($wpUser->display_name ?: $wpUser->user_login),
            'email' => sanitize_email($wpUser->user_email),
            'notes' => $existingMeta['notes'] ?? '',
            'wp_user_id' => $wpUserId,
        ];

        if ($allowMetaUpdates) {
            if (isset($metaPayload['name'])) {
                $meta['name'] = sanitize_text_field($metaPayload['name']);
            } elseif (isset($metaPayload['label'])) {
                $meta['name'] = sanitize_text_field($metaPayload['label']);
            }

            if (isset($metaPayload['notes'])) {
                $meta['notes'] = sanitize_textarea_field($metaPayload['notes']);
            }
        }

        return [
            'user_key' => $userKey,
            'wp_user' => $wpUser,
            'meta' => $meta,
        ];
    }
}
