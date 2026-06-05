<?php
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auto_reply_engine.php';

// Menangkap data mentah
$rawInput = file_get_contents('php://input');

// Log untuk debug
file_put_contents(__DIR__ . '/debug_webhook.log', "[" . date('Y-m-d H:i:s') . "] RAW DATA: " . $rawInput . PHP_EOL, FILE_APPEND);

$data = json_decode($rawInput, true);

if ($data && isset($data['sender_phone'])) {
    $senderPhone = $data['sender_phone'];
    $messageText = $data['message_text'] ?? '';

    // Proses Auto Reply
    $autoReply = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, __DIR__ . '/auto_reply_log.txt');
    $autoReply->processIncomingMessage($senderPhone, $messageText);
    
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'reason' => 'invalid_data']);
}