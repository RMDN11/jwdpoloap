<?php
declare(strict_types=1);

$pagePath = __DIR__ . '/../pages/automation-followup.php';
if (!file_exists($pagePath)) {
    throw new RuntimeException("Missing page file: {$pagePath}");
}

$page = file_get_contents($pagePath);
if ($page === false) {
    throw new RuntimeException("Cannot read {$pagePath}");
}

// 1. Must use crmAutomationFindMatchingTrigger to filter prospects against active rules
if (!str_contains($page, 'crmAutomationFindMatchingTrigger($conn')) {
    throw new RuntimeException("Page does not validate prospects using crmAutomationFindMatchingTrigger!");
}

// 2. Must filter out contacts that have neither active queue nor matched rule
if (!str_contains($page, '!$qData && !$matchedRule') && !str_contains($page, '!$matchedRule && !$qData')) {
    throw new RuntimeException("Page does not skip prospects with no matching rule and no queue!");
}

echo "automation-followup-prospect-filter-test: OK\n";
