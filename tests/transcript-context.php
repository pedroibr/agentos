<?php
/** Regression: display/archive text and resumable model context are distinct. */
require_once dirname(__DIR__) . '/src/Core/Config.php';
require_once dirname(__DIR__) . '/src/Rest/RestController.php';
require_once dirname(__DIR__) . '/src/Analysis/Analyzer.php';

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function wp_strip_all_tags($value)
{
    return strip_tags((string) $value);
}

function __($value, $domain = '')
{
    return $value;
}

function checkSame($expected, $actual, $label)
{
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . json_encode([$expected, $actual]));
    }
    echo "PASS: $label\n";
}

$transcript = [
    ['role' => 'user', 'text' => 'Tell me a story.'],
    ['role' => 'assistant', 'text' => 'Once upon a time, a robot found a library.', 'interrupted' => true, 'resumeEligible' => false],
    ['role' => 'user', 'text' => 'Stop.'],
    ['role' => 'assistant', 'text' => 'Okay, I will stop.', 'resumeEligible' => true],
    ['role' => 'user', 'text' => 'unfinished speech', 'partial' => true, 'resumeEligible' => false],
];
$restClass = new ReflectionClass(AgentOS\Rest\RestController::class);
$rest = $restClass->newInstanceWithoutConstructor();
$inputMethod = $restClass->getMethod('buildResponsesInput');
$inputMethod->setAccessible(true);
$input = $inputMethod->invoke($rest, $transcript, 'Speak naturally.');
checkSame(['User Prompt:' . "\n" . 'Speak naturally.', 'Tell me a story.', 'Stop.', 'Okay, I will stop.'], array_map(static function ($item) { return $item['content'][0]['text']; }, $input), 'model context excludes interrupted and incomplete items');
checkSame('Once upon a time, a robot found a library.', $transcript[1]['text'], 'context projection preserves archived story text');
checkSame(5, count($transcript), 'context projection does not delete history rows');

$analyzerClass = new ReflectionClass(AgentOS\Analysis\Analyzer::class);
$analyzer = $analyzerClass->newInstanceWithoutConstructor();
$promptMethod = $analyzerClass->getMethod('buildUserPrompt');
$promptMethod->setAccessible(true);
$prompt = $promptMethod->invoke($analyzer, ['transcript' => $transcript], []);
checkSame(true, strpos($prompt, 'Tutor (interrupted): Once upon a time, a robot found a library.') !== false, 'analysis retains story text with an interruption marker');
checkSame(true, strpos($prompt, 'Learner (incomplete): unfinished speech') !== false, 'analysis distinguishes incomplete user speech');
