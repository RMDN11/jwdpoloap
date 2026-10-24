<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/automation-followup.php';
if (!file_exists($configPath)) {
    throw new RuntimeException("Missing config file: {$configPath}");
}

$content = file_get_contents($configPath);
if ($content === false) {
    throw new RuntimeException("Cannot read {$configPath}");
}

$requiredFunctions = [
    'crmAutomationEnsureTables',
    'crmAutomationRegisterQueue',
    'crmAutomationHasUserReplied',
    'crmAutomationIsBlocked',
];

foreach ($requiredFunctions as $func) {
    if (!str_contains($content, "function {$func}")) {
        throw new RuntimeException("Missing required function: {$func}");
    }
}

$requiredTables = [
    'crm_automation_rules',
    'crm_automation_templates',
    'crm_automation_queue',
];

foreach ($requiredTables as $table) {
    if (!str_contains($content, "CREATE TABLE IF NOT EXISTS {$table}")) {
        throw new RuntimeException("Missing table declaration: {$table}");
    }
}

foreach ([
    'idx_auto_queue_status_sched',
    'cancelled_replied',
    'crmProspectNormalizeNumber',
    'bind_param',
] as $token) {
    if (!str_contains($content, $token)) {
        throw new RuntimeException("Missing expected schema/logic token: {$token}");
    }
}

echo "automation-followup-db-test: OK\n";
