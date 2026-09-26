<?php

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir   = __DIR__;
$allRequestLog = $baseDir . '/all_requests.log';
$logFile   = $baseDir . '/webhook.log';
$pingFile  = $baseDir . '/ping.log';
$debugFile = $baseDir . '/debug.log';
$timestamp = date('Y-m-d H:i:s');

$logEntry = "=== {$timestamp} ===
";
$logEntry .= "Method: " . $_SERVER['REQUEST_METHOD'] . "
";
$logEntry .= "IP: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown') . "
";
$logEntry .= "Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none') . "
";
$logEntry .= "Raw Input: " . file_get_contents('php://input') . "

";
file_put_contents($allRequestLog, $logEntry, FILE_APPEND);

function logx($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('H:i:s') . "] " . $msg . "
", FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents($pingFile, "{$timestamp} HIT (" . $_SERVER['REQUEST_METHOD'] . ") - REJECTED
", FILE_APPEND);
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed. Only POST is accepted.',
        'allowed_method' => 'POST'
    ]);
    exit;
}

logx("=== WEBHOOK CALLED (POST) ===");

$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    logx("EMPTY BODY - Dibatalkan");
    echo json_encode(['status' => 'ignored', 'reason' => 'empty_body']);
    exit;
}

file_put_contents($debugFile, "[$timestamp]
{$rawInput}

", FILE_APPEND);

$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logx("JSON ERROR: " . json_last_error_msg());
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_json']);
    exit;
}

$senderPhone = $data['sender_phone'] ?? $data['phone'] ?? $data['from'] ?? '';
$messageText = $data['message_text'] ?? $data['text'] ?? $data['message'] ?? '';
$externalMessageId = trim((string)($data['message_id'] ?? $data['messageId'] ?? $data['id'] ?? ''));

$senderPhone = trim($senderPhone);
$messageText = trim($messageText);
$senderName  = $data['from_name'] ?? $data['pushName'] ?? $data['name'] ?? '';

if (preg_match('/(?:nama saya|nama sy|perkenalkan nama saya)\s+([A-Za-z0-9]+)/i', $messageText, $matches)) {
    $extractedName = trim($matches[1]);
    if (!empty($extractedName)) {
        $senderName = ucfirst(strtolower($extractedName));
    }
}

if (empty($senderName) || htmlspecialchars($senderName) === 'Unknown') {
    $senderName = 'Kak';
}

if ($senderPhone === '' || $messageText === '') {
    logx("INVALID PAYLOAD — Nomor atau pesan tidak ditemukan dari Webhook.");
    logx("-> Terdeteksi: Phone='$senderPhone', Msg='$messageText'");
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_payload']);
    exit;
}

$senderPhone = preg_replace('/\D/', '', $senderPhone);

logx("PHONE: {$senderPhone}");
logx("NAME: {$senderName}");
logx("MESSAGE: " . substr($messageText, 0, 50) . "...");

require_once $baseDir . '/config.php';
require_once $baseDir . '/crm/config/chat.php';
require_once $baseDir . '/crm/config/chat-directory.php';
require_once $baseDir . '/crm/config/prospect.php';
require_once $baseDir . '/crm/config/chat-routing.php';

$dbConnected = isset($conn) && $conn instanceof mysqli && !$conn->connect_error;
logx("DB CONNECTED: " . ($dbConnected ? 'YES' : 'NO'));

$savedToDB = false;
$chatConversationId = null;
$chatMessageId = null;
$routingRoom = null;

if ($dbConnected) {
    try {
        $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssss', $senderPhone, $senderName, $messageText, $timestamp);
            if ($stmt->execute()) {
                $savedToDB = true;
                logx("✅ BERHASIL SIMPAN KE TABEL log_wa");
            } else {
                logx("❌ GAGAL SIMPAN DB (SQL Error): " . $stmt->error);
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        logx("❌ GAGAL SIMPAN DB (Exception): " . $e->getMessage());
    }
}

if ($dbConnected) {
    try {
        $chatMessageId = crmChatStoreMessage(
            $conn,
            $senderPhone,
            $senderName,
            $messageText,
            'in',
            'recipient',
            'webhook',
            $timestamp,
            $externalMessageId !== '' ? $externalMessageId : null
        );

        if ($chatMessageId !== null && $chatMessageId > 0) {
            $idStmt = $conn->prepare("SELECT id FROM crm_conversations WHERE nowa = ? LIMIT 1");
            if ($idStmt) {
                $idStmt->bind_param('s', $senderPhone);
                $idStmt->execute();
                $idRow = $idStmt->get_result()->fetch_assoc();
                $idStmt->close();
                $chatConversationId = $idRow ? (int)$idRow['id'] : null;
            }

            if ($chatConversationId) {
                $routing = crmChatRoutingEvaluateConversation($conn, $chatConversationId, $messageText);
                if ($routing) {
                    $routingRoom = (string)($routing['room'] ?? 'lainnya');
                    logx("CHAT ROUTING: {$routingRoom}");
                }
            }
        }
    } catch (Throwable $e) {
        logx("CHAT LAYER ERROR: " . $e->getMessage());
    }
}

$autoReplyStatus = 'skipped';
$engineFile = $baseDir . '/auto_reply_engine.php';

if (!file_exists($engineFile)) {
    logx("AUTO REPLY ENGINE FILE NOT FOUND");
} else {
    $apiUrl   = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : ($ONESENDER_API_URL ?? null);
    $apiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : ($ONESENDER_API_TOKEN ?? null);

    if (empty($apiUrl) || empty($apiToken)) {
        logx("ERROR: ONESENDER_API_URL atau ONESENDER_API_TOKEN tidak disetting di config.php");
    } else {
        logx("Menjalankan Auto Reply ke URL: " . $apiUrl);
        try {
            require_once $engineFile;
            $autoReply = new AutoReplyEngine($conn, $apiUrl, $apiToken, $baseDir . '/auto_reply_log.txt');
            $sent = $autoReply->processIncomingMessage($senderPhone, $messageText, $senderName);
            $autoReplyStatus = $sent ? 'sent' : 'failed';
            logx("AUTO REPLY STATUS: {$autoReplyStatus}");
        } catch (Throwable $e) {
            logx("AUTO REPLY ERROR: " . $e->getMessage());
            $autoReplyStatus = 'error';
        }
    }
}

echo json_encode([
    'status'  => 'success',
    'time'    => $timestamp,
    'data'    => [
        'phone'      => substr($senderPhone, 0, 4) . '***',
        'msg_length' => strlen($messageText),
        'saved_db'   => $savedToDB,
        'chat_saved' => $chatMessageId !== null && $chatMessageId !== 0,
        'room'       => $routingRoom,
        'auto_reply' => $autoReplyStatus
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

logx("=== WEBHOOK COMPLETED ===
");
