<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ignore_user_abort(true);
set_time_limit(0);

$logFile = __DIR__ . '/cron_debug.log';
function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

writeLog("=== CRON DIMULAI ===");

$secretToken = "jwd_secure_cron_2026"; 
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    writeLog("ERROR: Akses Ditolak - Token tidak valid");
    die("Akses Ditolak.");
}

writeLog("Token valid - melanjutkan proses");

date_default_timezone_set('Asia/Jakarta');

// AMBIL JADWAL PENDING
writeLog("Mengecek jadwal_pesan_grup dengan status pending");
$query = "SELECT * FROM jadwal_pesan_grup WHERE status = 'pending'";
$result = $conn->query($query);
if (!$result) {
    writeLog("ERROR: Query database gagal - " . $conn->error);
    die("Error Database.");
}
writeLog("Ditemukan " . $result->num_rows . " jadwal pending");

$hariIni = date('N'); 
$jamIni = date('H:i');
$tanggalIni = date('Y-m-d');
$totalDiproses = 0;

// --- BATAS MAKSIMAL DITURUNKAN AGAR TIDAK TIMEOUT ---
// Ubah dari 50 menjadi 5 per eksekusi
$batasMaksimal = 5; 

writeLog("Waktu eksekusi: Hari=$hariIni, Jam=$jamIni, Tanggal=$tanggalIni"); 

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($totalDiproses >= $batasMaksimal) break; 
        
        writeLog("Memproses ID: " . $row['id'] . " | Grup: " . $row['id_grup']);
        
        // FILTER WAKTU
        if ($row['tipe_jadwal'] === 'sekali' && strtotime($row['jadwal_kirim']) > time()) {
            continue;
        }
        if ($row['tipe_jadwal'] === 'harian') {
            $hariRutin = explode(',', $row['hari_rutin']);
            if (!in_array($hariIni, $hariRutin)) continue;
            if ($jamIni < substr($row['jam_harian'], 0, 5)) continue;
            if ($row['terakhir_dikirim'] === $tanggalIni) continue;
        }

        $curl = curl_init();

        if (!empty($row['media_path'])) {
            $imagePathLocal = __DIR__ . '/' . ltrim($row['media_path'], '/');
            
            if (file_exists($imagePathLocal)) {
                $mediaApiUrl = (strpos($apiUrl, '/v1/messages') !== false) ? str_replace('/v1/messages', '/message', $apiUrl) : rtrim($apiUrl, '/') . '/message';
                $mimeType = mime_content_type($imagePathLocal);
                $cfile = new CURLFile($imagePathLocal, $mimeType, basename($imagePathLocal));
                
                $postData = [
                    'type' => 'image', 
                    'phone' => $row['id_grup'], 
                    'message' => $row['pesan'], 
                    'attachment' => $cfile
                ];
                
                curl_setopt_array($curl, [
                    CURLOPT_URL => $mediaApiUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken],
                    CURLOPT_TIMEOUT => 30,
                ]);
            } else {
                $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
                continue;
            }
        } else {
            $postData = [
                "recipient_type" => "group",
                "to" => $row['id_grup'],
                "type" => "text",
                "text" => ["body" => $row['pesan']]
            ];
            
            $jsonData = json_encode($postData, JSON_UNESCAPED_UNICODE);
            curl_setopt_array($curl, [
                CURLOPT_URL => $apiUrl, 
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonData, 
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiToken,
                    'Content-Type: application/json'      
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
        }

        // EKSEKUSI API
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        if ($err || $httpcode >= 400) {
            $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
        } else {
            if ($row['tipe_jadwal'] === 'harian') {
                $conn->query("UPDATE jadwal_pesan_grup SET terakhir_dikirim = '{$tanggalIni}' WHERE id = " . $row['id']);
            } else {
                $conn->query("UPDATE jadwal_pesan_grup SET status = 'sent', terakhir_dikirim = '{$tanggalIni}' WHERE id = " . $row['id']);
            }
        }
        $totalDiproses++;
    }
    writeLog("=== CRON SELESAI - Total diproses: $totalDiproses ===");
    echo "Pengiriman dicicil: $totalDiproses antrean diproses.";
} else {
    writeLog("=== CRON SELESAI - Tidak ada antrean ===");
    echo "Tidak ada antrean pesan grup saat ini.";
}
?>