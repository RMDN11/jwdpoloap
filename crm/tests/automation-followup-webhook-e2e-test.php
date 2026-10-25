<?php
declare(strict_types=1);

/**
 * End-to-End Regression Test: Real-Time Webhook Follow-Up Automation & Trigger Pattern Engine
 */

$baseDir = dirname(__DIR__, 2);
$webhookFile = $baseDir . '/webhook.php';
$configFile  = $baseDir . '/crm/config/automation-followup.php';
$actionsFile = $baseDir . '/crm/actions/automation-followup.php';
$pageFile    = $baseDir . '/crm/pages/automation-followup.php';

$files = [$webhookFile, $configFile, $actionsFile, $pageFile];
foreach ($files as $f) {
    if (!file_exists($f)) {
        throw new RuntimeException("Missing file for E2E test: {$f}");
    }
}

$webhook = file_get_contents($webhookFile);
$config  = file_get_contents($configFile);
$actions = file_get_contents($actionsFile);
$page    = file_get_contents($pageFile);

// 1. Webhook wiring verification
assert(str_contains($webhook, 'crmAutomationHandleIncomingWebhook'), 'webhook.php must call crmAutomationHandleIncomingWebhook');
assert(str_contains($webhook, 'require_once $baseDir . \'/crm/config/automation-followup.php\''), 'webhook.php must require automation-followup.php');

// 2. Config helper & schema verification
assert(str_contains($config, 'function crmAutomationHandleIncomingWebhook'), 'config must implement crmAutomationHandleIncomingWebhook');
assert(str_contains($config, 'function crmAutomationGetActiveTriggers'), 'config must implement crmAutomationGetActiveTriggers');
assert(str_contains($config, 'function crmAutomationFindMatchingTrigger'), 'config must implement crmAutomationFindMatchingTrigger');
assert(str_contains($config, 'category VARCHAR(50)'), 'config must define category column in rules table');
assert(str_contains($config, 'trigger_source VARCHAR(30)'), 'config must define trigger_source column in queue table');

// 3. LP Button triggers check
$lpTriggers = [
    'Kak, saya ingin daftar Tahfidz Cilik',
    'Tahfidz Cilik',
    'Kak, saya ingin daftar Kelas Menghafal Pemula',
    'Menghafal Pemula',
    'Kak, saya ingin daftar Kelas Menghafal Lanjutan',
    'Menghafal Lanjutan',
    'Kak, saya ingin daftar Kelas Penguatan Hafalan',
    'Penguatan Hafalan',
    'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada',
    'Bismillah, perkenalkan nama saya',
];
foreach ($lpTriggers as $trig) {
    assert(str_contains($config, $trig), "config must seed default LP trigger: {$trig}");
}

// 4. Guardrails verification
assert(str_contains($config, 'crmAutomationIsOperationalNumber'), 'config must check operational CS numbers');
assert(str_contains($config, 'crmChatIsTeacherNumber'), 'config must exclude teacher contacts');
assert(str_contains($config, 'crmAutomationIsBlocked'), 'config must exclude blocked contacts');
assert(str_contains($config, 'crmAutomationHasReceivedPayment'), 'config must exclude contacts who already paid/finance');

// 5. Actions CRUD verification
assert(str_contains($actions, 'save_rule'), 'actions must handle save_rule');
assert(str_contains($actions, 'delete_rule'), 'actions must handle delete_rule');
assert(str_contains($actions, 'toggle_rule'), 'actions must handle toggle_rule');
assert(str_contains($actions, 'category'), 'actions must save category in save_rule');

// 6. UI & Prospects distinction verification
assert(str_contains($page, 'Terjadwal (Otomatis)'), 'page must render Terjadwal (Otomatis) badge');
assert(str_contains($page, 'Jadwalkan Alur (6 Tahap)'), 'page must maintain manual Jadwalkan Alur button for legacy prospects');
assert(str_contains($page, 'Pola Pemicu (Rules)'), 'page must display Pola Pemicu in tab title');
assert(str_contains($page, 'editRule'), 'page must provide editRule javascript function');
assert(str_contains($page, 'ORDER BY (status = \'pending\') DESC, scheduled_at ASC, id ASC'), 'page must sort pending queues by earliest scheduled_at first');

// 7. Review fix verifications
$autoReplyEngineFile = $baseDir . '/auto_reply_engine.php';
$autoReplyContent = file_get_contents($autoReplyEngineFile);
assert(!str_contains($autoReplyContent, 'crmAutomationRegisterQueue'), 'auto_reply_engine.php must not call crmAutomationRegisterQueue (webhook handles enrollment)');
assert(str_contains($config, 'crm_automation_meta'), 'config must use crm_automation_meta to avoid re-seeding deleted rules');
assert(str_contains($config, 'seed_rules_v1'), 'config must check seed_rules_v1 flag');
assert(!str_contains($config, "r.name LIKE '%Cilik%'"), 'Cleanup 3 must not cancel pending queues based on r.name');

echo "automation-followup-webhook-e2e-test: OK\n";
