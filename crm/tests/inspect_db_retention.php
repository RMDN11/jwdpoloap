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

function getTableIndexes(mysqli $conn, string $table): array
{
    $indexes = [];
    $res = $conn->query("SHOW INDEX FROM `{$table}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $indexes[] = [
                'Key_name' => $row['Key_name'],
                'Column_name' => $row['Column_name'],
            ];
        }
    }
    return $indexes;
}

echo json_encode([
    'auto_reply_logs_indexes' => getTableIndexes($conn, 'auto_reply_logs'),
    'log_wa_indexes' => getTableIndexes($conn, 'log_wa'),
], JSON_PRETTY_PRINT);
