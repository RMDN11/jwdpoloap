<?php
declare(strict_types=1);

/**
 * Runner Cron Otomasi Jadwal Reminder (Pembayaran & Peserta)
 * 
 * Dapat dijalankan melalui CLI Crontab cPanel:
 *   php /path/to/crm/actions/reminder-schedule-cron.php
 * Atau via URL Cron / cURL dengan token pengaman:
 *   curl https://domain.com/crm/actions/reminder-schedule-cron.php?cron_token=jwd_auto_cron
 */

$isCli = (php_sapi_name() === 'cli');
$cronToken = (string)($_GET['cron_token'] ?? $_GET['token'] ?? '');
$expectedToken = defined('CRM_CRON_TOKEN') ? CRM_CRON_TOKEN : 'jwd_auto_cron';

// Jika via HTTP dan token tidak cocok, periksa session login admin
if (!$isCli && $cronToken !== $expectedToken) {
    require_once __DIR__ . '/../config/session.php';
    crmSessionInit();
    require_once __DIR__ . '/../../config.php';
    if (empty($_SESSION['logged_in']) && isset($conn) && $conn instanceof mysqli) {
        crmRememberSessionCheck($conn);
    }
    if (empty($_SESSION['logged_in'])) {
        http_response_code(403);
        exit('Akses cron ditolak. Token pengaman tidak valid.');
    }
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/reminder-schedule.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Koneksi basis data tidak tersedia.');
}

date_default_timezone_set('Asia/Jakarta');

$url = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : (!empty($apiUrl) ? $apiUrl : 'https://wa51024.oneapi.my.id/api/v1/messages');
$token = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : (!empty($apiToken) ? $apiToken : '');

if (empty($url) || empty($token)) {
    http_response_code(500);
    exit('Konfigurasi API OneSender (apiUrl / apiToken) tidak ditemukan.');
}

$limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
$summary = crmProcessPendingReminderQueue($conn, (string)$url, (string)$token, $limit);

$now = date('Y-m-d H:i:s');
$output = sprintf(
    "[REMINDER_SCHEDULE_CRON] %s - Processed: %d, Sent: %d, Failed: %d\n",
    $now,
    $summary['processed'] ?? 0,
    $summary['sent'] ?? 0,
    $summary['failed'] ?? 0
);

if ($isCli) {
    echo $output;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $output;
}
