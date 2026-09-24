<?php
session_start();
require 'koneksi.php';

if (!isset($_SESSION['loggedin'])) {
    die("Akses ditolak.");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = json_decode($_POST['ids'], true);

    if (is_array($ids)) {
        // Contoh: hapus dari database
        // foreach ($ids as $id) {
        //     $stmt = $pdo->prepare("DELETE FROM peserta WHERE id = ?");
        //     $stmt->execute([$id]);
        // }

        echo "Berhasil menghapus " . count($ids) . " peserta.";
    } else {
        echo "Tidak ada data yang dipilih.";
    }
} else {
    header("Location: index.php");
    exit;
}
?>