<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir   = __DIR__;
$logFile   = $baseDir . '/webhook.log';     
$debugFile = $baseDir . '/debug_super.log'; // File baru untuk menangkap semua bukti
$timestamp = date('Y-m-d H:i:s');

// 1. TANGKAP SEMUA DATA YANG MASUK APA PUN METODENYA
$method = $_SERVER['REQUEST_METHOD'];
$rawInput = file_get_contents('php://input');
$getParams = json_encode($_GET);
$postParams = json_encode($_POST);

$debugMsg = "=== REQUEST MASUK at {$timestamp} ===\n";
$debugMsg .= "METHOD : {$method}\n";
$debugMsg .= "GET    : {$getParams}\n";
$debugMsg .= "POST   : {$postParams}\n";
$debugMsg .= "BODY   : {$rawInput}\n";
$debugMsg .= "======================================\n\n";

file_put_contents($debugFile, $debugMsg, FILE_APPEND);

// 2. CEK POST ATAU GET
if ($method !== 'POST') {
    file_put_contents($logFile, "GET REQUEST at {$timestamp}\n", FILE_APPEND);
    echo json_encode(['status' => 'ok', 'note' => 'GET ignored']);
    exit;
}

function logx($msg) {
    global $logFile;
    file_put_contents($logFile, $msg . "\n", FILE_APPEND);
}

logx("=== WEBHOOK CALLED (POST) at {$timestamp} ===");
// ... (Lanjutkan sisa kode webhook.php kamu yang lama ke bawah mulai dari "Baca raw input")

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

// === EKSTRAK DATA SESUAI DOKUMENTASI ONESENDER ===
$senderPhone = trim($data['sender_phone'] ?? '');
$messageText = trim($data['message_text'] ?? '');
$senderName  = trim($data['from_name'] ?? 'Unknown');
$isFromMe    = !empty($data['is_from_me']) && $data['is_from_me'] === true;

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