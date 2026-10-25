<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__, 2);
$configPath = $baseDir . '/crm/config/admin-bot.php';
$libPath = $baseDir . '/crm/lib/admin-bot.php';
$apiPath = $baseDir . '/api_antigravity.php';

$files = [$configPath, $libPath, $apiPath];
foreach ($files as $f) {
    if (!file_exists($f)) {
        throw new RuntimeException("Missing required file: {$f}");
    }
}

$configContent = file_get_contents($configPath);
$libContent = file_get_contents($libPath);
$apiContent = file_get_contents($apiPath);

// 1. Config tokens
$requiredConfigTokens = [
    'ANTIGRAVITY_BRIDGE_TOKEN',
    'crm_antigravity_tasks',
    'status ENUM(\'pending\', \'processing\', \'completed\', \'failed\')'
];
foreach ($requiredConfigTokens as $token) {
    if (!str_contains($configContent, $token)) {
        throw new RuntimeException("admin-bot.php missing token: {$token}");
    }
}

// 2. Lib router tokens
$requiredLibTokens = [
    '!agy',
    'crm_antigravity_tasks',
    'Antigravity AI Agent',
    'Task #'
];
foreach ($requiredLibTokens as $token) {
    if (!str_contains($libContent, $token)) {
        throw new RuntimeException("admin-bot.php lib missing token: {$token}");
    }
}

// 3. API relay tokens
$requiredApiTokens = [
    'ANTIGRAVITY_BRIDGE_TOKEN',
    'crmAdminBotEnsureTables',
    'hash_equals',
    'action === \'poll\'',
    'action === \'complete\'',
    'action === \'status\'',
    'crm_antigravity_tasks'
];
foreach ($requiredApiTokens as $token) {
    if (!str_contains($apiContent, $token)) {
        throw new RuntimeException("api_antigravity.php missing token: {$token}");
    }
}

echo "antigravity-bridge-test: OK\n";
