<?php
session_start();
require_once 'config.php';
require_once 'auth_checkwa.php';

// =================================================================
// FUNGSI API (DIPERBARUI: MENDUKUNG PATH STRING & ARRAY $_FILES)
// =================================================================
function kirimPesanGrupCanggih($groupId, $message, $imagePath, $apiUrl, $apiToken, $conn) {
    $textApiUrl = $apiUrl;
    $mediaApiUrl = $apiUrl;
    $returnPayload = ['sent_message' => $message];
    
    $hasImage = false;
    $cfile = null;

    // Cek apakah $imagePath adalah string (path lokal) atau array ($_FILES)
    if (is_string($imagePath) && !empty($imagePath)) {
        $fullPath = __DIR__ . '/' . $imagePath;
        if (file_exists($fullPath)) {
            $hasImage = true;
            $cfile = new CURLFile($fullPath, mime_content_type($fullPath), basename($fullPath));
        }
    } elseif (is_array($imagePath) && isset($imagePath['error']) && $imagePath['error'] == UPLOAD_ERR_OK) {
        $hasImage = true;
        $cfile = new CURLFile($imagePath['tmp_name'], $imagePath['type'], $imagePath['name']);
    }

    if ($hasImage) {
        if (strpos($apiUrl, '/v1/messages') !== false) {
            $mediaApiUrl = str_replace('/v1/messages', '/message', $apiUrl);
        } else {
            $mediaApiUrl = rtrim($apiUrl, '/') . '/message';
        }
        $postData = [
            'type' => 'image',
            'phone' => $groupId,
            'message' => $message,
            'attachment' => $cfile
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $mediaApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $returnPayload['status'] = 'GAGAL';
            $returnPayload['message'] = "cURL Error (Media): " . $curlError;
            return $returnPayload;
        }
        $responseData = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $returnPayload['status'] = 'TERKIRIM';
            $returnPayload['message'] = "Pesan media berhasil dikirim.";
        } else {
            $errorMessage = isset($responseData['message']) ? $responseData['message'] : "Unknown error.";
            $returnPayload['status'] = 'GAGAL';
            $returnPayload['message'] = "Gagal (Media). Code: {$httpCode}. Pesan API: " . $errorMessage;
        }
        return $returnPayload;
    } else {
        $payload = [
            'recipient_type' => 'group',
            'to' => $groupId,
            'type' => 'text',
            'text' => ['body' => $message]
        ];
        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($textApiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $returnPayload['status'] = 'GAGAL';
            $returnPayload['message'] = "cURL Error (Teks): " . $curlError;
            return $returnPayload;
        }
        $responseData = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $returnPayload['status'] = 'TERKIRIM';
            $returnPayload['message'] = "Pesan teks berhasil dikirim.";
        } else {
            $errorMessage = isset($responseData['message']) ? $responseData['message'] : "Unknown error.";
            $returnPayload['status'] = 'GAGAL';
            $returnPayload['message'] = "Gagal (Teks). Code: {$httpCode}. Pesan API: " . $errorMessage;
        }
        return $returnPayload;
    }
}

// =================================================================
// 1. TANGKAP REQUEST UPLOAD GAMBAR TERPISAH (AGAR TIDAK DIULANG)
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_upload_image'])) {
    header('Content-Type: application/json');
    $imageFile = $_FILES['promo_image'] ?? null;
    if ($imageFile && $imageFile['error'] == UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $fileName = time() . '_' . rand(100,999) . '_' . basename($imageFile['name']);
        $savedPath = $uploadDir . $fileName;
        if (move_uploaded_file($imageFile['tmp_name'], $savedPath)) {
            echo json_encode(['status' => 'success', 'path' => 'uploads/' . $fileName]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Gagal menyimpan file']);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'File gambar tidak valid']);
    }
    exit;
}

// =================================================================
// 2. TANGKAP REQUEST AJAX: EKSEKUSI MANUAL JADWAL
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_manual_trigger'])) {
    header('Content-Type: application/json');
    $idJadwalList = $_POST['id_jadwal'] ?? '';
    $ids = array_map('intval', explode(',', $idJadwalList));
    $idsString = implode(',', $ids);

    if (empty($idsString)) {
        echo json_encode(['status' => 'error', 'msg' => 'ID Jadwal tidak valid']);
        exit;
    }

    $query = "SELECT * FROM jadwal_pesan_grup WHERE id IN ($idsString)";
    $result = $conn->query($query);
    
    $successCount = 0;
    $failCount = 0;

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $groupId = $row['id_grup'];
            $pesan = $row['pesan'];
            $mediaPath = $row['media_path'];
            $tipeJadwal = $row['tipe_jadwal'];
            
            $imageArg = null;
            if (!empty($mediaPath)) {
                $localFile = __DIR__ . '/' . $mediaPath;
                if (file_exists($localFile)) {
                    $imageArg = $mediaPath; // Kirim sebagai string path
                }
            }

            $res = kirimPesanGrupCanggih($groupId, $pesan, $imageArg, $apiUrl, $apiToken, $conn);
            
            if ($res['status'] == 'TERKIRIM') {
                $successCount++;
                if ($tipeJadwal == 'sekali') {
                    $conn->query("UPDATE jadwal_pesan_grup SET status = 'sent' WHERE id = {$row['id']}");
                } elseif ($tipeJadwal == 'harian') {
                    $conn->query("UPDATE jadwal_pesan_grup SET terakhir_dikirim = CURDATE() WHERE id = {$row['id']}");
                }
            } else {
                $failCount++;
            }
        }
    }

    echo json_encode([
        'status' => 'success', 
        'msg' => "Eksekusi Selesai! Berhasil: {$successCount}, Gagal: {$failCount}"
    ]);
    exit;
}

// =================================================================
// 3. TANGKAP REQUEST AJAX KIRIM GRUP (STANDARD)
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_kirim_grup'])) {
    header('Content-Type: application/json');
    $groupId = $_POST['group_id'] ?? '';
    $pesan = trim($_POST['pesan'] ?? '');
    $imageFile = (isset($_FILES['promo_image']) && $_FILES['promo_image']['error'] == UPLOAD_ERR_OK) ? $_FILES['promo_image'] : null;
    $savedImagePath = $_POST['saved_image_path'] ?? null; // Path dari upload terpisah
    
    $tipeJadwal = $_POST['tipe_jadwal'] ?? 'sekarang';
    $waktuJadwal = trim($_POST['waktu_jadwal'] ?? '');
    $jamHarian = trim($_POST['jam_harian'] ?? '');
    $hariRutin = trim($_POST['hari_rutin'] ?? '');

    if (empty($groupId)) {
        echo json_encode(['status' => 'error', 'msg' => 'Grup kosong']); exit;
    }

    if ($tipeJadwal === 'sekali' || $tipeJadwal === 'harian') {
        $finalImagePath = null;
        if ($savedImagePath) {
            $finalImagePath = $savedImagePath;
        } elseif ($imageFile) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fileName = time() . '_' . rand(100,999) . '_' . basename($imageFile['name']);
            $finalImagePath = 'uploads/' . $fileName;
            move_uploaded_file($imageFile['tmp_name'], $uploadDir . $fileName);
        }

        $waktuKirimSekali = ($tipeJadwal === 'sekali') ? $waktuJadwal : null;
        $waktuKirimHarian = ($tipeJadwal === 'harian') ? $jamHarian : null;
        $statusAwal = 'pending';
        $terakhirDikirim = null;

        if ($tipeJadwal === 'harian' && !empty($jamHarian)) {
            date_default_timezone_set('Asia/Jakarta');
            if ($jamHarian <= date('H:i')) {
                $terakhirDikirim = date('Y-m-d');
            }
        }

        $stmt = $conn->prepare("INSERT INTO jadwal_pesan_grup (id_grup, pesan, media_path, tipe_jadwal, jadwal_kirim, jam_harian, hari_rutin, status, terakhir_dikirim) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", $groupId, $pesan, $finalImagePath, $tipeJadwal, $waktuKirimSekali, $waktuKirimHarian, $hariRutin, $statusAwal, $terakhirDikirim);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'msg' => 'Pesan berhasil dijadwalkan']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Gagal Database: ' . $conn->error]);
        }
        $stmt->close();
        exit;
    }

    // KIRIM SEKARANG
    $namaGrupLog = "Grup ID: " . $groupId;
    $namaResult = $conn->query("SELECT nama_grup FROM wa_grup WHERE id_grup = '{$conn->real_escape_string($groupId)}'");
    if($namaRow = $namaResult->fetch_assoc()) $namaGrupLog = $namaRow['nama_grup'];

    // Prioritaskan path string, jika tidak ada baru pakai $_FILES
    $imageArg = $savedImagePath ? $savedImagePath : $imageFile;
    $result = kirimPesanGrupCanggih($groupId, $pesan, $imageArg, $apiUrl, $apiToken, $conn);

    $logMessage = "[" . $result['status'] . "] [GRUP] " . $result['message'];
    if ($imageArg) $logMessage .= " (dengan gambar)";
    $logMessage .= " :: " . $result['sent_message'];
    $logMessage = substr($logMessage, 0, 500);

    $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $groupId, $namaGrupLog, $logMessage);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'status' => ($result['status'] == 'TERKIRIM') ? 'success' : 'error',
        'msg' => $result['message']
    ]);
    exit;
}

// =================================================================
// PROSES FORM TRADISIONAL & LOGIKA HALAMAN LAINNYA
// =================================================================
$notification = '';
$notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notification_type'];
    unset($_SESSION['notification'], $_SESSION['notification_type']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_history'])) {
    if ($conn->query("DELETE FROM log_wa WHERE message LIKE '%[GRUP]%'")) {
        $_SESSION['notification'] = "Semua riwayat pengiriman grup berhasil dihapus."; $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus riwayat: " . $conn->error; $_SESSION['notification_type'] = 'error';
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['batalkan_jadwal'])) {
    $idJadwalList = $_POST['id_jadwal'];
    $ids = array_map('intval', explode(',', $idJadwalList));
    $idsString = implode(',', $ids);
    if (!empty($idsString)) {
        $conn->query("DELETE FROM jadwal_pesan_grup WHERE id IN ($idsString)");
    }
    $_SESSION['notification'] = "Semua jadwal dalam kelompok ini berhasil dibatalkan.";
    $_SESSION['notification_type'] = 'success';
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

$jadwalBerjalan = [];
$jadwalQuery = "
SELECT j.pesan, j.tipe_jadwal, j.jadwal_kirim, j.jam_harian, j.hari_rutin, j.media_path,
GROUP_CONCAT(DISTINCT COALESCE(w.nama_grup, j.id_grup) ORDER BY COALESCE(w.nama_grup, j.id_grup) SEPARATOR ', ') as daftar_grup,
GROUP_CONCAT(DISTINCT j.id SEPARATOR ',') as id_jadwal_list,
COUNT(DISTINCT j.id_grup) as total_grup
FROM jadwal_pesan_grup j
LEFT JOIN wa_grup w ON j.id_grup = w.id_grup
WHERE j.status = 'pending'
GROUP BY j.pesan, COALESCE(j.jadwal_kirim, ''), COALESCE(j.jam_harian, ''), COALESCE(j.hari_rutin, ''), j.tipe_jadwal, COALESCE(j.media_path, '')
ORDER BY MAX(j.id) DESC
";
$jadwalResult = $conn->query($jadwalQuery);
if ($jadwalResult) { $jadwalBerjalan = $jadwalResult->fetch_all(MYSQLI_ASSOC); }

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_message_history'])) {
    if ($conn->query("DELETE FROM log_wa WHERE message LIKE '%[GRUP]%'")) {
        $_SESSION['notification'] = "Semua riwayat isi pesan berhasil dihapus."; $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus riwayat isi pesan: " . $conn->error; $_SESSION['notification_type'] = 'error';
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_log_id'])) {
    $logIdToDelete = $_POST['delete_log_id'];
    $stmt = $conn->prepare("DELETE FROM log_wa WHERE id = ? AND message LIKE '%[GRUP]%'");
    $stmt->bind_param("i", $logIdToDelete);
    if ($stmt->execute()) {
        $_SESSION['notification'] = "Satu riwayat pengiriman berhasil dihapus."; $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus riwayat: " . $stmt->error; $_SESSION['notification_type'] = 'error';
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

$groupsByCategory = [];
$groupResult = $conn->query("SELECT id, nama_grup, id_grup, kategori FROM wa_grup ORDER BY kategori, nama_grup");
if ($groupResult) { while ($row = $groupResult->fetch_assoc()) { $groupsByCategory[$row['kategori']][] = $row; } }

$logPesan = [];
$logResult = $conn->query("SELECT id, nama, message, created_at FROM log_wa WHERE message LIKE '%[GRUP]%' ORDER BY created_at DESC LIMIT 30");
if($logResult) $logPesan = $logResult->fetch_all(MYSQLI_ASSOC);

$pesanHistory = [];
$historyResult = $conn->query("SELECT SUBSTRING_INDEX(message, ' :: ', -1) as sent_content FROM log_wa WHERE message LIKE '%[GRUP]%' GROUP BY sent_content ORDER BY MAX(id) DESC LIMIT 15");
if ($historyResult) { $pesanHistory = $historyResult->fetch_all(MYSQLI_ASSOC); }

function formatHariRutin($hariString) {
    if(empty($hariString)) return '-';
    $hariMap = [1=>'Sen',2=>'Sel',3=>'Rab',4=>'Kam',5=>'Jum',6=>'Sab',7=>'Min'];
    $hariArr = explode(',', $hariString);
    $hariNama = array_map(function($h) use ($hariMap) { return $hariMap[(int)$h] ?? $h; }, $hariArr);
    return implode(', ', $hariNama);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kirim Pesan Grup - JWD</title>
<?php $cache_buster = time(); ?>
<link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
.whatsapp-chat-preview { background-image: url('https://i.ibb.co/7z3P8sW/wa-bg.png'); background-size: cover; }
.chat-bubble { max-width: 80%; }

/* Bento UI Custom Styles */
.bento-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 1.5rem; /* 24px */
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02);
    transition: all 0.3s ease;
}
.bento-card:hover {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
}
.bento-input {
    border: 1.5px solid #e2e8f0;
    border-radius: 0.75rem; /* 12px */
    transition: all 0.2s;
}
.bento-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    outline: none;
}
.btn-bento-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
    transition: all 0.2s;
}
.btn-bento-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
}
.mode-card {
    transition: all 0.2s ease;
    border: 2px solid transparent;
}
.mode-card.active-mode {
    background-color: #eff6ff;
    border-color: #3b82f6;
    box-shadow: 0 4px 6px -1px rgba(59,130,246,0.1);
}
@keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.animate-fade-in-down { animation: fadeInDown 0.4s ease-out forwards; }
</style>
</head>
<body class="text-gray-800">
<div id="app" class="flex flex-col min-h-screen">
    <!-- Header dengan efek Glassmorphism -->
    <header class="bg-white/80 backdrop-blur-md text-gray-800 shadow-sm sticky top-0 z-50 border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
                <div class="flex items-center space-x-3">
                    <div class="bg-gradient-to-br from-blue-500 to-indigo-600 p-2.5 rounded-xl shadow-lg">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-extrabold text-gray-900">Kirim Pesan Grup</h1>
                        <p class="text-xs text-gray-500 font-medium">Atur Promosi Ke Grup WhatsApp</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                    <a href="reminder.php" class="text-xs font-semibold text-gray-600 hover:text-blue-600 px-3 py-2 rounded-lg bg-gray-100 hover:bg-blue-50 transition"><i class="fas fa-bell mr-1.5"></i> Pembayaran</a>
                    <a href="promosi.php" class="text-xs font-semibold text-gray-600 hover:text-blue-600 px-3 py-2 rounded-lg bg-gray-100 hover:bg-blue-50 transition"><i class="fas fa-bullhorn mr-1.5"></i> Promosi</a>
                    <a href="kelola_reminder.php" class="text-xs font-semibold text-gray-600 hover:text-blue-600 px-3 py-2 rounded-lg bg-gray-100 hover:bg-blue-50 transition"><i class="fas fa-message mr-1.5"></i> Reminder</a>
                    <a href="kelola_grup.php" class="text-xs font-semibold text-gray-600 hover:text-blue-600 px-3 py-2 rounded-lg bg-gray-100 hover:bg-blue-50 transition"><i class="fas fa-plus-circle mr-1.5"></i> Grup</a>
                    <a href="logoutwa.php" class="text-xs font-semibold text-red-600 hover:text-white px-3 py-2 rounded-lg bg-red-50 hover:bg-red-500 transition"><i class="fas fa-right-from-bracket mr-1.5"></i> Keluar</a>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow p-4 sm:p-6 lg:p-8">
        <div class="max-w-7xl mx-auto relative">
            
            <!-- Loader / Progress Bar (Bento Style) -->
            <div id="loader" class="hidden animate-fade-in-down mb-8 bento-card overflow-hidden relative border-blue-100 bg-blue-50/50">
                <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-blue-400 via-indigo-500 to-purple-500"></div>
                <div class="p-6 flex flex-col sm:flex-row items-center gap-6">
                    <div class="bg-white w-16 h-16 rounded-2xl flex items-center justify-center shrink-0 shadow-sm border border-blue-100">
                        <i class="fas fa-paper-plane text-3xl text-blue-500 animate-bounce"></i>
                    </div>
                    <div class="flex-1 w-full">
                        <div class="flex justify-between items-end mb-3">
                            <div>
                                <h3 class="font-extrabold text-slate-800 text-lg" id="loadingTitle">Proses Berjalan...</h3>
                                <p id="progressStatus" class="text-sm font-medium text-slate-500 mt-1">Mempersiapkan data...</p>
                            </div>
                            <p id="progressText" class="text-2xl font-black text-blue-600">0 / 0</p>
                        </div>
                        <div class="w-full bg-white rounded-full h-4 shadow-inner overflow-hidden relative border border-blue-100">
                            <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-indigo-600 h-full rounded-full transition-all duration-300 w-0 relative">
                                <div class="absolute inset-0 bg-white/20"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($notification)): ?>
            <div class="mb-8 p-5 rounded-2xl shadow-sm border-l-4 <?php echo $notificationType === 'success' ? 'bg-green-50 text-green-800 border-green-500' : 'bg-red-50 text-red-800 border-red-500'; ?>">
                <div class="flex items-center">
                    <i class="fas <?php echo $notificationType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> text-xl mr-3"></i>
                    <p class="font-semibold"><?php echo htmlspecialchars($notification); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- BENTO GRID LAYOUT -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                
                <!-- KOLOM KIRI: FORM (8 Kolom) -->
                <div class="lg:col-span-8 space-y-6">
                    <form enctype="multipart/form-data" id="main-form" class="bento-card p-6 sm:p-8">
                        <h2 class="text-xl font-bold mb-6 flex items-center text-gray-900">
                            <span class="bg-blue-100 text-blue-600 p-2 rounded-lg mr-3"><i class="fas fa-pencil-alt"></i></span> 
                            Buat Pesan Anda
                        </h2>
                        
                        <div class="space-y-5">
                            <div>
                                <label for="pesan" class="block font-semibold text-sm text-gray-700 mb-2">Isi Pesan / Caption</label>
                                <textarea id="pesan" name="pesan" rows="6" class="w-full bento-input p-4 text-sm resize-none" placeholder="Tulis pesan promosi Anda di sini..."></textarea>
                            </div>
                            
                            <div>
                                <label for="promo_image" class="block font-semibold text-sm text-gray-700 mb-2">Upload Gambar (Opsional - Max 5MB)</label>
                                <div class="relative">
                                    <input type="file" id="promo_image" name="promo_image" class="hidden" accept="image/png, image/jpeg, image/gif">
                                    <label for="promo_image" class="flex items-center justify-center w-full p-4 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-blue-400 hover:bg-blue-50/50 transition">
                                        <i class="fas fa-cloud-upload-alt text-2xl text-gray-400 mr-3"></i>
                                        <span class="text-sm font-medium text-gray-600">Klik untuk memilih gambar</span>
                                    </label>
                                </div>
                            </div>

                            <!-- MODE PENGIRIMAN -->
                            <div class="mt-6 p-5 bg-gradient-to-br from-slate-50 to-blue-50 border border-blue-100 rounded-2xl">
                                <label class="block font-bold text-sm text-blue-800 mb-4"><i class="fas fa-clock mr-2"></i> Mode Pengiriman</label>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                                    <div class="mode-card bg-white border border-gray-200 rounded-xl p-4 cursor-pointer text-center hover:shadow-md" data-mode="sekarang">
                                        <input type="radio" name="tipe_jadwal" value="sekarang" id="mode_sekarang" class="hidden" checked>
                                        <i class="fas fa-bolt text-2xl text-yellow-500 mb-2 block"></i>
                                        <span class="font-bold text-sm text-gray-800">Kirim Sekarang</span>
                                        <p class="text-xs text-gray-500 mt-1">Langsung terkirim</p>
                                    </div>
                                    <div class="mode-card bg-white border border-gray-200 rounded-xl p-4 cursor-pointer text-center hover:shadow-md" data-mode="sekali">
                                        <input type="radio" name="tipe_jadwal" value="sekali" id="mode_sekali" class="hidden">
                                        <i class="fas fa-calendar-day text-2xl text-indigo-500 mb-2 block"></i>
                                        <span class="font-bold text-sm text-gray-800">Jadwal Sekali</span>
                                        <p class="text-xs text-gray-500 mt-1">Tgl & waktu tertentu</p>
                                    </div>
                                    <div class="mode-card bg-white border border-gray-200 rounded-xl p-4 cursor-pointer text-center hover:shadow-md" data-mode="harian">
                                        <input type="radio" name="tipe_jadwal" value="harian" id="mode_harian" class="hidden">
                                        <i class="fas fa-sync-alt text-2xl text-teal-500 mb-2 block"></i>
                                        <span class="font-bold text-sm text-gray-800">Rutin Harian</span>
                                        <p class="text-xs text-gray-500 mt-1">Setiap hari & jam</p>
                                    </div>
                                </div>
                                <div id="input_sekali" class="hidden mt-4 p-4 bg-white rounded-xl border border-gray-200">
                                    <label for="waktu_jadwal" class="block text-xs text-blue-600 mb-2 font-bold">Pilih Tanggal & Waktu Kirim:</label>
                                    <input type="datetime-local" id="waktu_jadwal" name="waktu_jadwal" class="w-full bento-input p-3 text-sm">
                                </div>
                                <div id="input_harian" class="hidden mt-4 p-4 bg-white rounded-xl border border-gray-200">
                                    <label for="jam_harian" class="block text-xs text-blue-600 mb-2 font-bold">Kirim pada jam:</label>
                                    <input type="time" id="jam_harian" name="jam_harian" class="w-full bento-input p-3 text-sm mb-4">
                                    <label class="block text-xs text-blue-600 mb-2 font-bold">Pilih Hari Rutin:</label>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="flex items-center text-xs bg-gray-100 px-3 py-2 rounded-lg cursor-pointer hover:bg-blue-50"><input type="checkbox" name="hari_rutin[]" value="1" class="mr-2 rounded"> Sen</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-3 py-2 rounded-lg cursor-pointer hover:bg-blue-50"><input type="checkbox" name="hari_rutin[]" value="2" class="mr-2 rounded"> Sel</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-3 py-2 rounded-lg cursor-pointer hover:bg-blue-50"><input type="checkbox" name="hari_rutin[]" value="3" class="mr-2 rounded"> Rab</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-3 py-2 rounded-lg cursor-pointer hover:bg-blue-50"><input type="checkbox" name="hari_rutin[]" value="4" class="mr-2 rounded"> Kam</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-3 py-2 rounded-lg cursor-pointer hover:bg-blue-50"><input type="checkbox" name="hari_rutin[]" value="5" class="mr-2 rounded"> Jum</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-3 py-2 rounded-lg cursor-pointer hover:bg-blue-50"><input type="checkbox" name="hari_rutin[]" value="6" class="mr-2 rounded"> Sab</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-3 py-2 rounded-lg cursor-pointer hover:bg-blue-50"><input type="checkbox" name="hari_rutin[]" value="7" class="mr-2 rounded"> Min</label>
                                    </div>
                                </div>
                            </div>

                            <!-- PILIH GRUP -->
                            <div class="pt-4">
                                <h2 class="text-xl font-bold mb-4 flex items-center text-gray-900">
                                    <span class="bg-green-100 text-green-600 p-2 rounded-lg mr-3"><i class="fas fa-check-square"></i></span> 
                                    Pilih Grup Penerima
                                </h2>
                                <div class="relative">
                                    <button type="button" id="group-filter-btn" class="w-full bg-white border-2 border-gray-200 rounded-xl p-4 text-left flex justify-between items-center focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition hover:bg-gray-50">
                                        <span id="group-filter-btn-text" class="font-semibold text-gray-700">Pilih Grup Penerima</span>
                                        <i class="fas fa-chevron-down text-gray-400"></i>
                                    </button>
                                    <div id="group-filter-popup" class="hidden absolute z-20 mt-2 w-full bg-white border border-gray-200 rounded-2xl shadow-xl max-h-72 overflow-y-auto p-4">
                                        <?php if (!empty($groupsByCategory)): ?>
                                        <?php foreach($groupsByCategory as $kategori => $groups): ?>
                                        <div class="category-group space-y-1 mb-4">
                                            <label class="flex items-center space-x-3 cursor-pointer p-3 bg-gray-50 rounded-xl border border-gray-100">
                                                <input type="checkbox" class="category-checkbox h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                <span class="text-sm font-bold text-gray-800"><?= htmlspecialchars($kategori) ?></span>
                                            </label>
                                            <div class="pl-6 pt-2 space-y-1">
                                                <?php foreach($groups as $group): ?>
                                                <label class="flex items-center space-x-3 cursor-pointer p-2 rounded-lg hover:bg-blue-50 transition">
                                                    <input type="checkbox" name="selected_groups[]" value="<?= htmlspecialchars($group['id_grup']) ?>" class="group-checkbox h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                    <span class="text-sm text-gray-700"><?= htmlspecialchars($group['nama_grup']) ?></span>
                                                </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <p class="text-sm text-gray-500 text-center p-8">Belum ada grup. <a href="kelola_grup.php" class="text-blue-600 font-bold hover:underline">Tambah Grup</a></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-3 flex items-center"><i class="fas fa-info-circle mr-2"></i> Centang grup tujuan, bisa lebih dari satu.</p>
                            </div>

                            <button type="submit" name="kirim_grup" class="w-full btn-bento-primary text-white px-6 py-4 font-bold text-lg transition flex items-center justify-center mt-6">
                                <i class="fas fa-paper-plane text-xl mr-3"></i> Proses Pengiriman
                            </button>
                        </div>
                    </form>
                </div>

                <!-- KOLOM KANAN: PREVIEW (4 Kolom) -->
                <div class="lg:col-span-4 space-y-6 lg:sticky top-24 self-start">
                    <div class="bento-card p-6">
                        <h2 class="text-lg font-bold mb-4 flex items-center text-gray-900">
                            <span class="bg-green-100 text-green-600 p-2 rounded-lg mr-3"><i class="fab fa-whatsapp"></i></span> 
                            Preview Chat
                        </h2>
                        <div class="w-full max-w-sm mx-auto bg-white rounded-2xl shadow-inner overflow-hidden border border-gray-200">
                            <div class="whatsapp-chat-preview p-4 h-96 flex flex-col-reverse overflow-y-auto bg-cover">
                                <div id="chat-preview-container" class="flex flex-col items-end space-y-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIWAYAT GRID (2 Kolom) -->
            <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bento-card p-6">
                    <div class="flex justify-between items-center mb-5">
                        <h2 class="text-lg font-bold flex items-center text-gray-900">
                            <span class="bg-purple-100 text-purple-600 p-2 rounded-lg mr-3"><i class="fas fa-comment-dots"></i></span> 
                            Riwayat Isi Pesan
                        </h2>
                        <form method="POST" onsubmit="return confirm('Hapus semua riwayat isi pesan?');" class="inline-block">
                            <button type="submit" name="delete_message_history" class="text-xs bg-red-50 text-red-600 font-bold px-3 py-2 rounded-lg hover:bg-red-100 transition flex items-center"><i class="fas fa-trash text-xs mr-1.5"></i> Hapus</button>
                        </form>
                    </div>
                    <div class="space-y-2 max-h-80 overflow-y-auto pr-2 custom-scrollbar">
                        <?php if(!empty($pesanHistory)): ?>
                        <?php foreach($pesanHistory as $history): ?>
                        <button type="button" class="use-again-btn w-full text-left text-sm p-3 bg-gray-50 rounded-xl border border-gray-100 hover:bg-blue-50 hover:border-blue-200 transition group" data-message="<?= htmlspecialchars($history['sent_content']) ?>">
                            <p class="truncate text-gray-700 group-hover:text-blue-700"><?= htmlspecialchars($history['sent_content']) ?></p>
                        </button>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <p class="text-sm text-gray-500 text-center p-8">Belum ada riwayat isi pesan.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bento-card p-6">
                    <div class="flex justify-between items-center mb-5">
                        <h2 class="text-lg font-bold flex items-center text-gray-900">
                            <span class="bg-blue-100 text-blue-600 p-2 rounded-lg mr-3"><i class="fas fa-history"></i></span> 
                            Riwayat Pengiriman
                        </h2>
                        <form method="POST" onsubmit="return confirm('Hapus semua riwayat pengiriman?');" class="inline-block">
                            <button type="submit" name="delete_history" class="text-xs bg-red-50 text-red-600 font-bold px-3 py-2 rounded-lg hover:bg-red-100 transition flex items-center"><i class="fas fa-trash text-xs mr-1.5"></i> Hapus</button>
                        </form>
                    </div>
                    <div class="space-y-3 max-h-80 overflow-y-auto pr-2 custom-scrollbar">
                        <?php if(!empty($logPesan)): ?>
                        <?php foreach($logPesan as $log): ?>
                        <?php
                        $logParts = explode(' :: ', $log['message'], 2);
                        $statusMessage = $logParts[0];
                        $isSuccess = strpos($statusMessage, '[TERKIRIM]') !== false;
                        ?>
                        <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-xl border border-gray-100 hover:shadow-sm transition">
                            <div class="mt-0.5">
                                <?php if ($isSuccess): ?><i class="fas fa-check-circle text-green-500 text-lg"></i><?php else: ?><i class="fas fa-times-circle text-red-500 text-lg"></i><?php endif; ?>
                            </div>
                            <div class="flex-1 overflow-hidden">
                                <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($log['nama']) ?></p>
                                <p class="text-xs text-gray-500 mt-1 truncate"><?= htmlspecialchars($statusMessage) ?></p>
                                <div class="flex justify-between items-center mt-2">
                                    <p class="text-gray-400 text-[10px] font-medium"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></p>
                                    <form method="POST" onsubmit="return confirm('Hapus riwayat ini?');" class="inline-block">
                                        <input type="hidden" name="delete_log_id" value="<?= $log['id'] ?>">
                                        <button type="submit" class="text-gray-400 hover:text-red-500 transition" title="Hapus"><i class="fas fa-trash-alt text-xs"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <p class="text-sm text-gray-500 text-center p-8">Belum ada riwayat.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TABEL JADWAL SEDANG BERJALAN (Bento Style) -->
            <div class="mt-8 bento-card p-6 sm:p-8">
                <h2 class="text-xl font-bold mb-6 flex items-center text-gray-900">
                    <span class="bg-orange-100 text-orange-600 p-2 rounded-lg mr-3"><i class="fas fa-calendar-alt"></i></span> 
                    Jadwal Sedang Berjalan
                </h2>
                <div class="overflow-x-auto -mx-6 sm:-mx-8 px-6 sm:px-8">
                    <table class="min-w-full">
                        <thead>
                            <tr class="text-left text-xs font-bold text-gray-500 uppercase tracking-wider border-b border-gray-100">
                                <th class="pb-4 pr-4">Grup Target</th>
                                <th class="pb-4 pr-4">Pesan</th>
                                <th class="pb-4 pr-4">Tipe</th>
                                <th class="pb-4 pr-4">Waktu</th>
                                <th class="pb-4 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100">
                            <?php if(!empty($jadwalBerjalan)): ?>
                            <?php foreach($jadwalBerjalan as $j): ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="py-5 pr-4">
                                    <span class="font-bold text-blue-700 bg-blue-50 px-2.5 py-1 rounded-lg text-xs"><?= $j['total_grup'] ?> Grup</span>
                                    <p class="text-xs text-gray-500 truncate block max-w-[200px] mt-2" title="<?= htmlspecialchars($j['daftar_grup']) ?>">
                                        <?= htmlspecialchars($j['daftar_grup']) ?>
                                    </p>
                                </td>
                                <td class="py-5 pr-4 max-w-xs">
                                    <div class="flex items-center">
                                        <?php if(!empty($j['media_path'])): ?>
                                        <span class="bg-purple-50 text-purple-600 p-1.5 rounded-lg mr-2" title="Terdapat gambar"><i class="fas fa-image text-xs"></i></span>
                                        <?php endif; ?>
                                        <p class="truncate text-gray-700 font-medium" title="<?= htmlspecialchars($j['pesan']) ?>">
                                            <?= htmlspecialchars(mb_substr($j['pesan'], 0, 50)) . (strlen($j['pesan']) > 50 ? '...' : '') ?>
                                        </p>
                                    </div>
                                </td>
                                <td class="py-5 pr-4">
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold 
                                        <?= $j['tipe_jadwal'] == 'sekali' ? 'bg-indigo-50 text-indigo-700' : ($j['tipe_jadwal'] == 'harian' ? 'bg-teal-50 text-teal-700' : 'bg-gray-100 text-gray-700') ?>">
                                        <?= ucfirst($j['tipe_jadwal']) ?>
                                    </span>
                                </td>
                                <td class="py-5 pr-4 text-xs text-gray-600 font-medium">
                                    <?php if($j['tipe_jadwal'] == 'sekali'): ?>
                                    <i class="far fa-calendar-alt mr-1 text-gray-400"></i> <?= date('d M Y, H:i', strtotime($j['jadwal_kirim'])) ?>
                                    <?php elseif($j['tipe_jadwal'] == 'harian'): ?>
                                    <i class="fas fa-clock mr-1 text-gray-400"></i> <?= htmlspecialchars($j['jam_harian']) ?><br>
                                    <span class="text-gray-400"><?= formatHariRutin($j['hari_rutin']) ?></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td class="py-5 text-center">
                                    <div class="flex flex-col sm:flex-row gap-2 justify-center">
                                        <!-- TOMBOL BARU: KIRIM SEKARANG -->
                                        <button type="button" class="btn-trigger-manual bg-green-50 text-green-700 border border-green-200 px-3 py-2 rounded-lg hover:bg-green-100 text-xs font-bold transition flex items-center justify-center whitespace-nowrap" data-ids="<?= $j['id_jadwal_list'] ?>" data-total="<?= $j['total_grup'] ?>">
                                            <i class="fas fa-paper-plane mr-1.5"></i> Kirim Sekarang
                                        </button>
                                        <!-- TOMBOL HAPUS -->
                                        <form method="POST" onsubmit="return confirm('Batalkan SEMUA jadwal untuk kelompok ini?');" class="inline-block">
                                            <input type="hidden" name="id_jadwal" value="<?= $j['id_jadwal_list'] ?>">
                                            <button type="submit" name="batalkan_jadwal" class="w-full bg-red-50 text-red-700 border border-red-200 px-3 py-2 rounded-lg hover:bg-red-100 text-xs font-bold transition flex items-center justify-center whitespace-nowrap">
                                                <i class="fas fa-trash-alt mr-1.5"></i> Batalkan
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="5" class="py-10 text-center text-gray-400 font-medium">Tidak ada jadwal aktif.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
// Logic Form & Jadwal
function toggleJadwal() {
    const tipe = document.querySelector('input[name="tipe_jadwal"]:checked').value;
    document.getElementById('input_sekali').style.display = (tipe === 'sekali') ? 'block' : 'none';
    document.getElementById('input_harian').style.display = (tipe === 'harian') ? 'block' : 'none';
}

function initModeCards() {
    const cards = document.querySelectorAll('.mode-card');
    const radios = document.querySelectorAll('input[name="tipe_jadwal"]');
    function updateActive() {
        const selectedVal = document.querySelector('input[name="tipe_jadwal"]:checked').value;
        cards.forEach(card => {
            const mode = card.dataset.mode;
            if(mode === selectedVal) {
                card.classList.add('active-mode');
            } else {
                card.classList.remove('active-mode');
            }
        });
        toggleJadwal();
    }
    cards.forEach(card => {
        card.addEventListener('click', () => {
            const mode = card.dataset.mode;
            const radio = card.querySelector(`input[value="${mode}"]`);
            if(radio) radio.checked = true;
            updateActive();
        });
    });
    radios.forEach(r => r.addEventListener('change', updateActive));
    updateActive();
}

document.addEventListener('DOMContentLoaded', function () {
    initModeCards();

    const mainForm = document.getElementById('main-form');
    mainForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const groupCheckboxes = document.querySelectorAll('.group-checkbox:checked');
        const selectedGroupsCount = groupCheckboxes.length;
        const pesanText = document.getElementById('pesan').value.trim();
        const fileInput = document.getElementById('promo_image');
        const tipeJadwal = document.querySelector('input[name="tipe_jadwal"]:checked').value;
        const waktuJadwal = document.getElementById('waktu_jadwal').value;
        const jamHarian = document.getElementById('jam_harian').value;
        
        let hariTerpilih = [];
        if(tipeJadwal === 'harian') {
            document.querySelectorAll('input[name="hari_rutin[]"]:checked').forEach(cb => hariTerpilih.push(cb.value));
            if(hariTerpilih.length === 0) { alert("Pilih minimal 1 hari untuk jadwal rutin!"); return; }
        }
        const hariRutinString = hariTerpilih.join(',');

        if (selectedGroupsCount === 0) { alert("Gagal! Anda belum memilih satupun grup penerima."); return; }
        if (pesanText === "" && fileInput.files.length === 0) { alert("Gagal! Isi pesan teks atau gambar tidak boleh kosong."); return; }
        if (fileInput.files.length > 0 && fileInput.files[0].size > (5 * 1024 * 1024)) { alert("Gagal! Ukuran gambar terlalu besar. Maksimal 5 MB."); return; }
        if (tipeJadwal === 'sekali' && !waktuJadwal) { alert("Pilih tanggal dan jam untuk jadwal sekali!"); return; }
        if (tipeJadwal === 'harian' && !jamHarian) { alert("Pilih jam pengiriman harian!"); return; }

        const loader = document.getElementById('loader');
        const pBar = document.getElementById('progressBar');
        const pText = document.getElementById('progressText');
        const pStat = document.getElementById('progressStatus');
        const loadingTitle = document.getElementById('loadingTitle');
        const submitBtn = document.querySelector('button[name="kirim_grup"]');

        loader.classList.remove('hidden');
        loader.classList.add('block');
        window.scrollTo({ top: 0, behavior: 'smooth' });

        if (submitBtn) {
            submitBtn.style.pointerEvents = 'none';
            submitBtn.classList.add('opacity-75');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin text-xl mr-2"></i> Memproses...';
        }

        let successCount = 0;
        let failCount = 0;
        let total = selectedGroupsCount;
        let groupsArray = Array.from(groupCheckboxes).map(cb => cb.value);

        pBar.style.width = '0%';
        pText.innerText = `0 / ${total}`;
        
        // ==========================================
        // PERUBAHAN UTAMA: UPLOAD GAMBAR 1X SAJA
        // ==========================================
        let savedImagePath = null;
        if (fileInput.files.length > 0) {
            loadingTitle.innerText = "Mengunggah Gambar...";
            pStat.innerHTML = `Sedang upload gambar ke server (hanya 1x)...`;
            pBar.style.width = '10%';
            
            let uploadFormData = new FormData();
            uploadFormData.append('ajax_upload_image', '1');
            uploadFormData.append('promo_image', fileInput.files[0]);
            
            try {
                let uploadRes = await fetch(window.location.href, { method: 'POST', body: uploadFormData });
                let uploadJson = await uploadRes.json();
                if (uploadJson.status === 'success') {
                    savedImagePath = uploadJson.path;
                } else {
                    alert("Gagal upload gambar: " + uploadJson.msg);
                    loader.classList.add('hidden');
                    submitBtn.style.pointerEvents = 'auto';
                    submitBtn.classList.remove('opacity-75');
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane text-xl mr-2"></i> Proses Pengiriman';
                    return;
                }
            } catch (err) {
                alert("Error koneksi saat upload gambar");
                loader.classList.add('hidden');
                return;
            }
        }

        // ==========================================
        // LOOPING KIRIM PESAN
        // ==========================================
        loadingTitle.innerText = (tipeJadwal === 'sekarang') ? "Mengirim ke API..." : "Menyimpan Jadwal...";

        for (let i = 0; i < total; i++) {
            let groupId = groupsArray[i];
            let groupName = "Grup " + (i+1);
            const checkboxEl = document.querySelector(`.group-checkbox[value="${groupId}"]`);
            if(checkboxEl && checkboxEl.nextElementSibling) {
                groupName = checkboxEl.nextElementSibling.textContent;
            }
            pStat.innerHTML = `Memproses: <b>${groupName}</b>...`;

            let formData = new FormData();
            formData.append('ajax_kirim_grup', '1');
            formData.append('group_id', groupId);
            formData.append('pesan', pesanText);
            formData.append('tipe_jadwal', tipeJadwal);
            formData.append('waktu_jadwal', waktuJadwal);
            formData.append('jam_harian', jamHarian);
            formData.append('hari_rutin', hariRutinString);
            
            // PENTING: Kirim PATH saja, JANGAN kirim file mentah lagi!
            if (savedImagePath) {
                formData.append('saved_image_path', savedImagePath);
            }

            try {
                let response = await fetch(window.location.href, { method: 'POST', body: formData });
                let json = await response.json();
                if (json.status === 'success') {
                    successCount++;
                    pStat.innerHTML = `<span class="text-green-600 font-bold">✅ Sukses: ${groupName}</span>`;
                } else {
                    failCount++;
                    pStat.innerHTML = `<span class="text-red-600 font-bold">❌ Gagal: ${groupName} - ${json.msg}</span>`;
                }
            } catch (error) {
                failCount++;
                pStat.innerHTML = `<span class="text-red-600 font-bold">❌ Error: ${groupName}</span>`;
            }

            let pct = Math.round(((i + 1) / total) * 100);
            pBar.style.width = pct + '%';
            pText.innerText = `${i + 1} / ${total}`;
            
            // Delay dikurangi drastis dari 600ms menjadi 100ms
            await new Promise(r => setTimeout(r, 100)); 
        }

        pStat.innerHTML = `<span class="text-blue-600 font-bold">Proses Selesai! Memuat ulang...</span>`;
        setTimeout(() => { window.location.reload(); }, 1500);
    });

    // Group Filter Logic
    const groupBtn = document.getElementById('group-filter-btn');
    const groupPopup = document.getElementById('group-filter-popup');
    const groupBtnText = document.getElementById('group-filter-btn-text');

    if (groupBtn) {
        groupBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            groupPopup.classList.toggle('hidden');
        });
        document.addEventListener('click', (e) => {
            if (!groupPopup.contains(e.target) && !groupBtn.contains(e.target)) {
                groupPopup.classList.add('hidden');
            }
        });
        document.querySelectorAll('.category-checkbox').forEach(hcb => {
            hcb.addEventListener('change', function() {
                const groupCheckboxes = this.closest('.category-group').querySelectorAll('.group-checkbox');
                groupCheckboxes.forEach(cb => { cb.checked = this.checked; });
                updateButtonText();
            });
        });
        document.querySelectorAll('.group-checkbox').forEach(cb => {
            cb.addEventListener('change', updateButtonText);
        });
        function updateButtonText() {
            const selectedCount = document.querySelectorAll('.group-checkbox:checked').length;
            groupBtnText.textContent = selectedCount === 0 ? `Pilih Grup Penerima` : `${selectedCount} Grup Terpilih`;
        }
        updateButtonText();
    }

    // Use Again Button
    const pesanTextarea = document.getElementById('pesan');
    document.querySelectorAll('.use-again-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            pesanTextarea.value = this.dataset.message;
            pesanTextarea.dispatchEvent(new Event('input'));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    // Preview Logic
    const imageInput = document.getElementById('promo_image');
    const previewContainer = document.getElementById('chat-preview-container');

    function renderPreviewMessage(text) {
        let previewText = text.replace(/(https?:\/\/[^\s]+)/g, '<a href="#" class="text-cyan-600 break-all">$1</a>');
        return previewText.replace(/\n/g, '<br>');
    }

    function updatePreview() {
        const messageText = pesanTextarea.value;
        const imageFile = imageInput.files[0];
        previewContainer.innerHTML = '';
        if (!messageText && !imageFile) return;

        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble self-end bg-green-100 rounded-2xl p-3 shadow-sm flex flex-col border border-green-200';
        let contentHTML = '';

        if(imageFile) {
            const reader = new FileReader();
            reader.onload = function(e) {
                let imgContainer = bubble.querySelector('#preview-image-container');
                if (imgContainer) imgContainer.innerHTML = `<img src="${e.target.result}" class="rounded-xl max-h-40 w-auto">`;
            }
            reader.readAsDataURL(imageFile);
            contentHTML += `<div id="preview-image-container" class="mb-2"></div>`;
        }
        if (messageText) {
            contentHTML += `<div class="text-sm text-gray-800 break-words">${renderPreviewMessage(messageText)}</div>`;
        }
        contentHTML += `<div class="text-right text-[10px] text-gray-500 mt-2 font-medium">1:30 PM ✓✓</div>`;
        bubble.innerHTML = contentHTML;
        previewContainer.appendChild(bubble);
    }

    pesanTextarea.addEventListener('input', updatePreview);
    imageInput.addEventListener('change', updatePreview);
    updatePreview();

    // ==========================================
    // LOGIC TOMBOL EKSEKUSI MANUAL JADWAL
    // ==========================================
    document.querySelectorAll('.btn-trigger-manual').forEach(btn => {
        btn.addEventListener('click', async function() {
            const ids = this.dataset.ids;
            const totalGrup = this.dataset.total;
            
            if (!confirm(`Anda yakin ingin langsung mengirim pesan ke ${totalGrup} grup sekarang juga (melewati jadwal)?`)) return;

            const loader = document.getElementById('loader');
            const pBar = document.getElementById('progressBar');
            const pText = document.getElementById('progressText');
            const pStat = document.getElementById('progressStatus');
            const loadingTitle = document.getElementById('loadingTitle');

            loader.classList.remove('hidden');
            loader.classList.add('block');
            loadingTitle.innerText = "Eksekusi Manual Jadwal...";
            pStat.innerHTML = `Sedang mengirim ke ${totalGrup} grup...`;
            pBar.style.width = '30%'; 
            pText.innerText = `0 / ${totalGrup}`;
            window.scrollTo({ top: 0, behavior: 'smooth' });

            let formData = new FormData();
            formData.append('ajax_manual_trigger', '1');
            formData.append('id_jadwal', ids);

            try {
                let response = await fetch(window.location.href, { method: 'POST', body: formData });
                let json = await response.json();
                
                pBar.style.width = '100%';
                if (json.status === 'success') {
                    pStat.innerHTML = `<span class="text-green-600 font-bold">✅ ${json.msg}</span>`;
                    pText.innerText = `${totalGrup} / ${totalGrup}`;
                } else {
                    pStat.innerHTML = `<span class="text-red-600 font-bold">❌ ${json.msg}</span>`;
                }
                setTimeout(() => { window.location.reload(); }, 2000);
            } catch (error) {
                pStat.innerHTML = `<span class="text-red-600 font-bold">❌ Terjadi kesalahan koneksi.</span>`;
            }
        });
    });
});
</script>

<script>
if (window.self !== window.top) {
    const headerElement = document.querySelector('header');
    if (headerElement) headerElement.style.display = 'none';
    document.body.style.backgroundColor = "transparent";
}
</script>
</body>
</html>