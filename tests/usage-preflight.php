<?php
/** Exact-input counting regression against the Responses input_tokens endpoint. */
require_once dirname(__DIR__) . '/src/Core/Config.php';
require_once dirname(__DIR__) . '/src/Rest/RestController.php';

class WP_Error
{
    private string $code;
    private string $message;
    private $data;
    public function __construct($code, $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
$preflightResponse = ['code' => 200, 'body' => json_encode(['object' => 'response.input_tokens', 'input_tokens' => 16])];
$preflightRequests = [];
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($value, $domain = '') { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function wp_remote_post($url, $args) { $GLOBALS['preflightRequests'][] = [$url, $args]; return $GLOBALS['preflightResponse']; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function preflightExpect($condition, $label) { if (!$condition) throw new RuntimeException($label); echo "PASS: $label\n"; }
preflightExpect(AgentOS\Core\Config::RESPONSES_MIN_OUTPUT_TOKENS === 16, 'limited Responses API calls preserve the documented minimum output cap');

$reflection = new ReflectionClass(AgentOS\Rest\RestController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('countResponsesInputTokens');
$method->setAccessible(true);
$payload = [
    'model' => 'gpt-4.1-mini',
    'instructions' => 'Use the active tool schemas.',
    'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hello']]]],
    'tools' => [['type' => 'function', 'name' => 'lookup']],
    'tool_choice' => 'auto',
    'max_output_tokens' => 128,
];
$count = $method->invoke($controller, $payload, 'server-key');
preflightExpect($count === 16, 'preflight reads the provider-reported exact input token count');
preflightExpect($preflightRequests[0][0] === 'https://api.openai.com/v1/responses/input_tokens', 'preflight calls the dedicated Responses input-token endpoint');
$countPayload = json_decode($preflightRequests[0][1]['body'], true);
preflightExpect(isset($countPayload['tools'], $countPayload['instructions'], $countPayload['input']) && !isset($countPayload['max_output_tokens']), 'preflight counts the full model request and omits unsupported output fields');
preflightExpect($preflightRequests[0][1]['headers']['Authorization'] === 'Bearer server-key', 'preflight keeps provider credentials on the server');

$preflightResponse = new WP_Error('network', 'offline');
$failed = $method->invoke($controller, $payload, 'server-key');
preflightExpect(is_wp_error($failed) && $failed->get_error_code() === 'usage_preflight_failed', 'preflight failure blocks limited requests closed');
$preflightResponse = ['code' => 400, 'body' => json_encode(['error' => 'bad payload'])];
$failedHttp = $method->invoke($controller, $payload, 'server-key');
preflightExpect(is_wp_error($failedHttp) && $failedHttp->get_error_code() === 'usage_preflight_failed', 'provider HTTP errors do not fall back to character estimates');
