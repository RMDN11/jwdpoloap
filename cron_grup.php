<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ignore_user_abort(true);
set_time_limit(0);

$secretToken = "jwd_secure_cron_2026"; 
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    die("Akses Ditolak.");
}

date_default_timezone_set('Asia/Jakarta');

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'] ?? 'domain-anda.com';
$script_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . $domain . $script_path; 

// AMBIL JADWAL PENDING
$query = "SELECT * FROM jadwal_pesan_grup WHERE status = 'pending'";
$result = $conn->query($query);
if (!$result) die("Error Database.");

$hariIni = date('N'); 
$jamIni = date('H:i');
$tanggalIni = date('Y-m-d');
$totalDiproses = 0;
$batasMaksimal = 15; 

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($totalDiproses >= $batasMaksimal) break; 
        
        // FILTER WAKTU
        if ($row['tipe_jadwal'] === 'sekali' && strtotime($row['jadwal_kirim']) > time()) continue;
        if ($row['tipe_jadwal'] === 'harian') {
            $hariRutin = explode(',', $row['hari_rutin']);
            if (!in_array($hariIni, $hariRutin)) continue;
            if ($jamIni < substr($row['jam_harian'], 0, 5)) continue;
            if ($row['terakhir_dikirim'] === $tanggalIni) continue;
        }

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
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        // UPDATE STATUS
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
    echo "Pengiriman dicicil: $totalDiproses antrean diproses.";
} else {
    echo "Tidak ada antrean pesan grup saat ini.";
}
?>