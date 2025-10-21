<?php

namespace AgentOS\Analysis;

use AgentOS\Core\AgentRepository;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;
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

    public function __construct(Settings $settings, AgentRepository $agents, TranscriptRepository $transcripts)
    {
        $this->settings = $settings;
        $this->agents = $agents;
        $this->transcripts = $transcripts;
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

        $response = $this->callOpenAi($apiKey, $model, $messages);
        if (is_wp_error($response)) {
            $this->handleFailure($transcriptId, $attempt, $response);
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

    private function callOpenAi(string $apiKey, string $model, array $messages)
    {
        $payload = [
            'model' => $model ?: self::DEFAULT_MODEL,
            'input' => $messages,
        ];

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
            $transcriptLines[] = sprintf('%s: %s', $label, $text);
        }

        if (!$transcriptLines) {
            $transcriptLines[] = __('(Transcript is empty)', 'agentos');
        }

        return implode("\n", $transcriptLines);
    }
}
