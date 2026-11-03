<?php

require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Settings.php';

$options = [];
$isAdmin = false;
function get_option($key, $default = []) { global $options; return $options; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function current_user_can($capability) { global $isAdmin; return $capability === 'manage_options' && $isAdmin; }
function sanitize_text_field($value) { return (string) $value; }

$settings = new \AgentOS\Core\Settings();
foreach ([false, true] as $enabled) {
    foreach ([false, true] as $admin) {
        $options = ['show_token_usage' => $enabled];
        $isAdmin = $admin;
        if ($settings->canShowTokenUsage() !== ($enabled && $admin)) {
            throw new RuntimeException('Token visibility failed for setting=' . (int) $enabled . ', admin=' . (int) $admin);
        }
    }
}
$options = [];
$isAdmin = true;
if ($settings->canShowTokenUsage()) {
    throw new RuntimeException('Token visibility must default to off');
}
if ($settings->sanitize(['show_token_usage' => '1'])['show_token_usage'] !== 1 || $settings->sanitize([])['show_token_usage'] !== 0) {
    throw new RuntimeException('Token visibility setting did not save correctly');
}
echo "PASS: token visibility requires the enabled setting and administrator capability\n";
