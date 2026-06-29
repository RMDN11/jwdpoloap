<?php

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir   = __DIR__;
$allRequestLog = $baseDir . '/all_requests.log';
$logFile   = $baseDir . '/webhook.log';
$pingFile  = $baseDir . '/ping.log';
$debugFile = $baseDir . '/debug.log';
$timestamp = date('Y-m-d H:i:s');

// 1. Catat semua HTTP Request mentah
$logEntry = "=== {$timestamp} ===\n";
$logEntry .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$logEntry .= "IP: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
$logEntry .= "Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none') . "\n";
$logEntry .= "Raw Input: " . file_get_contents('php://input') . "\n\n";
file_put_contents($allRequestLog, $logEntry, FILE_APPEND);

// 2. Fungsi pembantu untuk log eksekusi internal
function logx($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// 3. HANYA MEMPROSES METHOD POST (selain POST ditolak)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents($pingFile, "{$timestamp} HIT (" . $_SERVER['REQUEST_METHOD'] . ") - REJECTED\n", FILE_APPEND);
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        'status'  => 'error',
        'message' => 'Method not allowed. Only POST is accepted.',
        'allowed_method' => 'POST'
    ]);
    exit;
}

logx("=== WEBHOOK CALLED (POST) ===");

// 4. Baca Raw Input JSON
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    logx("EMPTY BODY - Dibatalkan");
    echo json_encode(['status' => 'ignored', 'reason' => 'empty_body']);
    exit;
}

// Simpan Raw Data untuk keperluan Debug
file_put_contents($debugFile, "[$timestamp]\n{$rawInput}\n\n", FILE_APPEND);

$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logx("JSON ERROR: " . json_last_error_msg());
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_json']);
    exit;
}

// =================================================================
// 5. EKSTRAKSI DATA & DETEKSI NAMA OTOMATIS DARI ISI PESAN
// =================================================================
$senderPhone = $data['sender_phone'] ?? $data['phone'] ?? $data['from'] ?? '';
$messageText = $data['message_text'] ?? $data['text'] ?? $data['message'] ?? '';

$senderPhone = trim($senderPhone);
$messageText = trim($messageText);

// Ambil nama dari profile WhatsApp sebagai cadangan awal
$senderName  = $data['from_name'] ?? $data['pushName'] ?? $data['name'] ?? '';

// --- ENGINE DETEKSI NAMA DARI ISI TEKS ---
// Pola: mencari kata setelah "nama saya" atau "nama sy" atau "perkenalkan nama saya"
if (preg_match('/(?:nama saya|nama sy|perkenalkan nama saya)\s+([A-Za-z0-9]+)/i', $messageText, $matches)) {
    // $matches[1] akan mengambil 1 kata tepat setelah kalimat di atas (Yaitu: "Eny")
    $extractedName = trim($matches[1]);
    
    // Jika nama hasil ekstraksi tidak kosong, gunakan nama ini!
    if (!empty($extractedName)) {
        $senderName = ucfirst(strtolower($extractedName)); // Merapikan huruf kapital menjadi "Eny"
    }
}

// Jika setelah dicari di teks & profile tetap kosong, berikan sebutan default
if (empty($senderName) || htmlspecialchars($senderName) === 'Unknown') {
    $senderName = 'Kak';
}
// =================================================================

// Validasi jika Nomor WA atau Pesan ternyata kosong
if ($senderPhone === '' || $messageText === '') {
    logx("INVALID PAYLOAD — Nomor atau pesan tidak ditemukan dari Webhook.");
    logx("-> Terdeteksi: Phone='$senderPhone', Msg='$messageText'");
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_payload']);
    exit;
}

// Normalisasi nomor HP (Hanya menyisakan angka)
$senderPhone = preg_replace('/\D/', '', $senderPhone);

logx("PHONE: {$senderPhone}");
logx("NAME: {$senderName}");
logx("MESSAGE: " . substr($messageText, 0, 50) . "...");

// 6. SIMPAN KE DATABASE (Tabel log_wa)
require_once $baseDir . '/config.php';

$dbConnected = isset($conn) && $conn instanceof mysqli && !$conn->connect_error;
logx("DB CONNECTED: " . ($dbConnected ? 'YES' : 'NO'));

$savedToDB = false;
if ($dbConnected) {
    try {
        $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $senderPhone, $senderName, $messageText, $timestamp);
        
        if ($stmt->execute()) {
            $savedToDB = true;
            logx("✅ BERHASIL SIMPAN KE TABEL log_wa");
        } else {
            // Jika gagal insert, catat pesan error aslinya dari MySQL
            logx("❌ GAGAL SIMPAN DB (SQL Error): " . $stmt->error);
        }
        $stmt->close();
    } catch (Throwable $e) {
        logx("❌ GAGAL SIMPAN DB (Exception): " . $e->getMessage());
    }
}

// 7. AUTO-REPLY ENGINE
$autoReplyStatus = 'skipped';
$engineFile = $baseDir . '/auto_reply_engine.php';

if (!file_exists($engineFile)) {
    logx("AUTO REPLY ENGINE FILE NOT FOUND");
} else {
    // Membaca token dari config.php (Otomatis mendeteksi bentuk Define atau Variabel)
    $apiUrl   = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : ($ONESENDER_API_URL ?? null);
    $apiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : ($ONESENDER_API_TOKEN ?? null);

    if (empty($apiUrl) || empty($apiToken)) {
        logx("ERROR: ONESENDER_API_URL atau ONESENDER_API_TOKEN tidak disetting di config.php");
    } else {
        logx("Menjalankan Auto Reply ke URL: " . $apiUrl);
        try {
            require_once $engineFile;
            $autoReply = new AutoReplyEngine($conn, $apiUrl, $apiToken, $baseDir . '/auto_reply_log.txt');
            // PERBAIKAN: Sisipkan variabel $senderName agar Engine bisa menyapa nama user
            $sent = $autoReply->processIncomingMessage($senderPhone, $messageText, $senderName);
            $autoReplyStatus = $sent ? 'sent' : 'failed';
            logx("AUTO REPLY STATUS: {$autoReplyStatus}");
        } catch (Throwable $e) {
            logx("AUTO REPLY ERROR: " . $e->getMessage());
            $autoReplyStatus = 'error';
        }
    }
}

// 8. RESPONSE AKHIR KE ONESENDER
echo json_encode([
    'status' => 'success',
    'time'   => $timestamp,
    'data'   => [
        'phone'      => substr($senderPhone, 0, 4) . '***',
        'msg_length' => strlen($messageText),
        'saved_db'   => $savedToDB,
        'auto_reply' => $autoReplyStatus
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

logx("=== WEBHOOK COMPLETED ===\n");