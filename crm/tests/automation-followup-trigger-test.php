<?php
declare(strict_types=1);

$enginePath = __DIR__ . '/../../auto_reply_engine.php';
if (!file_exists($enginePath)) {
    throw new RuntimeException("Missing auto_reply_engine.php at: {$enginePath}");
}

$content = file_get_contents($enginePath);
if ($content === false) {
    throw new RuntimeException("Cannot read {$enginePath}");
}

$requiredTokens = [
    'crmAutomationRegisterQueue',
    'crm_automation_rules',
    'trigger_keyword',
];

foreach ($requiredTokens as $token) {
    if (!str_contains($content, $token)) {
        throw new RuntimeException("Missing auto_reply trigger integration token: {$token}");
    }
}

echo "automation-followup-trigger-test: OK\n";
