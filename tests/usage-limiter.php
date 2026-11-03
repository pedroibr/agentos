<?php
/** Offline quota regression coverage for session and period decisions. */

require_once dirname(__DIR__) . '/src/Core/Config.php';
require_once dirname(__DIR__) . '/src/Database/UsageRepository.php';
require_once dirname(__DIR__) . '/src/Subscriptions/SubscriptionRepository.php';
require_once dirname(__DIR__) . '/src/Subscriptions/UserSubscriptionRepository.php';
require_once dirname(__DIR__) . '/src/Subscriptions/UsageLimiter.php';

use AgentOS\Database\UsageRepository;
use AgentOS\Subscriptions\SubscriptionRepository;
use AgentOS\Subscriptions\UserSubscriptionRepository;
use AgentOS\Subscriptions\UsageLimiter;

function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function __( $value, $domain = '') { return $value; }
function current_time($type) { return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }
function quotaExpect($condition, $label) { if (!$condition) throw new RuntimeException($label); echo "PASS: $label\n"; }

class QuotaUsageFake extends UsageRepository
{
    public array $sessions = [];
    public array $usage = [];
    public array $sessionTotals = [];
    public function getSession(string $sessionId): ?array { return $this->sessions[$sessionId] ?? null; }
    public function summarizeUsage(string $subscriptionSlug, string $userKey, int $periodHours): array { return $this->usage[$subscriptionSlug] ?? ['sessions' => 0]; }
    public function sessionUsage(string $sessionId, bool $verifiedOnly = true): array { return $this->sessionTotals[$sessionId] ?? ['tokens_total' => 0, 'reserved_tokens_total' => 0]; }
    public function withUsageLock(string $scope, callable $callback) { return $callback(); }
    public function createSession(array $payload, string $ticket): bool { $this->sessions[$payload['session_id']] = $payload; return true; }
}
class QuotaPlanFake extends SubscriptionRepository
{
    public array $plans = [];
    public function all(): array { return $this->plans; }
}
class QuotaAssignmentsFake extends UserSubscriptionRepository
{
    public array $assignments = [];
    public function get(string $userKey, bool $includeExpired = false): array { return $this->assignments[$userKey] ?? []; }
}

$usage = new QuotaUsageFake();
$plans = new QuotaPlanFake();
$assignments = new QuotaAssignmentsFake();
$plans->plans['limited'] = [
    'slug' => 'limited', 'label' => 'Limited', 'allowed_agents' => ['math'], 'period_hours' => 24,
    'limits' => ['realtime_tokens' => 100, 'text_tokens' => 200, 'sessions' => 1],
    'session_token_cap' => 50, 'block_on_overage' => true,
];
$assignments->assignments['user_7'] = [['subscription_slug' => 'limited', 'expires_at' => '', 'overrides' => []]];
$agent = ['slug' => 'math', 'require_subscription' => false, 'session_token_cap' => 30];
$limiter = new UsageLimiter($plans, $assignments, $usage);

$usage->usage['limited'] = ['sessions' => 1, 'tokens_realtime' => 0, 'tokens_transcription' => 0, 'tokens_text' => 0, 'tokens_analysis' => 0];
$deniedNew = $limiter->evaluate('user_7', $agent);
quotaExpect(empty($deniedNew['allowed']) && $deniedNew['error_code'] === 'subscription_limit', 'period max sessions blocks a new session without falling back to no-plan access');

$usage->sessions['same-session'] = ['session_id' => 'same-session', 'user_key' => 'user_7', 'subscription_slug' => 'limited', 'subscription_expires_at' => '2000-01-01 00:00:00'];
$usage->sessionTotals['same-session'] = ['tokens_total' => 20, 'reserved_tokens_total' => 5];
$usage->usage['limited']['reserved_tokens_total'] = 999; // reservations from other sessions do not consume this session's cap.
$usage->usage['limited']['reserved_realtime_tokens'] = 0;
$continued = $limiter->evaluate('user_7', $agent, ['session_id' => 'same-session']);
quotaExpect(!empty($continued['allowed']), 'the current session may continue when max sessions is already reached and session cap has room');
quotaExpect($continued['remaining']['session_tokens'] === 5, 'session remaining budget subtracts only this session reservation and agent cap');

$usage->sessionTotals['same-session']['reserved_tokens_total'] = 11;
$blockedContinuation = $limiter->evaluate('user_7', $agent, ['session_id' => 'same-session']);
quotaExpect(empty($blockedContinuation['allowed']) && $blockedContinuation['error_code'] === 'subscription_limit', 'continuation is blocked when the combined session cap and reservation are exceeded');

$usage->sessions = [];
$usage->sessionTotals = [];
$plans->plans['limited']['block_on_overage'] = false;
$usage->usage['limited'] = ['sessions' => 0, 'tokens_realtime' => 101, 'tokens_transcription' => 0, 'tokens_text' => 0, 'tokens_analysis' => 0];
$warningOnly = $limiter->evaluate('user_7', ['slug' => 'math', 'require_subscription' => false, 'session_token_cap' => 0]);
quotaExpect(!empty($warningOnly['allowed']) && !empty($warningOnly['warnings']), 'warning-only overage stays allowed and reports a warning');

$plans->plans['limited']['session_token_cap'] = 40;
$usage->sessions['warning-session'] = ['session_id' => 'warning-session', 'user_key' => 'user_7', 'subscription_slug' => 'limited'];
$usage->sessionTotals['warning-session'] = ['tokens_total' => 45, 'reserved_tokens_total' => 0];
$warningSessionCap = $limiter->evaluate('user_7', ['slug' => 'math', 'require_subscription' => false, 'session_token_cap' => 0], ['session_id' => 'warning-session']);
quotaExpect(!empty($warningSessionCap['allowed']) && $warningSessionCap['session_cap'] === 0, 'warning-only plan session caps do not become hard browser cutoffs');
$hardAgentCap = $limiter->evaluate('user_7', ['slug' => 'math', 'require_subscription' => false, 'session_token_cap' => 30], ['session_id' => 'warning-session']);
quotaExpect(empty($hardAgentCap['allowed']), 'the agent session cap remains enforced when plan overage is warning-only');

$plans->plans['limited']['block_on_overage'] = true;
$exhausted = $limiter->evaluate('user_7', ['slug' => 'math', 'require_subscription' => false, 'session_token_cap' => 0]);
quotaExpect(empty($exhausted['allowed']), 'an exhausted assigned plan cannot bypass its quota through the no-plan fallback');

$assignments->assignments['user_7'] = [];
$noPlan = $limiter->evaluate('user_7', ['slug' => 'math', 'require_subscription' => false, 'session_token_cap' => 25]);
quotaExpect(!empty($noPlan['allowed']) && $noPlan['subscription_slug'] === '' && $noPlan['remaining']['session_tokens'] === 25, 'agents without a required subscription retain the agent cap as their session budget');

$plans->plans['limited']['limits'] = ['realtime_tokens' => 0, 'text_tokens' => 0, 'sessions' => 0];
$plans->plans['limited']['session_token_cap'] = 0;
$plans->plans['limited']['block_on_overage'] = true;
$usage->usage['limited'] = ['sessions' => 500, 'tokens_realtime' => 999999, 'tokens_transcription' => 999999, 'tokens_text' => 999999, 'tokens_analysis' => 999999];
$assignments->assignments['user_7'] = [['subscription_slug' => 'limited', 'expires_at' => '', 'overrides' => []]];
$unlimited = $limiter->evaluate('user_7', ['slug' => 'math', 'require_subscription' => false, 'session_token_cap' => 0]);
quotaExpect(!empty($unlimited['allowed']), 'zero plan limits remain unlimited');

$plans->plans['limited']['session_token_cap'] = 40;
$plans->plans['limited']['limits'] = ['realtime_tokens' => 0, 'text_tokens' => 0, 'sessions' => 0];
$usage->sessions['renewed'] = ['session_id' => 'renewed', 'user_key' => 'user_7', 'subscription_slug' => 'limited', 'subscription_expires_at' => '2000-01-01 00:00:00'];
$usage->sessionTotals['renewed'] = ['tokens_total' => 10, 'reserved_tokens_total' => 0];
$assignments->assignments['user_7'] = [['subscription_slug' => 'limited', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'overrides' => []]];
$renewed = $limiter->evaluate('user_7', $agent, ['session_id' => 'renewed']);
quotaExpect(!empty($renewed['allowed']), 'an active subscription renewal overrides the old expiry snapshot on a pinned session');
$assignments->assignments['user_7'][0]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 3600);
$revoked = $limiter->evaluate('user_7', $agent, ['session_id' => 'renewed']);
quotaExpect(empty($revoked['allowed']) && $revoked['error_code'] === 'subscription_expired', 'current assignment expiry cuts off a pinned session immediately');

$plans->plans['limited']['session_token_cap'] = 0;
$plans->plans['limited']['limits']['sessions'] = 1;
$assignments->assignments['user_7'][0]['expires_at'] = '';
$usage->usage['limited'] = ['sessions' => 0, 'tokens_realtime' => 0, 'tokens_transcription' => 0, 'tokens_text' => 0, 'tokens_analysis' => 0];
$started = $limiter->startSession('user_7', ['slug' => 'math'], ['session_id' => 'created', 'agent_id' => 'math', 'post_id' => 2, 'status' => 'pending'], 'ticket');
quotaExpect(!empty($started['allowed']) && $started['session_id'] === 'created' && isset($usage->sessions['created']), 'starting a session atomically pins the selected plan and creates the server-owned session');
