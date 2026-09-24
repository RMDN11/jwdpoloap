<?php
session_start();
require_once 'config.php'; // Memastikan config.php di-load

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    die("Akses ditolak: Gunakan method POST.");
}

$contactId = $_POST['contact_id'] ?? '';
$pesan = $_POST['pesan'] ?? '';
$fromDate = $_POST['from_date'] ?? null;
$toDate = $_POST['to_date'] ?? null;

if (empty($contactId)) {
    http_response_code(400);
    die("Error: Nomor kontak tidak boleh kosong.");
}

// --- Ambil Konfigurasi dari config.php ---
global $apiUrl, $apiToken;
if (!isset($apiUrl) || !isset($apiToken)) {
    error_log("Error: API URL atau Token tidak ditemukan di config.php.");
    http_response_code(500);
    die("Error Internal: Konfigurasi API tidak ditemukan.");
}

// --- Persiapkan Data untuk API (Format WABA seperti kelola_reminder.php) ---
$template = !empty($pesan) ? $pesan : "Assalamu’alaikum Kak 🙌\nKami cek Kak belum melanjutkan proses pendaftaran Tahfidz Privat Jawwada.\nMasih ingin kami bantu prosesnya?";

// Normalisasi nomor (ganti 0 di depan dengan 62, hapus + di awal)
$normalizedPhone = $contactId;
if (substr($contactId, 0, 1) === '0') {
    $normalizedPhone = '62' . substr($contactId, 1);
} elseif (substr($contactId, 0, 1) === '+') {
    $normalizedPhone = substr($contactId, 1);
}

// Data yang akan dikirim dalam format JSON (WABA)
$postData = [
    "recipient_type" => "individual", // WABA format
    "to" => $normalizedPhone,         // WABA format
    "type" => "text",                 // WABA format
    "text" => [                      // WABA format
        "body" => $template          // WABA format
    ]
];

$jsonData = json_encode($postData);

// --- Setup dan Kirim Request ke API ---
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl); // Gunakan URL dari config.php
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiToken" // Gunakan Token dari config.php
]);
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Hanya untuk debugging jika SSL bermasalah

// --- Debug: Log Request ---
error_log("WABA API Request - START - Target: $normalizedPhone");
error_log("WABA API Request - URL: $apiUrl");
error_log("WABA API Request - Headers: Authorization: Bearer [HIDDEN], Content-Type: application/json");
error_log("WABA API Request - Body: $jsonData");

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// --- Debug: Log Response ---
error_log("WABA API Response - END - Target: $normalizedPhone");
error_log("WABA API Response - HTTP Code: $httpCode");
error_log("WABA API Response - cURL Error Code: $curlErrno");
error_log("WABA API Response - cURL Error Message: $curlError");
error_log("WABA API Response - Raw Response Body: " . ($response === false ? 'false' : $response));

// --- Evaluasi Respon API (Mirip dengan fungsi kirimPesan di kelola_reminder.php) ---
$success = false;
$errorMessage = "";

if ($curlError) {
    $errorMessage = "cURL Error: " . $curlError;
    error_log("WABA cURL Error: $errorMessage for $normalizedPhone");
} else {
    $responseData = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        // Jika respons JSON valid dan statusnya sukses (tergantung struktur API gateway Anda)
        // Misalnya, jika API mengembalikan {"success": true, ...}
        // Atau jika HTTP 200/201 dianggap sukses
        // Kita ikuti logika dasar dari kelola_reminder.php
        // Jika tidak ada error dari curl dan HTTP OK, asumsikan sukses
        $success = true;
        error_log("WABA API Success (HTTP $httpCode) for $normalizedPhone: $template");
        // Anda bisa tambahkan pengecekan lebih lanjut terhadap $responseData jika gateway Anda memberikan indikator sukses spesifik di sana.
    } else {
        // Jika HTTP Code bukan 2xx
        $errorMessage = isset($responseData['message']) ? $responseData['message'] : "Unknown error. HTTP Code: " . $httpCode . " Response: " . $response;
        error_log("WABA API Error (HTTP $httpCode): $errorMessage for $normalizedPhone");
    }
}

// --- Redirect atau Tampilkan Error ---
if ($success) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $dashboardUrl = 'analitikjwd.php';
        $params = [];
        if ($fromDate) $params['from'] = $fromDate;
        if ($toDate) $params['to'] = $toDate;
        $redirectUrl = $dashboardUrl . '?' . http_build_query($params);
        header("Location: $redirectUrl", true, 302);
        exit;
    } else {
        // Mode debug: tampilkan pesan sukses
        echo "<p style='color:green;'>✅ Reminder berhasil dikirim ke <strong>" . htmlspecialchars($normalizedPhone) . "</strong> (Debug Mode).</p>";
    }
} else {
    // Gagal mengirim
    http_response_code(500); // Atau 400/422 tergantung error dari API
    echo "<p style='color:red;'>❌ Gagal mengirim reminder ke <strong>" . htmlspecialchars($normalizedPhone) . "</strong>.<br> Error: " . htmlspecialchars($errorMessage) . "</p>";
    // Atau redirect ke dashboard dengan parameter error (opsional)
    // $dashboardUrl = 'analitikjwd.php';
    // $params = ['error' => urlencode($errorMessage)];
    // if ($fromDate) $params['from'] = $fromDate;
    // if ($toDate) $params['to'] = $toDate;
    // $redirectUrl = $dashboardUrl . '?' . http_build_query($params);
    // header("Location: $redirectUrl", true, 302);
    // exit;
}

?>