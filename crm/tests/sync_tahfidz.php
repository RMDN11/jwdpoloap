<?php
declare(strict_types=1);

if (($_GET['token'] ?? '') !== 'jwd_auto_cron') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: application/json');

$tahfidzDir = '/home/wegqxcgv/subdomains/app.reqra.my.id/tahfidz';
$archiveDir = $tahfidzDir . '/archive/legacy_files';

$writable = is_writable($tahfidzDir);

if (!is_dir($archiveDir)) {
    @mkdir($archiveDir, 0755, true);
}

$moves = [
    'form-lama.php' => $tahfidzDir . '/form-lama.php',
    'tools-a.html' => $tahfidzDir . '/tools-a.html',
    'ndaftar_dhisik.html' => $tahfidzDir . '/ndaftar_dhisik.html',
    'liqo2.php' => $tahfidzDir . '/peserta/liqo2.php',
    'reset_liqo_ori.php' => $tahfidzDir . '/peserta/reset_liqo_ori.php',
    'hlq_old.php' => $tahfidzDir . '/hlq/old.php',
];

$results = [];
foreach ($moves as $destName => $srcPath) {
    if (file_exists($srcPath)) {
        $destPath = $archiveDir . '/' . $destName;
        $ok = @rename($srcPath, $destPath);
        $results[$destName] = $ok ? 'moved' : 'failed_to_move';
    } else {
        $results[$destName] = 'already_not_present';
    }
}

// Write README in archive
$readmePath = $archiveDir . '/README.md';
if (!file_exists($readmePath)) {
    @file_put_contents($readmePath, "# Arsip File Lawas & Duplikat Tahfidz\n\nFile-file di dalam folder ini adalah file cadangan atau lawas yang telah dipindahkan agar direktori kerja rapi.\n");
}

echo json_encode([
    'tahfidz_writable' => $writable,
    'archive_exists' => is_dir($archiveDir),
    'results' => $results,
], JSON_PRETTY_PRINT);
