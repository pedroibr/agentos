<?php

namespace AgentOS\Subscriptions;

use AgentOS\Core\Config;

class SubscriptionRepository
{
    public function blank(): array
    {
        return [
            'label' => '',
            'slug' => '',
            'allowed_agents' => [],
            'period_hours' => 24,
            'limits' => [
                'realtime_tokens' => 0,
                'text_tokens' => 0,
                'sessions' => 0,
            ],
            'session_token_cap' => 0,
            'block_on_overage' => true,
            'notes' => '',
        ];
    }

    public function all(): array
    {
        $raw = get_option(Config::OPTION_SUBSCRIPTIONS, []);
        if (!is_array($raw)) {
            return [];
        }

        $plans = [];
        foreach ($raw as $slug => $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $slug = isset($plan['slug']) ? sanitize_key($plan['slug']) : sanitize_key((string) $slug);
            if (!$slug) {
                continue;
            }
            $plans[$slug] = $this->normalizePlan($plan, $slug);
        }

        return $plans;
    }

    public function get(string $slug): ?array
    {
        $all = $this->all();
        return $all[$slug] ?? null;
    }

    public function saveAll(array $plans): void
    {
        update_option(Config::OPTION_SUBSCRIPTIONS, $plans);
    }

    public function delete(string $slug): void
    {
        $plans = $this->all();
        if (isset($plans[$slug])) {
            unset($plans[$slug]);
            $this->saveAll($plans);
        }
    }

    public function upsert(array $input, string $originalSlug = ''): string
    {
        $plans = $this->all();

        $requestedSlug = '';
        if (!empty($input['slug'])) {
            $requestedSlug = sanitize_key($input['slug']);
        }

        if (!$requestedSlug && !empty($input['label'])) {
            $requestedSlug = sanitize_key(sanitize_title($input['label']));
        }

        if (!$requestedSlug) {
            $requestedSlug = 'plan-' . wp_generate_password(6, false, false);
        }

        $slug = $this->ensureUniqueSlug($requestedSlug, $originalSlug, $plans);
        $plan = $this->sanitizePlan($input, $slug);

        if ($originalSlug && $originalSlug !== $slug) {
            unset($plans[$originalSlug]);
        }

        $plans[$slug] = $plan;
        $this->saveAll($plans);

        return $slug;
    }

    private function ensureUniqueSlug(string $slug, string $original, array $plans): string
    {
        $base = $slug;
        $i = 1;
        while (isset($plans[$slug]) && $slug !== $original) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function sanitizePlan(array $input, string $slug): array
    {
        $plan = $this->blank();
        $plan['slug'] = $slug;
        $plan['label'] = sanitize_text_field($input['label'] ?? '');
        $plan['notes'] = sanitize_textarea_field($input['notes'] ?? '');

        $allowed = $input['allowed_agents'] ?? [];
        if (is_string($allowed)) {
            $allowed = array_map('trim', explode(',', $allowed));
        }
        $allowed = array_filter(array_map('sanitize_key', (array) $allowed));
        $plan['allowed_agents'] = array_values(array_unique($allowed));

        $periodHours = isset($input['period_hours']) ? (int) $input['period_hours'] : 24;
        if (isset($input['period_hours_custom'])) {
            $custom = (int) $input['period_hours_custom'];
            if ($custom > 0) {
                $periodHours = $custom;
            }
        }
        if ($periodHours < 1) {
            $periodHours = 24;
        }
        $plan['period_hours'] = $periodHours;

        $limits = $plan['limits'];
        if (!empty($input['limits']) && is_array($input['limits'])) {
            foreach (['realtime_tokens', 'text_tokens', 'sessions'] as $key) {
                if (isset($input['limits'][$key])) {
                    $value = (int) $input['limits'][$key];
                    $limits[$key] = $value > 0 ? $value : 0;
                }
            }
        } else {
            foreach (['realtime_tokens', 'text_tokens', 'sessions'] as $key) {
                if (isset($input[$key])) {
                    $value = (int) $input[$key];
                    $limits[$key] = $value > 0 ? $value : 0;
                }
            }
        }
        $plan['limits'] = $limits;

        $plan['session_token_cap'] = isset($input['session_token_cap']) ? max(0, (int) $input['session_token_cap']) : 0;
        $plan['block_on_overage'] = !empty($input['block_on_overage']);

        return $plan;
    }

    private function normalizePlan(array $plan, string $slug): array
    {
        $defaults = $this->blank();
        $plan = wp_parse_args($plan, $defaults);
        $plan['slug'] = $slug;
        $plan['label'] = sanitize_text_field($plan['label']);
        $plan['notes'] = sanitize_textarea_field($plan['notes']);

        $plan['allowed_agents'] = array_values(array_filter(array_map('sanitize_key', (array) $plan['allowed_agents'])));
        $plan['period_hours'] = max(1, (int) $plan['period_hours']);

        $limits = is_array($plan['limits']) ? $plan['limits'] : [];
        $plan['limits'] = [
            'realtime_tokens' => isset($limits['realtime_tokens']) ? max(0, (int) $limits['realtime_tokens']) : 0,
            'text_tokens' => isset($limits['text_tokens']) ? max(0, (int) $limits['text_tokens']) : 0,
            'sessions' => isset($limits['sessions']) ? max(0, (int) $limits['sessions']) : 0,
        ];

        $plan['session_token_cap'] = isset($plan['session_token_cap']) ? max(0, (int) $plan['session_token_cap']) : 0;
        $plan['block_on_overage'] = !empty($plan['block_on_overage']);

        return $plan;
    }
}
