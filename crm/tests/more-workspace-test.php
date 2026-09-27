<?php
declare(strict_types=1);

$more = file_get_contents(__DIR__ . '/../pages/more.php');
if ($more === false) throw new RuntimeException('Cannot read more.php');

$required = [
    'Template Pesan',
    'Auto Reply',
    'Kelola Grup',
    'Analytics',
    'Keluar',
    'More',
];

foreach ($required as $label) {
    if (!str_contains($more, $label)) {
        throw new RuntimeException("Missing More workspace item: {$label}");
    }
}

$removed = [
    'Follow Up',
    'Reminder',
    'Kirim ke Grup',
];

foreach ($removed as $label) {
    if (str_contains($more, $label)) {
        throw new RuntimeException("Unexpected More workspace item: {$label}");
    }
}

if (!str_contains($more, '../logoutwa.php')) {
    throw new RuntimeException('More logout action must use the canonical logoutwa.php route.');
}

echo "CRM More workspace test passed.\n";
