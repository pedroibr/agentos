<?php
namespace AgentOS\Mcp {
    class ToolManager {
        public array $executions = [];
        public bool $enabled = true;
        public function resolveForAgent(array $agent): array {
            return ['tools' => $this->enabled ? [['type' => 'function', 'name' => 'mcp_lookup']] : [], 'instructions' => '', 'bindings' => $this->enabled ? ['mcp_lookup' => ['tool_name' => $agent['tool_name'] ?? 'lookup', 'connection_id' => 'form-connection', 'connection_label' => 'Form service', 'argument_fields' => ['answers', 'schema_version'], 'approval' => $agent['approval'] ?? 'confirm']] : []];
        }
        public function execute(array $agent, string $name, array $arguments, string $requestId = '') {
            $this->executions[] = [$name, $arguments];
            if (($agent['tool_name'] ?? '') === 'get_form_schema') {
                return ['content' => [['type' => 'text', 'text' => json_encode(['schema' => ['fields' => [['name' => 'email', 'type' => 'email'], ['name' => 'phone', 'type' => 'phone']]]])]]];
            }
            if (($agent['tool_name'] ?? '') === 'prepare_form_submission') {
                return new \WP_Error('validation_error', 'private@example.test and private phone must never be logged', [
                    'mcp_diagnostic' => ['phase' => 'prepare_form_submission', 'code' => 'validation_error', 'fields' => ['phone', 'secret@example.test'], 'issues' => [['field' => 'phone', 'rule' => 'phone_invalid']]],
                ]);
            }
            return ['content' => [['type' => 'text', 'text' => 'verified result']]];
        }
    }
}
namespace {
require_once dirname(__DIR__) . '/src/Mcp/SessionBroker.php';
require_once dirname(__DIR__) . '/src/Mcp/TextToolFlow.php';
class WP_Error {
    public string $code;
    public string $message;
    public $data;
    public function __construct($code, $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
$actor = 12; $transients = []; $options = []; $apiQueue = []; $apiRequests = [];
require_once dirname(__DIR__) . '/src/Mcp/Diagnostics.php';
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text, $domain = '') { return $text; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function get_current_user_id() { return $GLOBALS['actor']; }
function wp_generate_password($length, $special = true, $extra = false) { return substr(bin2hex(random_bytes($length)), 0, $length); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function add_option($key, $value, $deprecated = '', $autoload = true) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function wp_json_encode($value) { return json_encode($value); }
function wp_remote_post($url, $options) { $GLOBALS['apiRequests'][] = json_decode($options['body'], true); $item = array_shift($GLOBALS['apiQueue']); if ($item instanceof WP_Error) return $item; $code = is_array($item) && isset($item['_http_status']) ? (int) $item['_http_status'] : 200; if (is_array($item) && array_key_exists('_http_status', $item)) unset($item['_http_status']); return ['code' => $code, 'body' => json_encode($item)]; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function expectFlow($value, $label) { if (!$value) throw new RuntimeException($label); echo "PASS: $label\n"; }

$manager = new AgentOS\Mcp\ToolManager();
$broker = new AgentOS\Mcp\SessionBroker($manager);
$grant = $broker->issue([], 'agent', 2, 'session');
$ticket = $grant['ticket'];
$invoke = function ($call = 'call1', $args = ['key' => 'value'], $decision = null, $approval = '') use ($broker, $ticket) {
    return $broker->invoke([], $ticket, 'agent', 2, 'session', $call, 'mcp_lookup', $args, $decision, $approval);
};
expectFlow(is_wp_error($broker->validate($ticket, 'other-agent', 2, 'session')), 'tool ticket is bound to its agent');
expectFlow(is_wp_error($broker->validate($ticket, 'agent', 3, 'session')), 'tool ticket is bound to its post');
$actor = 13;
expectFlow(is_wp_error($broker->validate($ticket, 'agent', 2, 'session')), 'another authenticated user cannot use the ticket');
$actor = 12;
$pending = $invoke('call1', ['key' => 'value'], true, 'forged');
expectFlow(!empty($pending['approval_required']) && !$manager->executions, 'first request cannot self-approve');
expectFlow(is_wp_error($invoke('call1', ['key' => 'changed'])), 'call ID cannot change arguments');
$done = $invoke('call1', ['key' => 'value'], true, $pending['approval_id']);
expectFlow(count($manager->executions) === 1 && !empty($done['output']), 'valid approval executes the tool once');
expectFlow($invoke() === $done && count($manager->executions) === 1, 'duplicate call returns its receipt without executing again');
$pending2 = $invoke('call2');
$denied = $invoke('call2', ['key' => 'value'], false, $pending2['approval_id']);
expectFlow($denied['is_error'] && count($manager->executions) === 1, 'declining creates a result without remote execution');
$manager->enabled = false;
expectFlow(is_wp_error($invoke('call3')), 'disabling a tool revokes access in an existing session');
$manager->enabled = true;

$apiQueue = [
    ['output' => [['type' => 'reasoning', 'id' => 'reason1', 'summary' => []], ['type' => 'function_call', 'call_id' => 'text_call', 'name' => 'mcp_lookup', 'arguments' => '{"key":"value"}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13]],
    ['output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Answer from verified data']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 5, 'total_tokens' => 25]],
];
$flow = new AgentOS\Mcp\TextToolFlow($broker);
$first = $flow->run(['model' => 'test-model', 'input' => [['role' => 'user', 'content' => 'question']]], 'server-key', $ticket);
expectFlow(count($first['tool_calls']) === 1 && $first['continuation'] !== '', 'text response pauses at the model tool request');
expectFlow(is_wp_error($invoke('text_call', ['key' => 'browser replacement'])), 'browser cannot replace model-selected tool arguments');
expectFlow(is_wp_error($flow->run([], 'server-key', $ticket, $first['continuation'])), 'text continuation requires a server-owned call receipt');
$approval = $invoke('text_call');
$invoke('text_call', ['key' => 'value'], true, $approval['approval_id']);
$final = $flow->run(['input' => [['role' => 'user', 'content' => 'forged input']]], 'server-key', $ticket, $first['continuation']);
expectFlow($final['usage']['total_tokens'] === 38, 'text continuation aggregates usage over tool rounds');
$last = end($apiRequests);
expectFlow($last['input'][0]['content'] === 'question' && $last['input'][1]['type'] === 'reasoning', 'continuation retains original input and model reasoning on the server');
expectFlow(strpos(end($last['input'])['output'], 'verified result') !== false, 'model receives the server receipt rather than browser-provided output');
expectFlow($flow->run([], 'server-key', $ticket, $first['continuation']) === $final && count($apiRequests) === 2, 'duplicate continuation returns cached response');
$accountingGrant = $broker->issue([], 'account-agent', 2, 'account-session');
$accountingTicket = $accountingGrant['ticket'];
$accountingFlow = new AgentOS\Mcp\TextToolFlow($broker);
$preflights = []; $usageEvents = [];
$beforeRequest = function (array $payload) use (&$preflights) {
    $preflights[] = $payload;
    $payload['max_output_tokens'] = 16;
    return ['payload' => $payload, 'reservation_id' => 'reservation-' . count($preflights)];
};
$onUsage = function (string $reservationId, ?array $event, bool $unknown) use (&$usageEvents) {
    $usageEvents[] = [$reservationId, $event, $unknown];
    return true;
};
$apiQueue = [
    ['id' => 'resp-tool', 'status' => 'completed', 'output' => [['type' => 'function_call', 'call_id' => 'metered-call', 'name' => 'mcp_lookup', 'arguments' => '{}']], 'usage' => ['input_tokens' => 7, 'output_tokens' => 2, 'total_tokens' => 9]],
    ['id' => 'resp-final', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Done']]]], 'usage' => ['input_tokens' => 11, 'output_tokens' => 4, 'total_tokens' => 15]],
];
$accountFirst = $accountingFlow->run(['model' => 'test-model', 'input' => [['role' => 'user', 'content' => 'q']]], 'server-key', $accountingTicket, '', $beforeRequest, $onUsage);
expectFlow(count($usageEvents) === 1 && $usageEvents[0][1]['event_key'] === 'resp-tool' && $usageEvents[0][1]['total_tokens'] === 9, 'tool-call response usage is persisted even when it returns no user-visible text');
expectFlow($apiRequests[count($apiRequests)-1]['max_output_tokens'] === 16, 'budget preflight injects the minimum API-supported server-authorized output cap');
$accountingApproval = $broker->invoke([], $accountingTicket, 'account-agent', 2, 'account-session', 'metered-call', 'mcp_lookup', []);
$broker->invoke([], $accountingTicket, 'account-agent', 2, 'account-session', 'metered-call', 'mcp_lookup', [], true, $accountingApproval['approval_id']);
$accountFinal = $accountingFlow->run([], 'server-key', $accountingTicket, $accountFirst['continuation'], $beforeRequest, $onUsage);
expectFlow(count($usageEvents) === 2 && $usageEvents[1][1]['event_key'] === 'resp-final' && $accountFinal['usage']['total_tokens'] === 24, 'every continuation round records provider usage exactly once');
expectFlow($accountingFlow->run([], 'server-key', $accountingTicket, $accountFirst['continuation'], $beforeRequest, $onUsage) === $accountFinal && count($usageEvents) === 2, 'cached continuation replay does not duplicate usage events');

$missingGrant = $broker->issue([], 'missing-agent', 2, 'missing-session');
$apiQueue = [['id' => 'resp-without-usage', 'output' => [['type' => 'message', 'content' => []]]]];
$missingCalls = [];
$missingResult = (new AgentOS\Mcp\TextToolFlow($broker))->run(['model' => 'test', 'input' => []], 'server-key', $missingGrant['ticket'], '', $beforeRequest, function ($id, $event, $unknown) use (&$missingCalls) { $missingCalls[] = [$id, $event, $unknown]; return true; });
expectFlow(is_wp_error($missingResult) && $missingResult->get_error_code() === 'openai_usage_missing' && $missingCalls[0][2] === true, 'missing provider usage keeps the reservation unknown and pauses the flow');

$persistGrant = $broker->issue([], 'persist-agent', 2, 'persist-session');
$apiQueue = [['id' => 'resp-persist-fail', 'output' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]];
$persistResult = (new AgentOS\Mcp\TextToolFlow($broker))->run(['model' => 'test', 'input' => []], 'server-key', $persistGrant['ticket'], '', $beforeRequest, static function () { return false; });
expectFlow(is_wp_error($persistResult) && $persistResult->get_error_code() === 'usage_persist_failed', 'ledger persistence failure blocks downstream model and tool calls');

foreach ([500 => true, 400 => false] as $status => $unknown) {
    $httpGrant = $broker->issue([], 'http-agent-' . $status, 2, 'http-session-' . $status);
    $apiQueue = [['_http_status' => $status, 'error' => ['message' => 'provider error']]];
    $httpCalls = [];
    $httpResult = (new AgentOS\Mcp\TextToolFlow($broker))->run(['model' => 'test', 'input' => []], 'server-key', $httpGrant['ticket'], '', $beforeRequest, function ($id, $event, $isUnknown) use (&$httpCalls) { $httpCalls[] = [$id, $event, $isUnknown]; return true; });
    expectFlow(is_wp_error($httpResult) && $httpCalls[0][2] === $unknown, "HTTP $status reservation follows ambiguous-versus-definite failure policy");
}

$broker->revoke($ticket);
expectFlow(is_wp_error($broker->validate($ticket, 'agent', 2, 'session')), 'closing the conversation revokes its tool ticket');

$schemaGrant = $broker->issue(['approval' => 'auto', 'tool_name' => 'get_form_schema'], 'form-agent', 7, 'same-form-session');
$schemaRead = $broker->invoke(['approval' => 'auto', 'tool_name' => 'get_form_schema'], $schemaGrant['ticket'], 'form-agent', 7, 'same-form-session', 'schema-call', 'mcp_lookup', [], null, '');
expectFlow(empty($schemaRead['is_error']), 'form schema read stores only its safe field names for later diagnostics');
$prepareGrant = $broker->issue(['approval' => 'auto', 'tool_name' => 'prepare_form_submission'], 'form-agent', 7, 'same-form-session');
$prepared = $broker->invoke(['approval' => 'auto', 'tool_name' => 'prepare_form_submission'], $prepareGrant['ticket'], 'form-agent', 7, 'same-form-session', 'prepare-call', 'mcp_lookup', ['answers' => new stdClass(), 'schema_version' => 'fixture'], null, '');
$modelError = json_decode($prepared['output'], true);
$recentErrors = get_option('agentos_mcp_diagnostics', []);
expectFlow(!empty($prepared['is_error']) && str_contains($modelError['content'][0]['text'], 'phone (phone_invalid)'), 'validation errors pass known form field names across text-turn tickets and give the model a correction target');
expectFlow(!str_contains(json_encode($recentErrors), 'private@example.test') && !str_contains(json_encode($recentErrors), 'secret@example.test'), 'administrator diagnostics exclude remote error messages and unrecognized personal-looking field names');
$broker->revoke($prepareGrant['ticket']);
expectFlow(count(array_filter(array_keys($transients), static function ($key) { return strpos($key, 'agentos_mcp_form_schema_') === 0; })) === 0, 'closing a form conversation clears schema field metadata');
}
