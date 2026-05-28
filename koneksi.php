<?php
$host = 'localhost';
$dbname = 'wegqxcgv_poloap'; // Ganti dengan nama database Anda
$user = 'wegqxcgv_poloap';
$pass = 'aVZhT8d5bzgq#j1#'; // <--- Ubah ini jika Anda menggunakan XAMPP tanpa password root

try {
    // Baris ini yang membuat variabel $pdo
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

    // Atur mode error PDO ke exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Jika koneksi gagal, hentikan skrip dan tampilkan pesan error
    die("Koneksi ke database gagal: " . $e->getMessage());
}
?>