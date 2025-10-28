<?php

namespace AgentOS\Subscriptions;

use AgentOS\Core\Config;

class UserSubscriptionRepository
{
    public function all(): array
    {
        $raw = get_option(Config::OPTION_USER_SUBSCRIPTIONS, []);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $userKey => $entries) {
            $userKey = $this->sanitizeUserKey($userKey);
            if (!$userKey) {
                continue;
            }
            $out[$userKey] = array_values(array_filter(array_map([$this, 'normalizeEntry'], (array) $entries)));
        }

        return $out;
    }

    public function get(string $userKey, bool $includeExpired = false): array
    {
        $userKey = $this->sanitizeUserKey($userKey);
        if (!$userKey) {
            return [];
        }

        $all = $this->all();
        $entries = $all[$userKey] ?? [];

        if (!$includeExpired) {
            $now = current_time('timestamp');
            $entries = array_values(array_filter($entries, static function ($entry) use ($now) {
                if (empty($entry['expires_at'])) {
                    return true;
                }
                $expiresTs = strtotime($entry['expires_at']);
                return $expiresTs === false || $expiresTs >= $now;
            }));
        }

        return $entries;
    }

    public function assign(string $userKey, string $subscriptionSlug, array $overrides = [], ?string $expiresAt = null): void
    {
        $userKey = $this->sanitizeUserKey($userKey);
        if (!$userKey) {
            return;
        }

        $subscriptionSlug = sanitize_key($subscriptionSlug);
        if (!$subscriptionSlug) {
            return;
        }

        $all = $this->all();
        $entries = $all[$userKey] ?? [];

        $found = false;
        foreach ($entries as &$entry) {
            if ($entry['subscription_slug'] === $subscriptionSlug) {
                $entry['overrides'] = $this->sanitizeOverrides($overrides);
                $entry['expires_at'] = $this->sanitizeDate($expiresAt);
                $entry['assigned_at'] = $entry['assigned_at'] ?: current_time('mysql');
                $found = true;
                break;
            }
        }
        unset($entry);

        if (!$found) {
            $entries[] = [
                'subscription_slug' => $subscriptionSlug,
                'assigned_at' => current_time('mysql'),
                'expires_at' => $this->sanitizeDate($expiresAt),
                'overrides' => $this->sanitizeOverrides($overrides),
            ];
        }

        $all[$userKey] = $entries;
        update_option(Config::OPTION_USER_SUBSCRIPTIONS, $all);
    }

    public function remove(string $userKey, string $subscriptionSlug): void
    {
        $userKey = $this->sanitizeUserKey($userKey);
        if (!$userKey) {
            return;
        }

        $subscriptionSlug = sanitize_key($subscriptionSlug);
        if (!$subscriptionSlug) {
            return;
        }

        $all = $this->all();
        if (!isset($all[$userKey])) {
            return;
        }

        $entries = array_values(array_filter($all[$userKey], static function ($entry) use ($subscriptionSlug) {
            return isset($entry['subscription_slug']) && $entry['subscription_slug'] !== $subscriptionSlug;
        }));

        $all[$userKey] = $entries;
        update_option(Config::OPTION_USER_SUBSCRIPTIONS, $all);
    }

    public function clearExpired(): void
    {
        $all = $this->all();
        $changed = false;
        $now = current_time('timestamp');

        foreach ($all as $userKey => $entries) {
            $filtered = array_values(array_filter($entries, static function ($entry) use ($now) {
                if (empty($entry['expires_at'])) {
                    return true;
                }
                $expiresTs = strtotime($entry['expires_at']);
                return $expiresTs === false || $expiresTs >= $now;
            }));

            if (count($filtered) !== count($entries)) {
                $changed = true;
            }

            if ($filtered) {
                $all[$userKey] = $filtered;
            } else {
                unset($all[$userKey]);
            }
        }

        if ($changed) {
            update_option(Config::OPTION_USER_SUBSCRIPTIONS, $all);
        }
    }

    public function moveUser(string $originalKey, string $newKey): bool
    {
        $originalKey = $this->sanitizeUserKey($originalKey);
        $newKey = $this->sanitizeUserKey($newKey);
        if (!$originalKey || !$newKey || $originalKey === $newKey) {
            return false;
        }

        $assignments = $this->all();
        $meta = $this->getUserMeta();

        $moved = false;

        if (isset($assignments[$originalKey])) {
            $existing = $assignments[$newKey] ?? [];
            $combined = [];
            foreach (array_merge($existing, $assignments[$originalKey]) as $entry) {
                if (!is_array($entry) || empty($entry['subscription_slug'])) {
                    continue;
                }
                $combined[$entry['subscription_slug']] = $entry;
            }
            $assignments[$newKey] = array_values($combined);
            unset($assignments[$originalKey]);
            update_option(Config::OPTION_USER_SUBSCRIPTIONS, $assignments);
            $moved = true;
        }

        if (isset($meta[$originalKey])) {
            $meta[$newKey] = $meta[$originalKey];
            unset($meta[$originalKey]);
            update_option(Config::OPTION_USER_SUBSCRIPTION_META, $meta);
            $moved = true;
        }

        return $moved;
    }

    private function sanitizeUserKey(string $key): string
    {
        $key = trim($key);
        $key = preg_replace('/[^a-zA-Z0-9_\-:@]/', '', $key);
        return $key;
    }

    private function sanitizeOverrides(array $overrides): array
    {
        $clean = [];

        if (isset($overrides['allowed_agents'])) {
            $allowed = $overrides['allowed_agents'];
            if (is_string($allowed)) {
                $allowed = array_map('trim', explode(',', $allowed));
            }
            $clean['allowed_agents'] = array_values(array_filter(array_map('sanitize_key', (array) $allowed)));
        }

        if (isset($overrides['period_hours'])) {
            $clean['period_hours'] = max(1, (int) $overrides['period_hours']);
        }

        if (isset($overrides['limits']) && is_array($overrides['limits'])) {
            $limits = [];
            foreach (['realtime_tokens', 'text_tokens', 'sessions'] as $key) {
                if (isset($overrides['limits'][$key])) {
                    $limits[$key] = max(0, (int) $overrides['limits'][$key]);
                }
            }
            $clean['limits'] = $limits;
        }

        if (isset($overrides['session_token_cap'])) {
            $clean['session_token_cap'] = max(0, (int) $overrides['session_token_cap']);
        }

        if (isset($overrides['block_on_overage'])) {
            $clean['block_on_overage'] = !empty($overrides['block_on_overage']);
        }

        return $clean;
    }

    private function sanitizeDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }
        return wp_date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeEntry($entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $slug = isset($entry['subscription_slug']) ? sanitize_key($entry['subscription_slug']) : '';
        if (!$slug) {
            return null;
        }

        $overrides = isset($entry['overrides']) && is_array($entry['overrides']) ? $entry['overrides'] : [];

        return [
            'subscription_slug' => $slug,
            'assigned_at' => isset($entry['assigned_at']) ? sanitize_text_field($entry['assigned_at']) : '',
            'expires_at' => $this->sanitizeDate($entry['expires_at'] ?? null),
            'overrides' => $this->sanitizeOverrides($overrides),
        ];
    }
    public function ensureUser(string $userKey, array $meta = []): void
    {
        $userKey = $this->sanitizeUserKey($userKey);
        if (!$userKey) {
            return;
        }

        $all = $this->all();
        if (!isset($all[$userKey])) {
            $all[$userKey] = [];
            update_option(Config::OPTION_USER_SUBSCRIPTIONS, $all);
        }

        $details = $this->getUserMeta();
        $existing = $details[$userKey] ?? $this->defaultMeta();
        $merged = $this->mergeMeta($existing, $meta);
        if ($merged !== $existing) {
            $details[$userKey] = $merged;
            update_option(Config::OPTION_USER_SUBSCRIPTION_META, $details);
        }
    }

    public function getUserMeta(): array
    {
        $raw = get_option(Config::OPTION_USER_SUBSCRIPTION_META, []);
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $userKey => $meta) {
            $userKey = $this->sanitizeUserKey((string) $userKey);
            if (!$userKey) {
                continue;
            }
            $clean[$userKey] = $this->normalizeMeta(is_array($meta) ? $meta : []);
        }

        return $clean;
    }

    public function saveUserMeta(string $userKey, array $meta): void
    {
        $userKey = $this->sanitizeUserKey($userKey);
        if (!$userKey) {
            return;
        }

        $all = $this->getUserMeta();
        $existing = $all[$userKey] ?? $this->defaultMeta();
        $all[$userKey] = $this->mergeMeta($existing, $meta);
        update_option(Config::OPTION_USER_SUBSCRIPTION_META, $all);
    }

    public function deleteUser(string $userKey): void
    {
        $userKey = $this->sanitizeUserKey($userKey);
        if (!$userKey) {
            return;
        }

        $assignments = $this->all();
        if (isset($assignments[$userKey])) {
            unset($assignments[$userKey]);
            update_option(Config::OPTION_USER_SUBSCRIPTIONS, $assignments);
        }

        $meta = $this->getUserMeta();
        if (isset($meta[$userKey])) {
            unset($meta[$userKey]);
            update_option(Config::OPTION_USER_SUBSCRIPTION_META, $meta);
        }
    }

    private function normalizeMeta(array $meta): array
    {
        if (isset($meta['label']) && empty($meta['name'])) {
            $meta['name'] = $meta['label'];
        }

        $defaults = $this->defaultMeta();
        return $this->mergeMeta($defaults, $meta);
    }

    private function defaultMeta(): array
    {
        return [
            'name' => '',
            'email' => '',
            'notes' => '',
            'wp_user_id' => 0,
        ];
    }

    private function mergeMeta(array $existing, array $incoming): array
    {
        $incomingSanitized = [];
        if (isset($incoming['name'])) {
            $incomingSanitized['name'] = sanitize_text_field($incoming['name']);
        }
        if (isset($incoming['email'])) {
            $incomingSanitized['email'] = sanitize_email($incoming['email']);
        }
        if (isset($incoming['notes'])) {
            $incomingSanitized['notes'] = sanitize_textarea_field($incoming['notes']);
        }
        if (isset($incoming['wp_user_id'])) {
            $incomingSanitized['wp_user_id'] = (int) $incoming['wp_user_id'];
        }

        $merged = $existing;
        foreach ($incomingSanitized as $key => $value) {
            if ($key === 'wp_user_id') {
                if ($value > 0) {
                    $merged[$key] = $value;
                }
                continue;
            }
            if ($key === 'notes') {
                $merged[$key] = $value;
                continue;
            }
            if ($value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

}
