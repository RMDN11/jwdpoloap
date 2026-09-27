<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$home = file_get_contents($root . '/pages/home.php');
$css = file_get_contents($root . '/assets/css/app.css');

foreach ([
    'home-analytics-section',
    'home-analytics-bento',
    'home-analytics-feature',
    'home-analytics-tile',
    'Lead Valid',
    'Quality Check',
    'Interest Mix',
    'Trend',
    '$homeAnalytics',
    '$homeCategoryTotals',
    'crmIsEligibleProspect',
    '30 Hari Terakhir',
    '?page=analytics',
] as $needle) {
    if (!str_contains($home, $needle)) {
        throw new RuntimeException("Missing Home Analytics element: {$needle}");
    }
}

if (str_contains($home, 'home-tools-section') || str_contains($home, '>Tools<')) {
    throw new RuntimeException('Legacy Home Tools section still exists.');
}

foreach ([
    'Home Analytics Bento',
    '.home-analytics-feature',
    '.tile-lead',
    '.tile-quality',
    '.tile-interest',
    '.tile-trend',
    '@media(max-width:560px)',
] as $needle) {
    if (!str_contains($css, $needle)) {
        throw new RuntimeException("Missing Home Analytics CSS rule: {$needle}");
    }
}

echo "CRM Home Analytics bento test passed.\n";
