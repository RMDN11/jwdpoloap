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

// 1. Must NOT seed legacy 'Tahfidz Private - Follow Up' rules
if (str_contains($content, "\$ruleName = 'Tahfidz Private - ' . \$tpl['name']")) {
    throw new RuntimeException("Config still seeds legacy Tahfidz Private - Follow Up rules!");
}

// 2. Must contain cleanup migration for legacy rules
if (!str_contains($content, "DELETE FROM crm_automation_rules WHERE name LIKE 'Tahfidz Private - Follow Up %'")) {
    throw new RuntimeException("Config missing DELETE query for legacy Tahfidz Private - Follow Up rules!");
}

// 3. Must record migration flag in crm_automation_meta
if (!str_contains($content, 'legacy_fu_rules_cleaned_v1')) {
    throw new RuntimeException("Config missing legacy_fu_rules_cleaned_v1 migration flag!");
}

echo "automation-followup-cleanup-test: OK\n";
