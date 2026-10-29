<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$token = $_GET['token'] ?? '';
if ($token !== 'jwd_auto_cron') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Jakarta');
$todayDate = date('Y-m-d');
$todayDay = (int)date('N'); // 1=Senin .. 4=Kamis .. 7=Ahad
$currentTime = date('H:i:s');
$currentDateTime = date('Y-m-d H:i:s');

// 1. Ringkasan umum status
$resStatus = $conn->query("
    SELECT status, COUNT(*) AS count 
    FROM jadwal_pesan_grup 
    GROUP BY status
");
$summaryStatus = [];
while ($row = $resStatus->fetch_assoc()) {
    $summaryStatus[$row['status']] = (int)$row['count'];
}

// 2. Jadwal rutin yang aktif untuk hari ini (Kamis)
$resRoutineToday = $conn->query("
    SELECT j.id, j.id_grup, COALESCE(w.nama_grup, j.id_grup) AS nama_grup,
           j.jam_harian, j.hari_rutin, j.status, j.terakhir_dikirim,
           SUBSTRING(j.pesan, 1, 80) AS snippet,
           CASE WHEN j.terakhir_dikirim = '{$todayDate}' THEN 'sudah_kirim'
                WHEN j.jam_harian <= '{$currentTime}' THEN 'jatuh_tempo_belum_kirim'
                ELSE 'menunggu_jam'
           END AS status_hari_ini
    FROM jadwal_pesan_grup j
    LEFT JOIN wa_grup w ON w.id_grup = j.id_grup
    WHERE j.status = 'pending'
      AND j.tipe_jadwal = 'harian'
      AND FIND_IN_SET('{$todayDay}', j.hari_rutin) > 0
    ORDER BY j.jam_harian ASC
");
$routineToday = [];
while ($row = $resRoutineToday->fetch_assoc()) {
    $routineToday[] = $row;
}

// 3. Jadwal sekali kirim (sekali / sekarang) untuk hari ini
$resOnceToday = $conn->query("
    SELECT j.id, j.id_grup, COALESCE(w.nama_grup, j.id_grup) AS nama_grup,
           j.tipe_jadwal, j.jadwal_kirim, j.status, j.terakhir_dikirim,
           SUBSTRING(j.pesan, 1, 80) AS snippet
    FROM jadwal_pesan_grup j
    LEFT JOIN wa_grup w ON w.id_grup = j.id_grup
    WHERE (
        (j.tipe_jadwal = 'sekali' AND DATE(j.jadwal_kirim) = '{$todayDate}')
        OR (j.terakhir_dikirim = '{$todayDate}')
        OR (j.status = 'pending' AND j.tipe_jadwal IN ('sekali','sekarang') AND j.jadwal_kirim <= '{$currentDateTime}')
    )
    ORDER BY j.jadwal_kirim ASC
");
$onceToday = [];
while ($row = $resOnceToday->fetch_assoc()) {
    $onceToday[] = $row;
}

// 4. Semua jadwal yang aktif (pending) secara keseluruhan
$resAllPending = $conn->query("
    SELECT j.id, j.id_grup, COALESCE(w.nama_grup, j.id_grup) AS nama_grup,
           j.tipe_jadwal, j.jam_harian, j.hari_rutin, j.jadwal_kirim, j.status,
           SUBSTRING(j.pesan, 1, 80) AS snippet
    FROM jadwal_pesan_grup j
    LEFT JOIN wa_grup w ON w.id_grup = j.id_grup
    WHERE j.status = 'pending'
    ORDER BY j.id DESC
");
$allPending = [];
while ($row = $resAllPending->fetch_assoc()) {
    $allPending[] = $row;
}

echo json_encode([
    'server_time' => date('Y-m-d H:i:s'),
    'day_of_week' => $todayDay, // 4 = Kamis
    'summary_status' => $summaryStatus,
    'total_rutin_hari_ini' => count($routineToday),
    'rutin_hari_ini' => $routineToday,
    'total_sekali_hari_ini' => count($onceToday),
    'sekali_hari_ini' => $onceToday,
    'total_semua_pending' => count($allPending),
    'semua_pending' => $allPending
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
