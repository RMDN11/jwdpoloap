<?php
date_default_timezone_set('Asia/Jakarta');
$baseDir = __DIR__;
$logFile = $baseDir . '/webhook.log';

// Pastikan header merespon sebagai JSON
header('Content-Type: application/json');

// 1. Ambil data mentah
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

// 2. Logging
// Menggunakan @ agar tidak error fatal jika folder tidak writable
@file_put_contents($logFile, "=== WEBHOOK CALLED: " . date('Y-m-d H:i:s') . " ===\nRaw: {$rawInput}\n", FILE_APPEND);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON or Empty Payload']);
    exit;
}

// 3. Ekstraksi data (Mendukung format OneSender DAN trigger_webhook.php)
$senderPhone = trim($data['from_phone'] ?? $data['sender_phone'] ?? $data['from'] ?? '');
$messageText = trim($data['message_text'] ?? $data['message'] ?? '');
$senderName  = trim($data['from_name'] ?? $data['name'] ?? 'Kak');

// 4. Validasi
if ($senderPhone !== '' && $messageText !== '') {
    require_once $baseDir . '/config.php';
    require_once $baseDir . '/auto_reply_engine.php';

    $engine = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, $baseDir . '/auto_reply_log.txt');
    
    // Kirim senderName agar placeholder {nama} berfungsi
    $result = $engine->processIncomingMessage($senderPhone, $messageText, $senderName);
    
    // Berikan respon kembali agar trigger/API tahu statusnya
    echo json_encode([
        'status' => 'success', 
        'processed' => true, 
        'reply_sent' => $result
    ]);
} else {
    echo json_encode([
        'status' => 'ignored', 
        'message' => 'Payload missing phone or message text'
    ]);
}
?>