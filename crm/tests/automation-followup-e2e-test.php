<?php
declare(strict_types=1);

$cronPath = __DIR__ . '/../actions/automation-followup-cron.php';
if (!file_exists($cronPath)) {
    throw new RuntimeException("Missing cron runner: {$cronPath}");
}

$content = file_get_contents($cronPath);
if ($content === false) {
    throw new RuntimeException("Cannot read {$cronPath}");
}

$requiredTokens = [
    'crmAutomationProcessPendingQueue',
    '[CRM_AUTOMATION]',
    'php_sapi_name',
    'cron_token',
];

foreach ($requiredTokens as $token) {
    if (!str_contains($content, $token)) {
        throw new RuntimeException("Missing cron runner token: {$token}");
    }
}

echo "automation-followup-e2e-test: OK\n";
