<?php
date_default_timezone_set('Asia/Jakarta');
$baseDir = __DIR__;
$logFile = $baseDir . '/webhook.log';

// 1. Ambil data mentah
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

// 2. Logging untuk Debugging (Cek file webhook.log untuk melihat data masuk)
file_put_contents($logFile, "=== WEBHOOK CALLED: " . date('Y-m-d H:i:s') . " ===\nRaw: {$rawInput}\n", FILE_APPEND);

if (!$data) exit('Invalid JSON');

// 3. Ekstraksi data
$senderPhone = trim($data['from_phone'] ?? $data['sender_phone'] ?? '');
$messageText = trim($data['message_text'] ?? '');
$senderName  = trim($data['from_name'] ?? 'Kak');

// 4. Validasi
if ($senderPhone !== '' && $messageText !== '') {
    require_once $baseDir . '/config.php';
    require_once $baseDir . '/auto_reply_engine.php';

    $engine = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, $baseDir . '/auto_reply_log.txt');
    
    // Kirim senderName agar placeholder {nama} berfungsi
    $engine->processIncomingMessage($senderPhone, $messageText, $senderName);
}
?>