<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/automation-followup.php';
$webhookPath = __DIR__ . '/../../webhook.php';

if (!file_exists($configPath) || !file_exists($webhookPath)) {
    throw new RuntimeException("Missing config or webhook file.");
}

$configContent = file_get_contents($configPath);
$webhookContent = file_get_contents($webhookPath);

if ($configContent === false || $webhookContent === false) {
    throw new RuntimeException("Cannot read config or webhook files.");
}

// 1. Verifikasi webhook.php terhubung dengan crmAutomationHandleIncomingWebhook
if (!str_contains($webhookContent, 'crmAutomationHandleIncomingWebhook')) {
    throw new RuntimeException("webhook.php missing crmAutomationHandleIncomingWebhook call.");
}

// 2. Verifikasi crm/config/automation-followup.php memiliki deklarasi fungsi dan pengaman
$requiredConfigTokens = [
    'function crmAutomationHandleIncomingWebhook',
    'crmAutomationIsOperationalNumber',
    'crmAutomationIsBlocked',
    'crmAutomationHasReceivedPayment',
    'crmAutomationFindMatchingTrigger',
    'crmAutomationScheduleSequence',
    'auto_webhook',
];

foreach ($requiredConfigTokens as $token) {
    if (!str_contains($configContent, $token)) {
        throw new RuntimeException("automation-followup.php missing required webhook trigger token: {$token}");
    }
}

echo "automation-followup-trigger-test: OK\n";
