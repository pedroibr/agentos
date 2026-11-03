<?php
/**
 * Optional real-DB usage ledger smoke test. Run with:
 * wp eval-file tests/usage-ledger-wpcli.php
 * Uses only uniquely named synthetic rows and always removes them in finally.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must run through WP-CLI.\n");
    exit(1);
}

use AgentOS\Database\UsageRepository;

$tag = strtolower(wp_generate_password(12, false, false));
$userKey = 'usage-ledger-smoke-' . $tag;
$legacyUserKey = $userKey . '-legacy';
$sessionId = 'usage' . substr(hash('sha256', $tag), 0, 28);
$legacySessionId = 'legacy' . substr(hash('sha256', $tag), 0, 27);
$windowSessionId = 'window' . substr(hash('sha256', $tag), 0, 26);
$durationSessionId = 'duration' . substr(hash('sha256', $tag), 0, 24);
$ticket = wp_generate_password(48, false, false);
$planSlug = 'ledger-' . substr($tag, 0, 16);
$repo = new UsageRepository();
$error = null;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $expect($repo->createSession([
        'session_id' => $sessionId,
        'user_key' => $userKey,
        'subscription_slug' => $planSlug,
        'agent_id' => 'ledger-smoke',
        'post_id' => 1,
        'status' => 'pending',
        'metadata' => ['origin' => 'synthetic_ledger_smoke'],
    ], $ticket), 'synthetic session was created');

    $expect($repo->validateSession($sessionId, $ticket, $userKey, 'ledger-smoke', 1) !== null, 'ticket owner and scope validate');
    $expect($repo->validateSession($sessionId, $ticket, 'usage-ledger-other-' . $tag, 'ledger-smoke', 1) === null, 'another owner cannot use the session ticket');

    $realtime = ['input_tokens' => 4, 'output_tokens' => 3, 'total_tokens' => 7];
    $expect($repo->recordEvent($sessionId, 'provider-response-1', 'realtime', $realtime, 'browser_reported'), 'browser provider usage is recorded');
    $expect($repo->recordEvent($sessionId, 'provider-response-1', 'realtime', $realtime, 'browser_reported'), 'replaying the same event succeeds idempotently');
    $reported = $repo->sessionUsage($sessionId);
    $expect((int) $reported['tokens_total'] === 7 && (int) $reported['browser_reported_tokens'] === 7, 'replay does not duplicate the browser-reported total');

    $expect($repo->recordEvent($sessionId, 'provider-response-1', 'realtime', ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5], 'server'), 'server usage promotes the matching browser event');
    $promoted = $repo->sessionUsage($sessionId);
    $expect((int) $promoted['tokens_total'] === 5 && (int) $promoted['browser_reported_tokens'] === 0, 'promotion replaces the browser estimate without double counting');

    $duration = ['total_tokens' => 0, 'transcription_seconds' => 1.75];
    $expect($repo->recordEvent($sessionId, 'asr-duration-1', 'transcription', $duration, 'browser_reported'), 'duration-only ASR usage is recorded');
    $durationUsage = $repo->sessionUsage($sessionId);
    $expect((float) $durationUsage['transcription_seconds'] === 1.75 && (int) $durationUsage['tokens_total'] === 5, 'duration-only ASR remains seconds and contributes no invented tokens');
    $expect($repo->recordEvent($sessionId, 'asr-duration-1', 'transcription', ['total_tokens' => 0, 'transcription_seconds' => 1.5], 'server'), 'server duration promotes the browser duration event');
    $expect((float) $repo->sessionUsage($sessionId)['transcription_seconds'] === 1.5, 'transcription seconds are projected only once after promotion');

    $expect($repo->reserveBudget('smoke-reservation-' . $tag, $sessionId, $userKey, $planSlug, 'realtime', 11, 24), 'reservation lease is created');
    $expect((int) $repo->sessionUsage($sessionId)['reserved_tokens_total'] === 11, 'session usage reports its own live reservation');
    $expect($repo->refreshBudget('smoke-reservation-' . $tag, 8, 24), 'short reservation lease can be refreshed');
    $expect((int) $repo->sessionUsage($sessionId)['reserved_tokens_total'] === 8, 'reservation refresh updates the active session headroom');

    $summary = $repo->summarizeUsage($planSlug, $userKey, 24);
    $expect((int) $summary['tokens_realtime'] === 5 && (int) $summary['tokens_transcription'] === 0, 'period aggregation uses event timestamp and category');
    $expect((int) $summary['sessions'] === 1, 'period aggregation counts the synthetic session once');

    $expect($repo->createSession([
        'session_id' => $legacySessionId,
        'user_key' => $legacyUserKey,
        'subscription_slug' => $planSlug,
        'agent_id' => 'ledger-smoke',
        'post_id' => 1,
        'status' => 'legacy',
    ], wp_generate_password(48, false, false)), 'legacy snapshot fixture session was created');
    global $wpdb;
    $legacyAt = time() - (23 * HOUR_IN_SECONDS);
    $wpdb->update($wpdb->prefix . 'agentos_usage', [
        'tokens_realtime' => 14,
        'tokens_total' => 14,
        'legacy_tokens_realtime' => 14,
        'legacy_tokens_total' => 14,
        'legacy_occurred_at' => $legacyAt,
        'started_at' => $legacyAt,
    ], ['session_id' => $legacySessionId], ['%d', '%d', '%d', '%d', '%d', '%d'], ['%s']);
    $expect($repo->recordEvent($legacySessionId, 'after-migration', 'realtime', ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3], 'server'), 'new event can append to a migrated legacy session');
    $migratedUsage = $repo->sessionUsage($legacySessionId);
    $expect((int) $migratedUsage['tokens_total'] === 17 && (int) $migratedUsage['legacy_estimated_tokens'] === 14, 'legacy snapshot survives ledger events without duplication');

    $oldAt = time() - (25 * HOUR_IN_SECONDS);
    $expect($repo->createSession([
        'session_id' => $windowSessionId,
        'user_key' => $legacyUserKey,
        'subscription_slug' => $planSlug,
        'agent_id' => 'ledger-smoke',
        'post_id' => 1,
        'status' => 'legacy',
    ], wp_generate_password(48, false, false)), 'legacy window fixture session was created');
    $wpdb->update($wpdb->prefix . 'agentos_usage', [
        'tokens_text' => 19,
        'tokens_total' => 19,
        'legacy_tokens_text' => 19,
        'legacy_tokens_total' => 19,
        'legacy_occurred_at' => $oldAt,
        'started_at' => $oldAt,
    ], ['session_id' => $windowSessionId], ['%d', '%d', '%d', '%d', '%d', '%d'], ['%s']);
    $outsideWindow = $repo->summarizeUsage($planSlug, $legacyUserKey, 24);
    $expect((int) $outsideWindow['tokens_text'] === 0 && (int) $outsideWindow['sessions'] === 1, 'legacy usage outside the rolling period does not enter token totals');
    $freshLegacyAt = time() - (23 * HOUR_IN_SECONDS);
    $wpdb->update($wpdb->prefix . 'agentos_usage', ['legacy_occurred_at' => $freshLegacyAt, 'started_at' => $freshLegacyAt], ['session_id' => $windowSessionId], ['%d', '%d'], ['%s']);
    $insideWindow = $repo->summarizeUsage($planSlug, $legacyUserKey, 24);
    $expect((int) $insideWindow['tokens_text'] === 19 && (int) $insideWindow['sessions'] === 2, 'legacy snapshot and session timestamps are counted inside the rolling period');

    $expect($repo->createSession([
        'session_id' => $durationSessionId,
        'user_key' => $legacyUserKey,
        'subscription_slug' => $planSlug,
        'agent_id' => 'ledger-smoke',
        'post_id' => 1,
        'status' => 'legacy',
    ], wp_generate_password(48, false, false)), 'duration-only legacy fixture session was created');
    $wpdb->update($wpdb->prefix . 'agentos_usage', [
        'transcription_seconds' => 1.25,
        'legacy_transcription_seconds' => 1.25,
        'legacy_occurred_at' => time(),
        'started_at' => time(),
    ], ['session_id' => $durationSessionId], ['%f', '%f', '%d', '%d'], ['%s']);
    $durationOnly = $repo->sessionUsage($durationSessionId);
    $expect((int) $durationOnly['tokens_total'] === 0 && (int) $durationOnly['legacy_estimated_tokens'] === 0 && (float) $durationOnly['transcription_seconds'] === 1.25, 'duration-only legacy snapshots do not become token usage');
} catch (Throwable $exception) {
    $error = $exception;
} finally {
    $repo->deleteForUser($userKey);
    $repo->deleteForUser($legacyUserKey);
    $left = array_merge($repo->listForUser($userKey, 20), $repo->listForUser($legacyUserKey, 20));
    if ($error === null && $left) {
        $error = new RuntimeException('synthetic user rows remained after cleanup');
    }
}

if ($error !== null) {
    WP_CLI::error($error->getMessage());
}
WP_CLI::success('Usage ledger DB smoke passed; synthetic session, events, and reservation were cleaned up.');
