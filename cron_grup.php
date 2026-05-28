<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Mencegah server memutus proses jika pengiriman butuh waktu agak lama
ignore_user_abort(true);
set_time_limit(0);

$secretToken = "jwd_secure_cron_2026"; 
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    die("Akses Ditolak.");
}

date_default_timezone_set('Asia/Jakarta');

// Ambil data yang statusnya pending dan waktunya sudah tiba atau lewat
$query = "SELECT * FROM jadwal_pesan_grup WHERE status = 'pending' AND jadwal_kirim <= NOW() LIMIT 5";
$result = $conn->query($query);

if (!$result) {
    file_put_contents('cron_error.log', "Error DB: " . $conn->error . "\n", FILE_APPEND);
    die("Error DB");
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        // 1. Siapkan Parameter ke OneSender
        $postData = array(
            'target' => $row['id_grup'], 
            'message' => $row['pesan']
        );

        // Jika tabel punya fitur kirim gambar/file
        if (!empty($row['media_path'])) {
             // Opsional: Sesuaikan dengan struktur form-data OneSender jika ada media
             // $postData['file'] = new CURLFile($row['media_path']); 
        }

        // 2. Eksekusi API via cURL
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30, // Timeout aman
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => array(
                'Authorization: ' . $apiToken // Memasukkan token sesuai config.php Anda
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        // 3. Update Status di Database sesuai hasil respon
        if ($err) {
            // Jika curl error, ubah jadi failed (opsional) atau biarkan pending
            $conn->query("UPDATE jadwal_pesan_grup SET status = 'failed' WHERE id = " . $row['id']);
            file_put_contents('cron_error.log', "CURL Error ID ".$row['id'].": " . $err . "\n", FILE_APPEND);
        } else {
            // Jika berhasil tereksekusi, update ke 'sent' (sesuai enum DB)
            $conn->query("UPDATE jadwal_pesan_grup SET status = 'sent' WHERE id = " . $row['id']);
        }
    }
    echo "Pengiriman antrean selesai diproses.";
} else {
    echo "Tidak ada antrean pesan grup saat ini.";
}
?>