<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../config/automation-followup.php';
require_once __DIR__ . '/../lib/automation-engine.php';

date_default_timezone_set('Asia/Jakarta');

$token = (string)($_GET['token'] ?? '');
if ($token !== 'jwd_auto_cron' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak.');
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNOSA QUEUE & ONESENDER AUTOMATION ===\n\n";

$now = date('Y-m-d H:i:s');
echo "Waktu server saat ini: {$now} WIB\n\n";

// Cek kredensial OneSender
[$apiUrl, $apiToken] = crmAutomationGetApiCredentials();
echo "OneSender API URL: {$apiUrl}\n";
echo "OneSender API Token: " . substr($apiToken, 0, 10) . "...\n\n";

// Cek 15 antrean paling baru
echo "15 Antrean Terakhir di crm_automation_queue:\n";
$qRes = $conn->query("
    SELECT q.id, q.nowa, q.nama, q.status, q.scheduled_at, q.sent_at, q.error_message, q.trigger_source, q.created_at,
           t.name as template_name
    FROM crm_automation_queue q
    LEFT JOIN crm_automation_templates t ON t.id = q.template_id
    ORDER BY q.id DESC
    LIMIT 15
");

if ($qRes) {
    while ($r = $qRes->fetch_assoc()) {
        echo "[ID {$r['id']}] {$r['nowa']} ({$r['nama']}) | Template: {$r['template_name']} | Status: {$r['status']} | Jadwal: {$r['scheduled_at']} | Created: {$r['created_at']} | Err: " . ($r['error_message'] ?? 'none') . "\n";
    }
} else {
    echo "Gagal query queue: " . $conn->error . "\n";
}
echo "\n";

// Cek status antrean yang pending
$qPending = $conn->query("
    SELECT COUNT(*) as total_pending,
           SUM(CASE WHEN scheduled_at <= NOW() THEN 1 ELSE 0 END) as due_now
    FROM crm_automation_queue
    WHERE status = 'pending'
");
$pendingRow = $qPending->fetch_assoc();
echo "Total antrean pending: " . $pendingRow['total_pending'] . "\n";
echo "Antrean yang sudah jatuh tempo (scheduled_at <= NOW()): " . $pendingRow['due_now'] . "\n\n";

// Cek apakah OneSender bisa dihubungi (tes konektivitas ping / dummy check tanpa spam)
echo "Cek histori kirim OneSender di crm_message_history (5 terakhir):\n";
$hRes = $conn->query("
    SELECT id, nowa, template_name, sent_at, status
    FROM crm_message_history
    ORDER BY id DESC
    LIMIT 5
");
if ($hRes) {
    while ($h = $hRes->fetch_assoc()) {
        echo "[Hist ID {$h['id']}] {$h['nowa']} | Tpl: {$h['template_name']} | Tgl: {$h['sent_at']} | Status: {$h['status']}\n";
    }
}
