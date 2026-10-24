<?php
declare(strict_types=1);

// Skrip runner cron otomatisasi follow-up
// Dapat dijalankan melalui CLI crontab cPanel:
//   php /path/to/crm/actions/automation-followup-cron.php
// Atau via URL cPanel dengan token:
//   curl https://domain.com/crm/actions/automation-followup-cron.php?cron_token=jwd_auto_cron

$isCli = (php_sapi_name() === 'cli');
$cronToken = (string)($_GET['cron_token'] ?? '');

// Kunci pengaman sederhana untuk URL (bisa disesuaikan di config)
$expectedToken = defined('CRM_CRON_TOKEN') ? CRM_CRON_TOKEN : 'jwd_auto_cron';

if (!$isCli && $cronToken !== $expectedToken) {
    http_response_code(403);
    exit('Akses cron ditolak. Token tidak valid.');
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../config/automation-followup.php';
require_once __DIR__ . '/../lib/automation-engine.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Koneksi basis data tidak tersedia.');
}

date_default_timezone_set('Asia/Jakarta');

[$apiUrl, $apiToken] = crmAutomationGetApiCredentials();
$summary = crmAutomationProcessPendingQueue($conn, $apiUrl, $apiToken);

$now = date('Y-m-d H:i:s');
$output = sprintf(
    "[CRM_AUTOMATION] %s - Processed: %d, Sent: %d, Cancelled: %d, Failed: %d\n",
    $now,
    $summary['processed'],
    $summary['sent'],
    $summary['cancelled'],
    $summary['failed']
);

if ($isCli) {
    echo $output;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $output;
}
