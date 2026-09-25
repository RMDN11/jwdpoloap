<?php
// =================================================================
// KONFIGURASI SISTEM WHATSAPP WEBHOOK
// =================================================================

date_default_timezone_set('Asia/Jakarta');

// Deteksi environment
$is_local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);

// Setting error reporting
if ($is_local) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ==================== KONFIGURASI API ============================
// *** PASTIKAN KONSTANT INI ADA ***
define('ONESENDER_API_URL', 'https://wa51024.oneapi.my.id/api/v1/messages');
define('ONESENDER_API_TOKEN', 'u282a4673e74d4d1.b1025f245adf4371b4f7a220f30e3708');

$apiUrl = ONESENDER_API_URL;
$apiToken = ONESENDER_API_TOKEN;

// ==================== KONFIGURASI DATABASE =======================
if ($is_local) {
    // LOCALHOST
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = 'root';
    $db_name = 'jawwadatp';
} else {
    // PRODUCTION (UPDATE USER DATABASE & PASSWORD YANG AKTIF)
    $db_host = 'localhost';
    $db_user = 'wegqxcgv_tbeliau';       // Diubah ke user yang aktif
    $db_pass = 'g#liuO07up_WTht6';       // Diubah ke password yang sesuai
    $db_name = 'wegqxcgv_tbeliau';
}

// ==================== KONEKSI DATABASE ===========================
try {
    // Koneksi mysqli
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset('utf8mb4');
    
    // CRM dan file aplikasi saat ini menggunakan mysqli.
    // Hindari membuka koneksi PDO kedua pada setiap request karena dapat
    // menggandakan penggunaan koneksi MySQL dan memicu "Too many connections".
    
} catch (Exception $e) {
    // Log error tapi jangan tampilkan ke user
    error_log("Database Error: " . $e->getMessage());
    
    // Untuk kebutuhan debugging webhook, berikan respon minimal
    if ($is_local) {
        die("Database Error: " . $e->getMessage());
    }
}

// ==================== FUNGSI LOGGING =============================
function logActivity($message, $type = 'INFO') {
    $log_file = __DIR__ . '/logs/activity.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
}
// =================================================================