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

        $payload = [
            'model' => $config['model'],
            'voice' => $config['voice'],
            'modalities' => ['audio', 'text'],
            'instructions' => $instructions,
            'input_audio_transcription' => [
                'model' => Config::FALLBACK_TRANSCRIBE_MODEL,
            ],
            'input_audio_noise_reduction' => $noiseReduction === 'off'
                ? null
                : ['type' => $noiseReduction],
            'turn_detection' => $turnDetection,
        ];

        $speechLanguageHint = sanitize_key($config['speech_language_hint'] ?? '');
        if (in_array($speechLanguageHint, ['en', 'pt'], true)) {
            $payload['input_audio_transcription']['language'] = $speechLanguageHint;
        }

        $transcriptionHint = sanitize_textarea_field($config['transcription_hint'] ?? '');
        if ($transcriptionHint !== '') {
            $payload['input_audio_transcription']['prompt'] = $transcriptionHint;
        }

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
            'context' => $contextData,
        ];
    }

    public function routeTextResponse(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $sessionId = sanitize_text_field($request->get_param('session_id') ?: '');
        $anonId = sanitize_text_field($request->get_param('anon_id') ?: '');
        $message = sanitize_textarea_field($request->get_param('message') ?: '');
        $transcript = $request->get_param('transcript');
        $tokensText = max(0, intval($request->get_param('tokens_text') ?: 0));
        $tokensTotal = max(0, intval($request->get_param('tokens_total') ?: 0));
        $durationSeconds = max(0, intval($request->get_param('duration_seconds') ?: 0));

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
        if (!$sessionId) {
            $sessionId = wp_generate_password(16, false);
        }

        $userKey = $this->resolveUserKey($anonId);
        $limitResult = $this->limiter->evaluate($userKey, $agent);
        if (empty($limitResult['allowed'])) {
            $status = isset($limitResult['status']) ? (int) $limitResult['status'] : 402;
            $messageText = $limitResult['message'] ?? __('Usage limit reached for this subscription.', 'agentos');
            $code = $limitResult['error_code'] ?? 'subscription_limit';
            return new WP_Error($code, $messageText, ['status' => $status]);
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
            'status' => 'running',
            'metadata' => [
                'origin' => 'text_response',
                'mode' => 'text',
            ],
        ]);

        $apiKey = $this->settings->resolveApiKey();
        if (!$apiKey) {
            return new WP_Error('no_key', __('OpenAI key not configured', 'agentos'), ['status' => 500]);
        }

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

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 40,
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

        $assistantText = $this->extractResponsesText($json);
        if ($assistantText === '') {
            return new WP_Error('openai_empty', __('The model returned an empty response.', 'agentos'), ['status' => 502]);
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $requestTokens = max(0, (int) ($usage['total_tokens'] ?? 0));
        $nextTextTokens = $tokensText + $requestTokens;
        $nextTotalTokens = max($tokensTotal + $requestTokens, $nextTextTokens);

        $this->usage->updateBySession($sessionId, [
            'user_key' => $userKey,
            'subscription_slug' => $subscriptionSlug,
            'agent_id' => $agentId,
            'post_id' => $postId,
            'tokens_text' => $nextTextTokens,
            'tokens_total' => $nextTotalTokens,
            'duration_seconds' => $durationSeconds,
            'status' => 'running',
            'metadata' => [
                'mode' => 'text',
                'origin' => 'text_response',
                'input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
            ],
        ]);

        return [
            'text' => $assistantText,
            'model' => $textModel,
            'session_id' => $sessionId,
            'subscription' => $subscriptionSlug,
            'session_cap' => $sessionCap,
            'warnings' => $limitResult['warnings'] ?? [],
            'context' => $contextData,
            'usage' => [
                'input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
                'total_tokens' => $requestTokens,
                'session_text_tokens' => $nextTextTokens,
                'session_total_tokens' => $nextTotalTokens,
            ],
        ];
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
        $limitResult = $this->limiter->evaluate($userKey, $agent);
        if (empty($limitResult['allowed'])) {
            $status = isset($limitResult['status']) ? (int) $limitResult['status'] : 402;
            $messageText = $limitResult['message'] ?? __('Usage limit reached for this subscription.', 'agentos');
            $code = $limitResult['error_code'] ?? 'subscription_limit';
            return new WP_Error($code, $messageText, ['status' => $status]);
        }

        $files = $request->get_file_params();
        $audio = $files['audio'] ?? null;
        if (!is_array($audio) || empty($audio['tmp_name']) || !is_uploaded_file($audio['tmp_name'])) {
            return new WP_Error('missing_audio', __('An audio file upload is required.', 'agentos'), ['status' => 400]);
        }

        $apiKey = $this->settings->resolveApiKey();
        if (!$apiKey) {
            return new WP_Error('no_key', __('OpenAI key not configured', 'agentos'), ['status' => 500]);
        }

        $response = $this->requestAudioTranscription($audio, $apiKey, $language);
        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'text' => $response['text'],
            'model' => Config::FALLBACK_TRANSCRIBE_MODEL,
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
        $contextPayload = $request->get_param('context');

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

    private function requestAudioTranscription(array $audio, string $apiKey, string $language = '')
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
        ];
        if ($language !== '') {
            $postFields['language'] = $language;
        }

        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 60,
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

        $text = trim((string) ($json['text'] ?? ''));
        if ($text === '') {
            return new WP_Error('openai_empty', __('The transcription service returned empty text.', 'agentos'), ['status' => 502]);
        }

        return [
            'text' => $text,
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
