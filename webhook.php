<?php

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir   = __DIR__;
$logFile   = $baseDir . '/webhook.log';     // Tambah ini
$pingFile  = $baseDir . '/ping.log';        // Tambah ini  
$debugFile = $baseDir . '/debug.log';       // Tambah ini
$timestamp = date('Y-m-d H:i:s');

$timestamp = date('Y-m-d H:i:s');

// PING — selalu dijalankan
file_put_contents($pingFile, "{$timestamp} HIT\n", FILE_APPEND);

// Hanya proses POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents($logFile, "GET REQUEST at {$timestamp}\n", FILE_APPEND);
    echo json_encode(['status' => 'ok', 'note' => 'GET ignored']);
    exit;
}

function logx($msg) {
    global $logFile;
    file_put_contents($logFile, $msg . "\n", FILE_APPEND);
}

logx("=== WEBHOOK CALLED (POST) at {$timestamp} ===");

// Baca raw input
$rawInput = file_get_contents('php://input');
$inputLen = strlen($rawInput);

logx("RAW INPUT LENGTH: {$inputLen}");

if ($inputLen === 0) {
    logx("EMPTY BODY");
    echo json_encode(['status' => 'ignored', 'reason' => 'empty_body']);
    exit;
}

// Simpan debug
file_put_contents($debugFile, "[$timestamp]\n{$rawInput}\n\n", FILE_APPEND);

// Parse JSON
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logx("JSON ERROR: " . json_last_error_msg());
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_json']);
    exit;
}

// === // === EKSTRAK DATA (FLEKSIBEL UNTUK SEMUA VERSI ONESENDER) ===
$senderPhone = trim($data['sender_phone'] ?? $data['from'] ?? $data['phone'] ?? $data['to'] ?? '');
$senderName  = trim($data['from_name'] ?? $data['pushname'] ?? $data['name'] ?? 'Unknown');

// Deteksi isi pesan dari berbagai kemungkinan letak array
$messageText = '';
if (isset($data['message_text'])) {
    $messageText = $data['message_text'];
} elseif (isset($data['text']['body'])) {
    $messageText = $data['text']['body'];
} elseif (isset($data['message'])) {
    $messageText = is_array($data['message']) ? ($data['message']['text'] ?? '') : $data['message'];
}
$messageText = trim($messageText);

$isFromMe = !empty($data['is_from_me']) || (isset($data['fromMe']) && $data['fromMe'] === true);
// =============================================================

// Abaikan pesan dari diri sendiri
if ($isFromMe) {
    logx("MESSAGE FROM SELF — SKIPPED");
    echo json_encode(['status' => 'ignored', 'reason' => 'from_self']);
    exit;
}

// Validasi minimal
if ($senderPhone === '' || $messageText === '') {
    logx("INVALID PAYLOAD — sender_phone or message_text missing");
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_payload']);
    exit;
}

// Normalisasi nomor (hapus non-digit)
$senderPhone = preg_replace('/\D/', '', $senderPhone);

logx("PHONE: {$senderPhone}");
logx("NAME: {$senderName}");
logx("MESSAGE: " . substr($messageText, 0, 100));

// Koneksi DB
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
            logx("SAVED TO log_wa");
        }
        $stmt->close();
    } catch (Throwable $e) {
        logx("DB ERROR: " . $e->getMessage());
    }
}

// Auto-reply
$autoReplyStatus = 'skipped';
$engineFile = $baseDir . '/auto_reply_engine.php';

// Pastikan file engine ada
if (!file_exists($engineFile)) {
    logx("AUTO REPLY ENGINE FILE NOT FOUND: " . $engineFile);
} else {
    // Pastikan variabel API ada di config.php
    if (!defined('ONESENDER_API_URL') || !defined('ONESENDER_API_TOKEN')) {
        logx("ERROR: ONESENDER_API_URL atau ONESENDER_API_TOKEN tidak ditemukan di config.php");
    } elseif (empty(ONESENDER_API_URL) || empty(ONESENDER_API_TOKEN)) {
        logx("ERROR: ONESENDER_API_URL atau ONESENDER_API_TOKEN kosong di config.php");
    } else {
        // HILANGKAN TANDA '$' KARENA INI ADALAH KONSTANTA
        logx("API URL: " . ONESENDER_API_URL);
        logx("API Token: " . substr(ONESENDER_API_TOKEN, 0, 10) . '...');

        try {
            require_once $engineFile;
            $autoReply = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, $baseDir . '/auto_reply_log.txt');
            $sent = $autoReply->processIncomingMessage($senderPhone, $messageText);
            $autoReplyStatus = $sent ? 'sent' : 'failed';
            logx("AUTO REPLY: {$autoReplyStatus}");
        } catch (Throwable $e) {
            logx("AUTO REPLY ERROR: " . $e->getMessage());
            $autoReplyStatus = 'error';
        }
    }
}

// Respons
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