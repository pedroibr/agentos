<?php

namespace AgentOS\Subscriptions;

use AgentOS\Database\UsageRepository;
use function __;

class UsageLimiter
{
    private SubscriptionRepository $subscriptions;
    private UserSubscriptionRepository $userSubscriptions;
    private UsageRepository $usage;

    public function __construct(
        SubscriptionRepository $subscriptions,
        UserSubscriptionRepository $userSubscriptions,
        UsageRepository $usage
    ) {
        $this->subscriptions = $subscriptions;
        $this->userSubscriptions = $userSubscriptions;
        $this->usage = $usage;
    }

    /**
     * Evaluate a new session or a continuation. Pass session_id only after its
     * server ticket and owner have been validated by the REST controller.
     */
    public function evaluate(string $userKey, array $agent, array $context = []): array
    {
        $userKey = trim($userKey);
        $agentSlug = sanitize_key($agent['slug'] ?? '');
        $requireSubscription = !empty($agent['require_subscription']);
        $agentSessionCap = max(0, (int) ($agent['session_token_cap'] ?? 0));
        $sessionId = sanitize_text_field($context['session_id'] ?? '');
        $session = $sessionId !== '' ? $this->usage->getSession($sessionId) : null;
        $plans = $this->subscriptions->all();

        if ($requireSubscription && $userKey === '') {
            return $this->deny('login_required', 'missing_identity', __('You must be logged in to use this agent.', 'agentos'), 401);
        }

        $assignments = $userKey !== '' ? $this->userSubscriptions->get($userKey, true) : [];
        if ($session) {
            return $this->evaluatePinnedSession($userKey, $agent, $context, $session, $plans, $assignments, $requireSubscription, $agentSessionCap);
        }

        $best = null;
        $bestScore = -1.0;
        $warnings = [];
        $eligibleAssignments = 0;
        $hardBlocked = false;

        foreach ($assignments as $assignment) {
            $slug = sanitize_key($assignment['subscription_slug'] ?? '');
            if ($slug === '' || !isset($plans[$slug]) || $this->isExpired((string) ($assignment['expires_at'] ?? ''))) {
                continue;
            }

            $plan = $this->applyOverrides($plans[$slug], $assignment['overrides'] ?? []);
            if (!$this->agentAllowed($plan['allowed_agents'], $agentSlug)) {
                continue;
            }
            $eligibleAssignments++;

            $periodHours = max(1, (int) ($plan['period_hours'] ?? 24));
            $usage = $this->usage->summarizeUsage($slug, $userKey, $periodHours);
            $overages = $this->overages($agentSessionCap, 0, 0, $plan, $usage, $context, false);
            $agentCapExceeded = in_array('agent_session_token_cap', $overages, true);
            if ($overages && (!empty($plan['block_on_overage']) || $agentCapExceeded)) {
                $hardBlocked = true;
                continue;
            }
            if ($overages) {
                $warnings[] = $this->warning($slug, $overages, $usage, $plan['limits']);
            }

            $score = $this->remainingScore($plan, $usage, 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'plan' => $plan,
                    'usage' => $usage,
                    'assignment' => $assignment,
                    'remaining' => $this->remaining($agentSessionCap, 0, 0, $plan, $usage),
                ];
            }
        }

        if ($best) {
            $plan = $best['plan'];
            $planCap = (int) ($plan['session_token_cap'] ?? 0);
            $expiresAt = (string) ($best['assignment']['expires_at'] ?? '');
            return [
                'allowed' => true,
                'subscription_slug' => $plan['slug'],
                'session_cap' => $this->mergeCaps($agentSessionCap, !empty($plan['block_on_overage']) ? $planCap : 0),
                'usage' => $best['usage'],
                'plan' => $plan,
                'warnings' => $warnings,
                'expires_at' => $expiresAt,
                'realtime_control_required' => $this->requiresRealtimeControl($agent, $plan, $expiresAt),
                'remaining' => $best['remaining'],
            ];
        }

        // Do not fall back to an unmetered anonymous session when an eligible plan
        // exists but its block_on_overage limit has been reached.
        if ($requireSubscription || ($eligibleAssignments > 0 && $hardBlocked)) {
            return $this->deny(
                $hardBlocked ? 'subscription_limit' : 'subscription_required',
                $hardBlocked ? 'limit_reached' : 'subscription_missing',
                $hardBlocked ? __('Usage limit reached for this subscription.', 'agentos') : __('A valid subscription is required to use this agent.', 'agentos'),
                402,
                $warnings
            );
        }

        return [
            'allowed' => true,
            'subscription_slug' => '',
            'session_cap' => $agentSessionCap,
            'usage' => $this->usage->summarizeUsage('', $userKey, 24),
            'plan' => null,
            'warnings' => $warnings,
            'expires_at' => '',
            'realtime_control_required' => $agentSessionCap > 0,
            'remaining' => ['realtime_tokens' => 0, 'text_tokens' => 0, 'session_tokens' => $agentSessionCap],
        ];
    }

    /** Atomically check plan headroom and reserve one new session slot. */
    public function startSession(string $userKey, array $agent, array $payload, string $ticket): array
    {
        $scope = trim($userKey) !== '' ? 'user:' . trim($userKey) : 'guest:' . sanitize_key($agent['slug'] ?? '') . ':' . (int) ($payload['post_id'] ?? 0);
        $result = $this->usage->withUsageLock($scope, function () use ($userKey, $agent, $payload, $ticket) {
            $decision = $this->evaluate($userKey, $agent);
            if (empty($decision['allowed'])) {
                return $decision;
            }
            $sessionPayload = $payload;
            $sessionPayload['user_key'] = $userKey;
            $sessionPayload['subscription_slug'] = (string) ($decision['subscription_slug'] ?? '');
            $sessionPayload['subscription_expires_at'] = (string) ($decision['expires_at'] ?? '');
            if (!$this->usage->createSession($sessionPayload, $ticket)) {
                return $this->deny('session_conflict', 'session_conflict', __('Unable to reserve a conversation session. Please retry.', 'agentos'), 409);
            }
            return array_merge($decision, [
                'session_id' => (string) $sessionPayload['session_id'],
                'session_ticket' => $ticket,
            ]);
        });
        if ($result === false) {
            return $this->deny('usage_busy', 'usage_busy', __('Usage is being updated. Please retry in a moment.', 'agentos'), 503);
        }
        return $result;
    }

    private function evaluatePinnedSession(string $userKey, array $agent, array $context, array $session, array $plans, array $assignments, bool $requireSubscription, int $agentSessionCap): array
    {
        $slug = sanitize_key($session['subscription_slug'] ?? '');
        if ($slug === '') {
            if ($requireSubscription) {
                return $this->deny('subscription_required', 'subscription_missing', __('A valid subscription is required to continue this conversation.', 'agentos'), 402);
            }
            $usage = $this->usage->summarizeUsage('', $userKey, 24);
            $sessionUsage = $this->usage->sessionUsage((string) $session['session_id']);
            $cap = max(0, $agentSessionCap);
            $reserved = max(0, (int) ($sessionUsage['reserved_tokens_total'] ?? 0));
            $over = $cap > 0 && (int) ($sessionUsage['tokens_total'] ?? 0) + $reserved + max(0, (int) ($context['reserve_tokens'] ?? 0)) > $cap;
            if ($over) {
                return $this->deny('session_token_cap', 'limit_reached', __('This conversation has reached its token cap.', 'agentos'), 402);
            }
            return [
                'allowed' => true,
                'subscription_slug' => '',
                'session_cap' => $cap,
                'usage' => $usage,
                'plan' => null,
                'warnings' => [],
                'expires_at' => '',
                'realtime_control_required' => $cap > 0,
                'remaining' => ['realtime_tokens' => 0, 'text_tokens' => 0, 'session_tokens' => $cap > 0 ? max(0, $cap - (int) ($sessionUsage['tokens_total'] ?? 0) - $reserved) : 0],
            ];
        }

        $plan = $plans[$slug] ?? null;
        $assignment = null;
        foreach ($assignments as $item) {
            if (($item['subscription_slug'] ?? '') === $slug) {
                $assignment = $item;
                break;
            }
        }
        // The current assignment controls expiry so early revocation/extension is honored.
        $expiresAt = (string) ($assignment['expires_at'] ?? '');
        if (!$plan || !$assignment || $this->isExpired($expiresAt)) {
            return $this->deny('subscription_expired', 'subscription_expired', __('This conversation subscription is no longer active.', 'agentos'), 402);
        }
        $plan = $this->applyOverrides($plan, $assignment['overrides'] ?? []);
        if (!$this->agentAllowed($plan['allowed_agents'], sanitize_key($agent['slug'] ?? ''))) {
            return $this->deny('agent_not_allowed', 'agent_not_allowed', __('This subscription does not allow this agent.', 'agentos'), 403);
        }

        $usage = $this->usage->summarizeUsage($slug, $userKey, (int) $plan['period_hours']);
        $sessionUsage = $this->usage->sessionUsage((string) $session['session_id']);
        $sessionUsed = (int) ($sessionUsage['tokens_total'] ?? 0);
        $sessionReserved = (int) ($sessionUsage['reserved_tokens_total'] ?? 0);
        $overages = $this->overages($agentSessionCap, $sessionUsed, $sessionReserved, $plan, $usage, $context, true);
        $agentCapExceeded = in_array('agent_session_token_cap', $overages, true);
        $warnings = [];
        if ($overages && (empty($plan['block_on_overage']) && !$agentCapExceeded)) {
            $warnings[] = $this->warning($slug, $overages, $usage, $plan['limits']);
        } elseif ($overages) {
            return $this->deny('subscription_limit', 'limit_reached', __('Usage limit reached for this subscription.', 'agentos'), 402, $warnings);
        }

        $cap = $this->mergeCaps($agentSessionCap, !empty($plan['block_on_overage']) ? (int) ($plan['session_token_cap'] ?? 0) : 0);
        return [
            'allowed' => true,
            'subscription_slug' => $slug,
            'session_cap' => $cap,
            'usage' => $usage,
            'plan' => $plan,
            'warnings' => $warnings,
            'expires_at' => $expiresAt,
            'realtime_control_required' => $this->requiresRealtimeControl($agent, $plan, $expiresAt),
            'remaining' => $this->remaining($agentSessionCap, $sessionUsed, $sessionReserved, $plan, $usage),
        ];
    }

    private function overages(int $agentCap, int $sessionUsed, int $sessionReserved, array $plan, array $usage, array $context, bool $existingSession): array
    {
        $overages = [];
        $limits = $plan['limits'] ?? [];
        $reserveRealtime = max(0, (int) ($context['reserve_realtime_tokens'] ?? 0));
        $reserveText = max(0, (int) ($context['reserve_text_tokens'] ?? 0));
        $reserveSession = max(0, (int) ($context['reserve_tokens'] ?? 0));

        $sessionsLimit = max(0, (int) ($limits['sessions'] ?? 0));
        if (!$existingSession && $sessionsLimit > 0 && (int) ($usage['sessions'] ?? 0) >= $sessionsLimit) {
            $overages[] = 'sessions';
        }
        $realtimeLimit = max(0, (int) ($limits['realtime_tokens'] ?? 0));
        $realtimeUsed = (int) ($usage['tokens_realtime'] ?? 0) + (int) ($usage['tokens_transcription'] ?? 0) + (int) ($usage['reserved_realtime_tokens'] ?? 0);
        if ($realtimeLimit > 0 && $realtimeUsed + $reserveRealtime > $realtimeLimit) {
            $overages[] = 'realtime_tokens';
        }
        $textLimit = max(0, (int) ($limits['text_tokens'] ?? 0));
        $textUsed = (int) ($usage['tokens_text'] ?? 0) + (int) ($usage['tokens_analysis'] ?? 0) + (int) ($usage['reserved_text_tokens'] ?? 0);
        if ($textLimit > 0 && $textUsed + $reserveText > $textLimit) {
            $overages[] = 'text_tokens';
        }

        $planCap = max(0, (int) ($plan['session_token_cap'] ?? 0));
        $reservedSession = $sessionReserved;
        if ($planCap > 0 && $sessionUsed + $reservedSession + $reserveSession > $planCap) {
            $overages[] = 'session_token_cap';
        }
        if ($agentCap > 0 && $sessionUsed + $reservedSession + $reserveSession > $agentCap) {
            $overages[] = 'agent_session_token_cap';
        }
        return $overages;
    }

    private function remaining(int $agentCap, int $sessionUsed, int $sessionReserved, array $plan, array $usage): array
    {
        $limits = $plan['limits'] ?? [];
        $realtimeUsed = (int) ($usage['tokens_realtime'] ?? 0) + (int) ($usage['tokens_transcription'] ?? 0) + (int) ($usage['reserved_realtime_tokens'] ?? 0);
        $textUsed = (int) ($usage['tokens_text'] ?? 0) + (int) ($usage['tokens_analysis'] ?? 0) + (int) ($usage['reserved_text_tokens'] ?? 0);
        $sessionCap = $this->mergeCaps($agentCap, !empty($plan['block_on_overage']) ? (int) ($plan['session_token_cap'] ?? 0) : 0);
        return [
            'realtime_tokens' => max(0, (int) ($limits['realtime_tokens'] ?? 0) - $realtimeUsed),
            'text_tokens' => max(0, (int) ($limits['text_tokens'] ?? 0) - $textUsed),
            'session_tokens' => $sessionCap > 0 ? max(0, $sessionCap - $sessionUsed - $sessionReserved) : 0,
        ];
    }

    private function remainingScore(array $plan, array $usage, int $sessionUsed): float
    {
        $limits = $plan['limits'] ?? [];
        $buckets = [
            'realtime_tokens' => [(int) ($limits['realtime_tokens'] ?? 0), (int) ($usage['tokens_realtime'] ?? 0) + (int) ($usage['tokens_transcription'] ?? 0) + (int) ($usage['reserved_realtime_tokens'] ?? 0)],
            'text_tokens' => [(int) ($limits['text_tokens'] ?? 0), (int) ($usage['tokens_text'] ?? 0) + (int) ($usage['tokens_analysis'] ?? 0) + (int) ($usage['reserved_text_tokens'] ?? 0)],
            'sessions' => [(int) ($limits['sessions'] ?? 0), (int) ($usage['sessions'] ?? 0)],
            // The per-session cap is not shared across concurrent sessions.
            // Global reservations only reduce the plan's period token buckets.
            'session_token_cap' => [(int) ($plan['session_token_cap'] ?? 0), $sessionUsed],
        ];
        $scores = [];
        foreach ($buckets as [$limit, $used]) {
            if ($limit > 0) {
                // Compare dimensionless remaining fractions; token and session units are not interchangeable.
                $scores[] = max(0.0, min(1.0, ($limit - $used) / $limit));
            }
        }
        return $scores ? array_sum($scores) / count($scores) : PHP_FLOAT_MAX;
    }

    private function warning(string $slug, array $overages, array $usage, array $limits): array
    {
        return [
            'subscription_slug' => $slug,
            'reason' => reset($overages),
            'reasons' => $overages,
            'usage' => $usage,
            'limits' => $limits,
        ];
    }

    private function requiresRealtimeControl(array $agent, array $plan, string $expiresAt): bool
    {
        $limits = $plan['limits'] ?? [];
        return !empty($agent['require_subscription']) || $expiresAt !== ''
            || (int) ($agent['session_token_cap'] ?? 0) > 0
            || (int) ($limits['sessions'] ?? 0) > 0
            || (!empty($plan['block_on_overage']) && ((int) ($limits['realtime_tokens'] ?? 0) > 0 || (int) ($limits['text_tokens'] ?? 0) > 0 || (int) ($plan['session_token_cap'] ?? 0) > 0));
    }

    private function mergeCaps(int $agentCap, int $planCap): int
    {
        if ($agentCap > 0 && $planCap > 0) {
            return min($agentCap, $planCap);
        }
        return $agentCap > 0 ? $agentCap : max(0, $planCap);
    }

    private function applyOverrides(array $plan, array $overrides): array
    {
        if (isset($overrides['allowed_agents'])) {
            $plan['allowed_agents'] = array_values(array_filter(array_map('sanitize_key', (array) $overrides['allowed_agents'])));
        }
        if (isset($overrides['period_hours'])) {
            $plan['period_hours'] = max(1, (int) $overrides['period_hours']);
        }
        if (isset($overrides['limits']) && is_array($overrides['limits'])) {
            foreach (['realtime_tokens', 'text_tokens', 'sessions'] as $key) {
                if (isset($overrides['limits'][$key])) {
                    $plan['limits'][$key] = max(0, (int) $overrides['limits'][$key]);
                }
            }
        }
        if (isset($overrides['session_token_cap'])) {
            $plan['session_token_cap'] = max(0, (int) $overrides['session_token_cap']);
        }
        if (isset($overrides['block_on_overage'])) {
            $plan['block_on_overage'] = !empty($overrides['block_on_overage']);
        }
        return $plan;
    }

    private function agentAllowed(array $allowedAgents, string $agentSlug): bool
    {
        return !$allowedAgents || ($agentSlug !== '' && in_array($agentSlug, $allowedAgents, true));
    }

    private function isExpired(string $expiresAt): bool
    {
        if ($expiresAt === '') {
            return false;
        }
        $expiresTs = strtotime($expiresAt);
        return $expiresTs !== false && $expiresTs < current_time('timestamp');
    }

    private function deny(string $code, string $reason, string $message, int $status, array $warnings = []): array
    {
        return [
            'allowed' => false,
            'error_code' => $code,
            'reason' => $reason,
            'message' => $message,
            'status' => $status,
            'warnings' => $warnings,
        ];
    }
}
