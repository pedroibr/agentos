<?php

namespace AgentOS\Mcp;

/** Minimal MCP Streamable HTTP client. */
class Client
{
    private ConnectionRepository $connections;

    public function __construct(ConnectionRepository $connections)
    {
        $this->connections = $connections;
    }

    /** @return array|\WP_Error */
    public function discover(string $id)
    {
        $reference = Diagnostics::newReference();
        $connection = $this->connections->getSecret($id);
        if (!$connection || empty($connection['enabled'])) {
            return $this->failure('mcp_connection_unavailable', __('MCP connection is unavailable.', 'agentos'), $reference, 'initialize');
        }
        $initialize = $this->request($connection, 'initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'AgentOS', 'version' => '1.0'],
        ], '', false, $reference, 'initialize');
        if (is_wp_error($initialize)) {
            return $initialize;
        }
        if (($initialize['result']['protocolVersion'] ?? '') !== '2025-03-26') {
            return $this->failure('mcp_unsupported_protocol', __('MCP server selected an unsupported protocol version.', 'agentos'), $reference, 'initialize');
        }
        $notification = $this->notifyInitialized($connection, $initialize['session_id'] ?? '', $reference);
        if (is_wp_error($notification)) {
            return $notification;
        }

        $tools = [];
        $cursor = '';
        for ($page = 0; $page < 5; $page++) {
            $params = $cursor !== '' ? ['cursor' => $cursor] : new \stdClass();
            $result = $this->request($connection, 'tools/list', $params, $initialize['session_id'] ?? '', true, $reference, 'tools/list');
            if (is_wp_error($result)) {
                return $result;
            }
            foreach (($result['result']['tools'] ?? []) as $tool) {
                if (!is_array($tool) || !isset($tool['name']) || !is_string($tool['name'])
                    || trim($tool['name']) === '' || strlen($tool['name']) > 256
                    || preg_match('/[\x00-\x1F\x7F]/', $tool['name']) || !is_array($tool['inputSchema'] ?? null)) {
                    continue;
                }
                $schema = $tool['inputSchema'];
                if (($schema['type'] ?? '') !== 'object' || (isset($schema['properties']) && !is_array($schema['properties']))) {
                    continue;
                }
                $tools[] = [
                    'name' => (string) $tool['name'],
                    'description' => sanitize_textarea_field((string) ($tool['description'] ?? '')),
                    'inputSchema' => $schema,
                    'annotations' => is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [],
                ];
                if (count($tools) >= 100) {
                    break 2;
                }
            }
            $cursor = (string) ($result['result']['nextCursor'] ?? '');
            if ($cursor === '') {
                break;
            }
        }
        $this->connections->updateTools($id, $tools);
        return $tools;
    }

    /** @return array|\WP_Error */
    public function call(string $id, string $toolName, array $arguments, string $requestId = '')
    {
        $reference = $requestId !== '' ? $requestId : Diagnostics::newReference();
        $connection = $this->connections->getSecret($id);
        if (!$connection || empty($connection['enabled'])) {
            return $this->failure('mcp_connection_unavailable', __('MCP connection is unavailable.', 'agentos'), $reference, 'initialize');
        }
        $initialize = $this->request($connection, 'initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'AgentOS', 'version' => '1.0'],
        ], '', false, $reference, 'initialize');
        if (is_wp_error($initialize)) {
            return $initialize;
        }
        if (($initialize['result']['protocolVersion'] ?? '') !== '2025-03-26') {
            return $this->failure('mcp_unsupported_protocol', __('MCP server selected an unsupported protocol version.', 'agentos'), $reference, 'initialize');
        }
        $notification = $this->notifyInitialized($connection, $initialize['session_id'] ?? '', $reference);
        if (is_wp_error($notification)) {
            return $notification;
        }
        $result = $this->request($connection, 'tools/call', ['name' => $toolName, 'arguments' => (object) $arguments], $initialize['session_id'] ?? '', true, $reference, $toolName);
        if (is_wp_error($result)) {
            return $result;
        }
        return is_array($result['result'] ?? null) ? $result['result'] : [];
    }

    private function notifyInitialized(array $connection, string $sessionId, string $reference = ''): ?\WP_Error
    {
        $reference = $reference !== '' ? $reference : Diagnostics::newReference();
        $response = $this->post($connection, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], '', $sessionId, true, $reference, 'notifications_initialized');
        return is_wp_error($response) ? $response : null;
    }

    /** @return array|\WP_Error */
    private function request(array $connection, string $method, $params, string $sessionId = '', bool $initialized = false, string $reference = '', string $phase = '')
    {
        $reference = $reference !== '' ? $reference : Diagnostics::newReference();
        $phase = $phase !== '' ? $phase : $method;
        static $nextId = 1;
        $id = $nextId++;
        $response = $this->post($connection, [
            'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params,
        ], $id, $sessionId, $initialized, $reference, $phase);
        if (is_wp_error($response)) {
            return $response;
        }
        $decoded = $response['body'];
        if (!is_array($decoded) || ($decoded['jsonrpc'] ?? '') !== '2.0'
            || !array_key_exists('id', $decoded) || (string) $decoded['id'] !== (string) $id) {
            return $this->failure('mcp_invalid_response', __('MCP server returned an invalid response.', 'agentos'), $reference, $phase);
        }
        $hasResult = array_key_exists('result', $decoded);
        $hasError = array_key_exists('error', $decoded);
        if ($hasResult === $hasError) {
            return $this->failure('mcp_invalid_response', __('MCP server returned an invalid response.', 'agentos'), $reference, $phase);
        }
        if ($hasError) {
            if (!is_array($decoded['error']) || !isset($decoded['error']['code'], $decoded['error']['message'])
                || !is_int($decoded['error']['code']) || !is_string($decoded['error']['message'])) {
                return $this->failure('mcp_invalid_response', __('MCP server returned an invalid response.', 'agentos'), $reference, $phase);
            }
            $details = Diagnostics::safeRemoteDetails($decoded['error']);
            return $this->failure($details['code'], __('MCP server could not complete the request.', 'agentos'), $reference, $phase, (int) ($response['http_status'] ?? 0), $details['remote_code'], $details['fields'], $details['issues']);
        }
        if (!is_array($decoded['result'])) {
            return $this->failure('mcp_invalid_response', __('MCP server returned an invalid response.', 'agentos'), $reference, $phase);
        }
        return ['result' => $decoded['result'], 'session_id' => $response['session_id']];
    }

    /** @return array|\WP_Error */
    private function post(array $connection, array $payload, $id, string $sessionId = '', bool $initialized = false, string $reference = '', string $phase = '')
    {
        $reference = $reference !== '' ? $reference : Diagnostics::newReference();
        $url = ConnectionRepository::validateEndpoint((string) $connection['url']);
        if (!$url) {
            return $this->failure('mcp_invalid_endpoint', __('MCP endpoint is not allowed.', 'agentos'), $reference, $phase);
        }
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        if (($connection['auth'] ?? 'none') === 'bearer') {
            if (empty($connection['token'])) {
                return $this->failure('mcp_missing_credential', __('MCP credential is unavailable.', 'agentos'), $reference, $phase);
            }
            $headers['Authorization'] = 'Bearer ' . $connection['token'];
        }
        if ($sessionId !== '') {
            $headers['Mcp-Session-Id'] = $sessionId;
        }
        if ($initialized) {
            $headers['MCP-Protocol-Version'] = '2025-03-26';
        }
        $response = wp_safe_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 12,
            'redirection' => 0,
            'limit_response_size' => 262144,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            return $this->failure('mcp_network_error', __('Could not reach MCP server.', 'agentos'), $reference, $phase);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return $this->failure('mcp_http_error', __('MCP server returned an unsuccessful response.', 'agentos'), $reference, $phase, $status);
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > 262144) {
            return $this->failure('mcp_response_too_large', __('MCP server response exceeded the size limit.', 'agentos'), $reference, $phase, $status);
        }
        if ($id === '' && $body === '') {
            return ['body' => null, 'session_id' => (string) wp_remote_retrieve_header($response, 'Mcp-Session-Id')];
        }
        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if (strpos($contentType, 'text/event-stream') !== false) {
            $body = $this->extractSseData($body, $id);
        }
        $decoded = json_decode($body, true);
        if ($id === '') {
            return $this->failure('mcp_invalid_response', __('MCP server returned an unexpected notification response.', 'agentos'), $reference, $phase, $status);
        }
        if (!is_array($decoded)) {
            return $this->failure('mcp_invalid_response', __('MCP server returned an invalid response.', 'agentos'), $reference, $phase, $status);
        }
        if ($id !== '' && (!array_key_exists('id', $decoded) || (string) $decoded['id'] !== (string) $id)) {
            return $this->failure('mcp_invalid_response', __('MCP server returned an invalid response.', 'agentos'), $reference, $phase, $status);
        }
        if (($decoded['jsonrpc'] ?? '') !== '2.0') {
            return $this->failure('mcp_invalid_response', __('MCP server returned an invalid response.', 'agentos'), $reference, $phase, $status);
        }
        return ['body' => $decoded, 'session_id' => (string) wp_remote_retrieve_header($response, 'Mcp-Session-Id'), 'http_status' => $status];
    }

    private function extractSseData(string $body, $expectedId): string
    {
        foreach (preg_split('/\r?\n\r?\n/', $body) as $event) {
            $data = [];
            foreach (preg_split('/\r?\n/', $event) as $line) {
                if (strpos($line, 'data:') === 0) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            if ($data) {
                $candidate = implode("\n", $data);
                $message = json_decode($candidate, true);
                if (is_array($message) && ($message['jsonrpc'] ?? '') === '2.0'
                    && $expectedId !== '' && array_key_exists('id', $message)
                    && (string) $message['id'] === (string) $expectedId) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    private function failure(string $code, string $message, string $reference, string $phase, int $httpStatus = 0, ?int $remoteCode = null, array $fields = [], array $issues = []): \WP_Error
    {
        return new \WP_Error($code, $message, [
            'status' => 502,
            'mcp_diagnostic' => [
                'reference' => $reference,
                'phase' => $phase,
                'code' => $code,
                'http_status' => $httpStatus,
                'remote_code' => $remoteCode,
                'fields' => Diagnostics::safeFieldNames($fields),
                'issues' => $issues,
            ],
        ]);
    }
}
