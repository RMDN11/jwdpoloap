<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
date_default_timezone_set('Asia/Jakarta');

$token = (string)($_GET['token'] ?? '');
if ($token !== 'jwd_auto_cron' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak.');
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== CEK DATA PESERTA DAFTAR 10 HARI TERAKHIR ===\n\n";

// Show existing tables
$resTables = $conn->query("SHOW TABLES");
$tables = [];
while ($row = $resTables->fetch_row()) {
    $tables[] = $row[0];
}
echo "Tabel yang ada:\n" . implode(", ", $tables) . "\n\n";

// Tanggal 10 hari terakhir
$now = date('Y-m-d H:i:s');
$tenDaysAgo = date('Y-m-d 00:00:00', strtotime('-10 days'));

echo "Rentang waktu: {$tenDaysAgo} s.d. {$now}\n\n";

// Cek total di log_wa dalam rentang 10 hari
$qAllLog = $conn->query("
    SELECT COUNT(*) as total_chat, COUNT(DISTINCT nowa) as total_kontak
    FROM log_wa
    WHERE created_at >= '{$tenDaysAgo}'
");
$allLogRow = $qAllLog->fetch_assoc();
echo "Total chat di log_wa (10 hari terakhir): " . $allLogRow['total_chat'] . " chat, " . $allLogRow['total_kontak'] . " nomor unik\n\n";

// Cek chat pemicu pendaftaran / minat daftar di log_wa
$qTriggers = $conn->query("
    SELECT 
        DATE(created_at) as tgl,
        COUNT(*) as total_pesan,
        COUNT(DISTINCT nowa) as total_kontak
    FROM log_wa
    WHERE created_at >= '{$tenDaysAgo}'
      AND nowa IS NOT NULL AND nowa != ''
      AND nowa NOT LIKE '%88223053149%'
      AND nowa NOT LIKE '%248232064090227%'
      AND nowa NOT LIKE '%@g%'
      AND message NOT LIKE '[GRUP]%'
      AND message NOT LIKE '[PROMOSI]%'
      AND message NOT LIKE '[REMINDER]%'
      AND message != 'Data CSV/Manual'
      AND (
          LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%'
          OR LOWER(message) LIKE '%perkenalkan nama saya%'
          OR LOWER(message) LIKE '%tahfidz cilik%'
          OR LOWER(message) LIKE '%menghafal pemula%'
          OR LOWER(message) LIKE '%menghafal lanjutan%'
          OR LOWER(message) LIKE '%penguatan hafalan%'
          OR LOWER(message) LIKE '%tahfidz private%'
          OR LOWER(message) LIKE '%tanya detail program tahfidz%'
          OR LOWER(message) LIKE '%daftar%'
          OR LOWER(message) LIKE '%biaya%'
          OR LOWER(message) LIKE '%registrasi%'
      )
    GROUP BY DATE(created_at)
    ORDER BY tgl DESC
");

echo "Pesan pemicu/minat pendaftaran per hari (10 hari terakhir):\n";
$sumPesan = 0;
$sumKontak = 0;
if ($qTriggers) {
    while ($r = $qTriggers->fetch_assoc()) {
        echo "- {$r['tgl']}: {$r['total_pesan']} pesan ({$r['total_kontak']} nomor unik)\n";
        $sumPesan += (int)$r['total_pesan'];
    }
}
echo "Total pesan minat/pendaftaran: {$sumPesan}\n\n";

// Cek jumlah nomor unik pendaftar dalam 10 hari terakhir
$qDistinctLeads = $conn->query("
    SELECT COUNT(DISTINCT nowa) as unique_leads
    FROM log_wa
    WHERE created_at >= '{$tenDaysAgo}'
      AND nowa IS NOT NULL AND nowa != ''
      AND nowa NOT LIKE '%88223053149%'
      AND nowa NOT LIKE '%248232064090227%'
      AND nowa NOT LIKE '%@g%'
      AND message NOT LIKE '[GRUP]%'
      AND message NOT LIKE '[PROMOSI]%'
      AND message NOT LIKE '[REMINDER]%'
      AND message != 'Data CSV/Manual'
      AND (
          LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%'
          OR LOWER(message) LIKE '%perkenalkan nama saya%'
          OR LOWER(message) LIKE '%tahfidz cilik%'
          OR LOWER(message) LIKE '%menghafal pemula%'
          OR LOWER(message) LIKE '%menghafal lanjutan%'
          OR LOWER(message) LIKE '%penguatan hafalan%'
          OR LOWER(message) LIKE '%tahfidz private%'
          OR LOWER(message) LIKE '%tanya detail program tahfidz%'
          OR LOWER(message) LIKE '%daftar%'
          OR LOWER(message) LIKE '%biaya%'
          OR LOWER(message) LIKE '%registrasi%'
      )
");
$uniqueLeadsRow = $qDistinctLeads->fetch_assoc();
echo "Total kontak unik pendaftar/prospek baru (10 hari terakhir): " . $uniqueLeadsRow['unique_leads'] . " orang\n\n";

// Sample 10 pesan terbaru
echo "Sample pesan pendaftaran terbaru:\n";
$qSample = $conn->query("
    SELECT nowa, nama, message, created_at
    FROM log_wa
    WHERE created_at >= '{$tenDaysAgo}'
      AND nowa IS NOT NULL AND nowa != ''
      AND nowa NOT LIKE '%88223053149%'
      AND nowa NOT LIKE '%248232064090227%'
      AND nowa NOT LIKE '%@g%'
      AND message NOT LIKE '[GRUP]%'
      AND message NOT LIKE '[PROMOSI]%'
      AND message NOT LIKE '[REMINDER]%'
      AND message != 'Data CSV/Manual'
      AND (
          LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%'
          OR LOWER(message) LIKE '%perkenalkan nama saya%'
          OR LOWER(message) LIKE '%tahfidz cilik%'
          OR LOWER(message) LIKE '%menghafal pemula%'
          OR LOWER(message) LIKE '%menghafal lanjutan%'
          OR LOWER(message) LIKE '%penguatan hafalan%'
          OR LOWER(message) LIKE '%tahfidz private%'
          OR LOWER(message) LIKE '%tanya detail program tahfidz%'
          OR LOWER(message) LIKE '%daftar%'
          OR LOWER(message) LIKE '%biaya%'
          OR LOWER(message) LIKE '%registrasi%'
      )
    ORDER BY id DESC
    LIMIT 10
");
if ($qSample) {
    while ($s = $qSample->fetch_assoc()) {
        $msgShort = mb_strimwidth($s['message'], 0, 70, '...');
        echo "[{$s['created_at']}] {$s['nowa']} ({$s['nama']}): {$msgShort}\n";
    }
}
