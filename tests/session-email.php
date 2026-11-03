<?php

function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
require_once __DIR__ . '/../src/Core/SessionEmail.php';

use AgentOS\Core\SessionEmail;

$cases = [
    [true, 'account@example.com', 'guest@example.com', 'account@example.com'],
    [true, '', 'guest@example.com', ''],
    [false, '', ' guest+demo@example.com ', 'guest+demo@example.com'],
    [false, '', 'invalid-address', ''],
    [false, '', null, ''],
    [false, '', ['guest@example.com'], ''],
    [false, '', str_repeat('a', 185) . '@example.com', ''],
];
foreach ($cases as [$authenticated, $account, $guest, $expected]) {
    if (SessionEmail::resolve($authenticated, $account, $guest) !== $expected) {
        throw new RuntimeException('Session email resolution failed');
    }
}
echo "PASS: account email takes priority and guest emails must be valid\n";
