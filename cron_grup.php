<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ignore_user_abort(true);
set_time_limit(0); // Meminta server jangan mematikan skrip

$secretToken = "jwd_secure_cron_2026"; 
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    die("Akses Ditolak.");
}

date_default_timezone_set('Asia/Jakarta');

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'] ?? 'domain-anda.com';
$script_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . $domain . $script_path; 

// 1. AMBIL SEMUA JADWAL YANG STATUS PENDING
$query = "SELECT * FROM jadwal_pesan_grup WHERE status = 'pending'";
$result = $conn->query($query);

if (!$result) {
    die("Error Database saat mengambil antrean.");
}

$hariIni = date('N'); // 1 = Senin, 7 = Minggu
$jamIni = date('H:i');
$tanggalIni = date('Y-m-d');
$antreanSiapKirim = [];

// 2. KUMPULKAN JADWAL YANG BENAR-BENAR SIAP DIKIRIM MENIT INI
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($row['tipe_jadwal'] === 'sekali') {
            if (strtotime($row['jadwal_kirim']) > time()) {
                continue; // Belum waktunya
            }
        } elseif ($row['tipe_jadwal'] === 'harian') {
            $hariRutin = explode(',', $row['hari_rutin']);
            if (!in_array($hariIni, $hariRutin)) continue;
            if ($jamIni < substr($row['jam_harian'], 0, 5)) continue;
            if ($row['terakhir_dikirim'] === $tanggalIni) continue;
        }
        
        // Lolos filter waktu, masukkan ke keranjang siap kirim
        $antreanSiapKirim[] = $row; 
    }
}

// 3. PROSES PENGIRIMAN DENGAN BATAS (BATCHING)
$batasKirimMaksimal = 15; // <-- MAKSIMAL KIRIM 15 GRUP PER MENIT (Sangat Aman)
$totalDiproses = 0;

if (count($antreanSiapKirim) > 0) {
    foreach ($antreanSiapKirim as $row) {
        
        // Jika sudah mencapai 15 grup di menit ini, HENTIKAN. Sisanya lanjut menit depan.
        if ($totalDiproses >= $batasKirimMaksimal) {
            break; 
        }

        if (!empty($row['media_path'])) {
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
                    CURLOPT_TIMEOUT => 20, // Timeout diturunkan jadi 20 detik agar lebih cepat responsnya
                ]);
            } else {
                $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
                continue; // Lanjut ke grup berikutnya
            }

        } else {
            $postData = [
                "recipient_type" => "group",
                "to" => $row['id_grup'],
                "type" => "text",
                "text" => ["body" => $row['pesan']]
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
                CURLOPT_TIMEOUT => 15,
            ]);
        }

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        // UPDATE STATUS DATABASE
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
    
    echo "Pengiriman dicicil: $totalDiproses antrean berhasil diproses di menit ini.";
} else {
    echo "Tidak ada antrean pesan grup saat ini.";
}
?>