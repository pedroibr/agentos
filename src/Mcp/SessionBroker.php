<?php

namespace AgentOS\Mcp;

use WP_Error;

/** Server-owned tool grants and call receipts. No MCP credential crosses this boundary. */
class SessionBroker
{
    private ToolManager $manager;
    private Diagnostics $diagnostics;
    private const TTL = 3600;

    public function __construct(?ToolManager $manager = null)
    {
        $this->manager = $manager ?: new ToolManager();
        $this->diagnostics = new Diagnostics();
    }

    public function issue(array $agent, string $agentId, int $postId, string $sessionId)
    {
        $resolved = $this->manager->resolveForAgent($agent);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (empty($resolved['tools'])) {
            return ['ticket' => '', 'tools' => [], 'bindings' => [], 'instructions' => ''];
        }
        $ticket = wp_generate_password(48, false, false);
        $contextId = $this->formContextId(get_current_user_id(), $agentId, $postId, $sessionId);
        $knownFields = [];
        $schemaConnections = [];
        foreach ($resolved['bindings'] as $binding) {
            $connectionId = (string) ($binding['connection_id'] ?? '');
            if ($connectionId === '' || isset($schemaConnections[$connectionId])) {
                continue;
            }
            $schemaConnections[$connectionId] = true;
            $schema = get_transient($this->formSchemaKey($contextId, $connectionId));
            if (is_array($schema) && is_array($schema['fields'] ?? null)) {
                $knownFields[$connectionId] = $schema['fields'];
            }
        }
        $record = [
            'actor' => get_current_user_id(),
            'agent_id' => $agentId,
            'post_id' => $postId,
            'session_id' => $sessionId,
            'expires' => time() + self::TTL,
            'functions' => array_keys($resolved['bindings']),
            'calls' => [],
            'known_fields' => $knownFields,
            'form_context_id' => $contextId,
            'form_schema_connections' => array_keys($schemaConnections),
        ];
        if (!set_transient($this->key($ticket), $record, self::TTL)) {
            return new WP_Error('mcp_storage', __('Unable to start tool access.', 'agentos'), ['status' => 503]);
        }
        return array_merge($resolved, ['ticket' => $ticket]);
    }

    public function validate(string $ticket, string $agentId, int $postId, string $sessionId)
    {
        $record = strlen($ticket) === 48 ? get_transient($this->key($ticket)) : false;
        if (!is_array($record) || (int) ($record['expires'] ?? 0) <= time() || (int) $record['actor'] !== get_current_user_id()
            || $record['agent_id'] !== $agentId || (int) $record['post_id'] !== $postId
            || $record['session_id'] !== $sessionId) {
            return new WP_Error('mcp_session', __('Tool access expired or does not belong to this conversation. Resume the session.', 'agentos'), ['status' => 403]);
        }
        return $record;
    }

    public function revoke(string $ticket): void
    {
        $record = strlen($ticket) === 48 ? get_transient($this->key($ticket)) : false;
        if (is_array($record)) {
            foreach ((array) ($record['form_schema_connections'] ?? []) as $connectionId) {
                delete_transient($this->formSchemaKey((string) ($record['form_context_id'] ?? ''), (string) $connectionId));
            }
        }
        delete_transient($this->key($ticket));
    }

    public function invoke(array $agent, string $ticket, string $agentId, int $postId, string $sessionId, string $callId, string $name, array $arguments, ?bool $decision = null, string $approvalId = '')
    {
        $grant = $this->validate($ticket, $agentId, $postId, $sessionId);
        if (is_wp_error($grant)) {
            return $grant;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,160}$/D', $callId) || !in_array($name, $grant['functions'], true)) {
            return new WP_Error('mcp_forbidden', __('This tool is not available to this conversation.', 'agentos'), ['status' => 403]);
        }
        $resolved = $this->manager->resolveForAgent($agent);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $binding = $resolved['bindings'][$name] ?? null;
        if (!$binding) {
            return new WP_Error('mcp_revoked', __('This tool is no longer enabled.', 'agentos'), ['status' => 403]);
        }
        $encoded = wp_json_encode($arguments);
        if (!is_string($encoded) || strlen($encoded) > 32768) {
            return new WP_Error('mcp_arguments', __('Tool arguments are too large.', 'agentos'), ['status' => 400]);
        }
        $key = $this->callKey($ticket, $callId);
        $signature = hash('sha256', $name . '|' . $encoded);
        $expected = get_transient('agentos_mcp_expected_' . hash('sha256', $ticket . '|' . $callId));
        if (is_string($expected) && !hash_equals($expected, $signature)) {
            return new WP_Error('mcp_conflict', __('This call does not match the model tool request.', 'agentos'), ['status' => 409]);
        }
        $lock = 'agentos_mcp_lock_' . hash('sha256', $ticket . '|' . $callId);
        if (!add_option($lock, time(), '', false)) {
            return new WP_Error('mcp_busy', __('This tool call is already being processed.', 'agentos'), ['status' => 409]);
        }
        try {
            $saved = get_transient($key);
            if (is_array($saved) && !hash_equals($saved['signature'], $signature)) {
                return new WP_Error('mcp_conflict', __('A tool call cannot be reused with different arguments.', 'agentos'), ['status' => 409]);
            }
            if (is_array($saved) && $saved['state'] === 'done') {
                return $saved['result'];
            }
            if (is_array($saved) && $saved['state'] === 'running') {
                return new WP_Error('mcp_unknown', __('The result of this tool call is not available. It will not be executed again.', 'agentos'), ['status' => 409]);
            }
            if (!$saved) {
                if (count($grant['calls']) >= 48) {
                    return new WP_Error('mcp_limit', __('This conversation reached its tool call limit. Start a new conversation.', 'agentos'), ['status' => 429]);
                }
                $grant['calls'][$callId] = true;
                set_transient($this->key($ticket), $grant, self::TTL);
                $saved = ['signature' => $signature, 'state' => 'pending', 'approval_id' => wp_generate_password(32, false, false)];
                if (!set_transient($key, $saved, self::TTL)) {
                    return new WP_Error('mcp_storage', __('Unable to prepare tool execution.', 'agentos'), ['status' => 503]);
                }
                // A browser cannot self-approve a call on its first request.
                $decision = null;
            }
            $requiresApproval = ($binding['approval'] ?? 'confirm') !== 'auto';
            if ($requiresApproval && ($decision === null || !hash_equals($saved['approval_id'], $approvalId))) {
                return [
                    'approval_required' => true,
                    'approval_id' => $saved['approval_id'],
                    'call_id' => $callId,
                    'connection' => $binding['connection_label'] ?? '',
                    'tool' => $binding['tool_name'],
                    'arguments' => $arguments,
                ];
            }
            if ($requiresApproval && $decision === false) {
                $result = ['output' => wp_json_encode(['isError' => true, 'content' => [['type' => 'text', 'text' => 'The user declined this tool call. Do not claim that it was executed.']]]), 'is_error' => true];
            } else {
                $stillAllowed = $this->validate($ticket, $agentId, $postId, $sessionId);
                if (is_wp_error($stillAllowed)) {
                    return $stillAllowed;
                }
                $saved['state'] = 'running';
                if (!set_transient($key, $saved, self::TTL)) {
                    return new WP_Error('mcp_storage', __('Unable to record tool execution.', 'agentos'), ['status' => 503]);
                }
                try {
                    $reference = Diagnostics::newReference();
                    $output = $this->manager->execute($agent, $name, $arguments, $reference);
                } catch (\Throwable $error) {
                    $reference = $reference ?? Diagnostics::newReference();
                    $output = new WP_Error('mcp_execution', __('The tool could not be completed.', 'agentos'), [
                        'mcp_diagnostic' => ['phase' => $binding['tool_name'], 'code' => 'mcp_execution'],
                    ]);
                }
                if (is_wp_error($output)) {
                    $reference = $reference ?? Diagnostics::newReference();
                    $knownFields = array_keys((array) ($grant['known_fields'][$binding['connection_id']] ?? []));
                    $allowedFields = (string) ($output->get_error_code()) === 'mcp_invalid_arguments'
                        ? array_merge((array) ($binding['argument_fields'] ?? []), $knownFields)
                        : $knownFields;
                    $entry = $this->diagnostics->recordError($output, $reference, (string) ($binding['connection_label'] ?? ''), (string) $binding['tool_name'], (string) $binding['tool_name'], $allowedFields);
                    $output = ['isError' => true, 'content' => [['type' => 'text', 'text' => Diagnostics::modelMessage($entry)]]];
                } elseif (is_array($output) && !empty($output['isError'])) {
                    $reference = $reference ?? Diagnostics::newReference();
                    $remote = $this->remoteResultDetails($output);
                    $knownFields = array_keys((array) ($grant['known_fields'][$binding['connection_id']] ?? []));
                    $remote['issues'] = array_values(array_filter($remote['issues'], static function ($issue) use ($knownFields) {
                        return in_array($issue['field'], $knownFields, true);
                    }));
                    $remote['fields'] = array_column($remote['issues'], 'field');
                    $entry = $this->diagnostics->record(
                        $reference,
                        (string) $binding['tool_name'],
                        (string) ($binding['connection_label'] ?? ''),
                        (string) $binding['tool_name'],
                        $remote['code'],
                        0,
                        $remote['remote_code'],
                        $remote['fields'],
                        $remote['issues']
                    );
                    $output = ['isError' => true, 'content' => [['type' => 'text', 'text' => Diagnostics::modelMessage($entry)]]];
                } elseif (is_array($output)) {
                    $this->rememberFormSchema($ticket, (string) $binding['connection_id'], (string) $binding['tool_name'], $output);
                }
                $json = wp_json_encode($output);
                if (!is_string($json) || strlen($json) > 131072) {
                    $reference = $reference ?? Diagnostics::newReference();
                    $entry = $this->diagnostics->record($reference, (string) $binding['tool_name'], (string) ($binding['connection_label'] ?? ''), (string) $binding['tool_name'], 'mcp_result_too_large');
                    $output = ['isError' => true, 'content' => [['type' => 'text', 'text' => Diagnostics::modelMessage($entry)]]];
                    $json = wp_json_encode($output);
                }
                $result = ['output' => $json, 'is_error' => !empty($output['isError'])];
                if (!empty($output['isError'])) {
                    $result['reference'] = $reference;
                }
            }
            $saved['state'] = 'done';
            $saved['result'] = $result;
            set_transient($key, $saved, self::TTL);
            return $result;
        } finally {
            delete_option($lock);
        }
    }

    public function completed(string $ticket, string $callId): ?array
    {
        $saved = get_transient($this->callKey($ticket, $callId));
        return is_array($saved) && $saved['state'] === 'done' ? $saved['result'] : null;
    }

    public function reserve(string $ticket, string $callId, string $name, string $arguments)
    {
        $decoded = json_decode($arguments);
        if (!$decoded instanceof \stdClass) {
            return new WP_Error('mcp_arguments', __('The model returned invalid tool arguments.', 'agentos'), ['status' => 502]);
        }
        $signature = hash('sha256', $name . '|' . wp_json_encode((array) $decoded));
        $key = 'agentos_mcp_expected_' . hash('sha256', $ticket . '|' . $callId);
        $old = get_transient($key);
        if ($old && !hash_equals($old, $signature)) {
            return new WP_Error('mcp_conflict', __('The model reused a tool call identifier.', 'agentos'), ['status' => 502]);
        }
        if (!$old && !set_transient($key, $signature, self::TTL)) {
            return new WP_Error('mcp_storage', __('Unable to prepare the tool request.', 'agentos'), ['status' => 503]);
        }
        return true;
    }

    private function key(string $ticket): string
    {
        return 'agentos_mcp_session_' . hash('sha256', $ticket);
    }

    private function callKey(string $ticket, string $callId): string
    {
        return 'agentos_mcp_call_' . hash('sha256', $ticket . '|' . $callId);
    }

    private function remoteResultDetails(array $output): array
    {
        $structured = is_array($output['structuredContent'] ?? null) ? $output['structuredContent'] : [];
        $detail = is_array($structured['details'] ?? null) ? $structured['details'] : [];
        $errors = is_array($detail['errors'] ?? null) ? $detail['errors'] : (is_array($structured['errors'] ?? null) ? $structured['errors'] : []);
        $code = in_array($structured['code'] ?? '', ['validation_error', 'schema_changed', 'answers_changed', 'confirmation_required', 'invalid_review', 'review_revoked', 'review_rejected', 'review_expired', 'submission_in_progress', 'submission_unknown'], true)
            ? $structured['code']
            : 'mcp_remote_error';
        $remoteCode = isset($structured['remote_code']) && is_int($structured['remote_code']) ? $structured['remote_code'] : null;
        $issues = [];
        if ($code === 'validation_error') {
            foreach ($errors as $key => $item) {
                if (is_array($item) && is_string($item['field'] ?? null)) {
                    $issues[] = ['field' => $item['field'], 'rule' => is_string($item['rule'] ?? null) ? $item['rule'] : 'other'];
                } elseif (is_string($key)) {
                    $issues[] = ['field' => $key, 'rule' => 'other'];
                }
            }
        }
        $issues = array_slice($issues, 0, 20);
        return ['code' => $code, 'remote_code' => $remoteCode, 'fields' => Diagnostics::safeFieldNames(array_column($issues, 'field')), 'issues' => $issues];
    }

    private function rememberFormSchema(string $ticket, string $connectionId, string $toolName, array $output): void
    {
        if ($toolName !== 'get_form_schema') {
            return;
        }
        foreach ((array) ($output['content'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['text'] ?? null)) {
                continue;
            }
            $payload = json_decode($item['text'], true);
            $fields = $payload['schema']['fields'] ?? null;
            if (!is_array($fields)) {
                continue;
            }
            $recordKey = $this->key($ticket);
            $grant = get_transient($recordKey);
            if (!is_array($grant)) {
                return;
            }
            $known = [];
            foreach ($fields as $field) {
                if (is_array($field) && is_string($field['name'] ?? null) && strlen($field['name']) <= 100) {
                    $known[$field['name']] = is_string($field['type'] ?? null) ? $field['type'] : '';
                }
            }
            if ($known) {
                $grant['known_fields'][$connectionId] = $known;
                set_transient($recordKey, $grant, self::TTL);
                $contextId = (string) ($grant['form_context_id'] ?? '');
                if ($contextId !== '') {
                    $grant['form_schema_connections'] = array_values(array_unique(array_merge((array) ($grant['form_schema_connections'] ?? []), [$connectionId])));
                    set_transient($recordKey, $grant, self::TTL);
                    set_transient($this->formSchemaKey($contextId, $connectionId), ['fields' => $known], self::TTL);
                }
            }
            return;
        }
    }

    private function formContextId(int $actor, string $agentId, int $postId, string $sessionId): string
    {
        return hash('sha256', $actor . "\0" . $agentId . "\0" . $postId . "\0" . $sessionId);
    }

    private function formSchemaKey(string $contextId, string $connectionId): string
    {
        return 'agentos_mcp_form_schema_' . hash('sha256', $contextId . "\0" . $connectionId);
    }
}
