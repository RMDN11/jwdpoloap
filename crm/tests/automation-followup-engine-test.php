<?php
declare(strict_types=1);

$enginePath = __DIR__ . '/../lib/automation-engine.php';
if (!file_exists($enginePath)) {
    throw new RuntimeException("Missing engine file: {$enginePath}");
}

$content = file_get_contents($enginePath);
if ($content === false) {
    throw new RuntimeException("Cannot read {$enginePath}");
}

$requiredFunctions = [
    'crmAutomationRenderMessage',
    'crmAutomationProcessPendingQueue',
    'crmAutomationDispatchSingle',
];

foreach ($requiredFunctions as $func) {
    if (!str_contains($content, "function {$func}")) {
        throw new RuntimeException("Missing required function: {$func}");
    }
}

foreach ([
    'cancelled_replied',
    'cancelled_blocked',
    'curl_init',
    'curl_exec',
    'crmAutomationHasUserReplied',
    'crmAutomationIsBlocked',
    'scheduled_at <= NOW()',
] as $token) {
    if (!str_contains($content, $token)) {
        throw new RuntimeException("Missing engine token: {$token}");
    }
}

echo "automation-followup-engine-test: OK\n";
