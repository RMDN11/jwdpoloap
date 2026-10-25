<?php
declare(strict_types=1);

$pagePath = __DIR__ . '/../pages/automation-followup.php';
$cssPath  = __DIR__ . '/../assets/css/automation-followup.css';

if (!file_exists($pagePath) || !file_exists($cssPath)) {
    throw new RuntimeException("Missing page or css file.");
}

$page = file_get_contents($pagePath);
$css  = file_get_contents($cssPath);

// 1. Page must include btn-copy-wa buttons
if (!str_contains($page, 'class="btn-copy-wa"')) {
    throw new RuntimeException("Page does not include btn-copy-wa button!");
}

// 2. Page must link directly to wa.me
if (!str_contains($page, 'https://wa.me/')) {
    throw new RuntimeException("Page missing wa.me link!");
}

// 3. Page must define crmCopyWaNumber function
if (!str_contains($page, 'function crmCopyWaNumber(')) {
    throw new RuntimeException("Page missing crmCopyWaNumber function!");
}

// 4. CSS must include styling for btn-copy-wa
if (!str_contains($css, '.btn-copy-wa')) {
    throw new RuntimeException("CSS missing .btn-copy-wa styling!");
}

echo "automation-followup-copy-wa-test: OK\n";
