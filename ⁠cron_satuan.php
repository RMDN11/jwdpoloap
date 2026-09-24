<?php
require_once 'config.php';

// Matikan error tampil di layar (agar respon web bersih), tapi tetap izinkan proses berjalan di latar belakang
error_reporting(E_ALL);
ini_set('display_errors', 0);
ignore_user_abort(true);

// Fungsi Logging
$logFile = __DIR__ . '/cron_satuan_debug.log';
function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// 1. Pengecekan Token Keamanan
$secretToken = "jwd_secure_cron_2026"; 
if (empty($_GET['token']) || $_GET['token'] !== $secretToken) {
    die("Akses Ditolak.");
}

writeLog("=== CRON SATUAN (OPTIMIZED) DIMULAI ===");

// 2. Batasi Eksekusi agar Nginx Tidak Timeout (Maksimal 5)
$limitAntrean = 5; 
$query = "SELECT id, nowa, nama, pesan FROM wa_queue_satuan WHERE status = 'pending' ORDER BY id ASC LIMIT $limitAntrean";
$result = $conn->query($query);

if (!$result) {
    writeLog("ERROR DB: " . $conn->error);
    die("Error DB");
}

$totalDiproses = 0;

if ($result->num_rows > 0) {
    writeLog("Memproses " . $result->num_rows . " antrean.");
    
    // Siapkan statement UPDATE di luar loop agar kinerja database (MariaDB/MySQL) jauh lebih ringan
    $stmt = $conn->prepare("UPDATE wa_queue_satuan SET status = ?, sent_at = NOW() WHERE id = ?");

    while ($row = $result->fetch_assoc()) {
        $curl = curl_init();
        $postData = [
            "recipient_type" => "individual",
            "to" => $row['nowa'],
            "type" => "text",
            "text" => ["body" => $row['pesan']]
        ];
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl, 
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'      
            ],
            CURLOPT_TIMEOUT => 15, // Timeout API dipercepat jadi 15 detik untuk mencegah antrean macet
        ]);
        
        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        $statusUpdate = ($httpcode >= 200 && $httpcode < 300) ? 'sent' : 'failed';
        
        // Eksekusi Update Status ke Database
        $stmt->bind_param("si", $statusUpdate, $row['id']);
        $stmt->execute();
        
        $totalDiproses++;
        
        // 3. Jeda Anti-Banned yang Aman untuk Nginx (Acak 2-3 detik per pesan)
        if ($totalDiproses < $result->num_rows) {
            sleep(rand(2, 3)); 
        }
    }
    $stmt->close();
    
    writeLog("=== SELESAI: $totalDiproses pesan diproses ===");
    echo "Sukses: $totalDiproses";
} else {
    echo "Kosong";
}
?>