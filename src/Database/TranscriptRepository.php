<?php

namespace AgentOS\Database;

use AgentOS\Core\Config;
use wpdb;

class TranscriptRepository
{
    public function install(): void
    {
        $this->maybeCreateTable();
    }

    public function maybeCreateTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NOT NULL,
      agent_id VARCHAR(64) NOT NULL DEFAULT '',
      session_id VARCHAR(64) NOT NULL,
      anon_id VARCHAR(64) DEFAULT '',
      model VARCHAR(128) DEFAULT '',
      voice VARCHAR(64) DEFAULT '',
      user_email VARCHAR(190) DEFAULT '',
      user_key VARCHAR(190) DEFAULT '',
      subscription_slug VARCHAR(64) DEFAULT '',
      user_agent TEXT,
      transcript LONGTEXT,
      analysis_status VARCHAR(20) NOT NULL DEFAULT 'idle',
      analysis_model VARCHAR(128) DEFAULT '',
      analysis_prompt_version VARCHAR(32) DEFAULT '',
      analysis_prompt LONGTEXT,
      analysis_requested_at DATETIME DEFAULT NULL,
      analysis_completed_at DATETIME DEFAULT NULL,
      analysis_error TEXT,
      analysis_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      analysis_feedback LONGTEXT,
      tokens_realtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tokens_text BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tokens_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
      duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY post_id (post_id),
      KEY agent_id (agent_id),
      KEY analysis_status (analysis_status),
      KEY user_key (user_key),
      KEY subscription_slug (subscription_slug)
    ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function insert(array $payload)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';
        $this->maybeCreateTable();

        $result = $wpdb->insert(
            $table,
            [
                'post_id' => $payload['post_id'],
                'agent_id' => $payload['agent_id'],
                'session_id' => $payload['session_id'],
                'anon_id' => $payload['anon_id'],
                'model' => $payload['model'],
                'voice' => $payload['voice'],
                'user_email' => $payload['user_email'],
                'user_key' => $payload['user_key'] ?? '',
                'subscription_slug' => $payload['subscription_slug'] ?? '',
                'user_agent' => $payload['user_agent'],
                'transcript' => wp_json_encode($payload['transcript']),
                'analysis_status' => $payload['analysis_status'] ?? 'idle',
                'analysis_model' => $payload['analysis_model'] ?? '',
                'analysis_prompt_version' => $payload['analysis_prompt_version'] ?? '',
                'analysis_prompt' => $payload['analysis_prompt'] ?? '',
                'analysis_requested_at' => $payload['analysis_requested_at'] ?? null,
                'analysis_completed_at' => $payload['analysis_completed_at'] ?? null,
                'analysis_error' => $payload['analysis_error'] ?? '',
                'analysis_attempts' => isset($payload['analysis_attempts']) ? intval($payload['analysis_attempts']) : 0,
                'analysis_feedback' => $payload['analysis_feedback'] ?? '',
                'tokens_realtime' => isset($payload['tokens_realtime']) ? (int) $payload['tokens_realtime'] : 0,
                'tokens_text' => isset($payload['tokens_text']) ? (int) $payload['tokens_text'] : 0,
                'tokens_total' => isset($payload['tokens_total']) ? (int) $payload['tokens_total'] : 0,
                'duration_seconds' => isset($payload['duration_seconds']) ? max(0, (int) $payload['duration_seconds']) : 0,
                'created_at' => current_time('mysql'),
            ]
        );

        if (!$result) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $row['transcript'] = json_decode($row['transcript'], true);
        return $row;
    }

    public function list(int $postId, string $agentId = '', int $limit = 10, array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';

        $sql = "SELECT * FROM $table WHERE post_id = %d";
        $params = [$postId];

        if ($agentId) {
            $sql .= " AND agent_id = %s";
            $params[] = $agentId;
        }

        if (!empty($filters['user_email'])) {
            $sql .= " AND user_email = %s";
            $params[] = $filters['user_email'];
        }

        if (!empty($filters['anon_id'])) {
            $sql .= " AND anon_id = %s";
            $params[] = $filters['anon_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND analysis_status = %s";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_key'])) {
            $sql .= " AND user_key = %s";
            $params[] = $filters['user_key'];
        }

        $sql .= " ORDER BY id DESC LIMIT %d";
        $params[] = $limit;

        $query = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($query, ARRAY_A);

        return $this->normalizeRows($rows);
    }

    public function query(array $filters = [], int $limit = 20): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';

        $sql = "SELECT * FROM $table WHERE 1=1";
        $params = [];

        if (!empty($filters['post_id'])) {
            $sql .= " AND post_id = %d";
            $params[] = intval($filters['post_id']);
        }

        if (!empty($filters['agent_id'])) {
            $sql .= " AND agent_id = %s";
            $params[] = $filters['agent_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND analysis_status = %s";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_email'])) {
            $sql .= " AND user_email = %s";
            $params[] = $filters['user_email'];
        }

        if (!empty($filters['user_key'])) {
            $sql .= " AND user_key = %s";
            $params[] = $filters['user_key'];
        }

        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, $limit);

        $query = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($query, ARRAY_A);

        return $this->normalizeRows($rows);
    }

    public function reassignUserKey(string $originalKey, string $newKey): void
    {
        $originalKey = trim($originalKey);
        $newKey = trim($newKey);
        if ($originalKey === '' || $newKey === '' || $originalKey === $newKey) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';

        $wpdb->update(
            $table,
            ['user_key' => $newKey],
            ['user_key' => $originalKey],
            ['%s'],
            ['%s']
        );
    }

    public function summarizeUsage(string $subscriptionSlug, string $userKey, int $periodHours): array
    {
        if ($subscriptionSlug === '' || $userKey === '') {
            return [
                'tokens_realtime' => 0,
                'tokens_text' => 0,
                'tokens_total' => 0,
                'sessions' => 0,
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';
        $periodHours = max(1, (int) $periodHours);
        $thresholdTimestamp = current_time('timestamp') - ($periodHours * HOUR_IN_SECONDS);
        $threshold = wp_date('Y-m-d H:i:s', $thresholdTimestamp);

        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(SUM(tokens_realtime), 0) AS tokens_realtime,
                COALESCE(SUM(tokens_text), 0) AS tokens_text,
                COALESCE(SUM(tokens_total), 0) AS tokens_total,
                COUNT(*) AS sessions
            FROM $table
            WHERE subscription_slug = %s
              AND user_key = %s
              AND created_at >= %s",
            $subscriptionSlug,
            $userKey,
            $threshold
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row) {
            return [
                'tokens_realtime' => 0,
                'tokens_text' => 0,
                'tokens_total' => 0,
                'sessions' => 0,
            ];
        }

        return [
            'tokens_realtime' => isset($row['tokens_realtime']) ? (int) $row['tokens_realtime'] : 0,
            'tokens_text' => isset($row['tokens_text']) ? (int) $row['tokens_text'] : 0,
            'tokens_total' => isset($row['tokens_total']) ? (int) $row['tokens_total'] : 0,
            'sessions' => isset($row['sessions']) ? (int) $row['sessions'] : 0,
        ];
    }

    public function listUsers(int $limit = 50, array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';
        $limit = max(1, min($limit, 500));

        $where = "WHERE user_key <> ''";
        $params = [];

        if (!empty($filters['search'])) {
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where .= ' AND (user_key LIKE %s OR user_email LIKE %s OR anon_id LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT
            user_key,
            MAX(user_email) AS user_email,
            MAX(anon_id) AS anon_id,
            COUNT(*) AS sessions,
            MAX(created_at) AS last_activity,
            GROUP_CONCAT(DISTINCT subscription_slug) AS subscriptions
        FROM $table
        $where
        GROUP BY user_key
        ORDER BY last_activity DESC
        LIMIT %d";

        $params[] = $limit;
        $query = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($query, ARRAY_A);
        if (!$rows) {
            return [];
        }

        return array_map(static function ($row) {
            $row['sessions'] = isset($row['sessions']) ? (int) $row['sessions'] : 0;
            $row['subscriptions'] = array_filter(array_map('trim', explode(',', $row['subscriptions'] ?? '')));
            return $row;
        }, $rows);
    }

    public function updateAnalysis(int $id, array $fields): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';

        $allowed = [
            'analysis_status',
            'analysis_model',
            'analysis_prompt_version',
            'analysis_prompt',
            'analysis_requested_at',
            'analysis_completed_at',
            'analysis_error',
            'analysis_attempts',
            'analysis_feedback',
        ];

        $data = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $data[$key] = $value;
        }

        if (!$data) {
            return false;
        }

        $formats = [];
        foreach ($data as $key => $value) {
            if ($key === 'analysis_attempts') {
                $formats[] = '%d';
                $data[$key] = intval($value);
                continue;
            }

            if (($key === 'analysis_requested_at' || $key === 'analysis_completed_at') && empty($value)) {
                $data[$key] = null;
                $formats[] = '%s';
                continue;
            }

            $formats[] = '%s';
        }

        $result = $wpdb->update(
            $table,
            $data,
            ['id' => $id],
            $formats,
            ['%d']
        );

        if ($result === false) {
            return false;
        }

        return $result > 0;
    }

    private function normalizeRows($rows): array
    {
        if (empty($rows) || !is_array($rows)) {
            return [];
        }

        return array_map(static function ($row) {
            $row['transcript'] = json_decode($row['transcript'], true);
            $row['analysis_attempts'] = isset($row['analysis_attempts']) ? (int) $row['analysis_attempts'] : 0;
            if (!isset($row['analysis_status'])) {
                $row['analysis_status'] = 'idle';
            }
            if (!isset($row['analysis_feedback'])) {
                $row['analysis_feedback'] = '';
            }
            $row['tokens_realtime'] = isset($row['tokens_realtime']) ? (int) $row['tokens_realtime'] : 0;
            $row['tokens_text'] = isset($row['tokens_text']) ? (int) $row['tokens_text'] : 0;
            $row['tokens_total'] = isset($row['tokens_total']) ? (int) $row['tokens_total'] : 0;
            $row['duration_seconds'] = isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : 0;
            return $row;
        }, $rows);
    }
}
