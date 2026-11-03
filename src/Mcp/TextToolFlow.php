<?php

namespace AgentOS\Mcp;

use WP_Error;

/** Retains model tool-call state on the server while the UI obtains approval. */
class TextToolFlow
{
    private SessionBroker $broker;

    public function __construct(SessionBroker $broker)
    {
        $this->broker = $broker;
    }

    public function run(array $payload, string $apiKey, string $ticket, string $continuation = '', ?callable $beforeRequest = null, ?callable $onUsage = null)
    {
        $round = 0;
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $usageEvents = [];
        $key = '';
        $lock = '';
        if ($continuation !== '') {
            if (!preg_match('/^[a-zA-Z0-9]{48}$/D', $continuation)) {
                return new WP_Error('mcp_continuation', __('Invalid tool continuation.', 'agentos'), ['status' => 400]);
            }
            $key = 'agentos_mcp_text_' . hash('sha256', $continuation);
            $state = get_transient($key);
            if (!is_array($state) || (int) $state['actor'] !== get_current_user_id() || !hash_equals($state['ticket'], $ticket)) {
                return new WP_Error('mcp_continuation', __('This tool continuation expired or belongs to another conversation.', 'agentos'), ['status' => 403]);
            }
            if (isset($state['result'])) {
                return $state['result'];
            }
            $payload = $state['payload'];
            $usage = $state['usage'];
            $usageEvents = $state['usage_events'] ?? [];
            $round = $state['round'];
            foreach ($state['calls'] as $call) {
                $result = $this->broker->completed($ticket, $call['call_id']);
                if (!$result) {
                    return new WP_Error('mcp_pending', __('Complete or decline the pending tool request first.', 'agentos'), ['status' => 409]);
                }
                $payload['input'][] = ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => $result['output']];
            }
            $lock = 'agentos_mcp_text_lock_' . hash('sha256', $continuation);
            if (!add_option($lock, time(), '', false)) {
                return new WP_Error('mcp_busy', __('This response is already being continued.', 'agentos'), ['status' => 409]);
            }
        }
        try {
            $requestPayload = $payload;
            $reservationId = '';
            if ($beforeRequest !== null) {
                $prepared = $beforeRequest($payload);
                if (is_wp_error($prepared)) {
                    return $prepared;
                }
                if (is_array($prepared)) {
                    $requestPayload = $prepared['payload'] ?? $payload;
                    $reservationId = (string) ($prepared['reservation_id'] ?? '');
                }
            }
            $response = wp_remote_post('https://api.openai.com/v1/responses', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
                'body' => wp_json_encode($requestPayload),
                'timeout' => 40,
            ]);
            if (is_wp_error($response)) {
                if ($reservationId !== '' && $onUsage !== null) {
                    $onUsage($reservationId, null, true);
                }
                return new WP_Error('openai_transport', __('Unable to contact the text service.', 'agentos'), ['status' => 502]);
            }
            $code = wp_remote_retrieve_response_code($response);
            $json = json_decode(wp_remote_retrieve_body($response), true);
            if ($code >= 400 || !is_array($json)) {
                if ($reservationId !== '' && $onUsage !== null) {
                    $onUsage($reservationId, null, $code >= 500 || $code === 0);
                }
                return new WP_Error('openai_http', __('The text service could not complete this request.', 'agentos'), ['status' => 502, 'provider_status' => $code]);
            }
            if (!is_array($json['usage'] ?? null)) {
                if ($reservationId !== '' && $onUsage !== null) {
                    $onUsage($reservationId, null, true);
                }
                return new WP_Error('openai_usage_missing', __('The text service did not return usage data, so this conversation has been paused safely.', 'agentos'), ['status' => 502]);
            }
            $requestUsage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
            $inputTokens = max(0, (int) ($requestUsage['input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($requestUsage['output_tokens'] ?? 0));
            $totalTokens = array_key_exists('total_tokens', $requestUsage)
                ? max(0, (int) $requestUsage['total_tokens'])
                : $inputTokens + $outputTokens;
            $responseId = sanitize_text_field($json['id'] ?? '');
            if ($responseId === '') {
                $responseId = 'request_' . wp_generate_password(32, false, false);
            }
            $usageEvent = [
                'event_key' => $responseId,
                'category' => 'text',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'cached_input_tokens' => max(0, (int) ($requestUsage['input_tokens_details']['cached_tokens'] ?? 0)),
                'status' => sanitize_key($json['status'] ?? 'completed'),
            ];
            $usageEvents[] = $usageEvent;
            if ($onUsage !== null) {
                $stored = $onUsage($reservationId, $usageEvent, false);
                if ($stored === false) {
                    return new WP_Error('usage_persist_failed', __('Unable to record this response usage. No further model or tool calls were started.', 'agentos'), ['status' => 503]);
                }
            }
            foreach (array_keys($usage) as $metric) {
                $usage[$metric] += max(0, (int) ($requestUsage[$metric] ?? 0));
            }
            $calls = [];
            foreach ((array) ($json['output'] ?? []) as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                    if (count($calls) >= 8 || empty($item['call_id']) || empty($item['name']) || !is_string($item['arguments'] ?? null)) {
                        return new WP_Error('mcp_tool_calls', __('The model returned an unsupported tool request.', 'agentos'), ['status' => 502]);
                    }
                    $calls[] = ['call_id' => $item['call_id'], 'name' => $item['name'], 'arguments' => $item['arguments']];
                }
            }
            $next = '';
            if ($calls) {
                if ($ticket === '' || $round >= 5) {
                    return new WP_Error('mcp_round_limit', __('The response reached its tool limit. Please ask a more specific question.', 'agentos'), ['status' => 422]);
                }
                foreach ($calls as $call) {
                    $reserved = $this->broker->reserve($ticket, $call['call_id'], $call['name'], $call['arguments']);
                    if (is_wp_error($reserved)) {
                        return $reserved;
                    }
                }
                // Keep all model outputs, including reasoning items, for the next request.
                $payload['input'] = array_merge($payload['input'], (array) $json['output']);
                $next = wp_generate_password(48, false, false);
                $nextState = ['actor' => get_current_user_id(), 'ticket' => $ticket, 'payload' => $payload, 'usage' => $usage, 'usage_events' => $usageEvents, 'round' => $round + 1, 'calls' => $calls];
                if (!set_transient('agentos_mcp_text_' . hash('sha256', $next), $nextState, 900)) {
                    return new WP_Error('mcp_storage', __('Unable to prepare the tool response.', 'agentos'), ['status' => 503]);
                }
            }
            $result = ['response' => $json, 'tool_calls' => $calls, 'continuation' => $next, 'usage' => $usage, 'usage_events' => $usageEvents];
            if ($key !== '') {
                $state['result'] = $result;
                set_transient($key, $state, 900);
            }
            return $result;
        } finally {
            if ($lock !== '') {
                delete_option($lock);
            }
        }
    }
}
