<?php
session_start();
// Hapus atau beri komentar require_once jika tidak diperlukan di proses logout
// require_once 'config.php'; 

$_SESSION['logout_message'] = "Berhasil logout!";
session_unset();
session_destroy();

// MENGHINDARI LOGIN DI DALAM IFRAME: Paksa seluruh halaman (top window) kembali ke login
echo "<script>window.top.location.href = 'loginwa.php';</script>";
exit();
?>