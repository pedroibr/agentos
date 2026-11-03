<?php

namespace AgentOS\Mcp;

/** Stores MCP connection metadata and encrypted credentials in one site option. */
class ConnectionRepository
{
    private const OPTION = 'agentos_mcp_connections';

    public function all(): array
    {
        $items = get_option(self::OPTION, []);
        if (!is_array($items)) {
            return [];
        }
        return array_map([$this, 'publicRecord'], $items);
    }

    public function get(string $id): ?array
    {
        $items = get_option(self::OPTION, []);
        return isset($items[$id]) && is_array($items[$id]) ? $this->publicRecord($items[$id]) : null;
    }

    public function getSecret(string $id): ?array
    {
        $items = get_option(self::OPTION, []);
        if (!isset($items[$id]) || !is_array($items[$id])) {
            return null;
        }
        $record = $items[$id];
        $record['token'] = $this->decrypt((string) ($record['token_enc'] ?? ''));
        unset($record['token_enc']);
        return $record;
    }

    public function save(array $input, string $id = '', bool $clearToken = false): string
    {
        $items = get_option(self::OPTION, []);
        if (!is_array($items)) {
            $items = [];
        }
        $id = $id && isset($items[$id]) ? $id : 'mc_' . wp_generate_password(20, false, false);
        $old = $items[$id] ?? [];
        $label = sanitize_text_field($input['label'] ?? '');
        $url = self::validateEndpoint((string) ($input['url'] ?? ''));
        if (!$label || !$url) {
            throw new \InvalidArgumentException('Enter a label and a valid public HTTPS endpoint.');
        }
        $auth = ($input['auth'] ?? 'none') === 'bearer' ? 'bearer' : 'none';
        $token = trim((string) ($input['token'] ?? ''));
        $tokenEnc = $clearToken ? '' : (string) ($old['token_enc'] ?? '');
        if ($token !== '') {
            $tokenEnc = $this->encrypt($token);
        }
        if ($auth === 'bearer' && !$tokenEnc) {
            throw new \InvalidArgumentException('A bearer token is required for this authentication mode.');
        }
        $sameEndpoint = !empty($old['url']) && $old['url'] === $url && ($old['auth'] ?? 'none') === $auth
            && $token === '' && !$clearToken;
        $items[$id] = [
            'id' => $id,
            'label' => $label,
            'url' => $url,
            'enabled' => !empty($input['enabled']),
            'auth' => $auth,
            'token_enc' => $tokenEnc,
            'tools' => $sameEndpoint && is_array($old['tools'] ?? null) ? $old['tools'] : [],
        ];
        update_option(self::OPTION, $items, false);
        return $id;
    }

    public function delete(string $id): void
    {
        $items = get_option(self::OPTION, []);
        if (is_array($items) && isset($items[$id])) {
            unset($items[$id]);
            update_option(self::OPTION, $items, false);
        }
    }

    public function updateTools(string $id, array $tools): void
    {
        $items = get_option(self::OPTION, []);
        if (!isset($items[$id])) {
            return;
        }
        $items[$id]['tools'] = array_values($tools);
        update_option(self::OPTION, $items, false);
    }

    public static function validateEndpoint(string $url): string
    {
        $url = esc_url_raw(trim($url), ['https']);
        $parts = wp_parse_url($url);
        if (!$url || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local'
            || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }
        if (function_exists('wp_http_validate_url') && !wp_http_validate_url($url)) {
            return '';
        }
        return $url;
    }

    private function publicRecord(array $record): array
    {
        $hasToken = !empty($record['token_enc']);
        unset($record['token_enc'], $record['token']);
        $record['has_token'] = $hasToken;
        return $record;
    }

    private function encrypt(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('Secure token storage is unavailable: OpenSSL is required.');
        }
        $iv = random_bytes(16);
        $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Could not securely store the token.');
        }
        $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
        return base64_encode($iv . $mac . $cipher);
    }

    private function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        if (!function_exists('openssl_decrypt')) {
            throw new \RuntimeException('Secure token storage is unavailable: OpenSSL is required.');
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 49) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);
        $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
        if (!hash_equals($mac, hash_hmac('sha256', $iv . $cipher, $key, true))) {
            return '';
        }
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }
}
