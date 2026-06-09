<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ignore_user_abort(true);
set_time_limit(0); // Biarkan skrip berjalan sampai selesai

// LOG FILE UNTUK DEBUG
$logFile = __DIR__ . '/cron_satuan_debug.log';
function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

writeLog("=== CRON SATUAN DIMULAI ===");

// MENGGUNAKAN TOKEN YANG SAMA DENGAN CRON GRUP
$secretToken = "jwd_secure_cron_2026"; 
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    writeLog("ERROR: Akses Ditolak - Token tidak valid");
    die("Akses Ditolak.");
}

// Batasi 3 pesan per menit agar aman dari Banned (1 jam bisa kirim ~180 pesan santai)
$limitAntrean = 3; 

// Ambil pesan yang statusnya pending
$query = "SELECT * FROM wa_queue_satuan WHERE status = 'pending' ORDER BY id ASC LIMIT $limitAntrean";
$result = $conn->query($query);

if (!$result) {
    writeLog("ERROR: Database - " . $conn->error);
    die("Error Database.");
}

$totalDiproses = 0;

if ($result->num_rows > 0) {
    writeLog("Ditemukan " . $result->num_rows . " antrean pesan satuan.");
    
    while ($row = $result->fetch_assoc()) {
        writeLog("Mengirim ke: " . $row['nama'] . " (" . $row['nowa'] . ")");
        
        $curl = curl_init();
        $postData = [
            "recipient_type" => "individual",
            "to" => $row['nowa'],
            "type" => "text",
            "text" => ["body" => $row['pesan']]
        ];
        
        $jsonData = json_encode($postData, JSON_UNESCAPED_UNICODE);
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl, // Diambil dari config.php
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData, 
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'      
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        $statusUpdate = 'failed';
        if (!$err && $httpcode >= 200 && $httpcode < 300) {
            $statusUpdate = 'sent';
        } else {
            writeLog("Error cURL/HTTP: $err | Code: $httpcode");
        }
        
        // Update status di database
        $stmt = $conn->prepare("UPDATE wa_queue_satuan SET status = ?, sent_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $statusUpdate, $row['id']);
        $stmt->execute();
        $stmt->close();
        
        $totalDiproses++;
        
        // --- SISTEM JEDA ACAK ANTI-BANNED (10 sampai 15 Detik per pesan) ---
        // Jika ini BUKAN pesan terakhir dari loop, kita jeda dulu sebelum lanjut pesan berikutnya
        if ($totalDiproses < $result->num_rows) {
            $jeda = rand(10, 15); 
            writeLog("Jeda delay anti-banned: $jeda detik...");
            sleep($jeda); 
        }
    }
    
    writeLog("=== CRON SATUAN SELESAI. Total terkirim: $totalDiproses ===");
    echo "Sukses memproses $totalDiproses antrean satuan.";
} else {
    writeLog("=== CRON SATUAN SELESAI. Antrean kosong ===");
    echo "Tidak ada antrean pesan satuan.";
}
?>