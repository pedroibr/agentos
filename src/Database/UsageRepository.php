<?php

namespace AgentOS\Database;

use AgentOS\Core\Config;
use wpdb;

class UsageRepository
{
    private static bool $schemaReady = false;

    public function install(): void
    {
        $this->maybeCreateTable();
    }

    public function maybeCreateTable(): void
    {
        if (self::$schemaReady) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        if ((int) get_option('agentos_usage_ledger_schema', 0) >= 2) {
            $tablesExist = true;
            foreach ([$table, $table . '_events', $table . '_reservations'] as $expectedTable) {
                $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($expectedTable)));
                if ((string) $found !== $expectedTable) {
                    $tablesExist = false;
                    break;
                }
            }
            if ($tablesExist) {
                self::$schemaReady = true;
                return;
            }
        }
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
      tokens_transcription BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tokens_analysis BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tokens_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
      legacy_tokens_realtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
      legacy_tokens_text BIGINT UNSIGNED NOT NULL DEFAULT 0,
      legacy_tokens_transcription BIGINT UNSIGNED NOT NULL DEFAULT 0,
      legacy_tokens_analysis BIGINT UNSIGNED NOT NULL DEFAULT 0,
      legacy_tokens_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
      legacy_occurred_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      legacy_transcription_seconds DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0,
      duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
      transcription_seconds DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      metadata LONGTEXT,
      ticket_hash CHAR(64) NOT NULL DEFAULT '',
      subscription_expires_at DATETIME DEFAULT NULL,
      started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY session_id (session_id),
      KEY user_key (user_key),
      KEY subscription_slug (subscription_slug),
      KEY agent_id (agent_id),
      KEY updated_at (updated_at)
    ) $charset;";

        $eventsSql = "CREATE TABLE {$table}_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      session_id VARCHAR(64) NOT NULL,
      event_key CHAR(64) NOT NULL,
      category VARCHAR(20) NOT NULL,
      source VARCHAR(24) NOT NULL DEFAULT 'server',
      verified TINYINT(1) NOT NULL DEFAULT 1,
      input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      total_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      transcription_seconds DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0,
      metadata LONGTEXT,
      occurred_at BIGINT UNSIGNED NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY session_event (session_id,event_key),
      KEY occurred_at (occurred_at),
      KEY category (category),
      KEY verified (verified)
    ) $charset;";

        $reservationsSql = "CREATE TABLE {$table}_reservations (
      reservation_id CHAR(64) NOT NULL,
      session_id VARCHAR(64) NOT NULL,
      user_key VARCHAR(190) NOT NULL DEFAULT '',
      subscription_slug VARCHAR(64) NOT NULL DEFAULT '',
      category VARCHAR(20) NOT NULL,
      reserved_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      expires_at BIGINT UNSIGNED NOT NULL,
      unknown_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
      state VARCHAR(12) NOT NULL DEFAULT 'held',
      PRIMARY KEY (reservation_id),
      KEY user_plan (user_key,subscription_slug),
      KEY session_id (session_id),
      KEY expires_at (expires_at)
    ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($eventsSql);
        dbDelta($reservationsSql);
        if ((int) get_option('agentos_usage_ledger_schema', 0) < 2) {
            $wpdb->query(
                "UPDATE $table u SET
                    legacy_tokens_realtime = tokens_realtime,
                    legacy_tokens_text = tokens_text,
                    legacy_tokens_transcription = tokens_transcription,
                    legacy_tokens_analysis = tokens_analysis,
                    legacy_tokens_total = tokens_total,
                    legacy_transcription_seconds = transcription_seconds,
                    legacy_occurred_at = CASE WHEN tokens_total > 0 OR transcription_seconds > 0 THEN UNIX_TIMESTAMP(updated_at) ELSE 0 END
                WHERE (tokens_total > 0 OR tokens_realtime > 0 OR tokens_text > 0 OR tokens_transcription > 0 OR tokens_analysis > 0 OR transcription_seconds > 0)
                  AND NOT EXISTS (SELECT 1 FROM {$table}_events e WHERE e.session_id = u.session_id)"
            );
            update_option('agentos_usage_ledger_schema', 2, false);
        }
        self::$schemaReady = true;
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

        $result = $wpdb->insert($table, $data, $formats);
        if ($result !== false) {
            return true;
        }

        // A repeated session start must never replace or zero an existing ledger row.
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE session_id = %s", $data['session_id']));
    }

    /** Create one server-owned session ticket. Client session IDs are not identity proof. */
    public function createSession(array $payload, string $ticket): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $this->maybeCreateTable();
        $sessionId = sanitize_text_field($payload['session_id'] ?? '');
        if ($sessionId === '' || $ticket === '') {
            return false;
        }

        $data = [
            'user_key' => sanitize_text_field($payload['user_key'] ?? ''),
            'subscription_slug' => sanitize_key($payload['subscription_slug'] ?? ''),
            'agent_id' => sanitize_key($payload['agent_id'] ?? ''),
            'session_id' => $sessionId,
            'post_id' => max(0, (int) ($payload['post_id'] ?? 0)),
            'status' => sanitize_key($payload['status'] ?? 'pending'),
            'metadata' => wp_json_encode($payload['metadata'] ?? []),
            'ticket_hash' => hash('sha256', $ticket),
            'subscription_expires_at' => !empty($payload['subscription_expires_at']) ? $payload['subscription_expires_at'] : null,
            'started_at' => time(),
        ];

        return $wpdb->insert(
            $table,
            $data,
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d']
        ) !== false;
    }

    /** Create a traceable legacy usage row for an older transcript lacking a session ledger row. */
    public function linkLegacyTranscript(array $transcript): ?array
    {
        $sessionId = sanitize_text_field((string) ($transcript['session_id'] ?? ''));
        if ($sessionId === '') {
            return null;
        }
        $existing = $this->getSession($sessionId);
        if ($existing) {
            return $existing;
        }
        $created = strtotime((string) ($transcript['created_at'] ?? ''));
        $created = $created === false ? time() : $created;
        $payload = [
            'session_id' => $sessionId,
            'user_key' => sanitize_text_field((string) ($transcript['user_key'] ?? '')),
            'subscription_slug' => sanitize_key((string) ($transcript['subscription_slug'] ?? '')),
            'agent_id' => sanitize_key((string) ($transcript['agent_id'] ?? '')),
            'post_id' => max(0, (int) ($transcript['post_id'] ?? 0)),
            'status' => 'legacy',
            'metadata' => ['origin' => 'legacy_transcript', 'usage_source' => 'legacy_estimated'],
        ];
        if (!$this->logStart($payload)) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $realtime = max(0, (int) ($transcript['tokens_realtime'] ?? 0));
        $text = max(0, (int) ($transcript['tokens_text'] ?? 0));
        $total = max(0, (int) ($transcript['tokens_total'] ?? ($realtime + $text)));
        $hasTokens = $realtime > 0 || $text > 0 || $total > 0;
        $updated = $wpdb->update($table, [
            'tokens_realtime' => $realtime,
            'tokens_text' => $text,
            'tokens_total' => $total,
            'legacy_tokens_realtime' => $realtime,
            'legacy_tokens_text' => $text,
            'legacy_tokens_total' => $total,
            'legacy_occurred_at' => $hasTokens ? $created : 0,
            'started_at' => $created,
        ], ['session_id' => $sessionId], ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d'], ['%s']);
        if ($updated === false) {
            return null;
        }
        return $this->getSession($sessionId);
    }

    public function getSession(string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE session_id = %s", $sessionId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** Merge trusted server metadata without replacing other lifecycle fields. */
    public function setSessionMetadata(string $sessionId, array $metadata): bool
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return false;
        }
        $current = json_decode((string) ($session['metadata'] ?? ''), true);
        $current = is_array($current) ? $current : [];
        $merged = array_replace($current, $metadata);
        return $this->updateBySession($sessionId, ['metadata' => $merged]);
    }

    /** Release an unconsumed session slot after a definite pre-generation failure. */
    public function releaseEmptySession(string $sessionId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $events = $table . '_events';
        $reservations = $table . '_reservations';
        $wpdb->delete($reservations, ['session_id' => $sessionId], ['%s']);
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE session_id = %s AND NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = %s)",
            $sessionId,
            $sessionId
        ));
        return $result !== false;
    }

    public function validateSession(string $sessionId, string $ticket, string $userKey, string $agentId, int $postId): ?array
    {
        $row = $this->getSession($sessionId);
        if (!$row || $ticket === '' || empty($row['ticket_hash'])) {
            return null;
        }
        if (in_array((string) ($row['status'] ?? ''), ['final', 'usage_error'], true)) {
            return null;
        }
        if (!hash_equals((string) $row['ticket_hash'], hash('sha256', $ticket))) {
            return null;
        }
        if (!hash_equals((string) ($row['user_key'] ?? ''), $userKey)
            || !hash_equals((string) ($row['agent_id'] ?? ''), sanitize_key($agentId))
            || (int) ($row['post_id'] ?? 0) !== $postId
        ) {
            return null;
        }
        return $row;
    }

    /** Serialize plan checks and budget reservations for one usage owner. */
    public function withUsageLock(string $scope, callable $callback)
    {
        global $wpdb;
        $name = 'agentos_usage_' . substr(hash('sha256', $scope), 0, 48);
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
        if ((string) $locked !== '1') {
            return false;
        }
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public function reserveBudget(string $reservationId, string $sessionId, string $userKey, string $subscriptionSlug, string $category, int $tokens, int $periodHours): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage_reservations';
        $category = in_array($category, ['realtime', 'text'], true) ? $category : 'text';
        $now = time();
        $leaseExpires = $now + 120;
        $unknownUntil = $now + (max(1, $periodHours) * HOUR_IN_SECONDS);
        return $wpdb->insert(
            $table,
            [
                'reservation_id' => hash('sha256', $reservationId),
                'session_id' => $sessionId,
                'user_key' => $userKey,
                'subscription_slug' => $subscriptionSlug,
                'category' => $category,
                'reserved_tokens' => max(0, $tokens),
                'expires_at' => $leaseExpires,
                'unknown_until' => $unknownUntil,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
        ) !== false;
    }

    public function settleBudget(string $reservationId, string $sessionId, string $eventKey, string $category, array $usage, string $source = 'server', array $metadata = []): bool
    {
        if (!$this->recordEvent($sessionId, $eventKey, $category, $usage, $source, $metadata)) {
            return false;
        }
        $this->releaseBudget($reservationId);
        return true;
    }

    public function releaseBudget(string $reservationId, bool $keepUnknown = false): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage_reservations';
        $key = hash('sha256', $reservationId);
        if ($keepUnknown) {
            // An API timeout can mean the provider completed the request but its usage event was lost.
            // Retain the maximum reservation for the configured quota window without reporting it as usage.
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET state = 'unknown', expires_at = unknown_until WHERE reservation_id = %s",
                $key
            ));
            return;
        }
        $wpdb->delete($table, ['reservation_id' => $key], ['%s']);
    }

    public function refreshBudget(string $reservationId, int $tokens, int $periodHours): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage_reservations';
        $now = time();
        return $wpdb->update(
            $table,
            [
                'reserved_tokens' => max(0, $tokens),
                'expires_at' => $now + 120,
                'unknown_until' => $now + (max(1, $periodHours) * HOUR_IN_SECONDS),
                'state' => 'held',
            ],
            ['reservation_id' => hash('sha256', $reservationId)],
            ['%d', '%d', '%d', '%s'],
            ['%s']
        ) !== false;
    }

    /** Append one provider usage event; event keys make response and transcription retries idempotent. */
    public function recordEvent(string $sessionId, string $eventKey, string $category, array $usage, string $source = 'server', array $metadata = []): bool
    {
        $allowedCategories = ['realtime', 'text', 'transcription', 'analysis'];
        if ($sessionId === '' || $eventKey === '' || !in_array($category, $allowedCategories, true)) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $events = $table . '_events';
        $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));
        $totalTokens = array_key_exists('total_tokens', $usage)
            ? max(0, (int) $usage['total_tokens'])
            : $inputTokens + $outputTokens;
        $seconds = max(0, (float) ($usage['transcription_seconds'] ?? $usage['seconds'] ?? 0));
        $source = in_array($source, ['server', 'monitor', 'browser_reported', 'browser_unverified'], true) ? $source : 'server';
        $browserReported = in_array($source, ['browser_reported', 'browser_unverified'], true);
        $verified = $browserReported ? 0 : 1;
        $rawTime = (string) ($usage['occurred_at'] ?? '');
        $timestamp = $rawTime !== '' ? strtotime($rawTime) : false;
        $occurredAt = $timestamp === false ? time() : $timestamp;
        // Reports have a separate idempotency namespace. A later server-confirmed
        // event promotes the matching browser report instead of double-counting it.
        $eventHash = hash('sha256', ($browserReported ? 'browser:' : 'provider:') . $category . ':' . $eventKey);
        $providerHash = hash('sha256', 'provider:' . $category . ':' . $eventKey);
        $browserHash = hash('sha256', 'browser:' . $category . ':' . $eventKey);
        $authoritative = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $events WHERE session_id = %s AND event_key = %s AND verified = 1",
            $sessionId,
            $providerHash
        ));
        if ($authoritative) {
            return $this->refreshSessionProjection($sessionId);
        }
        if (!$browserReported) {
            $reported = $wpdb->get_row($wpdb->prepare(
                "SELECT id,input_tokens,output_tokens,total_tokens,transcription_seconds FROM $events WHERE session_id = %s AND event_key = %s AND verified = 0",
                $sessionId,
                $browserHash
            ), ARRAY_A);
            if (is_array($reported)) {
                $updated = $wpdb->update($events, [
                    'event_key' => $providerHash,
                    'source' => $source,
                    'verified' => 1,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $totalTokens,
                    'transcription_seconds' => $seconds,
                    'metadata' => wp_json_encode($metadata),
                    'occurred_at' => $occurredAt,
                ], ['id' => (int) $reported['id']], ['%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%d'], ['%d']);
                if ($updated === false) {
                    return false;
                }
                return $this->refreshSessionProjection($sessionId);
            }
        }
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $events WHERE session_id = %s AND event_key = %s",
            $sessionId,
            $eventHash
        ));
        if ($exists) {
            return $this->refreshSessionProjection($sessionId);
        }

        // IGNORE suppresses only duplicate-key warnings during concurrent retries;
        // genuine SQL failures still return false and block the request.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $events
                (session_id,event_key,category,source,verified,input_tokens,output_tokens,total_tokens,transcription_seconds,metadata,occurred_at)
             VALUES (%s,%s,%s,%s,%d,%d,%d,%d,%f,%s,%d)",
            $sessionId,
            $eventHash,
            $category,
            $source,
            $verified,
            $inputTokens,
            $outputTokens,
            $totalTokens,
            $seconds,
            wp_json_encode($metadata),
            $occurredAt
        ));
        if ($inserted === false) {
            return false;
        }
        if ((int) $inserted === 0) {
            $exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $events WHERE session_id = %s AND event_key = %s",
                $sessionId,
                $eventHash
            ));
            return $exists && $this->refreshSessionProjection($sessionId);
        }

        return $this->refreshSessionProjection($sessionId);
    }

    /** Rebuild compatibility counters from snapshots plus ledger events; safe on replay after a failed UPDATE. */
    private function refreshSessionProjection(string $sessionId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $events = $table . '_events';
        $legacy = $this->getSession($sessionId);
        if (!$legacy) {
            return false;
        }
        $eventTotals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN category='realtime' THEN total_tokens ELSE 0 END),0) AS tokens_realtime,
                COALESCE(SUM(CASE WHEN category='text' THEN total_tokens ELSE 0 END),0) AS tokens_text,
                COALESCE(SUM(CASE WHEN category='transcription' THEN total_tokens ELSE 0 END),0) AS tokens_transcription,
                COALESCE(SUM(CASE WHEN category='analysis' THEN total_tokens ELSE 0 END),0) AS tokens_analysis,
                COALESCE(SUM(total_tokens),0) AS tokens_total,
                COALESCE(SUM(transcription_seconds),0) AS transcription_seconds,
                COUNT(*) AS event_count
             FROM $events WHERE session_id = %s",
            $sessionId
        ), ARRAY_A);
        if (!is_array($eventTotals) || (int) ($eventTotals['event_count'] ?? 0) < 1) {
            return false;
        }
        $hasLegacy = (int) ($legacy['legacy_occurred_at'] ?? 0) > 0;
        $fields = [
            'tokens_realtime' => (int) ($eventTotals['tokens_realtime'] ?? 0) + (int) ($hasLegacy ? ($legacy['legacy_tokens_realtime'] ?? 0) : 0),
            'tokens_text' => (int) ($eventTotals['tokens_text'] ?? 0) + (int) ($hasLegacy ? ($legacy['legacy_tokens_text'] ?? 0) : 0),
            'tokens_transcription' => (int) ($eventTotals['tokens_transcription'] ?? 0) + (int) ($hasLegacy ? ($legacy['legacy_tokens_transcription'] ?? 0) : 0),
            'tokens_analysis' => (int) ($eventTotals['tokens_analysis'] ?? 0) + (int) ($hasLegacy ? ($legacy['legacy_tokens_analysis'] ?? 0) : 0),
            'tokens_total' => (int) ($eventTotals['tokens_total'] ?? 0) + (int) ($hasLegacy ? ($legacy['legacy_tokens_total'] ?? 0) : 0),
            'transcription_seconds' => (float) ($eventTotals['transcription_seconds'] ?? 0) + (float) ($hasLegacy ? ($legacy['legacy_transcription_seconds'] ?? 0) : 0),
        ];
        return $wpdb->update($table, $fields, ['session_id' => $sessionId], ['%d', '%d', '%d', '%d', '%d', '%f'], ['%s']) !== false;
    }

    public function sessionUsage(string $sessionId, bool $verifiedOnly = false): array
    {
        global $wpdb;
        $events = $wpdb->prefix . 'agentos_usage_events';
        $whereVerified = $verifiedOnly ? ' AND verified = 1' : '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN category = 'realtime' THEN total_tokens ELSE 0 END),0) AS tokens_realtime,
                COALESCE(SUM(CASE WHEN category = 'text' THEN total_tokens ELSE 0 END),0) AS tokens_text,
                COALESCE(SUM(CASE WHEN category = 'transcription' THEN total_tokens ELSE 0 END),0) AS tokens_transcription,
                COALESCE(SUM(CASE WHEN category = 'analysis' THEN total_tokens ELSE 0 END),0) AS tokens_analysis,
                COALESCE(SUM(transcription_seconds),0) AS transcription_seconds,
                COALESCE(SUM(total_tokens),0) AS tokens_total,
                COUNT(*) AS event_count
            FROM $events WHERE session_id = %s $whereVerified",
            $sessionId
        ), ARRAY_A);

        $legacy = $this->getSession($sessionId) ?: [];
        $hasEvents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE session_id = %s", $sessionId)) > 0;
        $hasLegacySnapshot = (int) ($legacy['legacy_occurred_at'] ?? 0) > 0;
        $legacyTokens = [
            'tokens_realtime' => (int) ($hasLegacySnapshot || $hasEvents ? ($legacy['legacy_tokens_realtime'] ?? 0) : ($legacy['tokens_realtime'] ?? 0)),
            'tokens_text' => (int) ($hasLegacySnapshot || $hasEvents ? ($legacy['legacy_tokens_text'] ?? 0) : ($legacy['tokens_text'] ?? 0)),
            'tokens_transcription' => (int) ($hasLegacySnapshot || $hasEvents ? ($legacy['legacy_tokens_transcription'] ?? 0) : ($legacy['tokens_transcription'] ?? 0)),
            'tokens_analysis' => (int) ($hasLegacySnapshot || $hasEvents ? ($legacy['legacy_tokens_analysis'] ?? 0) : ($legacy['tokens_analysis'] ?? 0)),
            'tokens_total' => (int) ($hasLegacySnapshot || $hasEvents ? ($legacy['legacy_tokens_total'] ?? 0) : ($legacy['tokens_total'] ?? 0)),
        ];
        if ($hasEvents) {
            $result = [
                'tokens_realtime' => (int) $row['tokens_realtime'] + $legacyTokens['tokens_realtime'],
                'tokens_text' => (int) $row['tokens_text'] + $legacyTokens['tokens_text'],
                'tokens_transcription' => (int) $row['tokens_transcription'] + $legacyTokens['tokens_transcription'],
                'tokens_analysis' => (int) $row['tokens_analysis'] + $legacyTokens['tokens_analysis'],
                'transcription_seconds' => (float) $row['transcription_seconds'] + (float) ($hasLegacySnapshot ? ($legacy['legacy_transcription_seconds'] ?? 0) : 0),
                'tokens_total' => (int) $row['tokens_total'] + $legacyTokens['tokens_total'],
                'usage_source' => 'unknown',
                'legacy_estimated_tokens' => $legacyTokens['tokens_total'],
            ];
        } else {
            $result = [
                'tokens_realtime' => $legacyTokens['tokens_realtime'],
                'tokens_text' => $legacyTokens['tokens_text'],
                'tokens_transcription' => $legacyTokens['tokens_transcription'],
                'tokens_analysis' => $legacyTokens['tokens_analysis'],
                'transcription_seconds' => (float) ($hasLegacySnapshot ? ($legacy['legacy_transcription_seconds'] ?? 0) : ($legacy['transcription_seconds'] ?? 0)),
                'tokens_total' => $legacyTokens['tokens_total'],
                'usage_source' => 'legacy_estimated',
                'legacy_estimated_tokens' => $legacyTokens['tokens_total'],
            ];
        }

        $unverified = $wpdb->prefix . 'agentos_usage_events';
        $browserReported = $this->browserReportedSummary($sessionId);
        $verifiedEvents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events WHERE session_id = %s AND verified = 1", $sessionId));
        $result['usage_source'] = $browserReported['events'] > 0
            ? ($verifiedEvents > 0
                ? ($legacyTokens['tokens_total'] > 0 ? 'server_verified_with_browser_reported_and_legacy_estimate' : 'server_verified_with_browser_reported')
                : ($legacyTokens['tokens_total'] > 0 ? 'browser_reported_with_legacy_estimate' : 'browser_reported'))
            : ($verifiedEvents > 0
                ? ($legacyTokens['tokens_total'] > 0 ? 'server_verified_with_legacy_estimate' : 'server_verified')
                : 'legacy_estimated');
        $result['browser_reported_tokens'] = $browserReported['tokens'];
        $result['browser_reported_events'] = $browserReported['events'];
        $result['browser_unverified_tokens'] = $result['browser_reported_tokens'];
        return $this->appendReservationUsage($sessionId, $result);
    }

    private function browserReportedSummary(string $sessionId): array
    {
        global $wpdb;
        $unverified = $wpdb->prefix . 'agentos_usage_events';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(total_tokens),0) AS tokens, COUNT(*) AS events FROM $unverified WHERE session_id = %s AND verified = 0",
            $sessionId
        ), ARRAY_A) ?: [];
        return ['tokens' => (int) ($row['tokens'] ?? 0), 'events' => (int) ($row['events'] ?? 0)];
    }

    private function appendReservationUsage(string $sessionId, array $result): array
    {
        global $wpdb;
        $reservations = $wpdb->prefix . 'agentos_usage_reservations';
        $result['reserved_tokens_total'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(reserved_tokens),0) FROM $reservations WHERE session_id = %s AND expires_at > %d",
            $sessionId,
            time()
        ));
        return $result;
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
        $events = $table . '_events';
        $reservations = $table . '_reservations';
        $thresholdTs = time() - (max(1, (int) $periodHours) * HOUR_IN_SECONDS);

        $conditions = ['u.session_id = e.session_id', 'e.occurred_at >= %d'];
        $params = [$thresholdTs];
        if ($subscriptionSlug !== '') {
            $conditions[] = 'u.subscription_slug = %s';
            $params[] = $subscriptionSlug;
        }
        if ($userKey !== '') {
            $conditions[] = 'u.user_key = %s';
            $params[] = $userKey;
        }
        $eventRow = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN e.category = 'realtime' THEN e.total_tokens ELSE 0 END),0) AS tokens_realtime,
                COALESCE(SUM(CASE WHEN e.category = 'text' THEN e.total_tokens ELSE 0 END),0) AS tokens_text,
                COALESCE(SUM(CASE WHEN e.category = 'transcription' THEN e.total_tokens ELSE 0 END),0) AS tokens_transcription,
                COALESCE(SUM(CASE WHEN e.category = 'analysis' THEN e.total_tokens ELSE 0 END),0) AS tokens_analysis,
                COALESCE(SUM(e.total_tokens),0) AS tokens_total,
                COALESCE(SUM(e.transcription_seconds),0) AS transcription_seconds,
                COALESCE(SUM(CASE WHEN e.verified = 1 THEN e.total_tokens ELSE 0 END),0) AS verified_tokens,
                COALESCE(SUM(CASE WHEN e.verified = 0 THEN e.total_tokens ELSE 0 END),0) AS browser_reported_tokens
            FROM $events e JOIN $table u ON " . implode(' AND ', $conditions),
            ...$params
        ), ARRAY_A) ?: [];

        // Preserve pre-ledger history as an estimate alongside provider events.
        $legacyConditions = ['COALESCE(NULLIF(u.legacy_occurred_at, 0), UNIX_TIMESTAMP(u.updated_at)) >= %d'];
        $legacyParams = [$thresholdTs];
        if ($subscriptionSlug !== '') {
            $legacyConditions[] = 'u.subscription_slug = %s';
            $legacyParams[] = $subscriptionSlug;
        }
        if ($userKey !== '') {
            $legacyConditions[] = 'u.user_key = %s';
            $legacyParams[] = $userKey;
        }
        $legacyRow = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_realtime WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_realtime ELSE 0 END),0) AS tokens_realtime,
                COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_text WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_text ELSE 0 END),0) AS tokens_text,
                COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_transcription WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_transcription ELSE 0 END),0) AS tokens_transcription,
                COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_analysis WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_analysis ELSE 0 END),0) AS tokens_analysis,
                COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_total WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_total ELSE 0 END),0) AS tokens_total,
                COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_transcription_seconds WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.transcription_seconds ELSE 0 END),0) AS transcription_seconds
            FROM $table u WHERE " . implode(' AND ', $legacyConditions),
            ...$legacyParams
        ), ARRAY_A) ?: [];

        $unverifiedConditions = ['u.session_id = e.session_id', 'e.occurred_at >= %d', 'e.verified = 0'];
        $unverifiedParams = [$thresholdTs];
        if ($subscriptionSlug !== '') {
            $unverifiedConditions[] = 'u.subscription_slug = %s';
            $unverifiedParams[] = $subscriptionSlug;
        }
        if ($userKey !== '') {
            $unverifiedConditions[] = 'u.user_key = %s';
            $unverifiedParams[] = $userKey;
        }
        $unverifiedTokens = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(e.total_tokens),0) FROM $events e JOIN $table u ON " . implode(' AND ', $unverifiedConditions),
            ...$unverifiedParams
        ));

        $sessionConditions = ['COALESCE(NULLIF(started_at, 0), UNIX_TIMESTAMP(recorded_at)) >= %d'];
        $sessionParams = [$thresholdTs];
        if ($subscriptionSlug !== '') {
            $sessionConditions[] = 'subscription_slug = %s';
            $sessionParams[] = $subscriptionSlug;
        }
        if ($userKey !== '') {
            $sessionConditions[] = 'user_key = %s';
            $sessionParams[] = $userKey;
        }
        $sessions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $table WHERE " . implode(' AND ', $sessionConditions),
            ...$sessionParams
        ));

        $reservationConditions = ['expires_at > %d'];
        $reservationParams = [time()];
        if ($subscriptionSlug !== '') {
            $reservationConditions[] = 'subscription_slug = %s';
            $reservationParams[] = $subscriptionSlug;
        }
        if ($userKey !== '') {
            $reservationConditions[] = 'user_key = %s';
            $reservationParams[] = $userKey;
        }
        $reserved = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN category = 'realtime' THEN reserved_tokens ELSE 0 END),0) AS reserved_realtime_tokens,
                COALESCE(SUM(CASE WHEN category = 'text' THEN reserved_tokens ELSE 0 END),0) AS reserved_text_tokens,
                COALESCE(SUM(reserved_tokens),0) AS reserved_tokens_total
            FROM $reservations WHERE " . implode(' AND ', $reservationConditions),
            ...$reservationParams
        ), ARRAY_A) ?: [];

        return [
            'tokens_realtime' => (int) ($eventRow['tokens_realtime'] ?? 0) + (int) ($legacyRow['tokens_realtime'] ?? 0),
            'tokens_text' => (int) ($eventRow['tokens_text'] ?? 0) + (int) ($legacyRow['tokens_text'] ?? 0),
            'tokens_transcription' => (int) ($eventRow['tokens_transcription'] ?? 0) + (int) ($legacyRow['tokens_transcription'] ?? 0),
            'tokens_analysis' => (int) ($eventRow['tokens_analysis'] ?? 0) + (int) ($legacyRow['tokens_analysis'] ?? 0),
            'transcription_seconds' => (float) ($eventRow['transcription_seconds'] ?? 0) + (float) ($legacyRow['transcription_seconds'] ?? 0),
            'tokens_total' => (int) ($eventRow['tokens_total'] ?? 0) + (int) ($legacyRow['tokens_total'] ?? 0),
            'legacy_estimated_tokens' => (int) ($legacyRow['tokens_total'] ?? 0),
            'verified_tokens' => (int) ($eventRow['verified_tokens'] ?? 0),
            'browser_reported_tokens' => (int) ($eventRow['browser_reported_tokens'] ?? $unverifiedTokens),
            'browser_unverified_tokens' => $unverifiedTokens,
            'sessions' => $sessions,
            'reserved_realtime_tokens' => (int) ($reserved['reserved_realtime_tokens'] ?? 0),
            'reserved_text_tokens' => (int) ($reserved['reserved_text_tokens'] ?? 0),
            'reserved_tokens_total' => (int) ($reserved['reserved_tokens_total'] ?? 0),
        ];
    }

    public function summarizeUserTotals(string $userKey): array
    {
        $userKey = trim($userKey);
        if ($userKey === '') {
            return [
                'tokens_realtime' => 0,
                'tokens_text' => 0,
                'tokens_transcription' => 0,
                'tokens_analysis' => 0,
                'tokens_total' => 0,
                'legacy_estimated_tokens' => 0,
                'verified_tokens' => 0,
                'browser_reported_tokens' => 0,
                'browser_reported_events' => 0,
                'browser_unverified_tokens' => 0,
                'sessions' => 0,
                'last_activity' => '',
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'agentos_usage';
        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(SUM(tokens_realtime), 0) AS tokens_realtime,
                COALESCE(SUM(tokens_text), 0) AS tokens_text,
                COALESCE(SUM(tokens_transcription), 0) AS tokens_transcription,
                COALESCE(SUM(tokens_analysis), 0) AS tokens_analysis,
                COALESCE(SUM(tokens_total), 0) AS tokens_total,
                COUNT(DISTINCT session_id) AS sessions,
                MAX(updated_at) AS last_activity
            FROM $table
            WHERE user_key = %s",
            $userKey
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row) {
            return [
                'tokens_realtime' => 0,
                'tokens_text' => 0,
                'tokens_transcription' => 0,
                'tokens_analysis' => 0,
                'legacy_estimated_tokens' => 0,
                'verified_tokens' => 0,
                'browser_reported_tokens' => 0,
                'browser_reported_events' => 0,
                'browser_unverified_tokens' => 0,
                'tokens_total' => 0,
                'sessions' => 0,
                'last_activity' => '',
            ];
        }

        $events = $table . '_events';
        $legacy = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_total WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_total ELSE 0 END),0) FROM $table u WHERE u.user_key = %s",
            $userKey
        ));
        $eventTotals = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN verified = 1 THEN total_tokens ELSE 0 END),0) AS verified_tokens, COALESCE(SUM(CASE WHEN verified = 0 THEN total_tokens ELSE 0 END),0) AS browser_unverified_tokens, COALESCE(SUM(CASE WHEN verified = 0 THEN 1 ELSE 0 END),0) AS browser_reported_events FROM $events e INNER JOIN $table u ON u.session_id = e.session_id WHERE u.user_key = %s",
            $userKey
        ), ARRAY_A) ?: [];

        return [
            'tokens_realtime' => (int) $row['tokens_realtime'],
            'tokens_text' => (int) $row['tokens_text'],
            'tokens_transcription' => (int) $row['tokens_transcription'],
            'tokens_analysis' => (int) $row['tokens_analysis'],
            'legacy_estimated_tokens' => $legacy,
            'verified_tokens' => (int) ($eventTotals['verified_tokens'] ?? 0),
            'browser_reported_tokens' => (int) ($eventTotals['browser_unverified_tokens'] ?? 0),
            'browser_reported_events' => (int) ($eventTotals['browser_reported_events'] ?? 0),
            'browser_unverified_tokens' => (int) ($eventTotals['browser_unverified_tokens'] ?? 0),
            'tokens_total' => (int) $row['tokens_total'],
            'sessions' => (int) $row['sessions'],
            'last_activity' => (string) ($row['last_activity'] ?? ''),
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
            COALESCE(SUM(tokens_transcription), 0) AS tokens_transcription,
            COALESCE(SUM(tokens_analysis), 0) AS tokens_analysis,
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

        $userKeys = array_values(array_unique(array_map(static function ($row) { return (string) $row['user_key']; }, $rows)));
        $placeholders = implode(',', array_fill(0, count($userKeys), '%s'));
        $events = $table . '_events';
        $sourceRows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.user_key,
                COALESCE(SUM(CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_total WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_total ELSE 0 END),0) AS legacy_estimated_tokens
             FROM $table u WHERE u.user_key IN ($placeholders) GROUP BY u.user_key",
            ...$userKeys
        ), ARRAY_A) ?: [];
        $sourceTotals = [];
        foreach ($sourceRows as $sourceRow) {
            $sourceTotals[(string) $sourceRow['user_key']] = ['legacy_estimated_tokens' => (int) $sourceRow['legacy_estimated_tokens']];
        }
        $eventRows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.user_key,
                COALESCE(SUM(CASE WHEN e.verified = 1 THEN e.total_tokens ELSE 0 END),0) AS verified_tokens,
                COALESCE(SUM(CASE WHEN e.verified = 0 THEN e.total_tokens ELSE 0 END),0) AS browser_unverified_tokens,
                COALESCE(SUM(CASE WHEN e.verified = 0 THEN 1 ELSE 0 END),0) AS browser_reported_events
             FROM $events e INNER JOIN $table u ON u.session_id = e.session_id
             WHERE u.user_key IN ($placeholders) GROUP BY u.user_key",
            ...$userKeys
        ), ARRAY_A) ?: [];
        foreach ($eventRows as $eventRow) {
            $sourceTotals[(string) $eventRow['user_key']]['verified_tokens'] = (int) $eventRow['verified_tokens'];
            $sourceTotals[(string) $eventRow['user_key']]['browser_unverified_tokens'] = (int) $eventRow['browser_unverified_tokens'];
            $sourceTotals[(string) $eventRow['user_key']]['browser_reported_events'] = (int) $eventRow['browser_reported_events'];
        }

        return array_map(static function ($row) use ($sourceTotals) {
            $source = $sourceTotals[(string) $row['user_key']] ?? [];
            return [
                'user_key' => $row['user_key'],
                'tokens_realtime' => (int) $row['tokens_realtime'],
                'tokens_text' => (int) $row['tokens_text'],
                'tokens_transcription' => (int) $row['tokens_transcription'],
                'tokens_analysis' => (int) $row['tokens_analysis'],
                'tokens_total' => (int) $row['tokens_total'],
                'legacy_estimated_tokens' => (int) ($source['legacy_estimated_tokens'] ?? 0),
                'verified_tokens' => (int) ($source['verified_tokens'] ?? 0),
                'browser_reported_tokens' => (int) ($source['browser_unverified_tokens'] ?? 0),
                'browser_reported_events' => (int) ($source['browser_reported_events'] ?? 0),
                'browser_unverified_tokens' => (int) ($source['browser_unverified_tokens'] ?? 0),
                'sessions' => (int) $row['sessions'],
                'last_activity' => $row['last_activity'],
            ];
        }, $rows);
    }

    public function deleteOlderThanHours(int $hours): void
    {
        global $wpdb;
        $hours = max(1, $hours);
        $table = $wpdb->prefix . 'agentos_usage';
        $events = $table . '_events';
        $reservations = $table . '_reservations';
        $threshold = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
        $wpdb->query($wpdb->prepare(
            "DELETE e FROM $events e INNER JOIN $table u ON u.session_id = e.session_id WHERE u.updated_at < %s",
            $threshold
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE r FROM $reservations r INNER JOIN $table u ON u.session_id = r.session_id WHERE u.updated_at < %s",
            $threshold
        ));
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
        $events = $table . '_events';
        $limit = max(1, min(200, $limit));

        $sql = $wpdb->prepare(
            "SELECT u.session_id, u.subscription_slug, u.agent_id, u.post_id, u.tokens_realtime, u.tokens_text, u.tokens_transcription, u.tokens_analysis, u.transcription_seconds, u.tokens_total, u.legacy_tokens_total, u.legacy_occurred_at, u.duration_seconds, u.status, u.metadata, u.updated_at,
                (SELECT COUNT(*) FROM $events e WHERE e.session_id = u.session_id AND e.verified = 1) AS verified_event_count,
                (SELECT COUNT(*) FROM $events e WHERE e.session_id = u.session_id AND e.verified = 0) AS browser_reported_event_count,
                (SELECT COALESCE(SUM(e.total_tokens),0) FROM $events e WHERE e.session_id = u.session_id AND e.verified = 0) AS browser_unverified_tokens,
                CASE WHEN u.legacy_occurred_at > 0 THEN u.legacy_tokens_total WHEN NOT EXISTS (SELECT 1 FROM $events e WHERE e.session_id = u.session_id) THEN u.tokens_total ELSE 0 END AS legacy_estimated_tokens
            FROM $table u
            WHERE u.user_key = %s
            ORDER BY u.updated_at DESC
            LIMIT %d",
            $userKey,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) {
            return [];
        }

        return array_map(static function ($row) {
            $verifiedEvents = (int) $row['verified_event_count'];
            $legacyTokens = (int) $row['legacy_estimated_tokens'];
            $browserReportedTokens = (int) $row['browser_unverified_tokens'];
            $browserReportedEvents = (int) $row['browser_reported_event_count'];
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            return [
                'session_id' => $row['session_id'],
                'subscription_slug' => $row['subscription_slug'],
                'agent_id' => $row['agent_id'],
                'post_id' => (int) $row['post_id'],
                'tokens_realtime' => (int) $row['tokens_realtime'],
                'tokens_text' => (int) $row['tokens_text'],
                'tokens_transcription' => (int) $row['tokens_transcription'],
                'tokens_analysis' => (int) $row['tokens_analysis'],
                'transcription_seconds' => (float) $row['transcription_seconds'],
                'usage_source' => $browserReportedEvents > 0
                    ? ($verifiedEvents > 0 ? 'server_verified_with_browser_reported' : 'browser_reported')
                    : ($verifiedEvents > 0 && $legacyTokens > 0 ? 'server_verified_with_legacy_estimate' : ($verifiedEvents > 0 ? 'server_verified' : 'legacy_estimated')),
                'legacy_estimated_tokens' => $legacyTokens,
                'verified_event_count' => $verifiedEvents,
                'browser_reported_tokens' => $browserReportedTokens,
                'browser_reported_events' => $browserReportedEvents,
                'browser_unverified_tokens' => $browserReportedTokens,
                'tokens_total' => (int) $row['tokens_total'],
                'duration_seconds' => (int) $row['duration_seconds'],
                'status' => $row['status'],
                'usage_report_incomplete' => is_array($metadata) && !empty($metadata['usage_report_incomplete']),
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
        $events = $table . '_events';
        $reservations = $table . '_reservations';
        $wpdb->query($wpdb->prepare(
            "DELETE e FROM $events e INNER JOIN $table u ON u.session_id = e.session_id WHERE u.user_key = %s",
            $userKey
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE r FROM $reservations r INNER JOIN $table u ON u.session_id = r.session_id WHERE u.user_key = %s",
            $userKey
        ));
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
        $reservations = $table . '_reservations';
        $wpdb->update(
            $reservations,
            ['user_key' => $newKey],
            ['user_key' => $originalKey],
            ['%s'],
            ['%s']
        );
        $wpdb->update(
            $table,
            ['user_key' => $newKey],
            ['user_key' => $originalKey],
            ['%s'],
            ['%s']
        );
    }
}
