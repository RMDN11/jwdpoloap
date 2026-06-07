<?php
session_start();
require_once 'config.php';
require_once 'auth_checkwa.php';

// FUNGSI API 100% ORIGINAL ANDA (TIDAK DIUBAH)
function kirimPesanGrupCanggih($groupId, $message, $imagePath, $apiUrl, $apiToken, $conn) {
    
    $textApiUrl = $apiUrl;
    $mediaApiUrl = $apiUrl;
    $returnPayload = ['sent_message' => $message];

    if ($imagePath && $imagePath['error'] == UPLOAD_ERR_OK) {
        if (strpos($apiUrl, '/v1/messages') !== false) {
            $mediaApiUrl = str_replace('/v1/messages', '/message', $apiUrl);
        } else {
            $mediaApiUrl = rtrim($apiUrl, '/') . '/message';
        }

        $cfile = new CURLFile($imagePath['tmp_name'], $imagePath['type'], $imagePath['name']);
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
            CURLOPT_TIMEOUT => 30, 
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $returnPayload['status'] = 'GAGAL';
            $returnPayload['message'] = "cURL Error (Media Multi-Part): " . $curlError;
            return $returnPayload;
        }
        $responseData = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $returnPayload['status'] = 'TERKIRIM';
            $returnPayload['message'] = "Pesan media (Multi-Part) berhasil dikirim.";
        } else {
            $errorMessage = isset($responseData['message']) ? $responseData['message'] : "Unknown error.";
            $returnPayload['status'] = 'GAGAL';
            $returnPayload['message'] = "Gagal (Media Multi-Part). Code: {$httpCode}. Pesan API: " . $errorMessage . " Response Penuh: " . substr($response, 0, 100);
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
            $returnPayload['message'] = "Gagal (Teks). Code: {$httpCode}. Pesan API: " . $errorMessage . " Response Penuh: " . substr($response, 0, 100);
        }
        return $returnPayload;
    }
}

// =================================================================
// TANGKAP REQUEST AJAX (DENGAN TAMBAHAN LOGIKA JADWAL)
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_kirim_grup'])) {
    header('Content-Type: application/json');
    $groupId = $_POST['group_id'] ?? '';
    $pesan = trim($_POST['pesan'] ?? '');
    $imageFile = (isset($_FILES['promo_image']) && $_FILES['promo_image']['error'] == UPLOAD_ERR_OK) ? $_FILES['promo_image'] : null;

    // TANGKAP DATA JADWAL
    $tipeJadwal = $_POST['tipe_jadwal'] ?? 'sekarang';
    $waktuJadwal = trim($_POST['waktu_jadwal'] ?? '');
    $jamHarian = trim($_POST['jam_harian'] ?? '');
    $hariRutin = trim($_POST['hari_rutin'] ?? '');

    if (empty($groupId)) {
        echo json_encode(['status' => 'error', 'msg' => 'Grup kosong']); exit;
    }

    // JIKA DIJADWALKAN (SIMPAN KE DB, JANGAN KIRIM KE ONESENDER)
    if ($tipeJadwal === 'sekali' || $tipeJadwal === 'harian') {
        $savedImagePath = null;
        if ($imageFile) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fileName = time() . '_' . rand(100,999) . '_' . basename($imageFile['name']);
            $savedImagePath = 'uploads/' . $fileName;
            move_uploaded_file($imageFile['tmp_name'], $uploadDir . $fileName);
        }

        $waktuKirimSekali = ($tipeJadwal === 'sekali') ? $waktuJadwal : null;
        $waktuKirimHarian = ($tipeJadwal === 'harian') ? $jamHarian : null;
        $statusAwal = 'pending';
        $terakhirDikirim = null;

        if ($tipeJadwal === 'harian' && !empty($jamHarian)) {
            date_default_timezone_set('Asia/Jakarta');
            if ($jamHarian <= date('H:i')) {
                $terakhirDikirim = date('Y-m-d'); // Cegah dikirim hari ini jika jam sudah lewat
            }
        }

        $stmt = $conn->prepare("INSERT INTO jadwal_pesan_grup (id_grup, pesan, media_path, tipe_jadwal, jadwal_kirim, jam_harian, hari_rutin, status, terakhir_dikirim) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", $groupId, $pesan, $savedImagePath, $tipeJadwal, $waktuKirimSekali, $waktuKirimHarian, $hariRutin, $statusAwal, $terakhirDikirim);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'msg' => 'Pesan berhasil dijadwalkan']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Gagal Database: ' . $conn->error]);
        }
        $stmt->close();
        exit;
    }

    // JIKA KIRIM SEKARANG (REALTIME MENGGUNAKAN API ASLI)
    $namaGrupLog = "Grup ID: " . $groupId;
    $namaResult = $conn->query("SELECT nama_grup FROM wa_grup WHERE id_grup = '{$conn->real_escape_string($groupId)}'");
    if($namaRow = $namaResult->fetch_assoc()) $namaGrupLog = $namaRow['nama_grup'];
    
    $result = kirimPesanGrupCanggih($groupId, $pesan, $imageFile, $apiUrl, $apiToken, $conn);
    
    $logMessage = "[" . $result['status'] . "] [GRUP] " . $result['message'];
    if ($imageFile) $logMessage .= " (dengan gambar)";
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
    $idJadwalList = $_POST['id_jadwal']; // misal "12,13,14"
    $ids = array_map('intval', explode(',', $idJadwalList));
    $idsString = implode(',', $ids);
    if (!empty($idsString)) {
        $conn->query("DELETE FROM jadwal_pesan_grup WHERE id IN ($idsString)");
    }
    $_SESSION['notification'] = "Semua jadwal dalam kelompok ini berhasil dibatalkan.";
    $_SESSION['notification_type'] = 'success';
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['batalkan_jadwal'])) {
    $idJadwalList = $_POST['id_jadwal']; // misal "12,13,14"
    $ids = array_map('intval', explode(',', $idJadwalList));
    $idsString = implode(',', $ids);
    if (!empty($idsString)) {
        $conn->query("DELETE FROM jadwal_pesan_grup WHERE id IN ($idsString)");
    }
    $_SESSION['notification'] = "Semua jadwal dalam kelompok ini berhasil dibatalkan.";
    $_SESSION['notification_type'] = 'success';
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

// HAPUS SEMUA JADWAL
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['hapus_semua_jadwal'])) {
    $result = $conn->query("DELETE FROM jadwal_pesan_grup WHERE status = 'pending'");
    if ($result) {
        $affected = $conn->affected_rows;
        $_SESSION['notification'] = "Berhasil menghapus {$affected} jadwal yang sedang berjalan.";
        $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus jadwal: " . $conn->error;
        $_SESSION['notification_type'] = 'error';
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}
// AMBIL DATA JADWAL BERJALAN (DIKELOMPOKKAN PER PESAN/JADWAL)
$jadwalBerjalan = [];
$jadwalQuery = "
    SELECT 
        j.pesan, 
        j.tipe_jadwal, 
        j.jadwal_kirim, 
        j.jam_harian, 
        j.hari_rutin, 
        j.media_path,
        GROUP_CONCAT(DISTINCT COALESCE(w.nama_grup, j.id_grup) ORDER BY COALESCE(w.nama_grup, j.id_grup) SEPARATOR ', ') as daftar_grup,
        GROUP_CONCAT(DISTINCT j.id SEPARATOR ',') as id_jadwal_list,
        COUNT(DISTINCT j.id_grup) as total_grup
    FROM jadwal_pesan_grup j 
    LEFT JOIN wa_grup w ON j.id_grup = w.id_grup 
    WHERE j.status = 'pending' 
    GROUP BY 
        j.pesan, 
        COALESCE(j.jadwal_kirim, ''), 
        COALESCE(j.jam_harian, ''), 
        COALESCE(j.hari_rutin, ''), 
        j.tipe_jadwal, 
        COALESCE(j.media_path, '')
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

// Helper konversi hari
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }
    .whatsapp-chat-preview { background-image: url('https://i.ibb.co/7z3P8sW/wa-bg.png'); background-size: cover; }
    .chat-bubble { max-width: 80%; }
    .bg-primary { background-color: #374151; }
    .bg-secondary { background-color: #4b5563; }
    .bg-card { background-color: #f8fafc; }
    .text-primary { color: #111827; }
    .text-secondary { color: #4b5563; }
    .border-card { border-color: #e2e8f0; }
    .bg-accent { background-color: #9ca3af; }
    .text-accent { color: #9ca3af; }
    .bg-success { background-color: #d1fae5; }
    .text-success { color: #065f46; }
    .bg-error { background-color: #fee2e2; }
    .text-error { color: #991b1b; }
    .btn-primary { background: linear-gradient(to right, #3b82f6, #2563eb); }
    .btn-primary:hover { background: linear-gradient(to right, #2563eb, #1d4ed8); }
    @keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fade-in-down { animation: fadeInDown 0.4s ease-out forwards; }
    .mode-card { transition: all 0.2s ease; }
    .mode-card.active-mode { background-color: #eff6ff; border-color: #3b82f6; box-shadow: 0 2px 5px rgba(59,130,246,0.1); }
  </style>
</head>
<body class="bg-gray-50 text-gray-800">

  <div id="app" class="flex flex-col min-h-screen">
    <header class="bg-gray-800 text-white shadow-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-3">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                <div class="flex items-center space-x-3">
                    <div class="bg-gradient-to-br from-gray-600 to-gray-700 p-2 sm:p-3 rounded-xl shadow-lg">
                        <i class="fas fa-users text-white text-xl sm:text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-bold text-white">Kirim Pesan Grup</h1>
                        <p class="text-xs sm:text-sm text-gray-300">Atur Promosi Ke Grup WhatsApp</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                    <a href="reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-bell mr-1"></i> Pembayaran</a>
                    <a href="promosi.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-bullhorn mr-1"></i> Promosi </a>
                    <a href="kelola_reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-message mr-1"></i> Reminder Peserta</a>
                    <a href="kelola_grup.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-plus-circle mr-1"></i> Tambah Grup</a>
                    <a href="logoutwa.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-right-from-bracket"></i> Keluar</a>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow p-4 sm:p-6 lg:p-8">
      <div class="max-w-7xl mx-auto relative">
        
        <div id="loader" class="hidden animate-fade-in-down mb-6 bg-white rounded-xl shadow-lg border border-blue-100 overflow-hidden relative">
            <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-400 via-indigo-500 to-purple-500"></div>
            <div class="p-5 flex flex-col sm:flex-row items-center gap-5">
                <div class="bg-blue-50 w-14 h-14 rounded-full flex items-center justify-center shrink-0 shadow-inner border border-blue-100">
                    <i class="fas fa-paper-plane text-2xl text-blue-500 animate-bounce"></i>
                </div>
                <div class="flex-1 w-full">
                    <div class="flex justify-between items-end mb-2">
                        <div>
                            <h3 class="font-extrabold text-slate-800 text-base" id="loadingTitle">Proses Berjalan...</h3>
                            <p id="progressStatus" class="text-xs font-medium text-slate-500 mt-0.5">Mempersiapkan data...</p>
                        </div>
                        <p id="progressText" class="text-lg font-black text-blue-600">0 / 0</p>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-3 shadow-inner overflow-hidden relative">
                        <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-indigo-600 h-full rounded-full transition-all duration-300 w-0 relative">
                            <div class="absolute inset-0 bg-white/20"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($notification)): ?>
        <div class="mb-6 p-4 rounded-lg shadow-md <?php echo $notificationType === 'success' ? 'bg-success text-success border-l-4 border-success' : 'bg-error text-error border-l-4 border-error'; ?>"><?php echo htmlspecialchars($notification); ?></div>
        <?php endif; ?>

        <!-- BARIS ATAS: FORM KIRIM + PREVIEW CHAT -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            <!-- KOLOM KIRI: FORM PENGIRIMAN -->
            <div class="space-y-6">
                <form enctype="multipart/form-data" id="main-form">
                    <div class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">
                        <h2 class="text-lg font-semibold mb-4 flex items-center text-gray-800"><i class="fas fa-pencil-alt text-xl mr-2 text-blue-500"></i> Buat Pesan Anda</h2>
                        <div class="space-y-4">
                            <div>
                                <label for="pesan" class="block font-semibold text-sm text-gray-700 mb-1">Isi Pesan / Caption</label>
                                <textarea id="pesan" name="pesan" rows="8" class="w-full border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-400 text-sm transition bg-white" placeholder="Tulis pesan Anda di sini..."></textarea>
                            </div>
                            <div>
                                <label for="promo_image" class="block font-semibold text-sm text-gray-700 mb-1">Upload Gambar (Opsional - Max 5MB)</label>
                                <input type="file" id="promo_image" name="promo_image" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer" accept="image/png, image/jpeg, image/gif">
                            </div>

                            <!-- MODE PENGIRIMAN - TAMPILAN DIPERBARUI -->
                            <div class="mt-4 p-5 bg-gradient-to-r from-slate-50 to-blue-50 border border-blue-200 rounded-2xl shadow-sm">
                                <label class="block font-bold text-sm text-blue-800 mb-3"><i class="fas fa-clock mr-2"></i> Mode Pengiriman</label>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                                    <div class="mode-card border rounded-xl p-3 cursor-pointer text-center transition hover:shadow-md" data-mode="sekarang">
                                        <input type="radio" name="tipe_jadwal" value="sekarang" id="mode_sekarang" class="hidden" checked>
                                        <i class="fas fa-bolt text-2xl text-yellow-500 mb-1 block"></i>
                                        <span class="font-semibold text-sm">Kirim Sekarang</span>
                                        <p class="text-xs text-gray-500">Langsung terkirim</p>
                                    </div>
                                    <div class="mode-card border rounded-xl p-3 cursor-pointer text-center transition hover:shadow-md" data-mode="sekali">
                                        <input type="radio" name="tipe_jadwal" value="sekali" id="mode_sekali" class="hidden">
                                        <i class="fas fa-calendar-day text-2xl text-indigo-500 mb-1 block"></i>
                                        <span class="font-semibold text-sm">Jadwal Sekali</span>
                                        <p class="text-xs text-gray-500">Tgl & waktu tertentu</p>
                                    </div>
                                    <div class="mode-card border rounded-xl p-3 cursor-pointer text-center transition hover:shadow-md" data-mode="harian">
                                        <input type="radio" name="tipe_jadwal" value="harian" id="mode_harian" class="hidden">
                                        <i class="fas fa-clock text-2xl text-teal-500 mb-1 block"></i>
                                        <span class="font-semibold text-sm">Rutin Harian</span>
                                        <p class="text-xs text-gray-500">Setiap hari & jam</p>
                                    </div>
                                </div>
                                <div id="input_sekali" class="hidden mt-3 p-3 bg-white rounded-lg border">
                                    <label for="waktu_jadwal" class="block text-xs text-blue-600 mb-1 font-bold">Pilih Tanggal & Waktu Kirim:</label>
                                    <input type="datetime-local" id="waktu_jadwal" name="waktu_jadwal" class="w-full border-gray-300 rounded-lg p-2 text-sm bg-white">
                                </div>
                                <div id="input_harian" class="hidden mt-3 p-3 bg-white rounded-lg border">
                                    <label for="jam_harian" class="block text-xs text-blue-600 mb-1 font-bold">Kirim pada jam:</label>
                                    <input type="time" id="jam_harian" name="jam_harian" class="w-full border-gray-300 rounded-lg p-2 text-sm bg-white mb-3">
                                    <label class="block text-xs text-blue-600 mb-1 font-bold">Pilih Hari Rutin:</label>
                                    <div class="flex flex-wrap gap-3">
                                        <label class="flex items-center text-xs bg-gray-100 px-2 py-1 rounded"><input type="checkbox" name="hari_rutin[]" value="1" class="mr-1"> Sen</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-2 py-1 rounded"><input type="checkbox" name="hari_rutin[]" value="2" class="mr-1"> Sel</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-2 py-1 rounded"><input type="checkbox" name="hari_rutin[]" value="3" class="mr-1"> Rab</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-2 py-1 rounded"><input type="checkbox" name="hari_rutin[]" value="4" class="mr-1"> Kam</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-2 py-1 rounded"><input type="checkbox" name="hari_rutin[]" value="5" class="mr-1"> Jum</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-2 py-1 rounded"><input type="checkbox" name="hari_rutin[]" value="6" class="mr-1"> Sab</label>
                                        <label class="flex items-center text-xs bg-gray-100 px-2 py-1 rounded"><input type="checkbox" name="hari_rutin[]" value="7" class="mr-1"> Min</label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- PILIH GRUP PENERIMA - TAMPILAN DIPERBARUI -->
                            <div class="border-t pt-4 mt-2">
                                <h2 class="text-lg font-semibold mb-3 flex items-center text-gray-800"><i class="fas fa-check-square text-xl mr-2 text-blue-500"></i> Pilih Grup Penerima</h2>
                                <div class="relative">
                                    <button type="button" id="group-filter-btn" class="w-full bg-white border-2 border-gray-300 rounded-xl p-3 text-left flex justify-between items-center focus:ring-2 focus:ring-blue-400 transition hover:bg-gray-50">
                                        <span id="group-filter-btn-text" class="font-medium">Pilih Grup Penerima</span>
                                        <i class="fas fa-chevron-down text-gray-500"></i>
                                    </button>
                                    <div id="group-filter-popup" class="hidden absolute z-20 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-xl max-h-72 overflow-y-auto p-3">
                                        <?php if (!empty($groupsByCategory)): ?>
                                            <?php foreach($groupsByCategory as $kategori => $groups): ?>
                                            <div class="category-group space-y-1 mb-3">
                                                <label class="flex items-center space-x-3 cursor-pointer p-2 border-b bg-gray-50 rounded-t">
                                                    <input type="checkbox" class="category-checkbox h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                    <span class="text-sm font-bold text-gray-700"><?= htmlspecialchars($kategori) ?></span>
                                                </label>
                                                <div class="pl-4 pt-1 space-y-1">
                                                    <?php foreach($groups as $group): ?>
                                                    <label class="flex items-center space-x-3 cursor-pointer p-1.5 rounded-md hover:bg-blue-50 transition">
                                                        <input type="checkbox" name="selected_groups[]" value="<?= htmlspecialchars($group['id_grup']) ?>" class="group-checkbox h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                        <span class="text-sm text-gray-700"><?= htmlspecialchars($group['nama_grup']) ?></span>
                                                    </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-500 text-center p-8">Belum ada grup. <a href="kelola_grup.php" class="text-blue-600 font-semibold hover:underline">Tambah Grup</a></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2"><i class="fas fa-info-circle"></i> Centang grup tujuan, bisa lebih dari satu.</p>
                            </div>
                            <div class="border-t pt-4">
                                <button type="submit" name="kirim_grup" class="w-full btn-primary text-white px-6 py-3 rounded-xl shadow-lg font-bold text-lg transition flex items-center justify-center transform hover:scale-[1.02]">
                                    <i class="fas fa-paper-plane text-2xl mr-2"></i> Proses
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- KOLOM KANAN: PREVIEW CHAT -->
            <div class="space-y-6 lg:sticky top-24">
                 <div class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">
                     <h2 class="text-lg font-semibold mb-4 flex items-center text-gray-800"><i class="fab fa-whatsapp text-xl mr-2 text-green-500"></i> Preview Chat</h2>
                     <div class="w-full max-w-sm mx-auto bg-white rounded-2xl shadow-lg overflow-hidden border">
                         <div class="whatsapp-chat-preview p-4 h-96 flex flex-col-reverse overflow-y-auto bg-cover">
                            <div id="chat-preview-container" class="flex flex-col items-end space-y-2">
                            </div>
                         </div>
                     </div>
                </div>
            </div>
        </div>

        <!-- DUA ROW RIWAYAT: RIWAYAT ISI PESAN & RIWAYAT PENGIRIMAN -->
        <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            <div class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold flex items-center text-gray-800"><i class="fas fa-comment-dots text-xl mr-2 text-blue-500"></i> Riwayat Isi Pesan</h2>
                    <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus semua riwayat isi pesan?');" class="inline-block">
                        <button type="submit" name="delete_message_history" class="text-xs bg-red-100 text-red-700 font-semibold px-3 py-1.5 rounded-lg hover:bg-red-200 transition flex items-center"><i class="fas fa-trash text-sm mr-1.5"></i> Hapus Semua</button>
                    </form>
                </div>
                <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
                     <?php if(!empty($pesanHistory)): ?>
                        <?php foreach($pesanHistory as $history): ?>
                            <button type="button" class="use-again-btn w-full text-left text-sm p-3 bg-gray-50 rounded-lg border hover:bg-blue-50 hover:border-blue-300 transition" data-message="<?= htmlspecialchars($history['sent_content']) ?>">
                                <p class="truncate text-gray-700"><?= htmlspecialchars($history['sent_content']) ?></p>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-500 text-center p-8">Belum ada riwayat isi pesan.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold flex items-center text-gray-800"><i class="fas fa-history text-xl mr-2 text-blue-500"></i> Riwayat Pengiriman</h2>
                    <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus semua riwayat pengiriman grup?');" class="inline-block">
                        <button type="submit" name="delete_history" class="text-xs bg-red-100 text-red-700 font-semibold px-3 py-1.5 rounded-lg hover:bg-red-200 transition flex items-center"><i class="fas fa-trash text-sm mr-1.5"></i> Hapus Semua</button>
                    </form>
                </div>
                <div class="space-y-3 max-h-96 overflow-y-auto pr-2">
                    <?php if(!empty($logPesan)): ?>
                        <?php foreach($logPesan as $log): ?>
                            <?php
                            $logParts = explode(' :: ', $log['message'], 2);
                            $statusMessage = $logParts[0];
                            $isSuccess = strpos($statusMessage, '[TERKIRIM]') !== false;
                            ?>
                            <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg border">
                                <div><?php if ($isSuccess): ?><i class="fas fa-check-circle text-green-500 text-xl mt-1"></i><?php else: ?><i class="fas fa-times-circle text-red-500 text-xl mt-1"></i><?php endif; ?></div>
                                <div class="flex-1 overflow-hidden">
                                    <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($log['nama']) ?></p>
                                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($statusMessage) ?></p>
                                    <div class="flex justify-between items-center mt-2">
                                        <p class="text-gray-400 text-[10px]"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></p>
                                        <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus riwayat ini?');" class="inline-block">
                                            <input type="hidden" name="delete_log_id" value="<?= $log['id'] ?>">
                                            <button type="submit" class="text-gray-400 hover:text-red-500" title="Hapus riwayat ini"><i class="fas fa-trash text-sm"></i></button>
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

        <!-- TABEL JADWAL SEDANG BERJALAN (DIKELOMPOKKAN PER PESAN, DENGAN TOMBOL HAPUS SEMUA JADWAL DALAM SATU KELOMPOK) -->
        <div class="mt-8 bg-white shadow-md rounded-2xl p-6 border border-gray-100">
            <h2 class="text-lg font-semibold mb-4 flex items-center text-gray-800"><i class="fas fa-calendar-alt text-xl mr-2 text-blue-500"></i> Jadwal Sedang Berjalan</h2>
             <?php if(!empty($jadwalBerjalan)): ?>
        <form method="POST" onsubmit="return confirm('⚠️ PERINGATAN! Anda yakin ingin menghapus SEMUA jadwal yang ada (<?= count($jadwalBerjalan) ?> kelompok jadwal)? Tindakan ini tidak dapat dibatalkan!');" class="inline-block">
            <button type="submit" name="hapus_semua_jadwal" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-bold transition flex items-center shadow-md">
                <i class="fas fa-trash-alt mr-2"></i> Hapus Semua Jadwal
            </button>
        </form>
        <?php endif; ?>
    </div>
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                    <thead class="bg-gray-100 text-gray-600 text-xs uppercase text-left">
                        <tr>
                            <th class="py-3 px-4 border-b">Grup Target</th>
                            <th class="py-3 px-4 border-b">Pesan</th>
                            <th class="py-3 px-4 border-b">Tipe Jadwal</th>
                            <th class="py-3 px-4 border-b">Waktu Kirim</th>
                            <th class="py-3 px-4 border-b text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php if(!empty($jadwalBerjalan)): ?>
                            <?php foreach($jadwalBerjalan as $j): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 border-b">
                                    <span class="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs"><?= $j['total_grup'] ?> Grup Target</span><br>
                                    <span class="text-xs text-gray-500 truncate block max-w-xs mt-1" title="<?= htmlspecialchars($j['daftar_grup']) ?>">
                                        <?= htmlspecialchars($j['daftar_grup']) ?>
                                    </span>
                                 </td>
                                <td class="py-3 px-4 border-b max-w-xs truncate" title="<?= htmlspecialchars($j['pesan']) ?>">
                                    <?php if(!empty($j['media_path'])): ?>
                                        <i class="fas fa-image text-blue-400 mr-1" title="Terdapat gambar"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars(mb_substr($j['pesan'], 0, 60)) . (strlen($j['pesan']) > 60 ? '...' : '') ?>
                                 </td>
                                <td class="py-3 px-4 border-b font-semibold uppercase text-xs">
                                    <span class="px-2 py-1 rounded-full <?= $j['tipe_jadwal'] == 'sekali' ? 'bg-indigo-100 text-indigo-700' : ($j['tipe_jadwal'] == 'harian' ? 'bg-teal-100 text-teal-700' : 'bg-gray-100') ?>">
                                        <?= $j['tipe_jadwal'] == 'sekali' ? 'Sekali' : ($j['tipe_jadwal'] == 'harian' ? 'Harian' : 'Sekarang') ?>
                                    </span>
                                 </td>
                                <td class="py-3 px-4 border-b text-xs">
                                    <?php if($j['tipe_jadwal'] == 'sekali'): ?>
                                        <i class="far fa-calendar-alt mr-1"></i> <?= date('d M Y, H:i', strtotime($j['jadwal_kirim'])) ?>
                                    <?php elseif($j['tipe_jadwal'] == 'harian'): ?>
                                        <i class="fas fa-clock mr-1"></i> <?= htmlspecialchars($j['jam_harian']) ?><br>
                                        <i class="fas fa-calendar-week mr-1"></i> <?= formatHariRutin($j['hari_rutin']) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                 </td>
                                <td class="py-3 px-4 border-b text-center">
                                    <form method="POST" onsubmit="return confirm('Batalkan SEMUA jadwal untuk kelompok ini (<?= $j['total_grup'] ?> grup)?');">
                                        <input type="hidden" name="id_jadwal" value="<?= $j['id_jadwal_list'] ?>">
                                        <button type="submit" name="batalkan_jadwal" class="bg-red-50 text-red-600 px-3 py-1.5 rounded-lg hover:bg-red-100 text-xs font-bold transition flex items-center justify-center mx-auto">
                                            <i class="fas fa-trash-alt mr-1"></i> Hapus Semua
                                        </button>
                                    </form>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="py-6 text-center text-gray-500">Tidak ada jadwal aktif.</td></tr>
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

// Highlight mode selection visual
function initModeCards() {
    const cards = document.querySelectorAll('.mode-card');
    const radios = document.querySelectorAll('input[name="tipe_jadwal"]');
    
    function updateActive() {
        const selectedVal = document.querySelector('input[name="tipe_jadwal"]:checked').value;
        cards.forEach(card => {
            const mode = card.dataset.mode;
            if(mode === selectedVal) {
                card.classList.add('active-mode', 'border-blue-400', 'bg-blue-50');
                card.classList.remove('border-gray-200');
            } else {
                card.classList.remove('active-mode', 'border-blue-400', 'bg-blue-50');
                card.classList.add('border-gray-200');
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
        if (fileInput.files.length > 0) {
            if (fileInput.files[0].size > (5 * 1024 * 1024)) { alert("Gagal! Ukuran gambar terlalu besar. Maksimal 5 MB."); return; }
        }
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
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin text-2xl mr-2"></i> Proses...';
        }

        let successCount = 0;
        let failCount = 0;
        let total = selectedGroupsCount;
        let groupsArray = Array.from(groupCheckboxes).map(cb => cb.value);

        pBar.style.width = '0%';
        pText.innerText = `0 / ${total}`;
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
            
            if (fileInput.files.length > 0) {
                formData.append('promo_image', fileInput.files[0]);
            }

            try {
                let response = await fetch(window.location.href, { method: 'POST', body: formData });
                let json = await response.json();
                
                if (json.status === 'success') {
                    successCount++;
                    pStat.innerHTML = `<span class="text-green-600 font-bold">✓ Sukses: ${groupName}</span>`;
                } else {
                    failCount++;
                    pStat.innerHTML = `<span class="text-red-600 font-bold">✗ Gagal: ${groupName} - ${json.msg}</span>`;
                }
            } catch (error) {
                failCount++;
                pStat.innerHTML = `<span class="text-red-600 font-bold">✗ Error: ${groupName}</span>`;
            }

            let pct = Math.round(((i + 1) / total) * 100);
            pBar.style.width = pct + '%';
            pText.innerText = `${i + 1} / ${total}`;

            await new Promise(r => setTimeout(r, 600));
        }

        pStat.innerHTML = `<span class="text-blue-600 font-bold">Proses Selesai! Memuat ulang riwayat...</span>`;
        setTimeout(() => { window.location.reload(); }, 1500);
    });

    const pesanTextarea = document.getElementById('pesan');
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
            if (selectedCount === 0) {
                groupBtnText.textContent = `Pilih Grup Penerima`;
            } else {
                groupBtnText.textContent = `${selectedCount} Grup Terpilih`;
            }
        }
        updateButtonText();
    }
    
    document.querySelectorAll('.use-again-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const message = this.dataset.message;
            pesanTextarea.value = message;
            pesanTextarea.dispatchEvent(new Event('input')); 
            window.scrollTo({ top: 0, behavior: 'smooth' }); 
        });
    });

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
        bubble.className = 'chat-bubble self-end bg-green-100 rounded-lg p-2 shadow-sm flex flex-col';
        
        let contentHTML = '';

        if(imageFile) {
            const reader = new FileReader();
            reader.onload = function(e) {
                let imgContainer = bubble.querySelector('#preview-image-container');
                if (imgContainer) {
                    imgContainer.innerHTML = `<img src="${e.target.result}" class="rounded-md max-h-40 w-auto">`;
                }
            }
            reader.readAsDataURL(imageFile);
            contentHTML += `<div id="preview-image-container" class="mb-1"></div>`;
        }

        if (messageText) {
            contentHTML += `<div class="text-sm text-gray-800 break-words">${renderPreviewMessage(messageText)}</div>`;
        }
        
        contentHTML += `<div class="text-right text-xs text-gray-400 mt-1">1:30 PM</div>`;
        bubble.innerHTML = contentHTML;

        previewContainer.appendChild(bubble);
    }
    
    pesanTextarea.addEventListener('input', updatePreview);
    imageInput.addEventListener('change', updatePreview);
    
    updatePreview();
});
</script> <script>
        // Menyembunyikan header asli jika halaman ini dibuka di dalam iframe dashboard
        if (window.self !== window.top) {
            const headerElement = document.querySelector('header');
            if (headerElement) {
                headerElement.style.display = 'none';
            }
            
            // Memastikan background transparan agar menyatu dengan efek glassmorphism dashboard
            document.body.style.backgroundColor = "transparent";
        }
    </script>
</body>
</html>