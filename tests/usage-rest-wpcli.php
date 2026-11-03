<?php
/**
 * Optional integrated REST/text usage smoke test; uses mocked OpenAI HTTP responses.
 * Run with a WP-CLI bootstrap that autoloads this plugin's staged src/ classes.
 * Creates one synthetic user and post, then cleans them and their ledger rows in finally.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must run through WP-CLI.\n");
    exit(1);
}

use AgentOS\Analysis\Analyzer;
use AgentOS\Core\AgentRepository;
use AgentOS\Core\Settings;
use AgentOS\Database\TranscriptRepository;
use AgentOS\Database\UsageRepository;
use AgentOS\Rest\RestController;
use AgentOS\Subscriptions\SubscriptionRepository;
use AgentOS\Subscriptions\UsageLimiter;
use AgentOS\Subscriptions\UserSubscriptionRepository;

class AgentOSUsageRestSmokeSettings extends Settings
{
    public function resolveApiKey(): string
    {
        return 'synthetic-rest-smoke-key';
    }
}

class AgentOSUsageRestSmokeAgents extends AgentRepository
{
    public string $agentSlug;

    public function __construct(string $agentSlug)
    {
        $this->agentSlug = $agentSlug;
    }

    public function get(string $slug): ?array
    {
        if ($slug !== $this->agentSlug) {
            return null;
        }
        return array_merge($this->blank(), [
            'slug' => $this->agentSlug,
            'label' => 'Synthetic usage smoke agent',
            'post_types' => ['post'],
            'text_model' => 'gpt-4.1-mini',
            'require_subscription' => true,
            'session_token_cap' => 0,
        ]);
    }

    public function collectPostConfig(array $agent, int $postId): array
    {
        return [
            'text_model' => 'gpt-4.1-mini',
            'instructions' => 'Answer briefly.',
            'user_prompt' => '',
        ];
    }
}

class AgentOSUsageRestSmokeSubscriptions extends SubscriptionRepository
{
    public string $slug;
    public string $agentSlug;

    public function __construct(string $slug, string $agentSlug)
    {
        $this->slug = $slug;
        $this->agentSlug = $agentSlug;
    }

    public function all(): array
    {
        return [$this->slug => [
            'slug' => $this->slug,
            'label' => 'Synthetic REST usage smoke plan',
            'allowed_agents' => [$this->agentSlug],
            'period_hours' => 24,
            'limits' => ['realtime_tokens' => 0, 'text_tokens' => 0, 'sessions' => 1],
            'session_token_cap' => 100,
            'block_on_overage' => true,
        ]];
    }
}

class AgentOSUsageRestSmokeAssignments extends UserSubscriptionRepository
{
    public string $userKey;
    public string $planSlug;

    public function __construct(string $userKey, string $planSlug)
    {
        $this->userKey = $userKey;
        $this->planSlug = $planSlug;
    }

    public function get(string $userKey, bool $includeExpired = false): array
    {
        return $userKey === $this->userKey
            ? [['subscription_slug' => $this->planSlug, 'expires_at' => '', 'overrides' => []]]
            : [];
    }

    public function ensureUser(string $userKey, array $meta = []): void
    {
        // Avoid writing synthetic user metadata into shared site options.
    }
}

$tag = strtolower(wp_generate_password(12, false, false));
$agentSlug = 'usage-rest-' . substr($tag, 0, 10);
$planSlug = 'usage-rest-' . substr($tag, 0, 10);
$email = 'agentos-usage-rest-' . $tag . '@example.invalid';
$previousUserId = get_current_user_id();
$userId = 0;
$postId = 0;
$userKey = '';
$openAiTickets = [];
$countRequests = [];
$responseRequests = [];
$error = null;
$usage = new UsageRepository();
$broker = null;
$filter = null;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$requestFor = static function (array $params): WP_REST_Request {
    $request = new WP_REST_Request('POST', '/agentos/v1/text-response');
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }
    return $request;
};

try {
    $userId = wp_insert_user([
        'user_login' => 'agentos-usage-rest-' . $tag,
        'user_pass' => wp_generate_password(40, true, true),
        'user_email' => $email,
        'role' => 'subscriber',
    ]);
    if (is_wp_error($userId)) {
        throw new RuntimeException('Could not create the synthetic WordPress user.');
    }
    wp_set_current_user((int) $userId);
    $userKey = 'user_' . (int) $userId;

    $postId = wp_insert_post([
        'post_title' => 'AgentOS synthetic usage REST smoke ' . $tag,
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_author' => (int) $userId,
    ], true);
    if (is_wp_error($postId)) {
        throw new RuntimeException('Could not create the synthetic WordPress post.');
    }

    $settings = new AgentOSUsageRestSmokeSettings();
    $agents = new AgentOSUsageRestSmokeAgents($agentSlug);
    $subscriptions = new AgentOSUsageRestSmokeSubscriptions($planSlug, $agentSlug);
    $assignments = new AgentOSUsageRestSmokeAssignments($userKey, $planSlug);
    $limiter = new UsageLimiter($subscriptions, $assignments, $usage);
    $transcripts = new TranscriptRepository();
    $analyzer = (new ReflectionClass(Analyzer::class))->newInstanceWithoutConstructor();
    $controller = new RestController($settings, $agents, $transcripts, $analyzer, $limiter, $assignments, $subscriptions, $usage);
    $broker = new AgentOS\Mcp\SessionBroker();

    $countInvocation = 0;
    $responseInvocation = 0;
    $filter = static function ($preempt, array $args, string $url) use (&$countRequests, &$responseRequests, &$countInvocation, &$responseInvocation) {
        if ($url === 'https://api.openai.com/v1/responses/input_tokens') {
            $countInvocation++;
            $payload = json_decode((string) ($args['body'] ?? ''), true);
            $countRequests[] = $payload;
            $inputTokens = $countInvocation <= 2 ? 16 : 45;
            return [
                'headers' => [],
                'body' => wp_json_encode(['object' => 'response.input_tokens', 'input_tokens' => $inputTokens]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }
        if ($url === 'https://api.openai.com/v1/responses') {
            $responseInvocation++;
            $payload = json_decode((string) ($args['body'] ?? ''), true);
            $responseRequests[] = $payload;
            return [
                'headers' => [],
                'body' => wp_json_encode([
                    'id' => 'synthetic-responses-' . $responseInvocation,
                    'status' => 'completed',
                    'output' => [[
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => 'Synthetic response.']],
                    ]],
                    'usage' => [
                        'input_tokens' => 16,
                        'output_tokens' => 4,
                        'total_tokens' => 20,
                        'input_tokens_details' => ['cached_tokens' => 3],
                    ],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }
        return $preempt;
    };
    add_filter('pre_http_request', $filter, 10, 3);

    $base = [
        'post_id' => (int) $postId,
        'agent_id' => $agentSlug,
        'anon_id' => 'synthetic-anon-' . $tag,
        'message' => 'Count and respond.',
        'transcript' => [['role' => 'user', 'text' => 'Count and respond.']],
        // These browser values are deliberately false and must never enter authoritative totals.
        'tokens_realtime' => 900000,
        'tokens_text' => 900000,
        'tokens_total' => 1800000,
        'subscription_slug' => 'forged-subscription',
    ];

    $first = $controller->routeTextResponse($requestFor($base));
    $expect(is_array($first) && !empty($first['session_id']) && !empty($first['session_ticket']), 'first mocked text response returns a server-owned ticket');
    $openAiTickets[] = (string) ($first['tool_ticket'] ?? '');
    $expect(count($countRequests) === 1 && !array_key_exists('max_output_tokens', $countRequests[0]), 'preflight sends the complete request and omits output-only fields');
    $expect(($responseRequests[0]['max_output_tokens'] ?? 0) === 84, 'server reserves exact input tokens and injects the remaining server-authorized output cap');
    $expect($first['subscription'] === $planSlug, 'response uses the server-selected subscription rather than the browser value');

    $secondPayload = $base;
    $secondPayload['session_id'] = $first['session_id'];
    $secondPayload['session_ticket'] = $first['session_ticket'];
    $second = $controller->routeTextResponse($requestFor($secondPayload));
    $expect(is_array($second) && $second['session_id'] === $first['session_id'], 'max_sessions=1 permits a continuation on the owned current session');
    $openAiTickets[] = (string) ($second['tool_ticket'] ?? '');
    $expect(($responseRequests[1]['max_output_tokens'] ?? 0) === 64, 'continuation reserves against actual earlier provider usage');

    $wrongTicket = $secondPayload;
    $wrongTicket['session_ticket'] = 'not-the-server-ticket';
    $beforeWrongTicket = count($countRequests);
    $forbidden = $controller->routeTextResponse($requestFor($wrongTicket));
    $expect(is_wp_error($forbidden) && $forbidden->get_error_code() === 'usage_session_forbidden', 'a wrong ticket cannot use a session or trigger preflight');
    $expect(count($countRequests) === $beforeWrongTicket, 'ticket rejection happens before OpenAI requests');

    $tooSmall = $secondPayload;
    $smallBudget = $controller->routeTextResponse($requestFor($tooSmall));
    $expect(is_wp_error($smallBudget) && $smallBudget->get_error_code() === 'subscription_limit', 'remaining output budget below the Responses API minimum fails closed');
    $expect(count($responseRequests) === 2, 'a request with less than 16 output tokens is never sent to Responses API');

    $newSession = $base;
    $newSession['session_id'] = '';
    $newSession['session_ticket'] = '';
    $sessionLimit = $controller->routeTextResponse($requestFor($newSession));
    $expect(is_wp_error($sessionLimit) && $sessionLimit->get_error_code() === 'subscription_limit', 'max_sessions=1 blocks another session after the owned session is used');
    $expect(count($responseRequests) === 2, 'session cap rejection happens before generation');

    $sessionUsage = $usage->sessionUsage((string) $first['session_id']);
    $expect((int) $sessionUsage['tokens_total'] === 40 && (int) $sessionUsage['tokens_text'] === 40, 'ledger totals come from the two provider responses and ignore forged browser totals');
    $expect((int) $sessionUsage['browser_reported_tokens'] === 0, 'Responses API usage is recorded with server provenance');
    $row = $usage->getSession((string) $first['session_id']);
    $expect($row && ($row['subscription_expires_at'] === null || $row['subscription_expires_at'] === ''), 'empty subscription expiry is safe under strict SQL mode');
} catch (Throwable $exception) {
    $error = $exception;
} finally {
    if ($filter !== null) {
        remove_filter('pre_http_request', $filter, 10);
    }
    if ($broker instanceof AgentOS\Mcp\SessionBroker) {
        foreach ($openAiTickets as $ticket) {
            if ($ticket !== '') {
                $broker->revoke($ticket);
            }
        }
    }
    if ($userKey !== '') {
        $usage->deleteForUser($userKey);
    }
    wp_set_current_user($previousUserId);
    if ($postId && !is_wp_error($postId)) {
        wp_delete_post((int) $postId, true);
    }
    if ($userId && !is_wp_error($userId)) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $userId);
    }
}

if ($error !== null) {
    WP_CLI::error($error->getMessage());
}
WP_CLI::success('REST/text usage smoke passed; only mocked OpenAI count/response calls were intercepted, and synthetic rows were cleaned up.');
