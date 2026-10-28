<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../config/automation-followup.php';
require_once __DIR__ . '/../lib/automation-engine.php';

date_default_timezone_set('Asia/Jakarta');

$cronToken = (string)($_GET['cron_token'] ?? $_GET['token'] ?? '');
if ($cronToken !== 'jwd_auto_cron' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak.');
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== TESTING INSTANT AUTO-WELCOME & JAM TIDUR MALAM ===\n\n";

crmAutomationEnsureTables($conn);

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        $failed++;
        echo "[FAIL] {$name}" . ($detail ? " - {$detail}" : "") . "\n";
    }
}

// TEST 1: Sleep Settings
$settings = crmAutomationGetSleepSettings($conn);
assertTest(
    'Pengaturan Jam Istirahat Terbaca',
    isset($settings['enabled']) && isset($settings['start']) && isset($settings['end']),
    "Aktif: " . ($settings['enabled'] ? 'Ya' : 'Tidak') . ", Rentang: {$settings['start']} - {$settings['end']}"
);

// TEST 2: Deteksi Jam Tidur Siang vs Malam
$daytimeTs = strtotime('2026-10-28 10:00:00'); // Siang
$nighttimeTs = strtotime('2026-10-28 22:30:00'); // Malam (22:30)
$earlyMorningTs = strtotime('2026-10-28 02:00:00'); // Dini hari (02:00)

assertTest(
    'Deteksi Siang Hari (Bukan Jam Tidur)',
    crmAutomationIsSleepTime($conn, $daytimeTs) === false,
    "Jam 10:00 siang -> isSleepTime: false"
);

assertTest(
    'Deteksi Malam Hari (Masuk Jam Tidur)',
    crmAutomationIsSleepTime($conn, $nighttimeTs) === true,
    "Jam 22:30 malam -> isSleepTime: true"
);

assertTest(
    'Deteksi Dini Hari (Masuk Jam Tidur)',
    crmAutomationIsSleepTime($conn, $earlyMorningTs) === true,
    "Jam 02:00 subuh -> isSleepTime: true"
);

// TEST 3: Penyesuaian Jadwal Tidur ke Pagi Hari
$adjustedNight = crmAutomationAdjustForSleep($conn, $nighttimeTs);
$adjustedDateNight = date('Y-m-d H:i', $adjustedNight);
assertTest(
    'Pendaftar Malam Hari Dialihkan ke Pagi',
    $adjustedDateNight === '2026-10-29 07:30',
    "Pendaftar 22:30 malam -> dijadwalkan ulang ke: {$adjustedDateNight} WIB"
);

$adjustedEarly = crmAutomationAdjustForSleep($conn, $earlyMorningTs);
$adjustedDateEarly = date('Y-m-d H:i', $adjustedEarly);
assertTest(
    'Pendaftar Dini Hari Dialihkan ke Pagi Hari Ini',
    $adjustedDateEarly === '2026-10-28 07:30',
    "Pendaftar 02:00 subuh -> dijadwalkan ulang ke: {$adjustedDateEarly} WIB"
);

// TEST 4: Template Follow Up 1 Baru
$tplRes = $conn->query("SELECT id, name, content FROM crm_automation_templates WHERE name LIKE 'Follow Up 1%' LIMIT 1");
$tpl = $tplRes ? $tplRes->fetch_assoc() : null;
$hasWelcomeCopy = $tpl && str_contains($tpl['content'], 'sendiri atau ananda');
assertTest(
    'Template Follow Up 1 Baru Terpasang',
    $hasWelcomeCopy,
    $tpl ? "Nama: {$tpl['name']}" : 'Template tidak ditemukan'
);

// TEST 5: Render Pesan Follow Up 1 dengan Placeholder
if ($tpl) {
    $rendered = crmAutomationRenderMessage($tpl['content'], ['nama' => 'Budi Santoso']);
    assertTest(
        'Render Pesan Menyapa Nama Prospek & Pertanyaan Mudah',
        str_contains($rendered, 'kak Budi Santoso') && str_contains($rendered, 'sendiri atau ananda'),
        substr(str_replace("\n", " ", $rendered), 0, 75) . '...'
    );
}

// TEST 6: Alur Penjadwalan Sequence (Instant Welcome: 2 Menit)
$dummyPhone = '628999999901';
// Hapus antrean dummy lama jika ada
$conn->query("DELETE FROM crm_automation_queue WHERE nowa = '{$dummyPhone}'");

$seqRes = crmAutomationScheduleSequence($conn, $dummyPhone, 'Tes User', 0, 'Test Trigger', 'test_runner');
assertTest(
    'Penjadwalan 6 Tahap Sequence Sukses',
    !empty($seqRes['success']) && ($seqRes['count'] ?? 0) === 6,
    "Jumlah tahap terdaftar: " . ($seqRes['count'] ?? 0)
);

// Periksa jeda tahap 1
$q1Res = $conn->query("
    SELECT q.id, q.scheduled_at, t.name as tpl_name,
           ROUND((UNIX_TIMESTAMP(q.scheduled_at) - UNIX_TIMESTAMP(q.created_at)) / 60) as diff_min
    FROM crm_automation_queue q
    JOIN crm_automation_templates t ON t.id = q.template_id
    WHERE q.nowa = '{$dummyPhone}'
    ORDER BY q.scheduled_at ASC
    LIMIT 1
");
$q1 = $q1Res ? $q1Res->fetch_assoc() : null;
$diffMin = (int)($q1['diff_min'] ?? -1);
assertTest(
    'Tahap 1 Memakai Delay Instant Welcome 2 Menit',
    $diffMin >= 1 && $diffMin <= 3,
    "Jeda di database: {$diffMin} menit ({$q1['scheduled_at']})"
);

// Bersihkan antrean dummy test
$conn->query("DELETE FROM crm_automation_queue WHERE nowa = '{$dummyPhone}'");

echo "\n=== HASIL AKHIR: {$passed} PASS, {$failed} FAIL ===\n";
