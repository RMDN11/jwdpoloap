<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== 'jwd_auto_cron') {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden']);
    exit;
}

require_once dirname(__DIR__, 2) . '/config.php';

function inspectTable(mysqli $conn, string $tableName, string $dateCol): array
{
    $tableExists = $conn->query("SHOW TABLES LIKE '{$tableName}'")->num_rows > 0;
    if (!$tableExists) {
        return ['exists' => false];
    }

    $res = $conn->query("SELECT 
        COUNT(*) as total_rows,
        MIN({$dateCol}) as earliest_date,
        MAX({$dateCol}) as latest_date,
        SUM(CASE WHEN {$dateCol} < DATE_SUB(NOW(), INTERVAL 180 DAY) THEN 1 ELSE 0 END) as older_than_180d,
        SUM(CASE WHEN {$dateCol} < DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as older_than_90d,
        SUM(CASE WHEN {$dateCol} < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as older_than_30d
        FROM {$tableName}
    ");
    $data = $res->fetch_assoc();

    return [
        'exists' => true,
        'total_rows' => (int)($data['total_rows'] ?? 0),
        'earliest_date' => $data['earliest_date'] ?? null,
        'latest_date' => $data['latest_date'] ?? null,
        'older_than_180d' => (int)($data['older_than_180d'] ?? 0),
        'older_than_90d' => (int)($data['older_than_90d'] ?? 0),
        'older_than_30d' => (int)($data['older_than_30d'] ?? 0),
    ];
}

$retentionInfo = [
    'server_time' => date('Y-m-d H:i:s'),
    'auto_reply_logs' => inspectTable($conn, 'auto_reply_logs', 'created_at'),
    'log_wa' => inspectTable($conn, 'log_wa', 'created_at'),
    'crm_messages' => inspectTable($conn, 'crm_messages', 'created_at'),
    'log_wa_grup_count' => (int)($conn->query("SELECT COUNT(*) as c FROM log_wa WHERE message LIKE '%[GRUP]%'")->fetch_assoc()['c'] ?? 0),
];

echo json_encode($retentionInfo, JSON_PRETTY_PRINT);
