<?php

namespace AgentOS\Mcp;

/** Stores safe MCP failure metadata for administrators and user-facing references. */
class Diagnostics
{
    private const OPTION = 'agentos_mcp_diagnostics';
    private const MAX_ENTRIES = 50;
    private const REMOTE_CODES = [
        'answers_changed', 'answers_too_large', 'client_disabled', 'client_unavailable',
        'confirmation_required', 'form_incompatible', 'form_unavailable', 'invalid_answers', 'invalid_link',
        'invalid_review', 'link_unavailable', 'read_disabled', 'rate_limited',
        'review_expired', 'review_rejected', 'review_revoked', 'schema_changed',
        'source_unavailable', 'submission_in_progress', 'submission_unknown',
        'submission_rejected', 'validation_error', 'write_disabled',
    ];
    private const VALIDATION_RULES = [
        'required', 'phone_invalid', 'phone_country_disallowed', 'phone_extension',
        'email_invalid', 'choice_invalid', 'terms_required', 'type_invalid',
        'number_invalid', 'date_invalid', 'length', 'range', 'missing_required',
        'unknown_field', 'invalid_type_or_value', 'other',
    ];

    public static function newReference(): string
    {
        return 'MCP-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    }

    /**
     * Persist only diagnostic metadata. Arguments, response bodies, endpoint URLs,
     * credentials, and user supplied values are deliberately not accepted here.
     */
    public function record(
        string $reference,
        string $phase,
        string $connection,
        string $tool,
        string $code,
        int $httpStatus = 0,
        ?int $remoteCode = null,
        array $fields = [],
        array $issues = []
    ): array {
        $entry = [
            'reference' => $this->safeToken($reference, '/^MCP-[A-F0-9]{12}$/D', 'MCP-UNKNOWN'),
            'time' => gmdate('Y-m-d H:i:s') . ' UTC',
            'connection' => sanitize_text_field($connection),
            'tool' => $this->safeToken($tool, '/^[a-zA-Z0-9_.:-]{1,100}$/D', 'unknown'),
            'phase' => $this->safeToken($phase, '/^[a-zA-Z0-9_.:-]{1,80}$/D', 'unknown'),
            'code' => $this->safeToken($code, '/^[a-zA-Z0-9_.:-]{1,100}$/D', 'mcp_error'),
            'http_status' => $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : 0,
            'remote_code' => $remoteCode,
            'fields' => self::safeFieldNames($fields),
            'issues' => self::safeIssues($issues),
        ];

        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            $entries = [];
        }
        array_unshift($entries, $entry);
        update_option(self::OPTION, array_slice($entries, 0, self::MAX_ENTRIES), false);

        return $entry;
    }

    /** Record a WP_Error without storing its message or arbitrary error data. */
    public function recordError(\WP_Error $error, string $reference, string $connection, string $tool, string $fallbackPhase = 'execute', array $allowedFields = []): array
    {
        $data = $error->get_error_data();
        $diagnostic = is_array($data) && is_array($data['mcp_diagnostic'] ?? null) ? $data['mcp_diagnostic'] : [];

        return $this->record(
            $reference,
            (string) ($diagnostic['phase'] ?? $fallbackPhase),
            $connection,
            $tool,
            (string) ($diagnostic['code'] ?? $error->get_error_code()),
            (int) ($diagnostic['http_status'] ?? 0),
            isset($diagnostic['remote_code']) && is_numeric($diagnostic['remote_code']) ? (int) $diagnostic['remote_code'] : null,
            $this->allowFields(is_array($diagnostic['fields'] ?? null) ? $diagnostic['fields'] : [], $allowedFields),
            is_array($diagnostic['issues'] ?? null) ? $this->allowIssues($diagnostic['issues'], $allowedFields) : []
        );
    }

    public function recent(int $limit = self::MAX_ENTRIES): array
    {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            return [];
        }
        return array_slice($entries, 0, max(0, min($limit, self::MAX_ENTRIES)));
    }

    public static function safeFieldNames(array $fields): array
    {
        $safe = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = trim($field);
            if ($field !== '' && strlen($field) <= 100 && preg_match('/^[a-zA-Z0-9_.\[\]-]+$/D', $field)) {
                $safe[] = $field;
            }
        }
        return array_slice(array_values(array_unique($safe)), 0, 20);
    }

    public static function safeRemoteDetails($remoteError, array $allowedFields = []): array
    {
        if (!is_array($remoteError)) {
            return ['remote_code' => null, 'code' => 'mcp_remote_error', 'fields' => [], 'issues' => []];
        }

        $data = is_array($remoteError['data'] ?? null) ? $remoteError['data'] : [];
        $candidateCode = is_string($data['code'] ?? null) ? $data['code'] : '';
        $appCode = in_array($candidateCode, self::REMOTE_CODES, true) ? $candidateCode : 'mcp_remote_error';
        $validation = is_array($data['details']['errors'] ?? null) ? $data['details']['errors'] : [];
        $issues = [];
        if ($appCode === 'validation_error') {
            foreach ($validation as $key => $item) {
                if (is_array($item) && is_string($item['field'] ?? null)) {
                    $field = $item['field'];
                    $rule = is_string($item['rule'] ?? null) ? $item['rule'] : self::validationRuleFromMessage((string) ($item['message'] ?? ''));
                    $issues[] = ['field' => $field, 'rule' => $rule];
                } elseif (is_string($key) && in_array($key, $allowedFields, true)) {
                    $issues[] = ['field' => $key, 'rule' => 'other'];
                }
            }
        }
        $issues = self::safeIssues($issues);
        if ($allowedFields) {
            $allowedFields = self::safeFieldNames($allowedFields);
            $issues = array_values(array_filter($issues, static function ($issue) use ($allowedFields) {
                return in_array($issue['field'], $allowedFields, true);
            }));
        }

        return [
            'remote_code' => isset($remoteError['code']) && is_int($remoteError['code']) ? $remoteError['code'] : null,
            'code' => $appCode,
            'fields' => self::safeFieldNames(array_column($issues, 'field')),
            'issues' => $issues,
        ];
    }

    public static function modelMessage(array $entry): string
    {
        $reference = (string) ($entry['reference'] ?? 'MCP-UNKNOWN');
        $phase = (string) ($entry['phase'] ?? 'unknown');
        $code = (string) ($entry['code'] ?? 'mcp_error');
        $fields = self::safeFieldNames((array) ($entry['fields'] ?? []));
        if ($fields && $phase === 'prepare_form_submission' && $code === 'validation_error') {
            $issues = self::safeIssues((array) ($entry['issues'] ?? []));
            $summary = $issues ? implode(', ', array_map(static function ($issue) {
                return $issue['field'] . ' (' . $issue['rule'] . ')';
            }, $issues)) : implode(', ', $fields);
            return 'Form validation needs correction for: ' . $summary
                . '. Ask only about those fields, one at a time, and do not repeat the full review. No submission occurred. Reference: ' . $reference . '.';
        }
        if ($fields && $phase === 'validate_arguments' && $code === 'mcp_invalid_arguments') {
            $issues = self::safeIssues((array) ($entry['issues'] ?? []));
            $summary = $issues ? implode(', ', array_map(static function ($issue) {
                return $issue['field'] . ' (' . $issue['rule'] . ')';
            }, $issues)) : implode(', ', $fields);
            return 'The MCP tool arguments do not match the declared schema. Check these argument names and types: '
                . $summary . '. Supply required keys, correct invalid types, and remove unknown keys without changing answer values. Reference: ' . $reference . '.';
        }

        return 'The MCP tool failed during ' . $phase . ' (' . $code
            . '). Do not assume it succeeded or retry a write automatically. Reference: ' . $reference . '.';
    }

    private function safeToken(string $value, string $pattern, string $fallback): string
    {
        return preg_match($pattern, $value) ? $value : $fallback;
    }

    private function allowFields(array $fields, array $allowedFields): array
    {
        if (!$allowedFields) {
            return [];
        }
        $safe = [];
        $allowed = self::safeFieldNames($allowedFields);
        foreach (self::safeFieldNames($fields) as $field) {
            if (in_array($field, $allowed, true)) {
                $safe[] = $field;
                continue;
            }
            $parts = explode('.', $field);
            if (count($parts) >= 2 && in_array($parts[0], $allowed, true) && in_array(implode('.', array_slice($parts, 1)), $allowed, true)) {
                $safe[] = $field;
            }
        }
        return array_values(array_unique($safe));
    }

    private function allowIssues(array $issues, array $allowedFields): array
    {
        if (!$allowedFields) {
            return [];
        }
        $allowed = self::safeFieldNames($allowedFields);
        $filtered = [];
        foreach ($issues as $issue) {
            if (is_array($issue) && $this->allowFields([(string) ($issue['field'] ?? '')], $allowed)) {
                $filtered[] = $issue;
            }
        }
        return self::safeIssues($filtered);
    }

    public static function safeIssues(array $issues): array
    {
        $safe = [];
        foreach ($issues as $issue) {
            if (!is_array($issue) || !is_string($issue['field'] ?? null) || !is_string($issue['rule'] ?? null)) {
                continue;
            }
            $field = self::safeFieldNames([$issue['field']]);
            if (!$field) {
                continue;
            }
            $rule = in_array($issue['rule'], self::VALIDATION_RULES, true) ? $issue['rule'] : 'other';
            $safe[] = ['field' => $field[0], 'rule' => $rule];
        }
        return array_slice($safe, 0, 20);
    }

    private static function validationRuleFromMessage(string $message): string
    {
        $message = strtolower($message);
        if (strpos($message, 'required') !== false) return 'required';
        if (strpos($message, 'must be accepted') !== false) return 'terms_required';
        if (strpos($message, 'phone extension') !== false) return 'phone_extension';
        if (strpos($message, 'phone number from an allowed country') !== false || strpos($message, 'phone number from a country that is not allowed') !== false) return 'phone_country_disallowed';
        if (strpos($message, 'international country code') !== false || strpos($message, 'valid phone number') !== false) return 'phone_invalid';
        if (strpos($message, 'valid email address') !== false) return 'email_invalid';
        if (strpos($message, 'invalid choice') !== false || strpos($message, 'selection') !== false) return 'choice_invalid';
        if (strpos($message, 'must be a number') !== false || strpos($message, 'must be numeric') !== false) return 'number_invalid';
        if (strpos($message, 'must be at least') !== false || strpos($message, 'must be at most') !== false) return 'range';
        if (strpos($message, 'real date') !== false) return 'date_invalid';
        if (strpos($message, 'characters') !== false) return 'length';
        if (strpos($message, 'must be text') !== false) return 'type_invalid';
        return 'other';
    }
}
