<?php
declare(strict_types=1);

$more = file_get_contents(__DIR__ . '/../pages/more.php');
if ($more === false) {
    throw new RuntimeException('Cannot read more.php');
}

$required = ['Template Pesan', 'Auto Reply', 'Kelola Grup', 'Analytics', 'Keluar'];
foreach ($required as $label) {
    if (!str_contains($more, $label)) {
        throw new RuntimeException("Missing More item: {$label}");
    }
}

$removed = ['Follow Up', 'Reminder', 'Kirim ke Grup', 'quickLinks', 'Distribusi'];
foreach ($removed as $label) {
    if (str_contains($more, $label)) {
        throw new RuntimeException("Unexpected More content: {$label}");
    }
}

if (!str_contains($more, '../logoutwa.php')) {
    throw new RuntimeException('More logout action must use the canonical logoutwa.php route.');
}

echo "CRM More workspace test passed.\n";
