<?php
declare(strict_types=1);

$actionsPath = __DIR__ . '/../actions/automation-followup.php';
if (!file_exists($actionsPath)) {
    throw new RuntimeException("Missing actions file: {$actionsPath}");
}

$content = file_get_contents($actionsPath);
if ($content === false) {
    throw new RuntimeException("Cannot read {$actionsPath}");
}

$requiredTokens = [
    'crmVerifyCsrf',
    'save_rule',
    'toggle_rule',
    'save_template',
    'delete_template',
    'cancel_queue',
    'send_now',
    'crm_flash',
    'Location: index.php?page=automation-followup',
];

foreach ($requiredTokens as $token) {
    if (!str_contains($content, $token)) {
        throw new RuntimeException("Missing required action token: {$token}");
    }
}

echo "automation-followup-actions-test: OK\n";
