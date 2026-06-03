<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir   = __DIR__;
$logFile   = $baseDir . '/webhook.log';
$pingFile  = $baseDir . '/ping.log';
$debugFile = $baseDir . '/debug.log';
$timestamp = date('Y-m-d H:i:s');

// Selalu catat hit (untuk monitoring)
file_put_contents($pingFile, "{$timestamp} HIT\n", FILE_APPEND);

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $fullUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $logData = "=== GET REQUEST at {$timestamp} ===\nURL: {$fullUrl}\nGET: " . json_encode($_GET) . "\n\n";
    file_put_contents($logFile, $logData, FILE_APPEND);
    echo json_encode(['status' => 'ok', 'note' => 'GET ignored']);
    exit;
}

// Fungsi logging sederhana
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

// Simpan raw payload untuk debug
file_put_contents($debugFile, "[{$timestamp}]\n{$rawInput}\n\n", FILE_APPEND);

// Parse JSON
$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logx("JSON ERROR: " . json_last_error_msg());
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_json']);
    exit;
}

// ============================================================
// EKSTRAK DATA SESUAI DOKUMENTASI ONESENDER (INCOMING WEBHOOK)
// ============================================================
$senderPhone = trim($data['from_phone'] ?? '');
$messageText = trim($data['message_text'] ?? '');
$senderName  = trim($data['from_name'] ?? 'Unknown');
$isFromMe    = !empty($data['from_me']) && $data['from_me'] === true;

// Abaikan pesan dari diri sendiri (biar tidak loop)
if ($isFromMe) {
    logx("MESSAGE FROM SELF — SKIPPED");
    echo json_encode(['status' => 'ignored', 'reason' => 'from_self']);
    exit;
}

// Validasi payload minimal
if ($senderPhone === '' || $messageText === '') {
    logx("INVALID PAYLOAD — from_phone or message_text missing");
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_payload']);
    exit;
}

// Normalisasi nomor (hanya digit, dan jika mulai 0 ganti 62)
$senderPhone = preg_replace('/\D/', '', $senderPhone);
if (substr($senderPhone, 0, 1) === '0') {
    $senderPhone = '62' . substr($senderPhone, 1);
}

logx("PHONE: {$senderPhone} | NAME: {$senderName} | MESSAGE: " . substr($messageText, 0, 100));

// ------------------------------------------------------------
// KONEKSI DATABASE (config.php harus menyediakan $conn)
// ------------------------------------------------------------
require_once $baseDir . '/config.php';

$dbConnected = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $dbConnected = true;
    logx("DB CONNECTED: YES");
} else {
    logx("DB CONNECTED: NO");
}

// Simpan ke tabel log_wa (opsional, jika ada)
$savedToDB = false;
if ($dbConnected) {
    try {
        $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssss', $senderPhone, $senderName, $messageText, $timestamp);
            if ($stmt->execute()) {
                $savedToDB = true;
                logx("SAVED TO log_wa");
            } else {
                logx("DB INSERT ERROR: " . $stmt->error);
            }
            $stmt->close();
        } else {
            logx("DB PREPARE ERROR: " . $conn->error);
        }
    } catch (Throwable $e) {
        logx("DB EXCEPTION: " . $e->getMessage());
    }
}

// ------------------------------------------------------------
// AUTO-REPLY ENGINE
// ------------------------------------------------------------
$autoReplyStatus = 'skipped';
$engineFile = $baseDir . '/auto_reply_engine.php';

// Cek apakah konstanta API sudah terdefinisi dengan benar
if (!defined('ONESENDER_API_URL') || !defined('ONESENDER_API_TOKEN')) {
    logx("ERROR: ONESENDER_API_URL or ONESENDER_API_TOKEN not defined in config.php");
} elseif (empty(ONESENDER_API_URL) || empty(ONESENDER_API_TOKEN)) {
    logx("ERROR: ONESENDER_API_URL or ONESENDER_API_TOKEN is empty");
} elseif (!file_exists($engineFile)) {
    logx("AUTO REPLY ENGINE FILE NOT FOUND: {$engineFile}");
} else {
    logx("API URL: " . ONESENDER_API_URL);
    logx("API Token: " . substr(ONESENDER_API_TOKEN, 0, 10) . '...');
    
    try {
        require_once $engineFile;
        if (!class_exists('AutoReplyEngine')) {
            throw new Exception("Class AutoReplyEngine not found in {$engineFile}");
        }
        
        $autoReply = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, $baseDir . '/auto_reply_log.txt');
        $sent = $autoReply->processIncomingMessage($senderPhone, $messageText);
        $autoReplyStatus = $sent ? 'sent' : 'failed';
        logx("AUTO REPLY: {$autoReplyStatus}");
    } catch (Throwable $e) {
        logx("AUTO REPLY ERROR: " . $e->getMessage());
        $autoReplyStatus = 'error';
    }
}

// Kirim response ke OneSender (bisa diabaikan, tapi tetap dikirim)
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