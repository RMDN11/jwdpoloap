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

$now = date('Y-m-d H:i:s');
$tenDaysAgo = date('Y-m-d 00:00:00', strtotime('-10 days'));

$baseWhere = "
    created_at >= '{$tenDaysAgo}'
    AND nowa IS NOT NULL AND nowa != ''
    AND nowa NOT LIKE '%88223053149%'
    AND nowa NOT LIKE '%248232064090227%'
    AND nowa NOT LIKE '%85774037302%'
    AND nowa NOT LIKE '%@g%'
    AND message NOT LIKE '[GRUP]%'
    AND message NOT LIKE '[PROMOSI]%'
    AND message NOT LIKE '[REMINDER]%'
    AND message NOT LIKE '%LAPORAN%'
    AND message NOT LIKE '%MUTABA%'
    AND message NOT LIKE '%SETORAN%'
    AND message NOT LIKE '%halaqoh%'
    AND message NOT LIKE '%List waktu%'
    AND message NOT LIKE '%mengajukan%'
    AND message != 'Data CSV/Manual'
    AND (
        LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%'
        OR LOWER(message) LIKE '%perkenalkan nama saya%'
        OR (LOWER(message) LIKE '%daftar%' AND LOWER(message) NOT LIKE '%daftar ulang%')
        OR LOWER(message) LIKE '%tahfidz cilik%'
        OR LOWER(message) LIKE '%menghafal pemula%'
        OR LOWER(message) LIKE '%menghafal lanjutan%'
        OR LOWER(message) LIKE '%penguatan hafalan%'
        OR LOWER(message) LIKE '%biaya%'
        OR (LOWER(message) LIKE '%registrasi%' AND LOWER(message) NOT LIKE '%registrasi ulang%')
        OR LOWER(message) LIKE '%info pendaftaran%'
        OR LOWER(message) LIKE '%cara daftar%'
    )
";

$qPure = $conn->query("
    SELECT COUNT(*) as total_pesan, COUNT(DISTINCT nowa) as total_kontak
    FROM log_wa
    WHERE {$baseWhere}
");
$pureRow = $qPure->fetch_assoc();

echo "HASIL ANALISIS PENDAFTAR BARU (10 HARI TERAKHIR: 18 - 28 OKT 2026):\n\n";
echo "Total Pesan Masuk Calon Peserta: {$pureRow['total_pesan']} pesan\n";
echo "Total Calon Peserta Unik (Nomor WA): {$pureRow['total_kontak']} orang\n\n";

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

echo "Rincian per hari:\n";
while ($r = $qPerDay->fetch_assoc()) {
    echo "Tanggal {$r['tgl']}: {$r['jml_pesan']} pesan ({$r['jml_kontak']} calon peserta)\n";
}
echo "\n";

$qCat = $conn->query("
    SELECT 
        CASE 
            WHEN LOWER(message) LIKE '%bismillah, kak mau tanya detail program tahfidz private online jawwada%' THEN 'Klik Tombol Tanya Detail (Hero Web)'
            WHEN LOWER(message) LIKE '%perkenalkan nama saya%' THEN 'Isi Form Nama di Landing Page'
            WHEN LOWER(message) LIKE '%tahfidz cilik%' THEN 'Minat Program Tahfidz Cilik'
            WHEN LOWER(message) LIKE '%menghafal pemula%' THEN 'Minat Menghafal Pemula'
            WHEN LOWER(message) LIKE '%menghafal lanjutan%' OR LOWER(message) LIKE '%penguatan hafalan%' THEN 'Minat Lanjutan & Murojaah'
            ELSE 'Chat WhatsApp Mau Daftar / Tanya Biaya'
        END as kategori,
        COUNT(*) as total_pesan,
        COUNT(DISTINCT nowa) as total_kontak
    FROM log_wa
    WHERE {$baseWhere}
    GROUP BY kategori
    ORDER BY total_pesan DESC
");

echo "Sumber pesan pendaftar:\n";
while ($c = $qCat->fetch_assoc()) {
    echo "{$c['kategori']}: {$c['total_pesan']} pesan ({$c['total_kontak']} orang)\n";
}
echo "\n";

// Cek apakah ada yang sudah mengisi data form di calon_peserta
$qFormWeb = $conn->query("
    SELECT COUNT(*) as total_form
    FROM calon_peserta
    WHERE created_at >= '{$tenDaysAgo}'
");
$formWebRow = $qFormWeb ? $qFormWeb->fetch_assoc() : ['total_form' => 0];
echo "Pendaftar yang sudah submit form lengkap calon_peserta (10 hari): {$formWebRow['total_form']} orang\n";
