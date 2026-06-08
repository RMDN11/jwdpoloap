<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ignore_user_abort(true);
set_time_limit(0);

// LOG FILE UNTUK DEBUG
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
    writeLog("ERROR: Akses Ditolak - Token tidak valid atau tidak dikirim");
    die("Akses Ditolak.");
}

writeLog("Token valid - melanjutkan proses");

date_default_timezone_set('Asia/Jakarta');

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'] ?? 'domain-anda.com';
$script_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . $domain . $script_path; 

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
$batasMaksimal = 50; 

writeLog("Waktu eksekusi: Hari=$hariIni, Jam=$jamIni, Tanggal=$tanggalIni"); 

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($totalDiproses >= $batasMaksimal) break; 
        
        writeLog("Memproses ID jadwal: " . $row['id'] . ", Tipe: " . $row['tipe_jadwal'] . ", Grup: " . $row['id_grup']);
        
        // FILTER WAKTU
        if ($row['tipe_jadwal'] === 'sekali' && strtotime($row['jadwal_kirim']) > time()) {
            writeLog("SKIP (sekali): Jadwal belum tiba - " . $row['jadwal_kirim']);
            continue;
        }
        if ($row['tipe_jadwal'] === 'harian') {
            $hariRutin = explode(',', $row['hari_rutin']);
            if (!in_array($hariIni, $hariRutin)) {
                writeLog("SKIP (harian): Hari tidak cocok - Hari ini=$hariIni, Hari rutin=" . $row['hari_rutin']);
                continue;
            }
            if ($jamIni < substr($row['jam_harian'], 0, 5)) {
                writeLog("SKIP (harian): Jam belum tiba - Jam ini=$jamIni, Jadwal=" . $row['jam_harian']);
                continue;
            }
            if ($row['terakhir_dikirim'] === $tanggalIni) {
                writeLog("SKIP (harian): Sudah dikirim hari ini - " . $row['terakhir_dikirim']);
                continue;
            }
        }

        writeLog("Jadwal lolos filter waktu, menyiapkan pengiriman...");

        $curl = curl_init();

        if (!empty($row['media_path'])) {
            // =================================================================
            // LOGIKA GAMBAR (100% MENIRU "KIRIM SEKARANG" kirimgrup.php)
            // =================================================================
            $imagePathLocal = __DIR__ . '/' . ltrim($row['media_path'], '/');
            
            if (file_exists($imagePathLocal)) {
                // Trik 1: Ubah Endpoint
                $mediaApiUrl = (strpos($apiUrl, '/v1/messages') !== false) ? str_replace('/v1/messages', '/message', $apiUrl) : rtrim($apiUrl, '/') . '/message';
                
                $mimeType = mime_content_type($imagePathLocal);
                $cfile = new CURLFile($imagePathLocal, $mimeType, basename($imagePathLocal));
                
                // Trik 2: Parameter diubah menjadi phone, message, attachment (Bukan JSON)
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
                    // Trik 3: Tanpa Content-Type JSON
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken],
                    CURLOPT_TIMEOUT => 30,
                ]);
            } else {
                $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
                continue;
            }
        } else {
            // =================================================================
            // LOGIKA TEKS MURNI (SESUAI DOKUMEN JSON ANDA)
            // =================================================================
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
                // Wajib pakai Content-Type JSON untuk teks
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiToken,
                    'Content-Type: application/json'      
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
        }

        // EKSEKUSI API
        writeLog("Mengirim request ke API: " . (empty($row['media_path']) ? 'TEXT' : 'IMAGE'));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        writeLog("Response API - HTTP Code: $httpcode, Error: " . ($err ?: 'none'));
        if ($err) writeLog("cURL Error detail: $err");
        writeLog("Response body: " . substr($response, 0, 200));

        // UPDATE STATUS
        if ($err || $httpcode >= 400) {
            writeLog("GAGAL mengirim - Update status ke 'failed'");
            $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
        } else {
            writeLog("BERHASIL mengirim - Update status");
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