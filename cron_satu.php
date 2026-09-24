<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ignore_user_abort(true);
set_time_limit(0); 

$logFile = __DIR__ . '/cron_satuan_debug.log';
$healthFile = __DIR__ . '/cron_health.txt';

function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    
    // Pastikan direktori bisa ditulis
    if (is_writable(dirname($logFile))) {
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } else {
        echo "ERROR: Tidak bisa menulis ke log file: " . dirname($logFile) . "\n";
    }
}

// Catat health check
@file_put_contents($healthFile, time());

writeLog("=== CRON SATUAN DIMULAI ===");
writeLog("PHP SAPI: " . php_sapi_name());
writeLog("Working Directory: " . getcwd());

// Autentikasi Token
$secretToken = "jwd_secure_cron_2026"; 
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
        writeLog("ERROR: Token tidak valid");
        die("Akses Ditolak.");
    }
}

// CEK KONEKSI DATABASE
if (!$conn) {
    writeLog("ERROR FATAL: Koneksi database gagal - \$conn is null/false");
    die("Koneksi database gagal");
}

writeLog("Koneksi database: OK");
writeLog("Database: " . (isset($conn) ? mysqli_get_host_info($conn) : 'UNKNOWN'));

// CEK APAKAH TABEL ADA
$checkTable = $conn->query("SHOW TABLES LIKE 'wa_queue_satuan'");
if ($checkTable->num_rows === 0) {
    writeLog("ERROR: Tabel 'wa_queue_satuan' TIDAK DITEMUKAN!");
    
    // Coba cari tabel yang mirip
    $allTables = $conn->query("SHOW TABLES");
    $tableList = [];
    while ($row = $allTables->fetch_array()) {
        $tableList[] = $row[0];
    }
    writeLog("Tabel yang ada: " . implode(', ', $tableList));
    die("Tabel tidak ditemukan");
}
writeLog("Tabel 'wa_queue_satuan' ditemukan");

// CEK STRUKUR TABEL
$structCheck = $conn->query("DESCRIBE wa_queue_satuan");
$columns = [];
while ($col = $structCheck->fetch_assoc()) {
    $columns[] = $col['Field'];
}
writeLog("Kolom tabel: " . implode(', ', $columns));

// CEK BERAPA TOTAL RECORD PENDING (tanpa LIMIT)
$checkPending = $conn->query("SELECT COUNT(*) as total FROM wa_queue_satuan WHERE status = 'pending'");
$pendingCount = $checkPending->fetch_assoc()['total'];
writeLog("Total record dengan status='pending': " . $pendingCount);

// CEK JIKA ADA VARIASI STATUS
$statusCheck = $conn->query("SELECT DISTINCT status FROM wa_queue_satuan");
$statuses = [];
while ($s = $statusCheck->fetch_assoc()) {
    $statuses[] = "'" . $s['status'] . "'";
}
writeLog("Variasi status di tabel: " . implode(', ', $statuses));

// Ambil antrean pending
$limitAntrean = 10;
$query = "SELECT * FROM wa_queue_satuan WHERE status = 'pending' ORDER BY created_at ASC LIMIT " . $limitAntrean;
writeLog("Query: " . $query);

$result = $conn->query($query);

if (!$result) {
    writeLog("ERROR Query: " . $conn->error);
    die("Query Error");
}

$totalDiproses = 0;
writeLog("Jumlah baris hasil query: " . $result->num_rows);

if ($result->num_rows > 0) {
    writeLog("Ditemukan " . $result->num_rows . " antrean pending.");
    
    // Tampilkan sample data pertama
    $sample = $result->fetch_assoc();
    writeLog("Sample data - ID: {$sample['id']}, Status: '{$sample['status']}', Nama: {$sample['nama']}");
    $result->data_seek(0); // Reset pointer
    
    $stmt_success = $conn->prepare("UPDATE wa_queue_satuan SET status = 'sent', sent_at = NOW() WHERE id = ?");
    $stmt_fail = $conn->prepare("UPDATE wa_queue_satuan SET status = 'failed' WHERE id = ?");
    
    while ($row = $result->fetch_assoc()) {
        $rawNoWa = trim($row['nowa']);
        
        // PEMBERSIHAN FORMAT NOMOR WA OTOMATIS
        if (substr($rawNoWa, 0, 1) === '0') {
            $noWa = '62' . substr($rawNoWa, 1);
        } elseif (substr($rawNoWa, 0, 2) === '68') {
            $noWa = '628' . substr($rawNoWa, 2);
        } else {
            $noWa = $rawNoWa;
        }

        writeLog("Memproses ID {$row['id']}: " . $row['nama'] . " (Asli: $rawNoWa -> Format Kirim: $noWa)");
        
        $curl = curl_init();
        $postData = [
            "recipient_type" => "individual",
            "to" => $noWa,
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
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE); 
        curl_close($curl);

        if (!$err && $httpcode >= 200 && $httpcode < 300) {
            $stmt_success->bind_param("i", $row['id']);
            $stmt_success->execute();
            writeLog("SUCCESS: HTTP $httpcode - Terkirim ke {$row['nama']}");
        } else {
            writeLog("GAGAL Kirim ke {$row['nama']}: Error: $err | HTTP Code: $httpcode | Response: $response");
            $stmt_fail->bind_param("i", $row['id']);
            $stmt_fail->execute();
        }
        
        $totalDiproses++;
        if ($totalDiproses < $result->num_rows) {
            sleep(1); 
        }
    }
    
    if($stmt_success) $stmt_success->close();
    if($stmt_fail) $stmt_fail->close();
    
    writeLog("=== CRON SELESAI. Diproses: $totalDiproses ===");
    echo "✅ Sukses memproses $totalDiproses antrean.";
} else {
    writeLog("=== CRON SELESAI. Tidak ada antrean pending ===");
    writeLog("INFO: Cek apakah status di database persis 'pending' (huruf kecil semua)");
    echo "️ Tidak ada antrean pesan dengan status 'pending'.";
}
?>