<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$baseDir = __DIR__;
$logFile = $baseDir . '/webhook.log';

function logx($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// 1. Validasi HTTP Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

// 2. Tangkap JSON
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(200);
    exit;
}

$phone   = $data['from'] ?? $data['sender_phone'] ?? $data['phone'] ?? '';
$message = $data['text']['body'] ?? $data['message_text'] ?? $data['message'] ?? '';
$isFromMe = $data['fromMe'] ?? $data['is_from_me'] ?? false;

// 3. Normalisasi & Validasi Dasar
if ($isFromMe || empty($phone) || empty($message)) {
    http_response_code(200);
    exit;
}

$phone = preg_replace('/\D/', '', $phone);
logx("INCOMING: Phone={$phone}, Msg=" . substr($message, 0, 50));

// ====================================================================
// 4. TRIGGER WORKER (MEMUTUS DEADLOCK SERVER)
// ====================================================================
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$bgUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/webhook_worker.php";

$ch = curl_init($bgUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['phone' => $phone, 'message' => $message]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

// Trik agar PHP cURL tidak menunggu respon dari worker (Bypass server buffering)
curl_setopt($ch, CURLOPT_NOSIGNAL, 1); 
curl_setopt($ch, CURLOPT_TIMEOUT_MS, 200); // Batas waktu hanyak 200ms
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_exec($ch);
curl_close($ch);

// 5. KEMBALIKAN HTTP 200 KE ONESENDER DENGAN INSTAN
http_response_code(200);
echo json_encode(['status' => 'success', 'note' => 'delegated_to_worker']);
exit;