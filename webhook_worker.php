<?php
// Mencegah script mati meskipun koneksi webhook terputus
ignore_user_abort(true);
set_time_limit(0);

$logFile = __DIR__ . '/webhook.log';
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] WORKER: Memulai proses dari background...\n", FILE_APPEND);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    exit;
}

$phone = $data['phone'] ?? '';
$message = $data['message'] ?? '';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auto_reply_engine.php';

try {
    $engine = new AutoReplyEngine($conn, $apiUrl, $apiToken, __DIR__ . '/auto_reply_log.txt');
    $engine->processIncomingMessage($phone, $message);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] WORKER: Eksekusi engine selesai.\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] WORKER ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}