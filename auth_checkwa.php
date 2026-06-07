<?php
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // MENGHINDARI LOGIN DI DALAM IFRAME: Paksa seluruh halaman utama kembali ke login
    echo "<script>window.top.location.href = 'loginwa.php';</script>";
    exit();
}

// Security headers for mobile
// UBAH 'DENY' MENJADI 'SAMEORIGIN' AGAR BISA DIMUAT DI IFRAME DASHBOARD (Domain yang sama)
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");

// Optional: Tambahan security check
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Mobile detection (optional)
function isMobileDevice() {
    return preg_match("/(android|webos|iphone|ipad|ipod|blackberry|windows phone)/i", $_SERVER['HTTP_USER_AGENT']);
}
?>