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
     * Evaluate whether the given user is allowed to start a session for the agent.
     *
     * @param string $userKey
     * @param array  $agent
     * @param array  $context Optional context, e.g. estimated token usage.
     */
    public function evaluate(string $userKey, array $agent, array $context = []): array
    {
        $userKey = trim($userKey);
        $agentSlug = $agent['slug'] ?? '';
        $requireSubscription = !empty($agent['require_subscription']);
        $agentSessionCap = isset($agent['session_token_cap']) ? (int) $agent['session_token_cap'] : 0;

        $plans = $this->subscriptions->all();
        $assignments = $userKey !== '' ? $this->userSubscriptions->get($userKey) : [];

        $best = null;
        $bestScore = -1;
        $warnings = [];

        foreach ($assignments as $assignment) {
            $slug = $assignment['subscription_slug'] ?? '';
            if (!$slug || !isset($plans[$slug])) {
                continue;
            }

            $plan = $this->applyOverrides($plans[$slug], $assignment['overrides'] ?? []);
            if (!$this->agentAllowed($plan['allowed_agents'], $agentSlug)) {
                continue;
            }

            $periodHours = max(1, (int) ($plan['period_hours'] ?? 24));
            $usage = $this->usage->summarizeUsage($slug, $userKey, $periodHours);
            $limits = $plan['limits'];

            $blocked = false;
            $blockReason = '';

            $sessionsLimit = isset($limits['sessions']) ? (int) $limits['sessions'] : 0;
            if ($sessionsLimit > 0 && $usage['sessions'] >= $sessionsLimit) {
                $blocked = true;
                $blockReason = 'sessions';
            }

            $realtimeLimit = isset($limits['realtime_tokens']) ? (int) $limits['realtime_tokens'] : 0;
            if (!$blocked && $realtimeLimit > 0 && $usage['tokens_realtime'] >= $realtimeLimit) {
                $blocked = true;
                $blockReason = 'realtime_tokens';
            }

            $textLimit = isset($limits['text_tokens']) ? (int) $limits['text_tokens'] : 0;
            if (!$blocked && $textLimit > 0 && $usage['tokens_text'] >= $textLimit) {
                $blocked = true;
                $blockReason = 'text_tokens';
            }

            if ($blocked) {
                if (empty($plan['block_on_overage'])) {
                    $warnings[] = [
                        'subscription_slug' => $slug,
                        'reason' => $blockReason,
                        'usage' => $usage,
                        'limits' => $limits,
                    ];
                    $blocked = false;
                }
            }

            if ($blocked) {
                continue;
            }

            $score = $this->remainingScore($limits, $usage);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'plan' => $plan,
                    'usage' => $usage,
                    'assignment' => $assignment,
                ];
            }
        }

        if ($best) {
            $planCap = isset($best['plan']['session_token_cap']) ? (int) $best['plan']['session_token_cap'] : 0;
            $effectiveSessionCap = $this->mergeCaps($agentSessionCap, $planCap);

            return [
                'allowed' => true,
                'subscription_slug' => $best['plan']['slug'],
                'session_cap' => $effectiveSessionCap,
                'usage' => $best['usage'],
                'plan' => $best['plan'],
                'warnings' => $warnings,
            ];
        }

        if ($requireSubscription) {
            $reason = $warnings ? 'limit_warning' : 'subscription_missing';
            return [
                'allowed' => false,
                'error_code' => 'subscription_required',
                'reason' => $reason,
                'message' => __('A valid subscription is required to use this agent.', 'agentos'),
                'status' => 402,
                'warnings' => $warnings,
            ];
        }

        return [
            'allowed' => true,
            'subscription_slug' => '',
            'session_cap' => $agentSessionCap > 0 ? $agentSessionCap : 0,
            'usage' => [
                'tokens_realtime' => 0,
                'tokens_text' => 0,
                'tokens_total' => 0,
                'sessions' => 0,
            ],
            'plan' => null,
            'warnings' => $warnings,
        ];
    }

    private function mergeCaps(int $agentCap, int $planCap): int
    {
        if ($agentCap > 0 && $planCap > 0) {
            return min($agentCap, $planCap);
        }

        if ($agentCap > 0) {
            return $agentCap;
        }

        if ($planCap > 0) {
            return $planCap;
        }

        return 0;
    }

    private function remainingScore(array $limits, array $usage): int
    {
        $scores = [];

        foreach (['realtime_tokens', 'text_tokens'] as $key) {
            $limit = isset($limits[$key]) ? (int) $limits[$key] : 0;
            if ($limit > 0) {
                $remaining = $limit - (int) ($usage[$key === 'realtime_tokens' ? 'tokens_realtime' : 'tokens_text'] ?? 0);
                $scores[] = $remaining;
            }
        }

        $sessionLimit = isset($limits['sessions']) ? (int) $limits['sessions'] : 0;
        if ($sessionLimit > 0) {
            $remaining = $sessionLimit - (int) ($usage['sessions'] ?? 0);
            $scores[] = $remaining;
        }

        if (!$scores) {
            return PHP_INT_MAX;
        }

        return min($scores);
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
        if (!$allowedAgents) {
            return true;
        }

        if (!$agentSlug) {
            return false;
        }

        return in_array($agentSlug, $allowedAgents, true);
    }
}
