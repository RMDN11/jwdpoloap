<?php
declare(strict_types=1);

$indexPath = __DIR__ . '/../index.php';
$morePath = __DIR__ . '/../pages/more.php';
$pagePath = __DIR__ . '/../pages/automation-followup.php';
$cssPath = __DIR__ . '/../assets/css/automation-followup.css';

if (!file_exists($pagePath) || !file_exists($cssPath)) {
    throw new RuntimeException("Missing UI page or CSS file.");
}

$indexContent = file_get_contents($indexPath);
$moreContent = file_get_contents($morePath);
$pageContent = file_get_contents($pagePath);

if ($indexContent === false || $moreContent === false || $pageContent === false) {
    throw new RuntimeException("Cannot read CRM files.");
}

if (!str_contains($indexContent, "'automation-followup'")) {
    throw new RuntimeException("index.php missing 'automation-followup' route.");
}

if (!str_contains($moreContent, 'index.php?page=automation-followup')) {
    throw new RuntimeException("more.php missing link to automation-followup.");
}

$requiredPageTokens = [
    'Automation Follow Up',
    'tab=prospects',
    'tab=rules',
    'tab=templates',
    'tab=queue',
    'crm_automation_rules',
    'crm_automation_templates',
    'crm_automation_queue',
    'crmCsrfToken',
];

foreach ($requiredPageTokens as $token) {
    if (!str_contains($pageContent, $token)) {
        throw new RuntimeException("automation-followup.php missing required token: {$token}");
    }
}

echo "automation-followup-ui-test: OK\n";
