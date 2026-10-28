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

echo "=== CEK DATA DETAIL PENDAFTAR 10 HARI TERAKHIR ===\n\n";

$now = date('Y-m-d H:i:s');
$tenDaysAgo = date('Y-m-d 00:00:00', strtotime('-10 days'));

echo "Rentang waktu: {$tenDaysAgo} s.d. {$now}\n\n";

// 1. Cek tabel calon_peserta jika ada kolom created_at / tanggal
$resCols = $conn->query("SHOW COLUMNS FROM calon_peserta");
$cols = [];
if ($resCols) {
    while ($c = $resCols->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
    echo "Kolom calon_peserta: " . implode(", ", $cols) . "\n";
    $qCp = $conn->query("SELECT COUNT(*) as cnt FROM calon_peserta");
    $cntCp = $qCp ? $qCp->fetch_assoc()['cnt'] : 0;
    echo "Total row calon_peserta: {$cntCp}\n\n";
}

// 2. Query log_wa KHUSUS pendaftar baru (Eksklusi laporan harian / ziyadah / murojaah santri lama)
$baseWhere = "
    created_at >= '{$tenDaysAgo}'
    AND nowa IS NOT NULL AND nowa != ''
    AND nowa NOT LIKE '%88223053149%'
    AND nowa NOT LIKE '%248232064090227%'
    AND nowa NOT LIKE '%@g%'
    AND message NOT LIKE '[GRUP]%'
    AND message NOT LIKE '[PROMOSI]%'
    AND message NOT LIKE '[REMINDER]%'
    AND message NOT LIKE '%LAPORAN ZIYADAH%'
    AND message NOT LIKE '%LAPORAN MUTABA%'
    AND message NOT LIKE '%LAPORAN HARIAN%'
    AND message NOT LIKE '%MUTABA%'
    AND message NOT LIKE '%SETORAN%'
    AND message != 'Data CSV/Manual'
    AND (
        LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%'
        OR LOWER(message) LIKE '%perkenalkan nama saya%'
        OR (LOWER(message) LIKE '%daftar%' AND LOWER(message) NOT LIKE '%laporan%')
        OR (LOWER(message) LIKE '%tahfidz cilik%' AND LOWER(message) NOT LIKE '%laporan%')
        OR (LOWER(message) LIKE '%menghafal pemula%' AND LOWER(message) NOT LIKE '%laporan%')
        OR (LOWER(message) LIKE '%menghafal lanjutan%' AND LOWER(message) NOT LIKE '%laporan%')
        OR (LOWER(message) LIKE '%penguatan hafalan%' AND LOWER(message) NOT LIKE '%laporan%')
        OR (LOWER(message) LIKE '%biaya%' AND LOWER(message) NOT LIKE '%laporan%')
        OR (LOWER(message) LIKE '%registrasi%' AND LOWER(message) NOT LIKE '%laporan%')
        OR (LOWER(message) LIKE '%info pendaftaran%')
        OR (LOWER(message) LIKE '%cara daftar%')
    )
";

// Hitung total pesan & nomor kontak unik murni
$qPure = $conn->query("
    SELECT COUNT(*) as total_pesan, COUNT(DISTINCT nowa) as total_kontak
    FROM log_wa
    WHERE {$baseWhere}
");
$pureRow = $qPure->fetch_assoc();
echo "--- HASIL FILTER MURNI PENDAFTAR ---\n";
echo "Total Pesan Ingin Daftar: " . $pureRow['total_pesan'] . "\n";
echo "Total Peserta/Kontak Unik: " . $pureRow['total_kontak'] . "\n\n";

// Breakdown per hari
$qPerDay = $conn->query("
    SELECT 
        DATE(created_at) as tgl,
        COUNT(*) as jml_pesan,
        COUNT(DISTINCT nowa) as jml_kontak
    FROM log_wa
    WHERE {$baseWhere}
    GROUP BY DATE(created_at)
    ORDER BY tgl DESC
");

echo "Breakdown Pendaftar Per Hari:\n";
while ($r = $qPerDay->fetch_assoc()) {
    echo "- {$r['tgl']}: {$r['jml_pesan']} pesan ({$r['jml_kontak']} calon peserta)\n";
}
echo "\n";

// Kategori pemicu
$qCat = $conn->query("
    SELECT 
        CASE 
            WHEN LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%' THEN 'Klik Tombol Hero LP (Tanya Detail)'
            WHEN LOWER(message) LIKE '%perkenalkan nama saya%' THEN 'Isi Form Nama LP'
            WHEN LOWER(message) LIKE '%tahfidz cilik%' THEN 'Paket Tahfidz Cilik'
            WHEN LOWER(message) LIKE '%menghafal pemula%' THEN 'Paket Menghafal Pemula'
            WHEN LOWER(message) LIKE '%menghafal lanjutan%' OR LOWER(message) LIKE '%penguatan hafalan%' THEN 'Paket Lanjutan & Murojaah'
            ELSE 'Chat Eksplisit Mau Daftar / Biaya'
        END as kategori,
        COUNT(*) as total_pesan,
        COUNT(DISTINCT nowa) as total_kontak
    FROM log_wa
    WHERE {$baseWhere}
    GROUP BY kategori
    ORDER BY total_pesan DESC
");

echo "Breakdown Berdasarkan Kategori Sumber:\n";
while ($c = $qCat->fetch_assoc()) {
    echo "- {$c['kategori']}: {$c['total_pesan']} pesan ({$c['total_kontak']} orang)\n";
}
echo "\n";

// Tampilkan semua calon peserta dan pesan pertama mereka dalam 10 hari terakhir
echo "Daftar Calon Peserta 10 Hari Terakhir:\n";
$qLeadsList = $conn->query("
    SELECT nowa, nama, MIN(created_at) as first_chat, MAX(created_at) as last_chat, COUNT(*) as chat_count,
           SUBSTRING_INDEX(GROUP_CONCAT(message ORDER BY id ASC SEPARATOR '|||'), '|||', 1) as first_msg
    FROM log_wa
    WHERE {$baseWhere}
    GROUP BY nowa, nama
    ORDER BY last_chat DESC
");

$i = 1;
while ($lead = $qLeadsList->fetch_assoc()) {
    $msgPreview = mb_strimwidth(str_replace(["\r", "\n"], ' ', $lead['first_msg']), 0, 60, '...');
    echo "{$i}. {$lead['nowa']} ({$lead['nama']}) | Chat: {$lead['chat_count']}x | Tgl: {$lead['last_chat']} | \"{$msgPreview}\"\n";
    $i++;
}
