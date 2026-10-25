<?php
declare(strict_types=1);

$pagePath = __DIR__ . '/../pages/automation-followup.php';
$cssPath  = __DIR__ . '/../assets/css/automation-followup.css';

if (!file_exists($pagePath) || !file_exists($cssPath)) {
    throw new RuntimeException("Missing page or css file for pagination test.");
}

$page = file_get_contents($pagePath);
$css  = file_get_contents($cssPath);

// 1. Page must implement pagination parameters & helper
if (!str_contains($page, '$perPage = 15') && !str_contains($page, '$perPage = 15;')) {
    throw new RuntimeException("Page does not define $perPage = 15!");
}

if (!str_contains($page, 'crmRenderPagination')) {
    throw new RuntimeException("Page does not define crmRenderPagination helper!");
}

if (!str_contains($page, 'crm-pagination')) {
    throw new RuntimeException("Page does not include crm-pagination class!");
}

// 2. CSS must style .crm-pagination and .crm-page-btn
if (!str_contains($css, '.crm-pagination')) {
    throw new RuntimeException("CSS missing .crm-pagination styling!");
}

echo "automation-followup-pagination-test: OK\n";
