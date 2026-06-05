<?php
// webhook.php - Solusi Fleksibel
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auto_reply_engine.php';

// Menangkap semua kemungkinan format kiriman dari OneSender
$rawInput = file_get_contents('php://input');

// Jika data kosong di php://input, coba ambil dari $_POST (jika dikirim sebagai form-data)
if (empty($rawInput)) {
    $data = $_POST;
} else {
    $data = json_decode($rawInput, true);
    // Jika gagal decode JSON, mungkin datanya berbentuk query string
    if (!$data) {
        parse_str($rawInput, $data);
    }
}

// Log untuk memastikan apa yang diterima server
file_put_contents(__DIR__ . '/debug_webhook.log', "[" . date('Y-m-d H:i:s') . "] Data: " . print_r($data, true) . PHP_EOL, FILE_APPEND);

if (empty($data)) {
    // Jika masih kosong, OneSender benar-benar belum mengirim data
    exit('No data received');
}

// Proses data (Mapping sesuai dokumentasi OneSender)
$senderPhone = $data['sender_phone'] ?? '';
$messageText = $data['message_text'] ?? '';

if (!empty($senderPhone) && !empty($messageText)) {
    $autoReply = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, __DIR__ . '/webhook_engine.log');
    $autoReply->processIncomingMessage($senderPhone, $messageText);
}
?>