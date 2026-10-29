<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== 'jwd_auto_cron') {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden']);
    exit;
}

require_once __DIR__ . '/../lib/log-rotator.php';

$baseDir = dirname(__DIR__, 2);
$targets = [
    'cron_debug.log' => $baseDir . '/cron_debug.log',
    'all_requests.log' => $baseDir . '/all_requests.log',
    'debug.log' => $baseDir . '/debug.log',
    'webhook.log' => $baseDir . '/webhook.log',
    'auto_reply_log.txt' => $baseDir . '/auto_reply_log.txt',
    'cron_satuan_debug.log' => $baseDir . '/cron_satuan_debug.log',
    'ping.log' => $baseDir . '/ping.log',
];

$results = [];
$totalBefore = 0;
$totalAfter = 0;

foreach ($targets as $label => $path) {
    if (!file_exists($path)) {
        $results[$label] = ['status' => 'not_found'];
        continue;
    }

    $sizeBefore = (int)filesize($path);
    $totalBefore += $sizeBefore;

    // Force rotation by setting maxBytes to 0 and keeping only last 20 KB
    crmRotateLogFile($path, 0, 20480);
    clearstatcache(true, $path);

    $sizeAfter = (int)filesize($path);
    $totalAfter += $sizeAfter;

    $results[$label] = [
        'status' => 'truncated',
        'size_before_human' => round($sizeBefore / 1048576, 2) . ' MB',
        'size_after_human' => round($sizeAfter / 1024, 1) . ' KB',
        'freed_kb' => round(($sizeBefore - $sizeAfter) / 1024, 1),
    ];
}

$freedBytes = max(0, $totalBefore - $totalAfter);

echo json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'total_before_mb' => round($totalBefore / 1048576, 2) . ' MB',
    'total_after_mb' => round($totalAfter / 1048576, 2) . ' MB',
    'total_freed_mb' => round($freedBytes / 1048576, 2) . ' MB',
    'files' => $results,
], JSON_PRETTY_PRINT);
