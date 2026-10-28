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

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $tRes = $conn->query("SELECT id, name, SUBSTRING(content, 1, 80) as snippet FROM reminder_templates ORDER BY id ASC");
    $output .= "\n=== REMINDER TEMPLATES ===\n";
    if ($tRes) {
        while ($t = $tRes->fetch_assoc()) {
            $output .= sprintf("TPL ID: %d | Name: %s | Snippet: %s\n", $t['id'], $t['name'], str_replace("\n", " ", $t['snippet']));
        }
    }

    $rRes = $conn->query("SELECT id, peserta_id, peserta_nama, peserta_nowa, halaqoh, status FROM reminder_requests WHERE status = 'menunggu' ORDER BY id ASC");
    $output .= "\n=== PENDING REQUESTS (status=menunggu) ===\n";
    if ($rRes) {
        $count = 0;
        while ($r = $rRes->fetch_assoc()) {
            $count++;
            $output .= sprintf("#%d | ReqID: %d | PesertaID: %s | Nama: %s | WA: %s | Halaqoh: %s\n",
                $count, $r['id'], $r['peserta_id'] ?? '-', $r['peserta_nama'], $r['peserta_nowa'], $r['halaqoh']
            );
        }
        $output .= "Total pending requests: {$count}\n";
    }

    $cRes = $conn->query("SELECT id, title, campaign_type, status, total_targets, sent_count, failed_count, started_at FROM crm_reminder_campaigns ORDER BY id DESC LIMIT 5");
    $output .= "\n=== RECENT CAMPAIGNS ===\n";
    if ($cRes) {
        while ($c = $cRes->fetch_assoc()) {
            $output .= sprintf("ID: %d | Type: %s | Status: %s | Sent: %d/%d | Started: %s | Title: %s\n",
                $c['id'], $c['campaign_type'], $c['status'], $c['sent_count'], $c['total_targets'], $c['started_at'], $c['title']
            );
        }
    }
    $qRes = $conn->query("SELECT status, count(*) as cnt, min(scheduled_at) as earliest, max(scheduled_at) as latest FROM crm_reminder_campaign_queue GROUP BY status");
    $output .= "\n=== QUEUE STATS ===\n";
    if ($qRes) {
        while ($q = $qRes->fetch_assoc()) {
            $output .= sprintf("Status: %s | Count: %d | Earliest: %s | Latest: %s\n",
                $q['status'], $q['cnt'], $q['earliest'], $q['latest']
            );
        }
    }
    $tRes = $conn->query("SELECT NOW() as db_now, @@session.time_zone as tz");
    $tRow = $tRes ? $tRes->fetch_assoc() : [];
    $output .= sprintf("\n=== TIME CHECK ===\nPHP Time: %s\nDB Time: %s (tz: %s)\n", $now, $tRow['db_now'] ?? '-', $tRow['tz'] ?? '-');
}

if ($isCli) {
    echo $output;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $output;
}
