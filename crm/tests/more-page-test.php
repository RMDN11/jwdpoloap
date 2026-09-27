<?php
declare(strict_types=1);

$more = file_get_contents(__DIR__ . '/../pages/more.php');
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

if ($more === false || $css === false) {
    throw new RuntimeException('More page assets could not be read.');
}

$required = [
    'Template Pesan',
    'Auto Reply',
    'Kirim ke Grup',
    'Kelola Grup',
    'Analytics',
    'Keluar dari CRM',
    'more-grid',
    'more-card',
];

foreach ($required as $needle) {
    if (!str_contains($more, $needle)) {
        throw new RuntimeException("Missing More page element: {$needle}");
    }
}

foreach (['.more-hero', '.more-grid', '.more-card', '.more-account', '@media(min-width:769px)'] as $needle) {
    if (!str_contains($css, $needle)) {
        throw new RuntimeException("Missing More page style: {$needle}");
    }
}

echo "More page rebuild test passed.\n";
