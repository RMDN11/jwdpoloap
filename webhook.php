<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$logFile = __DIR__ . '/webhook_debug.log';

// TANGKAP SEMUA BUKTI
$rawInput = file_get_contents('php://input');
$method = $_SERVER['REQUEST_METHOD'];
$postData = json_encode($_POST);

file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] METHOD: {$method} | RAW INPUT: '{$rawInput}' | POST: '{$postData}'\n", FILE_APPEND);

// 2. CEK METODE POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ignored', 'reason' => 'not_post']);
    exit;
}

// 3. DECODE JSON
$data = json_decode($rawInput, true);
if (!$data && !empty($_POST)) {
    $data = $_POST; // Fallback jika format form-urlencoded
}

if (empty($data)) {
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: Data kosong / Gagal Decode JSON\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'reason' => 'no_data']);
    exit;
}

// 4. EKSTRAK DATA ONESENDER
$phone   = $data['from'] ?? $data['sender_phone'] ?? $data['phone'] ?? $data['sender'] ?? '';
$message = $data['text']['body'] ?? $data['message_text'] ?? $data['message'] ?? '';
$isFromMe = $data['fromMe'] ?? $data['is_from_me'] ?? false;

// Bersihkan nomor
$phone = preg_replace('/\D/', '', $phone);
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] PARSED: Phone={$phone}, Msg={$message}, FromMe=" . ($isFromMe ? 'true' : 'false') . "\n", FILE_APPEND);

// 5. VALIDASI DASAR
if ($isFromMe) {
    echo json_encode(['status' => 'skipped', 'reason' => 'from_me']);
    exit;
}
if (empty($phone) || empty($message)) {
    echo json_encode(['status' => 'skipped', 'reason' => 'empty_data']);
    exit;
}

// 6. PROSES AUTO REPLY (LANGSUNG)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auto_reply_engine.php';

try {
    $engine = new AutoReplyEngine($conn, $apiUrl, $apiToken, __DIR__ . '/auto_reply_debug.log');
    $sent = $engine->processIncomingMessage($phone, $message);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] RESULT: " . ($sent ? "SUCCESS" : "FAILED") . "\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] CRITICAL ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}

// 7. BERIKAN RESPON KE ONESENDER
echo json_encode(['status' => 'success']);
exit;