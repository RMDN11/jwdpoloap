<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Mencegah server memutus proses jika pengiriman butuh waktu agak lama
ignore_user_abort(true);
set_time_limit(0);

$secretToken = "jwd_secure_cron_2026"; 
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    die("Akses Ditolak. Pastikan URL Cron Job membawa parameter ?token=...");
}

date_default_timezone_set('Asia/Jakarta');

// =========================================================================
// FITUR PENDETEKSI DOMAIN OTOMATIS
// Mengubah file hasil upload lokal (misal: uploads/foto.png) 
// menjadi URL publik (misal: https://jawwada.com/uploads/foto.png)
// =========================================================================
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'] ?? 'domain-anda.com';
$script_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . $domain . $script_path; 
// =========================================================================

// Ambil data yang statusnya pending dan waktunya sudah tiba atau lewat
$query = "SELECT * FROM jadwal_pesan_grup WHERE status = 'pending' AND jadwal_kirim <= NOW() LIMIT 5";
$result = $conn->query($query);

if (!$result) {
    file_put_contents('cron_error.log', "Error DB: " . $conn->error . "\n", FILE_APPEND);
    die("Error Database saat mengambil antrean.");
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        // 1. LOGIKA GAMBAR VS TEKS
        if (!empty($row['media_path'])) {
            
            // JIKA ADA GAMBAR HASIL UPLOAD
            // Gabungkan URL Domain web Anda dengan lokasi file gambar
            $fullImageUrl = $baseUrl . ltrim($row['media_path'], '/');
            
            $postData = [
                "recipient_type" => "group",
                "to" => $row['id_grup'],
                "type" => "image",
                "image" => [
                    "link" => $fullImageUrl,     // Menggunakan 'link' alih-alih 'id'
                    "caption" => $row['pesan']   // Pesan teks dijadikan caption di bawah gambar
                ]
            ];
            
        } else {
            
            // JIKA TIDAK ADA GAMBAR (Hanya teks biasa)
            $postData = [
                "recipient_type" => "group",
                "to" => $row['id_grup'],
                "type" => "text",
                "text" => [
                    "body" => $row['pesan']
                ]
            ];
            
        }

        // Ubah array PHP menjadi format JSON string
        $jsonData = json_encode($postData);

        // 2. Eksekusi API via cURL
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $apiUrl, 
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30, 
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData, 
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'      
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        // 3. Update Status di Database sesuai hasil respon
        if ($err || $httpcode >= 400) {
            $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
            file_put_contents('cron_error.log', "ID: ".$row['id']." | Error: $err | HTTP: $httpcode | Payload: $jsonData | Resp: $response\n", FILE_APPEND);
        } else {
            $conn->query("UPDATE jadwal_pesan_grup SET status = 'sent' WHERE id = " . $row['id']);
        }
    }
    echo "Pengiriman antrean selesai diproses.";
} else {
    echo "Tidak ada antrean pesan grup saat ini.";
}
?>