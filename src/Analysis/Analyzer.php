<?php

namespace AgentOS\Analysis;

use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;
use AgentOS\Database\UsageRepository;
use AgentOS\Subscriptions\UsageLimiter;
use WP_Error;

class Analyzer
{
    public const HOOK = 'agentos_run_analysis';
    private const MAX_RETRIES = 3;
    private const PROMPT_VERSION = 'v1';
    private const DEFAULT_MODEL = 'gpt-4.1-mini';
    private const RETRY_DELAYS = [60, 300, 900];
    private const DEFAULT_SYSTEM_PROMPT = 'You are an assistant that evaluates tutoring sessions. Provide concise, constructive feedback highlighting strengths, areas to improve, and clear next steps for the learner.';

    private Settings $settings;
    private AgentRepository $agents;
    private TranscriptRepository $transcripts;
    private UsageRepository $usage;
    private UsageLimiter $limiter;

    public function __construct(Settings $settings, AgentRepository $agents, TranscriptRepository $transcripts, UsageRepository $usage, UsageLimiter $limiter)
    {
        $this->settings = $settings;
        $this->agents = $agents;
        $this->transcripts = $transcripts;
        $this->usage = $usage;
        $this->limiter = $limiter;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'handleScheduled'], 10, 1);
    }

    public function enqueue(int $transcriptId, array $options = []): bool
    {
        $record = $this->transcripts->find($transcriptId);
        if (!$record) {
            return false;
        }

        $agent = $this->agents->get($record['agent_id']);
        if (!$agent || empty($agent['analysis_enabled'])) {
            return false;
        }

        $model = isset($options['model']) ? sanitize_text_field($options['model']) : '';
        if (!$model) {
            $model = sanitize_text_field($agent['analysis_model'] ?? '') ?: self::DEFAULT_MODEL;
        }

        $customPrompt = '';
        if (!empty($options['custom_prompt'])) {
            $customPrompt = wp_kses_post($options['custom_prompt']);
        }

        $this->transcripts->updateAnalysis($transcriptId, [
            'analysis_status' => 'queued',
            'analysis_model' => $model,
            'analysis_prompt_version' => self::PROMPT_VERSION,
            'analysis_prompt' => $customPrompt,
            'analysis_requested_at' => current_time('mysql'),
            'analysis_completed_at' => null,
            'analysis_error' => '',
            'analysis_feedback' => '',
            'analysis_attempts' => 0,
        ]);

        wp_clear_scheduled_hook(self::HOOK, [$transcriptId]);

        $delay = isset($options['delay']) ? max(0, intval($options['delay'])) : 5;
        $timestamp = time() + $delay;

        return (bool) wp_schedule_single_event($timestamp, self::HOOK, [$transcriptId]);
    }

    public function handleScheduled(int $transcriptId): void
    {
        $record = $this->transcripts->find($transcriptId);
        if (!$record) {
            return;
        }

        $agent = $this->agents->get($record['agent_id']);
        if (!$agent || empty($agent['analysis_enabled'])) {
            $this->transcripts->updateAnalysis($transcriptId, [
                'analysis_status' => 'failed',
                'analysis_error' => __('Analysis is disabled for this agent.', 'agentos'),
            ]);
            return;
        }

        $apiKey = $this->settings->resolveApiKey();
        if (!$apiKey) {
            $this->transcripts->updateAnalysis($transcriptId, [
                'analysis_status' => 'failed',
                'analysis_error' => __('OpenAI API key is not configured.', 'agentos'),
            ]);
            return;
        }

        $attempt = isset($record['analysis_attempts']) ? (int) $record['analysis_attempts'] : 0;
        $attempt++;

        $this->transcripts->updateAnalysis($transcriptId, [
            'analysis_status' => 'running',
            'analysis_attempts' => $attempt,
            'analysis_error' => '',
        ]);

        $model = sanitize_text_field($record['analysis_model'] ?? '');
        if (!$model) {
            $model = sanitize_text_field($agent['analysis_model'] ?? '') ?: self::DEFAULT_MODEL;
        }

        $systemPrompt = isset($agent['analysis_system_prompt']) ? sanitize_textarea_field($agent['analysis_system_prompt']) : '';

        $customPrompt = isset($record['analysis_prompt']) ? trim((string) $record['analysis_prompt']) : '';
        if ($customPrompt !== '') {
            $systemPrompt = sanitize_textarea_field($customPrompt);
        }

        if ($systemPrompt === '') {
            $systemPrompt = self::DEFAULT_SYSTEM_PROMPT;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $systemPrompt,
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $this->buildUserPrompt($record, $agent),
                    ],
                ],
            ],
        ];

        $sessionId = sanitize_text_field((string) ($record['session_id'] ?? ''));
        $usageSession = $sessionId !== '' ? $this->usage->getSession($sessionId) : null;
        if (!$usageSession && $sessionId !== '') {
            $usageSession = $this->usage->linkLegacyTranscript($record);
        }
        if (!$usageSession
            || (string) ($usageSession['agent_id'] ?? '') !== (string) ($record['agent_id'] ?? '')
            || (int) ($usageSession['post_id'] ?? 0) !== (int) ($record['post_id'] ?? 0)
        ) {
            $this->transcripts->updateAnalysis($transcriptId, [
                'analysis_status' => 'failed',
                'analysis_error' => __('Analysis was not run because its usage session could not be linked safely.', 'agentos'),
            ]);
            return;
        }
        $userKey = sanitize_text_field((string) ($usageSession['user_key'] ?? ''));
        $decision = $this->limiter->evaluate($userKey, $agent, ['session_id' => $sessionId, 'mode' => 'analysis']);
        if (empty($decision['allowed'])) {
            $this->transcripts->updateAnalysis($transcriptId, [
                'analysis_status' => 'failed',
                'analysis_error' => __('Analysis was not run because the conversation quota or subscription is no longer available.', 'agentos'),
            ]);
            return;
        }

        $plan = is_array($decision['plan'] ?? null) ? $decision['plan'] : [];
        $limits = $plan['limits'] ?? [];
        $strict = (int) ($agent['session_token_cap'] ?? 0) > 0
            || (int) ($decision['session_cap'] ?? 0) > 0
            || (!empty($plan['block_on_overage']) && (int) ($limits['text_tokens'] ?? 0) > 0);
        $payload = ['model' => $model, 'input' => $messages];
        $reservationId = '';
        if ($strict) {
            $inputTokens = $this->countInputTokens($apiKey, $payload);
            if (is_wp_error($inputTokens)) {
                $this->transcripts->updateAnalysis($transcriptId, [
                    'analysis_status' => 'failed',
                    'analysis_error' => __('Analysis was not run because its exact input size could not be checked.', 'agentos'),
                ]);
                return;
            }
            $reservationId = wp_generate_password(48, false, false);
            $lockScope = $userKey !== '' ? 'user:' . $userKey : 'guest:' . sanitize_key((string) ($agent['slug'] ?? '')) . ':' . (int) ($record['post_id'] ?? 0);
            $outputTokens = $this->usage->withUsageLock($lockScope, function () use ($userKey, $record, $usageSession, $reservationId, $inputTokens, $agent) {
                $freshAgent = $this->agents->get((string) $record['agent_id']) ?: $agent;
                $fresh = $this->limiter->evaluate($userKey, $freshAgent, ['session_id' => (string) $record['session_id'], 'mode' => 'analysis']);
                if (empty($fresh['allowed'])) {
                    return false;
                }
                $freshPlan = is_array($fresh['plan'] ?? null) ? $fresh['plan'] : [];
                $freshLimits = $freshPlan['limits'] ?? [];
                $available = [];
                if (!empty($freshPlan['block_on_overage']) && (int) ($freshLimits['text_tokens'] ?? 0) > 0) {
                    $available[] = max(0, (int) ($fresh['remaining']['text_tokens'] ?? 0));
                }
                if ((int) ($fresh['session_cap'] ?? 0) > 0) {
                    $available[] = max(0, (int) ($fresh['remaining']['session_tokens'] ?? 0));
                }
                $budget = $available ? min($available) : 0;
                $outputLimit = min(2048, $budget - (int) $inputTokens);
                if ($outputLimit < Config::RESPONSES_MIN_OUTPUT_TOKENS) {
                    return false;
                }
                $reserved = $this->usage->reserveBudget(
                    $reservationId,
                    (string) $record['session_id'],
                    $userKey,
                    (string) ($fresh['subscription_slug'] ?? $usageSession['subscription_slug'] ?? ''),
                    'text',
                    (int) $inputTokens + (int) $outputLimit,
                    max(1, (int) ($freshPlan['period_hours'] ?? 24))
                );
                return $reserved ? $outputLimit : false;
            });
            if (!is_int($outputTokens) || $outputTokens < 1) {
                $this->transcripts->updateAnalysis($transcriptId, [
                    'analysis_status' => 'failed',
                    'analysis_error' => __('Analysis was not run because its remaining token budget could not reserve at least 16 output tokens.', 'agentos'),
                ]);
                return;
            }
            $payload['max_output_tokens'] = $outputTokens;
        }

        $response = $this->callOpenAi($apiKey, $model, $messages, $strict ? $payload['max_output_tokens'] : null);
        if (is_wp_error($response)) {
            if ($reservationId !== '') {
                $data = $response->get_error_data();
                $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
                $this->usage->releaseBudget($reservationId, $status === 0 || $status >= 500 || in_array($response->get_error_code(), ['http_request_failed', 'http_request_timeout'], true));
            }
            $this->handleFailure($transcriptId, $attempt, $response);
            return;
        }

        $responseUsage = is_array($response['usage'] ?? null) ? $response['usage'] : null;
        $responseId = sanitize_text_field((string) ($response['id'] ?? ''));
        if (!$responseUsage || $responseId === '') {
            if ($reservationId !== '') {
                $this->usage->releaseBudget($reservationId, true);
            }
            $this->usage->updateBySession($sessionId, ['status' => 'usage_error']);
            $this->transcripts->updateAnalysis($transcriptId, [
                'analysis_status' => 'failed',
                'analysis_error' => __('Analysis usage could not be confirmed, so the result was not saved.', 'agentos'),
            ]);
            return;
        }
        $usageData = [
            'input_tokens' => max(0, (int) ($responseUsage['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($responseUsage['output_tokens'] ?? 0)),
            'total_tokens' => max(0, (int) ($responseUsage['total_tokens'] ?? ((int) ($responseUsage['input_tokens'] ?? 0) + (int) ($responseUsage['output_tokens'] ?? 0)))),
        ];
        $usageMetadata = [
            'model' => $model,
            'status' => sanitize_key((string) ($response['status'] ?? 'completed')),
            'cached_input_tokens' => max(0, (int) ($responseUsage['input_tokens_details']['cached_tokens'] ?? 0)),
            'transcript_id' => $transcriptId,
        ];
        $savedUsage = $reservationId !== ''
            ? $this->usage->settleBudget($reservationId, $sessionId, $responseId, 'analysis', $usageData, 'server', $usageMetadata)
            : $this->usage->recordEvent($sessionId, $responseId, 'analysis', $usageData, 'server', $usageMetadata);
        if (!$savedUsage) {
            if ($reservationId !== '') {
                $this->usage->releaseBudget($reservationId, true);
            }
            $this->usage->updateBySession($sessionId, ['status' => 'usage_error']);
            $this->transcripts->updateAnalysis($transcriptId, [
                'analysis_status' => 'failed',
                'analysis_error' => __('Analysis usage could not be saved, so the result was not saved.', 'agentos'),
            ]);
            return;
        }

        $feedback = $this->extractFeedback($response);
        if ($feedback === '') {
            $this->handleFailure(
                $transcriptId,
                $attempt,
                new WP_Error('agentos_empty_feedback', __('Analysis response was empty.', 'agentos'))
            );
            return;
        }

        $this->transcripts->updateAnalysis($transcriptId, [
            'analysis_status' => 'succeeded',
            'analysis_feedback' => wp_kses_post($feedback),
            'analysis_completed_at' => current_time('mysql'),
        ]);
    }

    private function handleFailure(int $transcriptId, int $attempt, WP_Error $error): void
    {
        $code = $error->get_error_code();
        $message = $error->get_error_message();

        $this->transcripts->updateAnalysis($transcriptId, [
            'analysis_status' => 'failed',
            'analysis_error' => sprintf('%s (%s)', $message, $code),
        ]);

        if ($attempt >= self::MAX_RETRIES) {
            return;
        }

        $errorData = $error->get_error_data();
        $status = is_array($errorData) && isset($errorData['status']) ? (int) $errorData['status'] : 0;
        $shouldRetry = false;
        if ($status === 429 || $status >= 500) {
            $shouldRetry = true;
        }
        if (in_array($code, ['http_request_failed', 'http_request_timeout'], true)) {
            $shouldRetry = true;
        }

        if (!$shouldRetry) {
            return;
        }

        $delay = self::RETRY_DELAYS[min($attempt - 1, count(self::RETRY_DELAYS) - 1)];

        $this->transcripts->updateAnalysis($transcriptId, [
            'analysis_status' => 'queued',
        ]);

        wp_schedule_single_event(time() + $delay, self::HOOK, [$transcriptId]);
    }

    private function countInputTokens(string $apiKey, array $payload)
    {
        $response = wp_remote_post('https://api.openai.com/v1/responses/input_tokens', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 25,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('analysis_preflight_failed', __('Unable to verify the analysis input size.', 'agentos'), ['status' => 503]);
        }
        $code = wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($json) || !is_numeric($json['input_tokens'] ?? null)) {
            return new WP_Error('analysis_preflight_failed', __('The analysis input size could not be verified.', 'agentos'), ['status' => 503]);
        }
        return max(0, (int) $json['input_tokens']);
    }

    private function callOpenAi(string $apiKey, string $model, array $messages, ?int $maxOutputTokens = null)
    {
        $payload = [
            'model' => $model ?: self::DEFAULT_MODEL,
            'input' => $messages,
        ];
        if ($maxOutputTokens !== null && $maxOutputTokens > 0) {
            $payload['max_output_tokens'] = $maxOutputTokens;
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 60,
                'stream' => false,
                'decompress' => true,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($code >= 400 || !is_array($json)) {
            return new WP_Error('agentos_http_error', $body ?: __('Unexpected error from OpenAI.', 'agentos'), ['status' => $code]);
        }

        return $json;
    }

    private function extractFeedback(array $response): string
    {
        if (empty($response['output']) || !is_array($response['output'])) {
            return '';
        }

        $chunks = [];
        foreach ($response['output'] as $message) {
            if (!isset($message['content']) || !is_array($message['content'])) {
                continue;
            }
            foreach ($message['content'] as $part) {
                if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                    $chunks[] = (string) $part['text'];
                }
            }
        }

        $text = trim(implode("\n", $chunks));
        return $text;
    }

    private function buildUserPrompt(array $record, array $agent): string
    {
        $transcriptLines = [];
        $messages = $record['transcript'] ?? [];
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = $message['role'] ?? 'user';
            $text = isset($message['text']) ? wp_strip_all_tags((string) $message['text']) : '';
            if ($text === '') {
                continue;
            }
            $label = $role === 'assistant' ? 'Tutor' : 'Learner';
            if (!empty($message['interrupted'])) {
                $label .= ' (interrupted)';
            } elseif (!empty($message['partial'])) {
                $label .= ' (incomplete)';
            }
            $transcriptLines[] = sprintf('%s: %s', $label, $text);
        }

        if (!$transcriptLines) {
            $transcriptLines[] = __('(Transcript is empty)', 'agentos');
        }

        return implode("\n", $transcriptLines);
    }
}
