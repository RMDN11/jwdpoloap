<?php

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir   = __DIR__;
$logFile   = $baseDir . '/webhook.log';
$pingFile  = $baseDir . '/ping.log';
$debugFile = $baseDir . '/debug.log';
$timestamp = date('Y-m-d H:i:s');

// PING setiap hit
file_put_contents($pingFile, "{$timestamp} {$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']}\n", FILE_APPEND);

// Hanya proses POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $fullUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $logData = "=== GET REQUEST at {$timestamp} ===\nURL: {$fullUrl}\nGET: " . json_encode($_GET) . "\n\n";
    file_put_contents($logFile, $logData, FILE_APPEND);
    echo json_encode(['status' => 'ok', 'note' => 'Method not allowed, use POST']);
    exit;
}

function logx($msg) {
    global $logFile;
    file_put_contents($logFile, $msg . "\n", FILE_APPEND);
}

logx("=== WEBHOOK CALLED (POST) at {$timestamp} ===");

$rawInput = file_get_contents('php://input');
file_put_contents($debugFile, "[$timestamp]\n{$rawInput}\n\n", FILE_APPEND);

$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logx("JSON ERROR: " . json_last_error_msg());
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_json']);
    exit;
}

// Ekstrak sesuai dokumentasi OneSender
$senderPhone = trim($data['from_phone'] ?? $data['sender_phone'] ?? '');
$messageText = trim($data['message_text'] ?? '');
$senderName  = trim($data['from_name'] ?? 'Unknown');
$isFromMe    = !empty($data['from_me']) || !empty($data['is_from_me']);

if ($isFromMe) {
    logx("MESSAGE FROM SELF – SKIPPED");
    echo json_encode(['status' => 'ignored', 'reason' => 'from_self']);
    exit;
}

if ($senderPhone === '' || $messageText === '') {
    logx("INVALID PAYLOAD – missing from_phone or message_text");
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_payload']);
    exit;
}

$senderPhone = preg_replace('/\D/', '', $senderPhone);
if (substr($senderPhone, 0, 1) === '0') {
    $senderPhone = '62' . substr($senderPhone, 1);
}
logx("PHONE: {$senderPhone} | NAME: {$senderName} | MESSAGE: " . substr($messageText, 0, 100));

require_once $baseDir . '/config.php';

// Cek konstanta API
if (!defined('ONESENDER_API_URL') || !defined('ONESENDER_API_TOKEN')) {
    logx("CRITICAL: ONESENDER_API_URL or ONESENDER_API_TOKEN not defined in config.php");
    echo json_encode(['status' => 'error', 'reason' => 'config_missing']);
    exit;
}
if (empty(ONESENDER_API_URL) || empty(ONESENDER_API_TOKEN)) {
    logx("CRITICAL: ONESENDER_API_URL or ONESENDER_API_TOKEN is empty");
    echo json_encode(['status' => 'error', 'reason' => 'config_empty']);
    exit;
}

logx("API URL: " . ONESENDER_API_URL);
logx("API Token: " . substr(ONESENDER_API_TOKEN, 0, 10) . '...');

// Koneksi DB
$dbConnected = isset($conn) && $conn instanceof mysqli && !$conn->connect_error;
$savedToDB = false;
if ($dbConnected) {
    try {
        $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $senderPhone, $senderName, $messageText, $timestamp);
        if ($stmt->execute()) $savedToDB = true;
        $stmt->close();
        logx("SAVED TO log_wa");
    } catch (Throwable $e) {
        logx("DB ERROR: " . $e->getMessage());
    }
} else {
    logx("DB NOT CONNECTED");
}

// Auto-reply
$autoReplyStatus = 'skipped';
$engineFile = $baseDir . '/auto_reply_engine.php';

if (!file_exists($engineFile)) {
    logx("AUTO REPLY ENGINE FILE NOT FOUND: {$engineFile}");
} else {
    try {
        require_once $engineFile;
        if (!class_exists('AutoReplyEngine')) {
            throw new Exception("Class AutoReplyEngine not found");
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