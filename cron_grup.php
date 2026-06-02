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

// AMBIL SEMUA JADWAL YANG STATUS PENDING
$query = "SELECT * FROM jadwal_pesan_grup WHERE status = 'pending'";
$result = $conn->query($query);

if (!$result) {
    die("Error Database saat mengambil antrean.");
}

$hariIni = date('N'); // 1 = Senin, 7 = Minggu
$jamIni = date('H:i');
$tanggalIni = date('Y-m-d');

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        // --- LOGIKA FILTER WAKTU ---
        if ($row['tipe_jadwal'] === 'sekali') {
            if (strtotime($row['jadwal_kirim']) > time()) {
                continue; // Belum waktunya
            }
        } elseif ($row['tipe_jadwal'] === 'harian') {
            $hariRutin = explode(',', $row['hari_rutin']);
            
            // Cek apakah hari ini termasuk dalam jadwal dan sudah jamnya, dan belum terkirim hari ini
            if (!in_array($hariIni, $hariRutin)) continue;
            if ($jamIni < substr($row['jam_harian'], 0, 5)) continue;
            if ($row['terakhir_dikirim'] === $tanggalIni) continue;
        }
        // ---------------------------

        if (!empty($row['media_path'])) {
            // ==========================================
            // LOGIKA KIRIM GAMBAR (Sama dengan kirimgrup.php)
            // ==========================================
            $imagePathLocal = __DIR__ . '/' . ltrim($row['media_path'], '/');
            
            if (file_exists($imagePathLocal)) {
                $mediaApiUrl = $apiUrl;
                if (strpos($apiUrl, '/v1/messages') !== false) {
                    $mediaApiUrl = str_replace('/v1/messages', '/message', $apiUrl);
                } else {
                    $mediaApiUrl = rtrim($apiUrl, '/') . '/message';
                }

                $mimeType = mime_content_type($imagePathLocal);
                $cfile = new CURLFile($imagePathLocal, $mimeType, basename($imagePathLocal));
                
                $postData = [
                    'type' => 'image', 
                    'phone' => $row['id_grup'], 
                    'message' => $row['pesan'], 
                    'attachment' => $cfile
                ];
                
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $mediaApiUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken],
                    CURLOPT_TIMEOUT => 30,
                ]);
            } else {
                // Jika file fisik gambar hilang, lewati antrean ini
                $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
                continue;await new Promise(r => setTimeout(r, 300));
            }

        } else {
            // ==========================================
            // LOGIKA KIRIM TEKS MURNI (Pakai JSON)
            // ==========================================
            $postData = [
                "recipient_type" => "group",
                "to" => $row['id_grup'],
                "type" => "text",
                "text" => [
                    "body" => $row['pesan']
                ]
            ];
            
            $jsonData = json_encode($postData, JSON_UNESCAPED_UNICODE);

            $curl = curl_init();
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

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        // UPDATE DATABASE BERDASARKAN TIPE JADWAL
        if ($err || $httpcode >= 400) {
            // Jika gagal, hentikan statusnya (bisa dimodifikasi jika ingin re-try)
            $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
        } else {
            if ($row['tipe_jadwal'] === 'harian') {
                // Untuk harian, tandai sudah terkirim hari ini, biarkan status tetap pending
                $conn->query("UPDATE jadwal_pesan_grup SET terakhir_dikirim = '{$tanggalIni}' WHERE id = " . $row['id']);
            } else {
                // Untuk jadwal sekali, set status ke sent
                $conn->query("UPDATE jadwal_pesan_grup SET status = 'sent', terakhir_dikirim = '{$tanggalIni}' WHERE id = " . $row['id']);
            }
        }
    }
    echo "Pengiriman antrean selesai diproses.";
} else {
    echo "Tidak ada antrean pesan grup saat ini.";
}
?>