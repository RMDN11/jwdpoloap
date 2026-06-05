<?php
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auto_reply_engine.php';

// Fungsi log untuk debugging
function logx($msg) {
    file_put_contents(__DIR__ . '/webhook_engine.log', "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND);
}

// 1. Ambil data mentah
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    logx("ERROR: Gagal decode JSON atau body kosong");
    exit;
}

// 2. Ekstrak data sesuai dokumentasi OneSender
$senderPhone = $data['sender_phone'] ?? '';
$messageText = $data['message_text'] ?? ''; // KUNCI UTAMA: Gunakan message_text
$senderName  = $data['sender_push_name'] ?? 'Unknown';

logx("PESAN MASUK: {$senderPhone} - Teks: {$messageText}");

// 3. Validasi
if (empty($senderPhone) || empty($messageText)) {
    logx("INVALID PAYLOAD: sender_phone atau message_text hilang");
    exit;
}

// 4. Jalankan Engine
if (defined('ONESENDER_API_URL') && defined('ONESENDER_API_TOKEN')) {
    try {
        $autoReply = new AutoReplyEngine($conn, ONESENDER_API_URL, ONESENDER_API_TOKEN, __DIR__ . '/auto_reply_log.txt');
        
        // Simpan ke log_wa (Pastikan tabel ini ada di DB Anda)
        $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param('sss', $senderPhone, $senderName, $messageText);
        $stmt->execute();
        
        // Proses Balasan
        $sent = $autoReply->processIncomingMessage($senderPhone, $messageText);
        logx("AUTO REPLY: " . ($sent ? "BERHASIL" : "GAGAL/TIDAK ADA MATCH"));
    } catch (Throwable $e) {
        logx("ERROR ENGINE: " . $e->getMessage());
    }
} else {
    logx("ERROR: API Config tidak ditemukan");
}