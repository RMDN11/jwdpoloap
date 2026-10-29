<?php
// =============================================
// FETCH_MESSAGES.PHP — FINAL STABLE VERSION
// =============================================
// Mengambil pesan keluar dari OneSender API dan simpan ke DB pesan_masuk
// =============================================

require_once 'config.php';

// --- CEK KONEKSI DATABASE ---
if (!$conn) {
    error_log("FetchMessages: Database connection invalid.");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// --- FUNGSI UTILITAS ---
function normalizePhone($phone) {
    $phone = trim($phone);
    if (substr($phone, 0, 1) === '0') return '62' . substr($phone, 1);
    if (substr($phone, 0, 3) === '+62') return '62' . substr($phone, 3);
    if (substr($phone, 0, 2) === '62') return $phone;
    return preg_replace('/[^0-9]/', '', $phone);
}

function convertTimestamp($apiTimestamp) {
    $formats = [
        'Y-m-d\TH:i:s.vP',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.vO',
        'Y-m-d\TH:i:sO'
    ];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $apiTimestamp);
        if ($dt !== false) return $dt->format('Y-m-d H:i:s');
    }
    error_log("FetchMessages: Invalid timestamp format => $apiTimestamp");
    return null;
}

// --- KONFIGURASI API ---
$apiUrlMessages = rtrim($apiUrl);
$headers = [
    'Authorization: Bearer ' . $apiToken,
    'Content-Type: application/json'
];

// --- AMBIL PESAN DARI API ---
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrlMessages,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log("FetchMessages: cURL error => $curlError");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'cURL error: ' . $curlError]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    error_log("FetchMessages: API error => HTTP $httpCode, response: $response");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => "API error: HTTP $httpCode"]);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['data']) || !is_array($data['data'])) {
    error_log("FetchMessages: Invalid API response structure");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Invalid API response structure']);
    exit;
}

$messages = $data['data'];
$totalMessages = count($messages);
$successCount = 0;
$skippedCount = 0;

// --- LOOP SIMPAN PESAN ---
foreach ($messages as $index => $message) {
    $messageId = $message['id'] ?? uniqid();
    $from = $message['phone'] ?? null;
    $body = $message['message_content'] ?? null;
    $timestamp_raw = $message['created_at'] ?? null;
    $senderName = 'SYSTEM';

    if (!$from || !$body || !$timestamp_raw) {
        error_log("FetchMessages[$index]: Missing fields — skipped");
        $skippedCount++;
        continue;
    }

    $senderPhone = normalizePhone($from);
    $messageText = trim($body);
    $timestamp_mysql = convertTimestamp($timestamp_raw);
    if (!$timestamp_mysql) {
        $skippedCount++;
        continue;
    }

    $statusDbValue = 0;

    // --- SQL FIX: Gunakan parameter binding untuk semua kolom ---
    $sql = "
        INSERT INTO pesan_masuk 
        (message_id, sender_phone, sender_push_name, message_text, timestamp, status_db)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            sender_phone = VALUES(sender_phone),
            sender_push_name = VALUES(sender_push_name),
            message_text = VALUES(message_text),
            timestamp = VALUES(timestamp),
            status_db = 0
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("FetchMessages[$index]: Prepare failed => " . $conn->error);
        $skippedCount++;
        continue;
    }

    $stmt->bind_param("sssssi", $messageId, $senderPhone, $senderName, $messageText, $timestamp_mysql, $statusDbValue);

    if ($stmt->execute()) {
        $successCount++;
    } else {
        error_log("FetchMessages[$index]: Execute failed => " . $stmt->error);
        $skippedCount++;
    }

    $stmt->close();
}

echo json_encode([
    'success' => true,
    'message' => 'FetchMessages completed successfully',
    'endpoint_used' => $apiUrlMessages,
    'total_found' => $totalMessages,
    'inserted_updated' => $successCount,
    'skipped' => $skippedCount
]);

error_log("FetchMessages: Completed. Success=$successCount, Skipped=$skippedCount, Total=$totalMessages");
?>
