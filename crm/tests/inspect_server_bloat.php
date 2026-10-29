<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$token = $_GET['token'] ?? '';
if ($token !== 'jwd_auto_cron') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: application/json; charset=utf-8');

$baseDir = dirname(__DIR__, 2);

// 1. Cek ukuran file log di root server
$logFiles = [
    'all_requests.log' => $baseDir . '/all_requests.log',
    'webhook.log' => $baseDir . '/webhook.log',
    'ping.log' => $baseDir . '/ping.log',
    'debug.log' => $baseDir . '/debug.log',
    'auto_reply_log.txt' => $baseDir . '/auto_reply_log.txt',
    'cron_debug.log' => $baseDir . '/cron_debug.log',
    'logs/activity.log' => $baseDir . '/logs/activity.log',
    'cron_health.txt' => $baseDir . '/cron_health.txt',
];

$logsInfo = [];
foreach ($logFiles as $label => $path) {
    if (file_exists($path)) {
        $size = filesize($path);
        $logsInfo[$label] = [
            'exists' => true,
            'size_bytes' => $size,
            'size_human' => round($size / 1024 / 1024, 2) . ' MB (' . round($size / 1024, 1) . ' KB)'
        ];
    } else {
        $logsInfo[$label] = ['exists' => false];
    }
}

// 2. Cek ukuran folder uploads
$uploadsDir = $baseDir . '/uploads';
$uploadsCount = 0;
$uploadsTotalBytes = 0;
if (is_dir($uploadsDir)) {
    $files = scandir($uploadsDir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = $uploadsDir . '/' . $f;
        if (is_file($fp)) {
            $uploadsCount++;
            $uploadsTotalBytes += filesize($fp);
        }
    }
}

// 3. Cek ukuran tabel database
$dbTables = ['log_wa', 'crm_messages', 'jadwal_pesan_grup', 'crm_reminder_campaign_queue', 'auto_reply_logs'];
$tablesInfo = [];
foreach ($dbTables as $tbl) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM {$tbl}");
    if ($res) {
        $row = $res->fetch_assoc();
        $tablesInfo[$tbl] = (int)$row['total'];
    }
}

echo json_encode([
    'server_time' => date('Y-m-d H:i:s'),
    'logs_info' => $logsInfo,
    'uploads_info' => [
        'count_files' => $uploadsCount,
        'total_mb' => round($uploadsTotalBytes / 1024 / 1024, 2) . ' MB'
    ],
    'tables_info' => $tablesInfo
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
