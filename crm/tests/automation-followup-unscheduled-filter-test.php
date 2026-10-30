<?php
declare(strict_types=1);

$pagePath = __DIR__ . '/../pages/automation-followup.php';
$cssPath  = __DIR__ . '/../assets/css/automation-followup.css';

if (!file_exists($pagePath) || !file_exists($cssPath)) {
    throw new RuntimeException("Missing page or css file.");
}

$page = file_get_contents($pagePath);
$css  = file_get_contents($cssPath);

if ($page === false || $css === false) {
    throw new RuntimeException("Cannot read page or css file.");
}

// 1. Verify qfilter parameter parsing
if (!str_contains($page, "\$qfilter = (string)(\$_GET['qfilter'] ?? 'all');")) {
    throw new RuntimeException("Missing \$qfilter parameter initialization in automation-followup.php");
}

// 2. Verify filter logic for unscheduled vs scheduled
if (!str_contains($page, "\$qfilter === 'unscheduled' && \$isScheduled")) {
    throw new RuntimeException("Missing unscheduled queue filter condition in automation-followup.php");
}
if (!str_contains($page, "\$qfilter === 'scheduled' && !\$isScheduled")) {
    throw new RuntimeException("Missing scheduled queue filter condition in automation-followup.php");
}

// 3. Verify count badges
if (!str_contains($page, "\$countProspectsUnscheduled") || !str_contains($page, "\$countProspectsScheduled")) {
    throw new RuntimeException("Missing prospect queue count tracking in automation-followup.php");
}

// 4. Verify UI badge-unscheduled class
if (!str_contains($page, 'badge-unscheduled') || !str_contains($css, '.badge-unscheduled')) {
    throw new RuntimeException("Missing badge-unscheduled in page or CSS");
}

// 5. Verify filter chip links
if (!str_contains($page, "qfilter' => 'unscheduled'") || !str_contains($page, "Belum Terjadwal")) {
    throw new RuntimeException("Missing Belum Terjadwal filter button in automation-followup.php");
}

// 6. Verify pagination preserves qfilter
if (!str_contains($page, "'qfilter' => \$qfilter")) {
    throw new RuntimeException("Missing qfilter in prospectPaginationParams in automation-followup.php");
}

echo "automation-followup-unscheduled-filter-test: OK\n";
