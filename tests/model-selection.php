<?php
/** Offline coverage for legacy Realtime defaults and explicit model overrides. */

require_once dirname(__DIR__) . '/src/Core/Config.php';
require_once dirname(__DIR__) . '/src/Core/AgentRepository.php';

use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;

$testOptions = [];
$testMeta = [];

function get_option($key, $default = false)
{
    global $testOptions;
    return $testOptions[$key] ?? $default;
}

function update_option($key, $value)
{
    $GLOBALS['testOptions'][$key] = $value;
    return true;
}

function esc_url_raw($value) { return (string) $value; }
function get_post_types($args = [], $output = 'names') { return ['tutor']; }

function wp_parse_args($values, $defaults)
{
    return array_merge($defaults, $values);
}

function get_post_type($postId)
{
    return 'tutor';
}

function get_post_meta($postId, $key, $single)
{
    global $testMeta;
    return $testMeta[$postId][$key] ?? '';
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function sanitize_textarea_field($value)
{
    return trim((string) $value);
}

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function expectSame($expected, $actual, $label)
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': ' . json_encode([$expected, $actual]));
    }
    echo "PASS: $label\n";
}

$latest = 'gpt-realtime-2.1-mini';
$repository = new AgentRepository();
expectSame($latest, $repository->blank()['realtime_model'], 'new agents use the current Mini');
expectSame($latest, Config::resolveRealtimeModel('gpt-realtime-mini-2025-10-06'), 'retired Mini snapshot upgrades');
expectSame($latest, Config::resolveRealtimeModel('gpt-realtime-mini'), 'original Mini alias upgrades');
expectSame('custom-realtime-model', Config::resolveRealtimeModel('custom-realtime-model'), 'explicit other models survive');

$testOptions[Config::OPTION_AGENTS] = [
    'legacy' => ['default_model' => 'gpt-realtime-mini-2025-10-06'],
    'custom' => ['default_model' => 'custom-realtime-model'],
    'separate' => ['default_model' => 'gpt-realtime-mini', 'realtime_model' => 'custom-realtime-model'],
];
$before = $testOptions;
$agents = $repository->all();
expectSame($latest, $agents['legacy']['realtime_model'], 'stored legacy agent resolves to current Mini');
expectSame('custom-realtime-model', $agents['custom']['realtime_model'], 'legacy field keeps custom choice');
expectSame('custom-realtime-model', $agents['separate']['realtime_model'], 'explicit realtime selection wins');
expectSame($before, $testOptions, 'reading an agent does not rewrite stored options');

$agent = $repository->blank();
$agent['field_maps'] = ['tutor' => ['model' => 'voice_model']];
$testMeta[88]['voice_model'] = 'gpt-realtime-mini';
expectSame($latest, $repository->collectPostConfig($agent, 88)['model'], 'mapped original Mini upgrades');
$testMeta[88]['voice_model'] = 'custom-post-model';
$resolved = $repository->collectPostConfig($agent, 88);
expectSame('custom-post-model', $resolved['model'], 'custom per-post model survives');
expectSame(Config::FALLBACK_TEXT_MODEL, $resolved['text_model'], 'text model remains independent');

$demoTranscription = Config::realtimeTranscriptionPayload('pt');
expectSame([
    'model' => 'gpt-live-transcribe',
    'languages' => ['pt'],
], $demoTranscription, 'Realtime demo language uses the live transcription schema');

$tutorHint = 'Names from the course glossary';
$tutorTranscription = Config::realtimeTranscriptionPayload('', $tutorHint);
expectSame([
    'model' => 'gpt-live-transcribe',
    'prompt' => $tutorHint,
], $tutorTranscription, 'Realtime tutor keeps its transcription hint and leaves language automatic');
expectSame(false, array_key_exists('language', $tutorTranscription), 'Realtime transcription does not use the legacy language field');
expectSame(false, array_key_exists('languages', $tutorTranscription), 'automatic language omits the languages constraint');
expectSame('gpt-4o-mini-transcribe', Config::FALLBACK_TRANSCRIBE_MODEL, 'audio file uploads retain their transcription model');

expectSame(false, array_key_exists('delay', Config::realtimeTranscriptionPayload()), 'existing agents leave transcription delay at the model default');
foreach (['minimal', 'low', 'medium', 'high', 'xhigh', '1000'] as $delay) {
    $input = array_merge($repository->blank(), ['slug' => 'delay-test', 'transcription_delay' => $delay]);
    $repository->upsert($input, 'delay-test');
    $saved = $repository->get('delay-test');
    $config = $repository->collectPostConfig($saved, 88);
    $payload = Config::realtimeTranscriptionPayload('pt', 'Course glossary', $config['transcription_delay']);
    expectSame($delay === '1000' ? null : $delay, $payload['delay'] ?? null, 'saved delay reaches the API only when supported: ' . $delay);
    expectSame(['pt'], $payload['languages'], 'delay preserves the language hint: ' . $delay);
}
