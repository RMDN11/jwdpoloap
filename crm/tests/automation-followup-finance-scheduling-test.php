<?php
declare(strict_types=1);

/**
 * Test: Status Finance Toggle and Scheduling Engine Verification
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/crm/config/automation-followup.php';
require_once $baseDir . '/crm/lib/automation-engine.php';

// 1. Static code assertions on SQL query syntax in automation-followup.php
$cfgPath = $baseDir . '/crm/config/automation-followup.php';
$cfg = file_get_contents($cfgPath);

// Must not have invalid SQL dot concatenation inside prepared SQL strings
assert(!str_contains($cfg, "' . \$num . '"), "SQL query string must not contain raw PHP concatenation dot inside double-quoted string");
assert(!str_contains($cfg, "t.name LIKE 'Follow Up ' ."), "SQL query string has broken LIKE clause with raw dot concatenation");

// 2. Finance override helper and table verification
assert(function_exists('crmAutomationSetFinanceStatus'), "Function crmAutomationSetFinanceStatus must exist");
assert(str_contains($cfg, "crm_automation_finance"), "Config must create and manage crm_automation_finance table");

// 3. Actions file verification
$actionsPath = $baseDir . '/crm/actions/automation-followup.php';
$actions = file_get_contents($actionsPath);
assert(str_contains($actions, "toggle_finance_status") || str_contains($actions, "set_finance_status"), "Actions must handle toggle_finance_status");
assert(str_contains($actions, "crmAutomationSetFinanceStatus"), "Actions must call crmAutomationSetFinanceStatus");

// 4. Page file verification
$pagePath = $baseDir . '/crm/pages/automation-followup.php';
$page = file_get_contents($pagePath);
assert(str_contains($page, "toggle_finance_status"), "Prospects table must render toggle_finance_status form");
assert(str_contains($page, "Ubah Jadi Sudah") || str_contains($page, "Tandai Sudah"), "Prospects table must provide button to change finance status to Sudah");

echo "automation-followup-finance-scheduling-test: OK\n";
