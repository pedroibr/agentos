<?php

namespace AgentOS\Rest;

use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class RestController
{
    private Settings $settings;
    private AgentRepository $agents;
    private TranscriptRepository $transcripts;

    public function __construct(Settings $settings, AgentRepository $agents, TranscriptRepository $transcripts)
    {
        $this->settings = $settings;
        $this->agents = $agents;
        $this->transcripts = $transcripts;
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

        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to view transcripts.', 'agentos'),
                [
                    'status' => 403,
                ]
            );
        }

        return true;
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

        $agent = $this->agents->get($agentId);
        if (!$agent) {
            return new WP_Error('invalid_agent', __('Agent not found', 'agentos'), ['status' => 404]);
        }

        $postType = get_post_type($postId);
        if ($agent['post_types'] && !in_array($postType, $agent['post_types'], true)) {
            return new WP_Error('agent_unavailable', __('Agent not available for this post type.', 'agentos'), ['status' => 403]);
        }

        $config = $this->agents->collectPostConfig($agent, $postId);

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

        $currentUser = wp_get_current_user();
        $userEmail = '';
        if ($currentUser && $currentUser instanceof \WP_User && $currentUser->exists()) {
            $userEmail = sanitize_email($currentUser->user_email);
        }

        if (!$postId || !$sessionId || !$agentId || !is_array($transcript)) {
            return new WP_Error('bad_payload', __('Missing fields', 'agentos'), ['status' => 400]);
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
        ]);

        if (!$id) {
            global $wpdb;
            $reason = $wpdb->last_error ? sanitize_text_field($wpdb->last_error) : __('Database insert failed', 'agentos');
            return new WP_Error('db_insert', $reason, ['status' => 500]);
        }

        return ['id' => $id];
    }

    public function routeTranscriptList(WP_REST_Request $request)
    {
        $postId = intval($request->get_param('post_id') ?: 0);
        $agentId = sanitize_key($request->get_param('agent_id') ?: '');
        $limit = min(100, max(1, intval($request->get_param('limit') ?: 10)));

        if (!$postId) {
            return new WP_Error('no_post', __('post_id required', 'agentos'), ['status' => 400]);
        }

        return $this->transcripts->list($postId, $agentId, $limit);
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
}
