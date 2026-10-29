<?php
session_start();
require_once 'config.php';

// FIX: Set karakter set koneksi ke utf8mb4
if ($conn) {
    mysqli_set_charset($conn, "utf8mb4");
}

$basePath = __DIR__;
$logFile = "$basePath/webhook_log.txt";

// ========================================
// 🧠 FUNGSI UTILITAS (Sama seperti analitikjwd.php)
// ========================================
function extractMessageFromLog($line) {
    if (preg_match('/"message_text"\s*:\s*"([^"]*)"/', $line, $m)) return $m[1];
    if (preg_match('/"body"\s*:\s*"([^"]*)"/', $line, $m)) return $m[1];
    if (preg_match("/message:\s*'([^']*)'/", $line, $m)) return $m[1];
    return '';
}

function classifyMessage($message) {
    $messageLower = strtolower($message);
    $patterns = [
        'Kak, mau ikut mode Intensif' => '/kak,\s*mau\s*ikut\s*mode\s*intensif/i',
        'Kak, mau ikut mode Normal' => '/kak,\s*mau\s*ikut\s*mode\s*normal/i',
        'Kak, mau ikut mode Tahfidz Cilik' => '/kak,\s*mau\s*ikut\s*mode\s*tahfidz\s*cilik/i',
        'Kak, saya ingin bertanya program Tahfidz Private' => '/kak,\s*saya\s*ingin\s*bertanya\s*program\s*tahfidz\s*private/i',
        'Kak, mau ikut Ziyadah Pemula' => '/kak,\s*mau\s*ikut\s*ziyadah\s*pemula/i',
        'Kak, mau ikut Ziyadah Lanjutan' => '/kak,\s*mau\s*ikut\s*ziyadah\s*lanjutan/i',
        'Kak, mau ikut Muroja\'ah' => '/kak,\s*mau\s*ikut\s*muroja\'ah/i',
    ];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $messageLower)) {
            return $label;
        }
    }
    return 'Pesan Lainnya';
}

function parseProspectsFromLog($logFile, $fromDate = null, $toDate = null) {
    $prospek = [];
    $lastTimestamp = null;
    if (!file_exists($logFile)) return $prospek;
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $timestampStr = null;
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
            $timestampStr = $m[1];
            $lastTimestamp = $timestampStr;
        } elseif ($lastTimestamp) {
            $timestampStr = $lastTimestamp;
        }
        $include = true;
        if ($timestampStr) {
            $ts = strtotime($timestampStr);
            if ($fromDate && $ts < strtotime("$fromDate 00:00:00")) $include = false;
            if ($toDate && $ts > strtotime("$toDate 23:59:59")) $include = false;
        }
        if (!$include) continue;
        $msg = extractMessageFromLog($line);
        if (!$msg) continue;
        if (stripos($msg, 'kak, mau') !== 0) continue;
        $contactId = null;
        if (preg_match('/(\d+@c\.us)/', $line, $matches)) {
            $contactId = str_replace('@c.us', '', $matches[1]);
        } elseif (preg_match('/\b(\d{10,15})\b/', $line, $matches)) {
            $contactId = $matches[1];
        }
        if ($contactId) {
            if (!isset($prospek[$contactId])) {
                $prospek[$contactId] = [
                    'contact_id' => $contactId,
                    'status' => 'belum_daftar', // Default, tidak digunakan lagi
                    'last_message_time' => null,
                    'last_message' => '',
                    'pesan_pendaftaran_ditemukan' => false, // Tidak digunakan lagi
                    'pesan_klasifikasi' => 'Pesan Lainnya'
                ];
            }
            // Perbarui data terakhir jika pesan ditemukan
            $prospek[$contactId]['last_message_time'] = $timestampStr;
            $prospek[$contactId]['last_message'] = $msg;
            $prospek[$contactId]['pesan_klasifikasi'] = classifyMessage($msg);
        }
    }
    return $prospek;
}

// ✅ FUNGSI BARU: Ambil nomor yang sudah terdaftar di calon_peserta, di normalisasi ke format 62
function getRegisteredContacts($conn) {
    $registered = [];
    if (!$conn) return $registered;
    try {
        $stmt = $conn->prepare("SELECT DISTINCT nowa FROM calon_peserta");
        if (!$stmt) return $registered;
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dbPhone = $row['nowa'];
            $normalizedPhone = $dbPhone;
            if (substr($dbPhone, 0, 1) === '0') {
                $normalizedPhone = '62' . substr($dbPhone, 1);
            } elseif (substr($dbPhone, 0, 1) === '+') {
                $normalizedPhone = substr($dbPhone, 1);
            }
            // Pastikan hanya angka
            $normalizedPhone = preg_replace('/\D/', '', $normalizedPhone);
            $registered[$normalizedPhone] = true;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching calon_peserta: " . $e->getMessage());
    }
    return $registered;
}

// Ambil filter tanggal dari GET atau default ke null
$fromDate = $_GET['from'] ?? null;
$toDate = $_GET['to'] ?? null;

// Ambil semua prospek dari log
$prospek = parseProspectsFromLog($logFile, $fromDate, $toDate);

// Ambil daftar yang sudah terdaftar (dengan normalisasi)
$registeredContacts = [];
if ($conn) {
    $registeredContacts = getRegisteredContacts($conn);
}

// Filter: Hanya tampilkan prospek yang BELUM ada di calon_peserta
$filteredProspects = array_filter($prospek, function($data) use ($registeredContacts) {
    $contactId = $data['contact_id'];
    // Normalisasi contact_id dari log
    $normalizedContactId = $contactId;
    if (substr($contactId, 0, 1) === '0') {
        $normalizedContactId = '62' . substr($contactId, 1);
    }
    // Pastikan hanya angka
    $normalizedContactId = preg_replace('/\D/', '', $normalizedContactId);
    return !isset($registeredContacts[$normalizedContactId]);
});

// Siapkan nama file
$fileName = 'nomor_kontak_belum_terdaftar';
if ($fromDate) {
    $fileName .= '_' . $fromDate;
}
if ($toDate) {
    $fileName .= '_' . $toDate;
}
$fileName .= '.csv';

// Set header untuk download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

// Buka output stream
$output = fopen('php://output', 'w');

// Tulis header CSV
fputcsv($output, ['Nomor Kontak', 'Nama']); // Kolom Nama kosong karena tidak ada datanya

// Tulis data kontak
foreach ($filteredProspects as $contactId => $data) {
    // Karena nama tidak tersedia, kita biarkan kolom nama kosong
    fputcsv($output, [$contactId, '']); // Ganti '' dengan $data['nama'] jika suatu saat nama tersedia
}

// Tutup stream
fclose($output);

// Hentikan eksekusi skrip setelah download
exit;
?>