<?php

namespace AgentOS\Database;

use AgentOS\Core\Config;
use wpdb;

class UsageRepository
{
    public function install(): void
    {
        $this->maybeCreateTable();
    }

    public function maybeCreateTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_key VARCHAR(190) NOT NULL DEFAULT '',
      subscription_slug VARCHAR(64) DEFAULT '',
      agent_id VARCHAR(64) NOT NULL DEFAULT '',
      session_id VARCHAR(64) NOT NULL,
      post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tokens_realtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tokens_text BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tokens_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
      duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      metadata LONGTEXT,
      recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY session_id (session_id),
      KEY user_key (user_key),
      KEY subscription_slug (subscription_slug),
      KEY agent_id (agent_id),
      KEY updated_at (updated_at)
    ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function logStart(array $payload): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $this->maybeCreateTable();

        $data = [
            'user_key' => $payload['user_key'] ?? '',
            'subscription_slug' => $payload['subscription_slug'] ?? '',
            'agent_id' => $payload['agent_id'] ?? '',
            'session_id' => $payload['session_id'] ?? '',
            'post_id' => isset($payload['post_id']) ? (int) $payload['post_id'] : 0,
            'status' => $payload['status'] ?? 'pending',
            'metadata' => isset($payload['metadata']) ? wp_json_encode($payload['metadata']) : null,
        ];

        if (!$data['session_id']) {
            return false;
        }

        $formats = ['%s', '%s', '%s', '%s', '%d', '%s', '%s'];

        return (bool) $wpdb->replace($table, $data, $formats);
    }

    public function updateBySession(string $sessionId, array $fields): bool
    {
        if (!$sessionId) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';

        $defaults = [
            'tokens_realtime' => null,
            'tokens_text' => null,
            'tokens_total' => null,
            'duration_seconds' => null,
            'status' => null,
            'subscription_slug' => null,
            'metadata' => null,
            'user_key' => null,
            'agent_id' => null,
            'post_id' => null,
        ];
        $fields = array_merge($defaults, $fields);

        $setParts = [];
        $params = [];

        foreach (['tokens_realtime', 'tokens_text', 'tokens_total', 'duration_seconds'] as $numericField) {
            if ($fields[$numericField] !== null) {
                $value = max(0, (int) $fields[$numericField]);
                $setParts[] = "$numericField = GREATEST($numericField, %d)";
                $params[] = $value;
            }
        }

        if ($fields['status'] !== null) {
            $setParts[] = 'status = %s';
            $params[] = sanitize_key($fields['status']);
        }

        foreach (['subscription_slug', 'user_key', 'agent_id'] as $textField) {
            if ($fields[$textField] !== null) {
                $setParts[] = "$textField = %s";
                $params[] = sanitize_text_field($fields[$textField]);
            }
        }

        if ($fields['post_id'] !== null) {
            $setParts[] = 'post_id = %d';
            $params[] = (int) $fields['post_id'];
        }

        if ($fields['metadata'] !== null) {
            $setParts[] = 'metadata = %s';
            $params[] = wp_json_encode($fields['metadata']);
        }

        if (!$setParts) {
            return false;
        }

        $setParts[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $sessionId;

        $sql = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE session_id = %s";
        $result = $wpdb->query($wpdb->prepare($sql, ...$params));

        if ($result === false) {
            return false;
        }

        return true;
    }

    public function summarizeUsage(string $subscriptionSlug, string $userKey, int $periodHours): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $periodHours = max(1, (int) $periodHours);
        $thresholdTs = current_time('timestamp') - ($periodHours * HOUR_IN_SECONDS);
        $threshold = wp_date('Y-m-d H:i:s', $thresholdTs);

        $conditions = ['updated_at >= %s'];
        $params = [$threshold];

        if ($subscriptionSlug !== '') {
            $conditions[] = 'subscription_slug = %s';
            $params[] = $subscriptionSlug;
        }

        if ($userKey !== '') {
            $conditions[] = 'user_key = %s';
            $params[] = $userKey;
        }

        $where = implode(' AND ', $conditions);

        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(SUM(tokens_realtime), 0) AS tokens_realtime,
                COALESCE(SUM(tokens_text), 0) AS tokens_text,
                COALESCE(SUM(tokens_total), 0) AS tokens_total,
                COUNT(DISTINCT session_id) AS sessions
            FROM $table
            WHERE $where",
            ...$params
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
            'tokens_realtime' => (int) $row['tokens_realtime'],
            'tokens_text' => (int) $row['tokens_text'],
            'tokens_total' => (int) $row['tokens_total'],
            'sessions' => (int) $row['sessions'],
        ];
    }

    public function listUsers(int $limit = 100, array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $limit = max(1, min(1000, $limit));

        $where = 'WHERE 1=1';
        $params = [];

        if (!empty($filters['search'])) {
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where .= ' AND (user_key LIKE %s OR subscription_slug LIKE %s)';
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT
            user_key,
            COALESCE(SUM(tokens_realtime), 0) AS tokens_realtime,
            COALESCE(SUM(tokens_text), 0) AS tokens_text,
            COALESCE(SUM(tokens_total), 0) AS tokens_total,
            COUNT(DISTINCT session_id) AS sessions,
            MAX(updated_at) AS last_activity
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
            return [
                'user_key' => $row['user_key'],
                'tokens_realtime' => (int) $row['tokens_realtime'],
                'tokens_text' => (int) $row['tokens_text'],
                'tokens_total' => (int) $row['tokens_total'],
                'sessions' => (int) $row['sessions'],
                'last_activity' => $row['last_activity'],
            ];
        }, $rows);
    }

    public function deleteOlderThanHours(int $hours): void
    {
        global $wpdb;
        $hours = max(1, $hours);
        $thresholdTs = current_time('timestamp') - ($hours * HOUR_IN_SECONDS);
        $threshold = wp_date('Y-m-d H:i:s', $thresholdTs);

        $table = $wpdb->prefix . 'agentos_usage';
        $wpdb->query(
            $wpdb->prepare("DELETE FROM $table WHERE updated_at < %s", $threshold)
        );
    }

    public function listForUser(string $userKey, int $limit = 25): array
    {
        $userKey = trim($userKey);
        if ($userKey === '') {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $limit = max(1, min(200, $limit));

        $sql = $wpdb->prepare(
            "SELECT session_id, subscription_slug, agent_id, post_id, tokens_realtime, tokens_text, tokens_total, duration_seconds, status, updated_at
            FROM $table
            WHERE user_key = %s
            ORDER BY updated_at DESC
            LIMIT %d",
            $userKey,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) {
            return [];
        }

        return array_map(static function ($row) {
            return [
                'session_id' => $row['session_id'],
                'subscription_slug' => $row['subscription_slug'],
                'agent_id' => $row['agent_id'],
                'post_id' => (int) $row['post_id'],
                'tokens_realtime' => (int) $row['tokens_realtime'],
                'tokens_text' => (int) $row['tokens_text'],
                'tokens_total' => (int) $row['tokens_total'],
                'duration_seconds' => (int) $row['duration_seconds'],
                'status' => $row['status'],
                'updated_at' => $row['updated_at'],
            ];
        }, $rows);
    }

    public function deleteForUser(string $userKey): void
    {
        $userKey = trim($userKey);
        if ($userKey === '') {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $wpdb->delete($table, ['user_key' => $userKey], ['%s']);
    }

    public function reassignUser(string $originalKey, string $newKey): void
    {
        $originalKey = trim($originalKey);
        $newKey = trim($newKey);
        if ($originalKey === '' || $newKey === '' || $originalKey === $newKey) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $wpdb->update(
            $table,
            ['user_key' => $newKey],
            ['user_key' => $originalKey],
            ['%s'],
            ['%s']
        );
    }
}
