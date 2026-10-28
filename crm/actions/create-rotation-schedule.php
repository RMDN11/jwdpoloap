<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/reminder-schedule.php';

date_default_timezone_set('Asia/Jakarta');

$cronToken = (string)($_GET['cron_token'] ?? $_GET['token'] ?? '');
if ($cronToken !== 'jwd_auto_cron' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Koneksi basis data tidak tersedia.');
}

crmEnsureReminderScheduleTables($conn);

// Cegah duplikasi jika sudah ada kampanye peserta yang sedang berjalan
$chkActive = $conn->query("SELECT id, title FROM crm_reminder_campaigns WHERE campaign_type = 'peserta' AND status = 'running' LIMIT 1");
if ($chkActive && ($actRow = $chkActive->fetch_assoc())) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("Kampanye peserta sudah ada yang berjalan: #{$actRow['id']} - {$actRow['title']}.\nTidak membuat kampanye ganda.\n");
}

// 1. Ambil semua reminder requests yang masih pending
$res = $conn->query("SELECT id, peserta_id, peserta_nama, peserta_nowa, halaqoh FROM reminder_requests WHERE status = 'menunggu' ORDER BY id ASC");
if (!$res) {
    exit("Gagal membaca database: " . $conn->error . "\n");
}

$targets = [];
while ($row = $res->fetch_assoc()) {
    $targets[] = [
        'id' => (int)($row['peserta_id'] ?? 0),
        'request_id' => (int)$row['id'],
        'name' => (string)$row['peserta_nama'],
        'nowa' => (string)$row['peserta_nowa'],
        'halaqoh' => (string)($row['halaqoh'] ?: 'Umum'),
    ];
}

if (!$targets) {
    exit("Tidak ada peserta berstatus menunggu di reminder_requests.\n");
}

// 2. Tentukan waktu mulai: 08:15 WIB hari ini
$nowTs = time();
$startTs = strtotime(date('Y-m-d 08:15:00'));
if ($startTs < $nowTs + 120) {
    $startTs = $nowTs + 300; // 5 menit dari sekarang jika sudah lewat
}
$startDateTime = date('Y-m-d H:i:s', $startTs);

$title = 'Reminder Peserta Rotasi 1-6 (' . date('d M Y') . ')';

// 3. Buat kampanye dengan rotasi template 1 sampai 6
try {
    $campaignId = crmCreateReminderCampaign($conn, [
        'title' => $title,
        'campaign_type' => 'peserta',
        'is_rotating' => true,
        'template_id' => 15,
        'inter_message_delay' => 180, // Jeda antar peserta: 3 menit
        'inter_group_delay' => 420,   // Jeda antar halaqoh: 7 menit
        'allowed_days' => [1, 2, 3, 4, 5, 6, 7],
        'start_datetime' => $startDateTime,
        'targets' => $targets
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    exit("Gagal membuat kampanye rotasi: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// 4. Tampilkan rincian antrean yang telah dibuat
$qRes = $conn->query("SELECT q.id, q.nama, q.halaqoh, q.nowa, q.scheduled_at, SUBSTRING(q.message, 1, 65) as snippet 
                      FROM crm_reminder_campaign_queue q 
                      WHERE q.campaign_id = {$campaignId} 
                      ORDER BY q.scheduled_at ASC");

header('Content-Type: text/plain; charset=utf-8');
echo "=== JADWAL ROTASI TEMPLATE 1-6 BERHASIL DIBUAT ===\n";
echo "Campaign ID : {$campaignId}\n";
echo "Nama Jadwal : {$title}\n";
echo "Mulai Jam   : {$startDateTime} WIB\n";
echo "Total Target: " . count($targets) . " peserta\n\n";

if ($qRes) {
    $num = 0;
    while ($item = $qRes->fetch_assoc()) {
        $num++;
        echo sprintf(
            "%d. %s [%s] | %s | Jam: %s\n   Pesan: %s...\n\n",
            $num,
            $item['nama'],
            $item['halaqoh'],
            $item['nowa'],
            $item['scheduled_at'],
            str_replace("\n", " ", $item['snippet'])
        );
    }
}
