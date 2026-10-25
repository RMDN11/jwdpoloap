<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/automation-followup.php';
$pagePath   = __DIR__ . '/../pages/automation-followup.php';

if (!file_exists($configPath) || !file_exists($pagePath)) {
    throw new RuntimeException("Missing config or page file.");
}

$config = file_get_contents($configPath);
$page   = file_get_contents($pagePath);

// 1. Pagination maintains 'cfilter' parameter
if (!str_contains($page, "'cfilter' => \$cfilter")) {
    throw new RuntimeException("Pagination in page does not pass 'cfilter' => \$cfilter!");
}

// 2. Page accepts $_GET['cfilter'] ?? $_GET['cat']
if (!str_contains($page, "\$_GET['cfilter'] ?? \$_GET['cat']")) {
    throw new RuntimeException("Page does not fall back between cfilter and cat!");
}

// 3. wa.me links must use crmProspectNormalizeNumber
if (!str_contains($page, 'https://wa.me/<?= htmlspecialchars(crmProspectNormalizeNumber(')) {
    throw new RuntimeException("Page does not normalize wa.me phone numbers using crmProspectNormalizeNumber!");
}

// 4. Migration must check table existence before running DELETE
if (!str_contains($config, "SHOW TABLES LIKE 'crm_automation_rules'")) {
    throw new RuntimeException("Config does not guard DELETE with SHOW TABLES LIKE 'crm_automation_rules'!");
}

// 5. Active triggers must be memoized
if (!str_contains($config, '$cachedTriggers')) {
    throw new RuntimeException("Config does not memoize crmAutomationGetActiveTriggers!");
}

// 6. Copy button defends against double clicks
if (!str_contains($page, "btn.classList.contains('copied')")) {
    throw new RuntimeException("Page does not guard double clicks in crmCopyWaNumber!");
}

echo "automation-followup-review-fixes-test: OK\n";
