<?php
/** Synthetic REST save check; creates and removes only uniquely named test rows. */
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this check with WP-CLI.');
}

$agents = (new \AgentOS\Core\AgentRepository())->all();
$agentId = '';
foreach ($agents as $slug => $agent) {
    if (empty($agent['analysis_enabled']) || empty($agent['analysis_auto_run'])) {
        $agentId = (string) $slug;
        break;
    }
}
if ($agentId === '') {
    throw new RuntimeException('No agent available without automatic analysis.');
}
$adminIds = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if (!$adminIds) {
    throw new RuntimeException('No administrator available for the account-priority check.');
}
$usage = new \AgentOS\Database\UsageRepository();
$ids = [];
$originalUser = get_current_user_id();
try {
    foreach (['guest-valid', 'guest-invalid', 'account'] as $case) {
        $userId = $case === 'account' ? (int) $adminIds[0] : 0;
        wp_set_current_user($userId);
        $session = 'email-test-' . wp_generate_password(24, false, false);
        $ticket = wp_generate_password(48, false, false);
        $ids[] = $session;
        if (!$usage->createSession(['session_id' => $session, 'user_key' => $userId ? 'user_' . $userId : '', 'agent_id' => $agentId, 'post_id' => 1, 'status' => 'running'], $ticket)) {
            throw new RuntimeException('Could not create synthetic session');
        }
        $request = new WP_REST_Request('POST', '/agentos/v1/transcript-db');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_body_params(['post_id' => 1, 'agent_id' => $agentId, 'session_id' => $session, 'session_ticket' => $ticket, 'guest_email' => $case === 'guest-invalid' ? 'not-an-email' : 'agentos-test@example.com', 'transcript' => []]);
        $response = rest_do_request($request);
        if ($response->get_status() !== 200) {
            throw new RuntimeException('Synthetic transcript request failed: ' . $response->get_status());
        }
        $record = (new \AgentOS\Database\TranscriptRepository())->find((int) $response->get_data()['id']);
        $expected = $case === 'account' ? get_userdata($userId)->user_email : ($case === 'guest-valid' ? 'agentos-test@example.com' : '');
        if (($record['user_email'] ?? '') !== $expected || ($record['user_key'] ?? '') !== ($userId ? 'user_' . $userId : '')) {
            throw new RuntimeException('Unexpected email or user attribution: ' . $case);
        }
        echo "PASS: $case\n";
    }
} finally {
    global $wpdb;
    foreach ($ids as $session) {
        $wpdb->delete($wpdb->prefix . 'agentos_transcripts', ['session_id' => $session]);
        $wpdb->delete($wpdb->prefix . 'agentos_usage', ['session_id' => $session]);
    }
    wp_set_current_user($originalUser);
}
