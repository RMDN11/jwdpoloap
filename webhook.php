<?php
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auto_reply_engine.php';

// 1. Tangkap data mentah
$rawInput = file_get_contents('php://input');

// 2. Simpan untuk semakan (Sangat penting!)
// Fail ini akan muncul di folder yang sama dengan webhook.php
file_put_contents(__DIR__ . '/debug_raw_data.log', "[" . date('Y-m-d H:i:s') . "] RAW: " . $rawInput . PHP_EOL, FILE_APPEND);

// 3. Semak jika kosong
if (empty($rawInput)) {
    die("Error: No data received from OneSender");
}

// 4. Cuba decode
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON format. Last error: " . json_last_error_msg());
}

// 5. Jika berjaya, teruskan proses
$senderPhone = $data['sender_phone'] ?? '';
$messageText = $data['message_text'] ?? '';

if (!empty($senderPhone)) {
    $autoReply = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, __DIR__ . '/webhook_engine.log');
    $autoReply->processIncomingMessage($senderPhone, $messageText);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'reason' => 'missing_sender_phone']);
}
?>