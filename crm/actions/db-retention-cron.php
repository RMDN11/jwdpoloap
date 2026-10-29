<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$isCli = (php_sapi_name() === 'cli');
$cronToken = (string)($_GET['cron_token'] ?? $_GET['token'] ?? '');
$expectedToken = defined('CRM_CRON_TOKEN') ? CRM_CRON_TOKEN : 'jwd_auto_cron';

if (!$isCli && $cronToken !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'message' => 'Token tidak valid.']);
    exit;
}

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/lib/db-retention.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database tidak tersedia.']);
    exit;
}

$summary = crmRunAllDbRetention($conn);

if ($isCli) {
    echo sprintf(
        "[%s] DB Retention Done: AutoReply=%d, LogWaGroups=%d, LogWaLegacy=%d, Total=%d\n",
        $summary['timestamp'],
        $summary['auto_reply_logs_deleted'],
        $summary['log_wa_group_broadcasts_deleted'],
        $summary['log_wa_legacy_deleted'],
        $summary['total_deleted']
    );
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary, JSON_PRETTY_PRINT);
}
