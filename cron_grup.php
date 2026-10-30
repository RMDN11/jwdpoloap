<?php
require_once 'config.php';
require_once __DIR__ . '/crm/lib/log-rotator.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ignore_user_abort(true);
set_time_limit(0);

$logFile = __DIR__ . '/cron_debug.log';
function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    crmAppendLog($logFile, $logEntry);
}

writeLog("=== CRON DIMULAI ===");

function updateScheduleRow(mysqli $conn, int $id, string $sql, string $label): bool {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        writeLog("ERROR UPDATE jadwal ID $id ($label): prepare gagal - " . $conn->error);
        return false;
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    if (!$ok) {
        writeLog("ERROR UPDATE jadwal ID $id ($label): execute gagal - " . $stmt->error);
    } elseif ($stmt->affected_rows < 0) {
        writeLog("WARNING UPDATE jadwal ID $id ($label): affected_rows tidak valid");
    } else {
        writeLog("UPDATE jadwal ID $id ($label): berhasil, affected_rows=" . $stmt->affected_rows);
    }
    $stmt->close();
    return $ok;
}

function updateScheduleDateRow(mysqli $conn, int $id, string $date, string $sql, string $label): bool {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        writeLog("ERROR UPDATE jadwal ID $id ($label): prepare gagal - " . $conn->error);
        return false;
    }
    $stmt->bind_param('si', $date, $id);
    $ok = $stmt->execute();
    if (!$ok) {
        writeLog("ERROR UPDATE jadwal ID $id ($label): execute gagal - " . $stmt->error);
    } else {
        writeLog("UPDATE jadwal ID $id ($label): berhasil, affected_rows=" . $stmt->affected_rows);
    }
    $stmt->close();
    return $ok;
}

function uploadOneSenderMediaCron(string $localFilePath, string $apiUrl, string $apiToken): ?string {
    if (!file_exists($localFilePath)) return null;
    $mediaApiUrl = (strpos($apiUrl, '/v1/messages') !== false) 
        ? str_replace('/v1/messages', '/v1/media', $apiUrl) 
        : rtrim($apiUrl, '/') . '/media';

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($localFilePath) ?: 'image/jpeg';
    $fileBytes = file_get_contents($localFilePath);
    if ($fileBytes === false || $fileBytes === '') return null;

    $ch = curl_init($mediaApiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fileBytes,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: ' . $mimeType,
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $json = json_decode((string)$response, true);
        if (isset($json['data']['id']) && !empty($json['data']['id'])) {
            return (string)$json['data']['id'];
        }
    }
    return null;
}

$secretToken = "jwd_secure_cron_2026";
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    $providedToken = $_GET['token'] ?? '';
    if (!is_string($providedToken) || !hash_equals($secretToken, $providedToken)) {
        http_response_code(403);
        writeLog("ERROR: Akses Ditolak - Token tidak valid (web)");
        die("Akses Ditolak.");
    }
    writeLog("Token web valid - melanjutkan proses");
} else {
    writeLog("Eksekusi CLI terdeteksi - autentikasi token URL dilewati");
}

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

$mediaIdCache = [];

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
                if (!isset($mediaIdCache[$imagePathLocal])) {
                    $mediaIdCache[$imagePathLocal] = uploadOneSenderMediaCron($imagePathLocal, $apiUrl, $apiToken);
                }
                $mediaId = $mediaIdCache[$imagePathLocal];

                if (!$mediaId) {
                    updateScheduleRow($conn, (int)$row['id'], "UPDATE jadwal_pesan_grup SET status = 'gagal' WHERE id = ?", 'Gagal upload media ke OneSender');
                    writeLog("ERROR: Gagal upload media OneSender untuk ID " . $row['id'] . " | Path: " . $imagePathLocal);
                    $totalDiproses++;
                    continue;
                }
                
                $postData = [
                    'recipient_type' => 'group',
                    'to' => $row['id_grup'],
                    'type' => 'image',
                    'image' => [
                        'id' => (string)$mediaId,
                        'caption' => (string)$row['pesan']
                    ]
                ];
                $jsonData = json_encode($postData, JSON_UNESCAPED_UNICODE);
                curl_setopt_array($curl, [
                    CURLOPT_URL => $apiUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $jsonData,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $apiToken,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT => 45,
                ]);
            } else {
                updateScheduleRow($conn, (int)$row['id'], "UPDATE jadwal_pesan_grup SET status = 'gagal' WHERE id = ?", 'media tidak ditemukan');
                writeLog("ERROR: File media tidak ditemukan untuk ID " . $row['id'] . " | Path: " . $imagePathLocal);
                $totalDiproses++;
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

        if ($err || $httpcode < 200 || $httpcode >= 300) {
            writeLog("ERROR kirim ID " . $row['id'] . " | HTTP " . $httpcode . " | cURL: " . ($err ?: '-') . " | Respons: " . substr((string)$response, 0, 500));
            updateScheduleRow($conn, (int)$row['id'], "UPDATE jadwal_pesan_grup SET status = 'gagal' WHERE id = ?", 'API gagal');
        } else {
            writeLog("API menerima kiriman ID " . $row['id'] . " | HTTP " . $httpcode . " | Respons: " . substr((string)$response, 0, 300));
            if ($row['tipe_jadwal'] === 'harian') {
                updateScheduleDateRow($conn, (int)$row['id'], $tanggalIni, "UPDATE jadwal_pesan_grup SET terakhir_dikirim = ? WHERE id = ?", 'catat kiriman rutin');
            } else {
                updateScheduleDateRow($conn, (int)$row['id'], $tanggalIni, "UPDATE jadwal_pesan_grup SET status = 'terkirim', terakhir_dikirim = ? WHERE id = ?", 'tandai terkirim sekali');
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