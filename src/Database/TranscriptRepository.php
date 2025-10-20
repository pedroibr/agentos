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
      user_agent TEXT,
      transcript LONGTEXT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY post_id (post_id),
      KEY agent_id (agent_id)
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
                'user_agent' => $payload['user_agent'],
                'transcript' => wp_json_encode($payload['transcript']),
                'created_at' => current_time('mysql'),
            ]
        );

        if (!$result) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function list(int $postId, string $agentId = '', int $limit = 10): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_transcripts';

        if ($agentId) {
            $query = $wpdb->prepare(
                "SELECT * FROM $table WHERE post_id=%d AND agent_id=%s ORDER BY id DESC LIMIT %d",
                $postId,
                $agentId,
                $limit
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT * FROM $table WHERE post_id=%d ORDER BY id DESC LIMIT %d",
                $postId,
                $limit
            );
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        return array_map(static function ($row) {
            $row['transcript'] = json_decode($row['transcript'], true);
            return $row;
        }, $rows ?: []);
    }
}
