<?php

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir   = __DIR__;
require_once $baseDir . '/crm/lib/log-rotator.php';
$allRequestLog = $baseDir . '/all_requests.log';
$logFile   = $baseDir . '/webhook.log';
$pingFile  = $baseDir . '/ping.log';
$debugFile = $baseDir . '/debug.log';
$timestamp = date('Y-m-d H:i:s');

$logEntry = "=== {$timestamp} ===\n";
$logEntry .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$logEntry .= "IP: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
$logEntry .= "Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none') . "\n";
$logEntry .= "Raw Input: " . file_get_contents('php://input') . "\n\n";

crmAppendLog($allRequestLog, $logEntry);

function logx($msg) {
    global $logFile;
    crmAppendLog($logFile, "[" . date('H:i:s') . "] " . $msg . "\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crmAppendLog($pingFile, "{$timestamp} HIT (" . $_SERVER['REQUEST_METHOD'] . ") - REJECTED\n");
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

crmAppendLog($debugFile, "[$timestamp]\n{$rawInput}\n\n");

$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logx("JSON ERROR: " . json_last_error_msg());
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_json']);
    exit;
}

$senderPhone = $data['sender_phone'] ?? $data['phone'] ?? $data['from'] ?? '';
$messageText = $data['message_text'] ?? $data['text'] ?? $data['message'] ?? '';
$externalMessageId = trim((string)($data['message_id'] ?? $data['messageId'] ?? $data['id'] ?? ''));
$isFromMe = !empty($data['is_from_me']);
$isGroup = !empty($data['is_group']);
$rawChatJid = trim((string)($data['chat'] ?? ''));
$rawSenderLid = trim((string)($data['sender_lid'] ?? ''));

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
require_once $baseDir . '/crm/config/automation-followup.php';

$dbConnected = isset($conn) && $conn instanceof mysqli && !$conn->connect_error;
logx("DB CONNECTED: " . ($dbConnected ? 'YES' : 'NO'));

$savedToDB = false;
$chatConversationId = null;
$chatMessageId = null;
$routingRoom = null;

if ($dbConnected) {
    // Simpan peta sender_lid -> nowa agar balasan admin HP ke @lid juga bisa terhubung ke thread prospek
    if (!$isFromMe && !$isGroup && $rawSenderLid !== '' && $senderPhone !== '') {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS crm_lid_map (
                lid VARCHAR(64) PRIMARY KEY,
                nowa VARCHAR(32) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $lidStmt = $conn->prepare("INSERT INTO crm_lid_map (lid, nowa) VALUES (?, ?) ON DUPLICATE KEY UPDATE nowa = VALUES(nowa)");
            if ($lidStmt) {
                $lidStmt->bind_param('ss', $rawSenderLid, $senderPhone);
                $lidStmt->execute();
                $lidStmt->close();
            }
        } catch (Throwable $e) {
            logx("LID MAP ERROR: " . $e->getMessage());
        }
    }

    // Jika pesan keluar dari admin HP (is_from_me = true & bukan grup), catat ke thread prospek di crm_messages
    if ($isFromMe && !$isGroup && $senderPhone === '248232064090227' && $rawChatJid !== '') {
        try {
            $targetPhone = '';
            if (str_ends_with($rawChatJid, '@s.whatsapp.net')) {
                $targetPhone = preg_replace('/\D/', '', str_replace('@s.whatsapp.net', '', $rawChatJid));
            } elseif (str_ends_with($rawChatJid, '@lid')) {
                $lidLookup = $conn->prepare("SELECT nowa FROM crm_lid_map WHERE lid = ? LIMIT 1");
                if ($lidLookup) {
                    $lidLookup->bind_param('s', $rawChatJid);
                    $lidLookup->execute();
                    $lidRow = $lidLookup->get_result()->fetch_assoc();
                    $lidLookup->close();
                    if ($lidRow) {
                        $targetPhone = (string)$lidRow['nowa'];
                    }
                }
            }

            if ($targetPhone !== '' && !crmAutomationIsOperationalNumber($targetPhone)) {
                $adminMsgId = crmChatStoreMessage(
                    $conn,
                    $targetPhone,
                    '',
                    $messageText,
                    'out',
                    'admin',
                    'wa_hp',
                    $timestamp,
                    $externalMessageId !== '' ? $externalMessageId : null
                );
                logx("ADMIN HP OUTBOUND LOGGED TO PROSPECT {$targetPhone}: msg_id=" . (string)$adminMsgId);
                if (function_exists('crmChatRoutingIsPaymentMessage') && crmChatRoutingIsPaymentMessage($messageText)) {
                    $convStmt = $conn->prepare("SELECT id FROM crm_conversations WHERE nowa = ? LIMIT 1");
                    if ($convStmt) {
                        $convStmt->bind_param('s', $targetPhone);
                        $convStmt->execute();
                        $cRow = $convStmt->get_result()->fetch_assoc();
                        $convStmt->close();
                        if ($cRow) {
                            crmChatRoutingEvaluateConversation($conn, (int)$cRow['id'], $messageText);
                        }
                    }
                    crmAutomationSetFinanceStatus($conn, $targetPhone, 'paid');
                }
            }
        } catch (Throwable $e) {
            logx("ADMIN HP OUTBOUND ERROR: " . $e->getMessage());
        }
    }

    if (!$isFromMe) {
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
}

if ($dbConnected && !$isFromMe) {
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
$isTeacherContact = false;
if ($dbConnected) {
    try {
        $isTeacherContact = crmChatIsTeacherNumber($conn, $senderPhone);
    } catch (Throwable $e) {
        logx("TEACHER DIRECTORY CHECK ERROR: " . $e->getMessage());
    }
}

if ($isFromMe) {
    $autoReplyStatus = 'skipped_from_me';
    logx("AUTO REPLY/TRIGGER SKIPPED: outbound echo (is_from_me)");
} elseif ($isTeacherContact) {
    // Keep raw inbound message in log_wa/crm_messages, but never run prospect/auto-reply triggers for teachers.
    $autoReplyStatus = 'skipped_teacher';
    logx("AUTO REPLY/TRIGGER SKIPPED: known teacher contact");
} else {
    // 1. Eksekusi Automasi Follow-Up Real-Time jika pesan cocok dengan pola pemicu pendaftaran
    $autoFollowupStatus = 'skipped';
    if ($dbConnected && function_exists('crmAutomationHandleIncomingWebhook')) {
        try {
            $followupResult = crmAutomationHandleIncomingWebhook($conn, $senderPhone, $messageText, $senderName);
            $autoFollowupStatus = (string)($followupResult['status'] ?? 'skipped');
            logx("AUTO FOLLOWUP REALTIME: {$autoFollowupStatus} (Reason: " . ($followupResult['reason'] ?? '') . ", Count: " . ($followupResult['count'] ?? 0) . ")");
        } catch (Throwable $e) {
            logx("AUTO FOLLOWUP REALTIME ERROR: " . $e->getMessage());
            $autoFollowupStatus = 'error';
        }
    }

    // 2. Eksekusi Auto Reply Pesan Instan
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
}

echo json_encode([
    'status'  => 'success',
    'time'    => $timestamp,
    'data'    => [
        'phone'      => substr($senderPhone, 0, 4) . '***',
        'msg_length' => strlen($messageText),
        'saved_db'   => $savedToDB,
        'chat_saved' => $chatMessageId !== null && $chatMessageId !== 0,
        'room'          => $routingRoom,
        'auto_reply'    => $autoReplyStatus,
        'auto_followup' => $autoFollowupStatus ?? 'skipped'
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

logx("=== WEBHOOK COMPLETED ===
");
