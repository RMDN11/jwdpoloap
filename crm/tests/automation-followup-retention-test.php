<?php
declare(strict_types=1);

$actionsPath = __DIR__ . '/../actions/automation-followup.php';
$pagesPath = __DIR__ . '/../pages/automation-followup.php';
$cssPath = __DIR__ . '/../assets/css/automation-followup.css';

if (!file_exists($actionsPath)) {
    throw new RuntimeException("Missing actions file: {$actionsPath}");
}
if (!file_exists($pagesPath)) {
    throw new RuntimeException("Missing pages file: {$pagesPath}");
}
if (!file_exists($cssPath)) {
    throw new RuntimeException("Missing CSS file: {$cssPath}");
}

$actionsContent = file_get_contents($actionsPath);
$pagesContent = file_get_contents($pagesPath);
$cssContent = file_get_contents($cssPath);

// 1. Verify crmAutoRedirect signature in actions
if (!preg_match('/function\s+crmAutoRedirect\s*\(\s*string\s+\$tab\s*=\s*[\'"](?:rules|prospects)[\'"],\s*\?string\s+\$returnUrl\s*=\s*null,\s*\?string\s+\$anchor\s*=\s*null\s*\)/', $actionsContent)) {
    throw new RuntimeException("crmAutoRedirect must accept optional \$returnUrl and \$anchor parameters");
}

// 2. Verify return_url handling in crmAutoRedirect
if (!str_contains($actionsContent, 'return_url') || !str_contains($actionsContent, '$returnUrl')) {
    throw new RuntimeException("crmAutoRedirect must support return_url redirection");
}

// 3. Verify open redirect protection (must validate index.php?page=automation-followup)
if (!str_contains($actionsContent, 'index.php?page=automation-followup') && !str_contains($actionsContent, 'parse_url')) {
    throw new RuntimeException("crmAutoRedirect must validate internal return_url to prevent open redirects");
}

// 4. Verify actions consume return_url and row anchors
$requiredActionParams = [
    '$_POST[\'return_url\']',
    'row-p-',
    'row-rule-',
    'row-queue-',
    'row-tpl-',
];
foreach ($requiredActionParams as $param) {
    if (!str_contains($actionsContent, $param)) {
        throw new RuntimeException("Missing return-state or anchor handling for: {$param}");
    }
}

// 5. Verify row IDs in pages
$requiredRowIds = [
    'id="row-p-',
    'id="row-rule-',
    'id="row-tpl-',
    'id="row-queue-',
];
foreach ($requiredRowIds as $rowId) {
    if (!str_contains($pagesContent, $rowId)) {
        throw new RuntimeException("Missing row ID anchor in pages: {$rowId}");
    }
}

// 6. Verify hidden return_url in forms
if (!str_contains($pagesContent, 'name="return_url"')) {
    throw new RuntimeException("Missing hidden input return_url in pages");
}

// 7. Verify CSS animation for row pulse
if (!str_contains($cssContent, 'crmRowPulse') || !str_contains($cssContent, ':target')) {
    throw new RuntimeException("Missing CSS animation crmRowPulse or :target styling in automation-followup.css");
}

// 8. Verify search input in pages
if (!str_contains($pagesContent, 'name="search"') || !str_contains($pagesContent, '$search')) {
    throw new RuntimeException("Missing search filter integration in automation-followup.php");
}

// 9. Verify hardcoded LIMIT 100 on queue is removed
if (preg_match('/FROM\s+crm_automation_queue[^\;]+LIMIT\s+100/i', $pagesContent)) {
    throw new RuntimeException("Hardcoded LIMIT 100 on crm_automation_queue query must be removed");
}

echo "automation-followup-retention-test: OK\n";
