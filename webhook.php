<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir = __DIR__;
$logFile = $baseDir . '/webhook.log';

// Fungsi log simpel
function logx($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// 1. Abaikan jika bukan POST (Tetap kasih respon 200 agar server WA tidak retry)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

// 2. Baca data dari OneSender
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    logx("Invalid JSON received");
    http_response_code(200);
    echo json_encode(['status' => 'error', 'reason' => 'invalid_json']);
    exit;
}

// 3. Ekstrak data (Fokus ke format standar OneSender)
$phone   = $data['from'] ?? $data['sender_phone'] ?? $data['phone'] ?? '';
$message = $data['text']['body'] ?? $data['message_text'] ?? $data['message'] ?? '';
$isFromMe = $data['fromMe'] ?? $data['is_from_me'] ?? false;

// Normalisasi nomor (jadikan angka saja, hapus @c.us atau @s.whatsapp.net)
$phone = preg_replace('/\D/', '', $phone);

logx("INCOMING: Phone={$phone}, Msg=" . substr($message, 0, 50));

// 4. CEKAN PENTING: Jangan balas pesan dari diri sendiri (Mencegah Infinite Loop)
if ($isFromMe) {
    logx("SKIPPED: Pesan dari diri sendiri (Mencegah loop)");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'note' => 'skipped_self']);
    exit;
}

if (empty($phone) || empty($message)) {
    logx("SKIPPED: Data tidak lengkap");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'note' => 'incomplete_data']);
    exit;
}

// 5. Load Config dari Server (AMAN: Tidak ada hardcoded URL/Token di file ini)
require_once $baseDir . '/config.php';

// Validasi ketat: Jika config tidak ada, stop proses (Aman untuk GitHub public)
if (!isset($apiUrl) || !isset($apiToken) || empty($apiUrl) || empty($apiToken)) {
    logx("ERROR: \$apiUrl atau \$apiToken tidak ditemukan di config.php");
    http_response_code(200);
    echo json_encode(['status' => 'error', 'note' => 'missing_config']);
    exit;
}

// 6. Proses Auto Reply
require_once $baseDir . '/auto_reply_engine.php';

try {
    // Panggil engine untuk mencocokkan keyword dan mengirim
    $engine = new AutoReplyEngine($conn, $apiUrl, $apiToken, $baseDir . '/auto_reply_log.txt');
    $sent = $engine->processIncomingMessage($phone, $message);

    logx("RESULT: " . ($sent ? "BERHASIL DIKIRIM" : "TIDAK ADA RULE YANG COCOK / GAGAL"));

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'auto_reply' => $sent ? 'sent' : 'no_match'
    ]);

} catch (Exception $e) {
    logx("CRITICAL ERROR: " . $e->getMessage());
    http_response_code(200);
    echo json_encode(['status' => 'error', 'note' => 'exception']);
}