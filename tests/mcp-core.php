<?php
/** Focused MCP storage, allowlist, schema, and request-path checks. */

require_once dirname(__DIR__) . '/src/Mcp/ConnectionRepository.php';
require_once dirname(__DIR__) . '/src/Mcp/Diagnostics.php';
require_once dirname(__DIR__) . '/src/Mcp/Client.php';
require_once dirname(__DIR__) . '/src/Mcp/ToolManager.php';
require_once dirname(__DIR__) . '/src/Core/Config.php';
require_once dirname(__DIR__) . '/src/Core/AgentRepository.php';

class WP_Error
{
    public string $code;
    public string $message;
    public $data;
    public function __construct(string $code, string $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}

$options = [];
$requests = [];
$failInitialized = false;
$remoteFailure = null;
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; return true; }
function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
function wp_generate_password($length = 12, $special = true, $extra = false) { static $sequence = 0; $sequence++; return substr(str_pad((string) $sequence, $length, 'x'), -$length); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_title($value) { return sanitize_key(str_replace(' ', '-', (string) $value)); }
function wp_parse_args($args, $defaults = []) { return array_merge((array) $defaults, (array) $args); }
function wp_unslash($value) { if (is_array($value)) { $out = []; foreach ($value as $key => $item) { $out[$key] = wp_unslash($item); } return $out; } return is_string($value) ? stripslashes($value) : $value; }
function wp_slash($value) { if (is_array($value)) { $out = []; foreach ($value as $key => $item) { $out[$key] = wp_slash($item); } return $out; } return is_string($value) ? addslashes($value) : $value; }
function get_post_types($args = [], $output = 'names') { return ['post']; }
function esc_url_raw($url, $protocols = null) { return (string) $url; }
function wp_parse_url($url) { return parse_url($url); }
function wp_http_validate_url($url) { return true; }
function __($message) { return $message; }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_safe_remote_post($url, $args) {
    global $requests, $failInitialized, $remoteFailure;
    $requests[] = [$url, $args];
    $payload = json_decode($args['body'], true);
    $id = $payload['id'] ?? null;
    if (($payload['method'] ?? '') === 'notifications/initialized') {
        if ($failInitialized) {
            return ['response' => ['code' => 503], 'headers' => ['content-type' => 'text/plain'], 'body' => ''];
        }
        return ['response' => ['code' => 202], 'headers' => ['content-type' => 'text/plain'], 'body' => ''];
    }
    if (($payload['method'] ?? '') === 'tools/call' && is_array($remoteFailure)) {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
            'code' => -32000,
            'message' => 'private@example.test must never enter diagnostics',
            'data' => ['code' => $remoteFailure['code'], 'details' => ['errors' => [['field' => $remoteFailure['field'], 'message' => 'Phone must be a valid phone number. private value rejected']]]],
        ]];
        return ['response' => ['code' => 200], 'headers' => ['content-type' => 'application/json', 'mcp-session-id' => 'session-1'], 'body' => json_encode($message)];
    }
    $result = [];
    if (($payload['method'] ?? '') === 'initialize') {
        $result = ['protocolVersion' => '2025-03-26', 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'fixture', 'version' => '1']];
    } elseif (($payload['method'] ?? '') === 'tools/list') {
        $result = ['tools' => [
            ['name' => 'lookup', 'description' => 'Read only lookup', 'inputSchema' => ['type' => 'object', 'properties' => ['key' => ['type' => ['string', 'null'], 'enum' => ['value', null]]], 'required' => ['key'], 'additionalProperties' => false]],
            ['name' => 'empty_input', 'description' => 'No arguments', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
            ['name' => 'nested', 'description' => 'Validate nested object', 'inputSchema' => ['type' => 'object', 'properties' => ['config' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'], 'additionalProperties' => false]], 'required' => ['config'], 'additionalProperties' => false]],
        ]];
    } elseif (($payload['method'] ?? '') === 'tools/call') {
        $result = ['content' => [['type' => 'text', 'text' => 'done']], 'isError' => false];
    }
    $message = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    $body = "event: message\ndata: " . json_encode(['jsonrpc' => '2.0', 'id' => 'unrelated', 'result' => ['ignored' => true]])
        . "\n\nevent: message\ndata: " . json_encode($message) . "\n\n";
    return ['response' => ['code' => 200], 'headers' => ['content-type' => 'text/event-stream', 'mcp-session-id' => 'session-1'], 'body' => $body];
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][strtolower($name)] ?? ''; }
function expect($condition, string $label): void { if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } echo 'PASS: ' . $label . "\n"; }

$repo = new AgentOS\Mcp\ConnectionRepository();
expect(AgentOS\Mcp\ConnectionRepository::validateEndpoint('http://example.com/mcp') === '', 'rejects non-HTTPS endpoints');
expect(AgentOS\Mcp\ConnectionRepository::validateEndpoint('https://user:pass@example.com/mcp') === '', 'rejects endpoint userinfo');
expect(AgentOS\Mcp\ConnectionRepository::validateEndpoint('https://127.0.0.1/mcp') === '', 'rejects literal IP endpoints');
$id = $repo->save(['label' => 'Example', 'url' => 'https://mcp.example.org/api', 'auth' => 'bearer', 'token' => 'secret-token', 'enabled' => 1]);
expect(!str_contains(json_encode($repo->all()), 'secret-token'), 'public connection listing never returns credentials');
expect(!empty($repo->get($id)['has_token']), 'public metadata indicates a token exists without exposing it');
expect(str_contains(json_encode(get_option('agentos_mcp_connections')), 'secret-token') === false, 'credentials are encrypted at rest');
$secret = $repo->getSecret($id);
expect(($secret['token'] ?? '') === 'secret-token', 'stored credential decrypts for backend requests');
$repo->save(['label' => 'Example changed', 'url' => 'https://mcp.example.org/api', 'auth' => 'bearer', 'token' => '', 'enabled' => 1], $id);
expect($repo->getSecret($id)['token'] === 'secret-token', 'blank credential preserves existing token');
$client = new AgentOS\Mcp\Client($repo);
$discovered = $client->discover($id);
expect(is_array($discovered) && count($discovered) === 3, 'discovery unwraps matching JSON-RPC SSE envelope');
$manager = new AgentOS\Mcp\ToolManager();
$agent = ['mcp_tools' => [$id => ['lookup']], 'mcp_approval' => [$id => 'confirm']];
$resolved = $manager->resolveForAgent($agent);
expect(count($resolved['tools']) === 1 && $resolved['bindings'][$resolved['tools'][0]['name']]['approval'] === 'confirm', 'only explicitly selected tools resolve with approval policy');
expect(str_contains($resolved['tools'][0]['description'], 'lookup'), 'model-facing description includes the real MCP tool name');
expect(is_array($resolved['tools'][0]['parameters']['properties']), 'lookup tool schema properties remain an object-shaped map');
$emptyAgent = ['mcp_tools' => [$id => ['empty_input']]];
$emptyResolved = $manager->resolveForAgent($emptyAgent);
expect(is_object($emptyResolved['tools'][0]['parameters']['properties']), 'empty object schema properties remain JSON object');
expect(is_array($manager->execute($emptyAgent, $emptyResolved['tools'][0]['name'], [])), 'empty object input executes with no arguments');
$nestedAgent = ['mcp_tools' => [$id => ['nested']]];
$nestedName = $manager->resolveForAgent($nestedAgent)['tools'][0]['name'];
expect(is_array($manager->execute($nestedAgent, $nestedName, ['config' => (object) ['path' => '/read-only']])) , 'stdClass nested objects pass recursive schema validation');
expect($manager->execute($nestedAgent, $nestedName, ['config' => (object) []]) instanceof WP_Error, 'required nested object properties are enforced');
$nestedError = $manager->execute($nestedAgent, $nestedName, ['config' => (object) []]);
$nestedDiagnostic = (new AgentOS\Mcp\Diagnostics())->recordError($nestedError, 'MCP-ABCDEF123457', 'Example', 'nested', 'nested', ['config', 'path']);
expect(($nestedDiagnostic['issues'][0]['field'] ?? '') === 'config.path' && ($nestedDiagnostic['issues'][0]['rule'] ?? '') === 'missing_required', 'local schema failures record only the expected argument name and issue type');
expect(str_contains(AgentOS\Mcp\Diagnostics::modelMessage($nestedDiagnostic), 'config.path (missing_required)'), 'model receives a safe expected-argument correction hint');
expect($manager->execute($nestedAgent, $nestedName, ['config' => ['not-an-object']]) instanceof WP_Error, 'list arrays cannot satisfy object schemas');
expect($manager->execute($agent, 'forged_name', []) instanceof WP_Error, 'unknown function names are denied');
expect($manager->execute($agent, $resolved['tools'][0]['name'], []) instanceof WP_Error, 'required arguments are enforced');
$output = $manager->execute($agent, $resolved['tools'][0]['name'], ['key' => null]);
expect(is_array($output) && empty($output['isError']), 'tools/call unwraps the JSON-RPC result and accepts nullable union schema');
expect(strpos($requests[0][1]['headers']['Authorization'], 'secret-token') !== false, 'authorization is only sent in backend MCP HTTP request');
expect($requests[0][1]['redirection'] === 0 && $requests[0][1]['limit_response_size'] === 262144, 'MCP request disables redirects and bounds response size');
expect($requests[1][1]['headers']['MCP-Protocol-Version'] === '2025-03-26', 'initialized notification carries negotiated protocol version');
expect(json_decode($requests[1][1]['body'], true) === ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], 'initialized notification accepts the Streamable HTTP 202 empty response');
$remoteFailure = ['code' => 'validation_error', 'field' => 'phone'];
$remoteError = $client->call($id, 'prepare_form_submission', ['answers' => ['phone' => 'synthetic value'], 'schema_version' => 'schema'], 'MCP-ABCDEF123456');
$remoteData = $remoteError instanceof WP_Error ? $remoteError->get_error_data() : [];
expect(is_wp_error($remoteError) && $remoteError->get_error_code() === 'validation_error', 'JSON-RPC validation code survives the MCP client boundary');
expect(($remoteData['mcp_diagnostic']['http_status'] ?? 0) === 200 && ($remoteData['mcp_diagnostic']['remote_code'] ?? 0) === -32000, 'diagnostic separates successful HTTP status from remote JSON-RPC code');
expect(($remoteData['mcp_diagnostic']['issues'][0]['rule'] ?? '') === 'phone_invalid', 'known validation wording becomes a safe rule code without retaining its message');
expect(!str_contains(json_encode($remoteData), 'private@example.test') && !str_contains(json_encode($remoteData), 'private value rejected'), 'remote messages and validation values are excluded from diagnostics');
$diagnostics = new AgentOS\Mcp\Diagnostics();
$entry = $diagnostics->recordError($remoteError, 'MCP-ABCDEF123456', 'Form service', 'prepare_form_submission', 'prepare_form_submission', ['phone']);
expect($entry['fields'] === ['phone'] && $entry['code'] === 'validation_error', 'admin diagnostic keeps only known validation field names and safe error codes');
$remoteFailure = null;
$formId = $repo->save(['label' => 'Form service', 'url' => 'https://forms.example.org/mcp', 'enabled' => 1]);
$repo->updateTools($formId, [['name' => 'get_form_schema', 'description' => 'Read form fields', 'inputSchema' => ['type' => 'object', 'properties' => []]]]);
$repo->updateTools($id, [['name' => 'search_openai_docs', 'description' => 'Search docs', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]]]);
$agentRepo = new AgentOS\Core\AgentRepository();
$formInstructions = "Form-only guidance. Don't list future fields.";
$agentInput = wp_unslash(wp_slash([
    'label' => 'MCP instructions test', 'slug' => 'mcp-instructions-test',
    'mcp_instructions' => 'Legacy instructions stay intact.',
    'mcp_connection_instructions' => [$formId => $formInstructions, $id => 'Docs-only guidance.'],
    'mcp_tools' => [$formId => ['get_form_schema'], $id => ['search_openai_docs']],
]));
$slug = $agentRepo->upsert($agentInput);
$savedAgent = $agentRepo->get($slug);
$agentInput['slug'] = $slug;
$agentInput['mcp_tools'] = [$formId => ['get_form_schema']];
$agentRepo->upsert(wp_unslash(wp_slash($agentInput)), $slug);
expect($savedAgent['mcp_instructions'] === 'Legacy instructions stay intact.' && $savedAgent['mcp_connection_instructions'][$formId] === $formInstructions && $savedAgent['mcp_connection_instructions'][$id] === 'Docs-only guidance.', 'slashed form input preserves apostrophes and independently stores connection and legacy instructions');
$runtime = $manager->resolveForAgent($agentRepo->get($slug));
expect(str_contains($runtime['instructions'], 'Form-only guidance.') && !str_contains($runtime['instructions'], 'Docs-only guidance.'), 'unselected connection instructions stay out of runtime while their saved text remains available');
$repo->save(['label' => 'OpenAI Docs', 'url' => 'https://mcp.example.org/api', 'auth' => 'bearer', 'token' => '', 'enabled' => 0], $id);
$runtime = $manager->resolveForAgent($agentRepo->get($slug));
expect(str_contains($runtime['instructions'], 'Legacy instructions stay intact.') && str_contains($runtime['instructions'], 'Form-only guidance.') && !str_contains($runtime['instructions'], 'Docs-only guidance.'), 'runtime includes only selected enabled connection instructions and keeps legacy guidance');
expect(count($runtime['tools']) === 1 && $runtime['bindings'][$runtime['tools'][0]['name']]['tool_name'] === 'get_form_schema', 'disabled connection tools do not enter the runtime tool set');
$repo->save(['label' => 'OpenAI Docs', 'url' => 'https://mcp.example.org/api', 'auth' => 'bearer', 'token' => '', 'enabled' => 1], $id);
$failInitialized = true;
expect(is_wp_error($client->discover($id)), 'initialized notification transport failures abort discovery');
$failInitialized = false;
$repo->save(['label' => 'Moved endpoint', 'url' => 'https://other.example.org/mcp', 'auth' => 'bearer', 'token' => '', 'enabled' => 1], $id);
expect($repo->get($id)['tools'] === [], 'changing endpoint clears the old tool catalog');
