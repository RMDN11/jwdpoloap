<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/reminder-schedule.php';

date_default_timezone_set('Asia/Jakarta');

$cronToken = (string)($_GET['cron_token'] ?? $_GET['token'] ?? '');
if ($cronToken !== 'jwd_auto_cron' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak.');
}

crmEnsureReminderScheduleTables($conn);

// 1. Ambil 6 template
$templates = [];
$tRes = $conn->query("SELECT id, name, content FROM reminder_templates WHERE name IN ('1','2','3','4','5','6') ORDER BY CAST(name AS UNSIGNED) ASC");
if ($tRes) {
    while ($t = $tRes->fetch_assoc()) {
        $templates[] = $t;
    }
}

if (count($templates) < 6) {
    $tRes = $conn->query("SELECT id, name, content FROM reminder_templates ORDER BY id ASC LIMIT 6");
    $templates = [];
    if ($tRes) {
        while ($t = $tRes->fetch_assoc()) {
            $templates[] = $t;
        }
    }
}

if (!$templates) {
    exit("Error: Template reminder tidak ditemukan.\n");
}

// 2. Ambil peserta pending
$rRes = $conn->query("SELECT id, peserta_id, peserta_nama, peserta_nowa, halaqoh FROM reminder_requests WHERE status = 'menunggu' ORDER BY id ASC");
$requests = [];
if ($rRes) {
    while ($r = $rRes->fetch_assoc()) {
        $requests[] = $r;
    }
}

if (!$requests) {
    exit("Tidak ada antrean reminder_requests yang berstatus menunggu.\n");
}

// Grouping by halaqoh agar rapi dan terjadwal per kelompok halaqoh
$grouped = [];
foreach ($requests as $r) {
    $hal = trim((string)($r['halaqoh'] ?? ''));
    if ($hal === '' || $hal === '-') $hal = 'Umum';
    $grouped[$hal][] = $r;
}

// 3. Buat Kampanye Baru
$title = 'Reminder Peserta Rotasi Template (1-6) ' . date('d M Y');
$campaignType = 'peserta';
$templateId = (int)$templates[0]['id'];
$interMessageDelay = 180; // 3 menit
$interGroupDelay = 420;   // 7 menit jeda antar halaqoh
$allowedDays = '1,2,3,4,5,6,7';
$totalTargets = count($requests);

// Tentukan waktu mulai: 08:15 WIB hari ini
$nowTs = time();
$startTs = strtotime(date('Y-m-d 08:15:00'));
if ($startTs < $nowTs + 120) {
    // Jika sudah lewat atau mepet dari 08:15, mulai 5 menit dari sekarang
    $startTs = $nowTs + 300;
}
$startedAt = date('Y-m-d H:i:s', $startTs);

$cStmt = $conn->prepare("INSERT INTO crm_reminder_campaigns 
    (title, campaign_type, template_id, bulan, inter_message_delay, inter_group_delay, allowed_days, status, total_targets, started_at) 
    VALUES (?, ?, ?, '', ?, ?, ?, 'running', ?, ?)");
if (!$cStmt) {
    exit("Gagal prepare kampanye: " . $conn->error . "\n");
}
$cStmt->bind_param('ssisiisis', $title, $campaignType, $templateId, $interMessageDelay, $interGroupDelay, $allowedDays, $totalTargets, $startedAt);
$cStmt->execute();
$campaignId = (int)$cStmt->insert_id;
$cStmt->close();

// 4. Masukkan antrean dengan rotasi template 1 sampai 6
$qStmt = $conn->prepare("INSERT INTO crm_reminder_campaign_queue
    (campaign_id, peserta_id, request_id, nama, nowa, halaqoh, group_order, message, scheduled_at, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
if (!$qStmt) {
    exit("Gagal prepare queue: " . $conn->error . "\n");
}

$groupOrder = 1;
$currentTime = $startTs;
$rotationIdx = 0;
$numTemplates = count($templates);

$scheduleSummary = [];

foreach ($grouped as $halName => $items) {
    $itemCount = count($items);
    $idxInGroup = 0;
    foreach ($items as $item) {
        $idxInGroup++;
        $pId = !empty($item['peserta_id']) ? (int)$item['peserta_id'] : null;
        $rId = (int)$item['id'];
        $name = trim((string)$item['peserta_nama']) ?: 'Kak';
        $nowa = preg_replace('/\D+/', '', (string)$item['peserta_nowa']);
        if (str_starts_with($nowa, '0')) $nowa = '62' . substr($nowa, 1);

        // Pilih template secara bergilir (1 s/d 6 lalu berulang)
        $chosenTemplate = $templates[$rotationIdx % $numTemplates];
        $templateNum = $chosenTemplate['name'];
        $rawTpl = (string)$chosenTemplate['content'];

        // Render placeholders
        $message = str_ireplace(
            ['{peserta_nama}', '{peserta_nowa}', '{nama}', '[nama]', '{halaqoh}', '[halaqoh]'],
            [$name, $nowa, $name, $name, $halName, $halName],
            $rawTpl
        );
        $message = preg_replace('/ {2,}/', ' ', $message);

        $scheduledAtStr = date('Y-m-d H:i:s', $currentTime);

        $qStmt->bind_param('iiisssiss', $campaignId, $pId, $rId, $name, $nowa, $halName, $groupOrder, $message, $scheduledAtStr);
        $qStmt->execute();

        $scheduleSummary[] = [
            'request_id' => $rId,
            'nama' => $name,
            'nowa' => $nowa,
            'halaqoh' => $halName,
            'template_name' => $templateNum,
            'scheduled_at' => $scheduledAtStr
        ];

        $rotationIdx++;

        // Jarak antar pesan dalam 1 halaqoh
        if ($idxInGroup < $itemCount) {
            $currentTime += $interMessageDelay;
        }
    }

    // Jeda antar halaqoh
    $currentTime += $interGroupDelay;
    $groupOrder++;
}
$qStmt->close();

header('Content-Type: text/plain; charset=utf-8');
echo "JADWAL ROTASI BERHASIL DIBUAT!\n";
echo "Campaign ID: {$campaignId}\n";
echo "Title: {$title}\n";
echo "Total Peserta: " . count($scheduleSummary) . "\n\n";

foreach ($scheduleSummary as $i => $s) {
    echo sprintf(
        "%d. %s (%s) | WA: %s | Template: %s | Jam: %s\n",
        $i + 1,
        $s['nama'],
        $s['halaqoh'],
        $s['nowa'],
        $s['template_name'],
        $s['scheduled_at']
    );
}
