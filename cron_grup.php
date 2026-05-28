<?php
require_once 'config.php';

// 1. Tambahkan ini di awal untuk melihat jika ada error PHP
error_reporting(E_ALL);
ini_set('display_errors', 1);

$secretToken = "jwd_secure_cron_2026"; 
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    die("Akses Ditolak.");
}

date_default_timezone_set('Asia/Jakarta'); // PENTING!

// 2. Query yang lebih aman
$query = "SELECT * FROM jadwal_pesan_grup WHERE status = 'pending' AND jadwal_kirim <= NOW() LIMIT 5";
$result = $conn->query($query);

if (!$result) {
    // Jika query gagal, simpan error ke log (atau buat file log.txt)
    file_put_contents('cron_error.log', $conn->error, FILE_APPEND);
    die("Error DB");
}

while ($row = $result->fetch_assoc()) {
    // Panggil fungsi kirim yang ada di config atau copy dari kirimgrup.php
    // PENTING: Pastikan variabel $apiUrl dan $apiToken tersedia di config.php
    
    // ... [Panggil fungsi kirimPesanGrupCanggih] ...
    
    // Setelah kirim, update status
    $conn->query("UPDATE jadwal_pesan_grup SET status = 'terkirim' WHERE id = " . $row['id']);
}
?>