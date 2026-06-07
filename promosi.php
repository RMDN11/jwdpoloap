<?php
session_start();
require_once 'auth_checkwa.php';
require_once 'config.php';

// Masukkan semua fungsi PHP yang dibutuhkan di sini
function kirimPromosi($recipient, $caption, $imagePath, $imageMime, $imageName, $apiUrl, $apiToken) {
    if (strpos($apiUrl, '/v1/messages') !== false) {
        $legacyApiUrl = str_replace('/v1/messages', '/message', $apiUrl);
    } else {
        $legacyApiUrl = rtrim($apiUrl, '/') . '/message';
    }
    $cfile = new CURLFile($imagePath, $imageMime, $imageName);
    $postData = ['type' => 'image', 'phone' => $recipient, 'message' => $caption, 'attachment' => $cfile];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $legacyApiUrl,
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
        return ['status' => 'GAGAL', 'message' => "cURL Error: " . $curlError];
    }
    $responseData = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['status' => 'TERKIRIM', 'message' => "Berhasil dikirim."];
    } else {
        $errorMessage = isset($responseData['message']) ? $responseData['message'] : "Unknown error.";
        return ['status' => 'GAGAL', 'message' => "Pengiriman tidak berhasil: " . $errorMessage];
    }
}

// =================================================================
// TANGKAP REQUEST AJAX SATUAN (UNTUK PROGRESS BAR REALTIME)
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_kirim_promosi'])) {
    header('Content-Type: application/json');
    
    $nowa = $_POST['nowa'] ?? '';
    $nama = $_POST['nama'] ?? '';
    $captionText = $_POST['caption_text'] ?? '';
    $regLink = $_POST['registration_link'] ?? '';
    
    if (empty($nowa)) {
        echo json_encode(['status' => 'error', 'msg' => 'Nomor WA kosong']); exit;
    }

    // Gabung caption dan link
    $finalCaption = $captionText;
    if (!empty($regLink)) { $finalCaption .= "\n" . $regLink; }
    $personalizedCaption = str_replace('{nama}', $nama, $finalCaption);

    $tempImagePath = null;
    $imageType = '';
    $imageName = '';
    
    if (isset($_FILES['promo_image']) && $_FILES['promo_image']['error'] == UPLOAD_ERR_OK) {
        $tempImagePath = $_FILES['promo_image']['tmp_name'];
        $imageType = $_FILES['promo_image']['type'];
        $imageName = $_FILES['promo_image']['name'];
    }

    $result = kirimPromosi($nowa, $personalizedCaption, $tempImagePath, $imageType, $imageName, $apiUrl, $apiToken);
    
    $finalLogMessage = "[".$result['status']."] [PROMOSI] " . $result['message'];
    $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $nowa, $nama, $finalLogMessage);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'status' => ($result['status'] == 'TERKIRIM') ? 'success' : 'error',
        'msg' => $result['message']
    ]);
    exit;
}

// =================================================================
// LOGIKA TRADISIONAL & NOTIFIKASI
// =================================================================
$notification = '';
$notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notification_type'];
    unset($_SESSION['notification'], $_SESSION['notification_type']);
}

// PROSES KIRIM PROMOSI TRADISIONAL (Sebagai Fallback)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['kirim_promosi'])) {
    $captionText = $_POST['caption_text'] ?? '';
    $regLink = $_POST['registration_link'] ?? '';
    $tempImagePath = null;
    
    if (isset($_FILES['promo_image'])) {
        switch ($_FILES['promo_image']['error']) {
            case UPLOAD_ERR_OK:
                $tempImagePath = $_FILES['promo_image']['tmp_name'];
                break;
            case UPLOAD_ERR_NO_FILE:
                $_SESSION['notification'] = "Tidak ada file yang diupload."; $_SESSION['notification_type'] = 'error';
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $_SESSION['notification'] = "Ukuran file terlalu besar. Maksimal 5 MB."; $_SESSION['notification_type'] = 'error';
                break;
            default:
                $_SESSION['notification'] = "Terjadi error yang tidak diketahui saat upload file."; $_SESSION['notification_type'] = 'error';
                break;
        }
    } else {
        $_SESSION['notification'] = "Anda harus memilih sebuah gambar untuk promosi."; $_SESSION['notification_type'] = 'error';
    }
    
    if ($tempImagePath && !empty($captionText)) {
        $finalCaption = $captionText;
        if (!empty($regLink)) { $finalCaption .= "\n" . $regLink; }
        $searchPromo = $_POST['search_promo_hidden'] ?? '';
        $halaqohPromo = $_POST['halaqoh_promo_hidden'] ?? [];
        $statusPromo = $_POST['status_promo_hidden'] ?? 'proses';
        $pembayaranPromo = $_POST['pembayaran_promo_hidden'] ?? '';
        
        $sqlPromo = "SELECT p.nowa, p.nama_lengkap, MAX(pemb.id) as id_pembayaran FROM peserta p LEFT JOIN pembayaran pemb ON p.id = pemb.peserta_id WHERE p.nowa IS NOT NULL AND p.nowa != ''";
        $paramsPromo = [];
        $typesPromo = '';
        if ($searchPromo) { $sqlPromo .= " AND p.nama_lengkap LIKE ?"; $typesPromo .= 's'; $paramsPromo[] = '%' . $searchPromo . '%'; }
        if (!empty($halaqohPromo)) {
            $placeholders = implode(',', array_fill(0, count($halaqohPromo), '?'));
            $sqlPromo .= " AND p.halaqoh IN ($placeholders)";
            $typesPromo .= str_repeat('s', count($halaqohPromo));
            $paramsPromo = array_merge($paramsPromo, $halaqohPromo);
        }
        if ($statusPromo && $statusPromo !== 'semua') { $sqlPromo .= " AND p.status = ?"; $typesPromo .= 's'; $paramsPromo[] = $statusPromo; }
        $sqlPromo .= " GROUP BY p.id, p.nowa, p.nama_lengkap, p.halaqoh, p.status";
        if ($pembayaranPromo === 'lunas') { $sqlPromo .= " HAVING id_pembayaran IS NOT NULL"; }
        elseif ($pembayaranPromo === 'belum_lunas') { $sqlPromo .= " HAVING id_pembayaran IS NULL"; }
        
        $stmtPromo = $conn->prepare($sqlPromo);
        if (!empty($paramsPromo)) { $stmtPromo->bind_param($typesPromo, ...$paramsPromo); }
        $stmtPromo->execute();
        $resultPromo = $stmtPromo->get_result();
        $allPeserta = $resultPromo->fetch_all(MYSQLI_ASSOC);
        $stmtPromo->close();
        
        if (!empty($allPeserta)) {
            $pesertaToLog = [];
            $jumlahTerkirim = 0;
            foreach ($allPeserta as $peserta) {
                $personalizedCaption = str_replace('{nama}', $peserta['nama_lengkap'], $finalCaption);
                $result = kirimPromosi($peserta['nowa'], $personalizedCaption, $tempImagePath, $_FILES['promo_image']['type'], $_FILES['promo_image']['name'], $apiUrl, $apiToken);
                $finalLogMessage = "[".$result['status']."] [PROMOSI] " . $result['message'];
                $pesertaToLog[] = ['nowa' => $peserta['nowa'], 'nama' => $peserta['nama_lengkap'], 'pesan' => $finalLogMessage];
                if ($result['status'] == 'TERKIRIM') {
                    $jumlahTerkirim++;
                }
            }
            $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message) VALUES (?, ?, ?)");
            foreach ($pesertaToLog as $log) {
                $stmt->bind_param("sss", $log['nowa'], $log['nama'], $log['pesan']);
                $stmt->execute();
            }
            $stmt->close();
            $_SESSION['notification'] = "Promosi berhasil dikirim ke " . $jumlahTerkirim . " peserta yang difilter!";
            $_SESSION['notification_type'] = 'success';
        } else {
            $_SESSION['notification'] = "Tidak ditemukan peserta yang cocok dengan filter untuk dikirimkan promosi.";
            $_SESSION['notification_type'] = 'error';
        }
    } else {
        if (empty($_SESSION['notification'])) {
            $_SESSION['notification'] = "Gagal mengirim promosi. Pastikan Anda mengupload gambar dan mengisi caption.";
            $_SESSION['notification_type'] = 'error';
        }
    }
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// =================================================================
// AMBIL DATA UNTUK TAMPILAN
// =================================================================
$halaqohList = [];
$halaqohResult = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh != '' ORDER BY halaqoh");
while ($row = $halaqohResult->fetch_assoc()) {
    $halaqohList[] = $row['halaqoh'];
}

$search_promo = $_GET['search_promo'] ?? '';
$halaqoh_promo = $_GET['halaqoh_promo'] ?? [];
if (!is_array($halaqoh_promo)) {
    $halaqoh_promo = !empty($halaqoh_promo) ? [$halaqoh_promo] : [];
}
$status_promo = $_GET['status_promo'] ?? 'proses';
$pembayaran_promo = $_GET['pembayaran_promo'] ?? '';

$sqlPromoList = "SELECT p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status, MAX(pemb.id) as id_pembayaran FROM peserta p LEFT JOIN pembayaran pemb ON p.id = pemb.peserta_id WHERE p.nowa IS NOT NULL AND p.nowa != ''";
$paramsPromoList = [];
$typesPromoList = '';

if ($search_promo) { $sqlPromoList .= " AND p.nama_lengkap LIKE ?"; $typesPromoList .= 's'; $paramsPromoList[] = '%' . $search_promo . '%'; }
if (!empty($halaqoh_promo)) {
    $placeholders = implode(',', array_fill(0, count($halaqoh_promo), '?'));
    $sqlPromoList .= " AND p.halaqoh IN ($placeholders)";
    $typesPromoList .= str_repeat('s', count($halaqoh_promo));
    $paramsPromoList = array_merge($paramsPromoList, $halaqoh_promo);
}
if ($status_promo && $status_promo !== 'semua') { $sqlPromoList .= " AND p.status = ?"; $typesPromoList .= 's'; $paramsPromoList[] = $status_promo; }

$sqlPromoList .= " GROUP BY p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status";

if ($pembayaran_promo === 'lunas') { $sqlPromoList .= " HAVING id_pembayaran IS NOT NULL"; }
elseif ($pembayaran_promo === 'belum_lunas') { $sqlPromoList .= " HAVING id_pembayaran IS NULL"; }

$sqlPromoList .= " ORDER BY p.halaqoh, p.nama_lengkap";
$stmtPromoList = $conn->prepare($sqlPromoList);
if (!empty($paramsPromoList)) { $stmtPromoList->bind_param($typesPromoList, ...$paramsPromoList); }
$stmtPromoList->execute();
$resultPromoList = $stmtPromoList->get_result();
$pesertaPromo = $resultPromoList->fetch_all(MYSQLI_ASSOC);
$stmtPromoList->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kirim Promosi - JWD</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        
        .bg-primary { background-color: #374151; }
        .bg-secondary { background-color: #4b5563; }
        .bg-card { background-color: #f3f4f6; }
        .text-primary { color: #111827; }
        .text-secondary { color: #4b5563; }
        .border-card { border-color: #e5e7eb; }
        .bg-accent { background-color: #9ca3af; }
        .text-accent { color: #9ca3af; }
        .bg-accent-light { background-color: #e5e7eb; }
        .text-accent-light { color: #9ca3af; }
        .bg-success { background-color: #d1fae5; }
        .text-success { color: #065f46; }
        .bg-error { background-color: #fee2e2; }
        .text-error { color: #991b1b; }
        .border-success { border-color: #a7f3d0; }
        .border-error { border-color: #fecaca; }
        .btn-primary { background: linear-gradient(to right, #6b7280, #4b5563); color: white; }
        .btn-primary:hover { background: linear-gradient(to right, #4b5563, #374151); transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .badge-lunas { background-color: #d1fae5; color: #065f46; }
        .badge-belum-lunas { background-color: #fee2e2; color: #991b1b; }
        .badge-proses { background-color: #fef3c7; color: #92400e; }
        .badge-selesai { background-color: #d1fae5; color: #065f46; }
        .status-indicator { width: 0.5rem; height: 0.5rem; border-radius: 50%; }
        .status-proses { background-color: #f59e0b; }
        .status-selesai { background-color: #10b981; }
        
        .section-card { background-color: #f3f4f6; border-radius: 0.75rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1); }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
        .dropdown-container { position: relative; }
        .dropdown-menu { position: absolute; z-index: 20; width: 100%; background-color: white; border: 1px solid #e5e7eb; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); max-height: 16rem; overflow-y: auto; }
        .table-container { height: 500px; overflow-y: auto; overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 0.5rem; }
        .table-container table { width: 100%; min-width: 800px; }
        .table-container thead { position: sticky; top: 0; z-index: 10; background-color: #f9fafb; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); }
        .table-container th { position: sticky; top: 0; background-color: #f9fafb; z-index: 11; }
        
        .form-input { width: 100%; border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 0.625rem; background-color: white; transition: all 0.2s; }
        .form-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.625rem 1.25rem; border-radius: 0.5rem; font-weight: 500; transition: all 0.2s; cursor: pointer; }
        
        .notification { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border-left-width: 4px; }
        .notification-success { background-color: #d1fae5; color: #065f46; border-color: #10b981; }
        .notification-error { background-color: #fee2e2; color: #991b1b; border-color: #ef4444; }
        
        .whatsapp-preview { background-color: #e5ddd5; border-radius: 0.75rem; padding: 1rem; min-height: 300px; max-height: 400px; overflow-y: auto; position: relative; }
        .whatsapp-preview::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-image: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM34 90c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm56-76c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM12 86c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm28-65c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm23-11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-6 60c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm29 22c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM32 63c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm57-13c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-9-21c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM60 91c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM35 41c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM12 60c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z' fill='%239ca3af' fill-opacity='0.1' fill-rule='evenodd'/%3E%3C/svg%3E"); opacity: 0.3; pointer-events: none; }
        .message-received { background-color: white; border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 0.75rem; max-width: 85%; position: relative; box-shadow: 0 1px 0.5px rgba(0,0,0,0.13); transition: all 0.3s ease; }
        .message-received::before { content: ''; position: absolute; left: -8px; top: 0; width: 0; height: 0; border: 8px solid transparent; border-right-color: white; border-left: 0; }
        .message-image-container { position: relative; margin-bottom: 0.5rem; border-radius: 0.5rem; overflow: hidden; max-width: 100%; }
        .message-image { max-width: 100%; height: auto; display: block; border-radius: 0.5rem; }
        .image-placeholder { width: 200px; height: 150px; background-color: #f0f0f0; border-radius: 0.5rem; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #666; }
        .message-text { font-size: 0.875rem; line-height: 1.4; white-space: pre-line; word-wrap: break-word; }
        .message-time { font-size: 0.75rem; color: #667781; text-align: right; margin-top: 0.25rem; }
        .preview-header { background: linear-gradient(to right, #075e54, #128c7e); color: white; padding: 0.75rem 1rem; border-radius: 0.75rem 0.75rem 0 0; display: flex; align-items: center; }
        .preview-avatar { width: 2rem; height: 2rem; border-radius: 50%; background-color: #ddd; margin-right: 0.75rem; display: flex; align-items: center; justify-content: center; }
        .preview-info { flex: 1; }
        .preview-name { font-weight: 600; font-size: 0.875rem; }
        .preview-status { font-size: 0.75rem; opacity: 0.8; }
        .image-small { max-width: 200px; } .image-medium { max-width: 300px; } .image-large { max-width: 400px; }
        .bubble-small { max-width: 60%; } .bubble-medium { max-width: 75%; } .bubble-large { max-width: 85%; }
        
        /* Animasi Loader Inline */
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in-down { animation: fadeInDown 0.4s ease-out forwards; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">

    <div id="app" class="flex flex-col min-h-screen">
        <!-- Header -->
        <header class="bg-gray-800 text-white shadow-sm sticky top-0 z-10">
            <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-3">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                    <div class="flex items-center space-x-3">
                        <div class="bg-gradient-to-br from-gray-600 to-gray-700 p-2 sm:p-3 rounded-xl shadow-lg">
                            <i class="fas fa-bullhorn text-white text-xl sm:text-2xl"></i>
                        </div>
                        <div>
                            <h1 class="text-lg sm:text-xl font-bold text-white">Kelola Promosi</h1>
                            <p class="text-xs sm:text-sm text-gray-300">Atur Promosi Program</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                        <a href="reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-bell mr-1"></i> Pembayaran</a>
                        <a href="kelola_reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-message mr-1"></i> Reminder Peserta</a>
                        <a href="kirimgrup.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-users mr-1"></i> Grup</a>
                        <a href="logoutwa.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50"><i class="fas fa-right-from-bracket"></i> Keluar</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-grow p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl mx-auto relative">
                
                <!-- BANNER LOADER (DI BAWAH HEADER, TIDAK FULL SCREEN) -->
                <div id="loader" class="hidden animate-fade-in-down mb-6 bg-white rounded-xl shadow-lg border border-blue-100 overflow-hidden relative">
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-400 via-indigo-500 to-purple-500"></div>
                    <div class="p-5 flex flex-col sm:flex-row items-center gap-5">
                        <div class="bg-blue-50 w-14 h-14 rounded-full flex items-center justify-center shrink-0 shadow-inner border border-blue-100">
                            <i class="fas fa-paper-plane text-2xl text-blue-500 animate-bounce"></i>
                        </div>
                        <div class="flex-1 w-full">
                            <div class="flex justify-between items-end mb-2">
                                <div>
                                    <h3 class="font-extrabold text-slate-800 text-base">Proses Pengiriman Berjalan...</h3>
                                    <p id="progressStatus" class="text-xs font-medium text-slate-500 mt-0.5">Mempersiapkan data pengiriman...</p>
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
                <div class="notification <?php echo $notificationType === 'success' ? 'notification-success' : 'notification-error'; ?>">
                    <?php echo htmlspecialchars($notification); ?>
                </div>
                <?php endif; ?>
                
                <!-- Filter -->
                <div class="section-card p-6 mb-8">
                    <h2 class="text-lg font-semibold mb-4 flex items-center text-primary">
                        <i class="fas fa-filter text-xl mr-2 text-accent"></i> Filter Penerima Promosi
                    </h2>
                    <form id="filter-form-promo" method="GET" action="" class="space-y-4">
                        <div class="filter-grid">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">Cari Nama Peserta</label>
                                <input type="text" name="search_promo" value="<?= htmlspecialchars($search_promo) ?>" placeholder="Masukkan nama peserta..." class="form-input">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">Pilih Halaqoh</label>
                                <div class="dropdown-container">
                                    <button type="button" id="halaqoh-filter-btn-promo" class="w-full form-input flex justify-between items-center text-left">
                                        <span id="halaqoh-filter-btn-text-promo">Pilih Halaqoh</span>
                                        <i class="fas fa-caret-down"></i>
                                    </button>
                                    <div id="halaqoh-filter-popup-promo" class="dropdown-menu hidden">
                                        <div class="p-2">
                                            <div class="p-2 border-b border-gray-200">
                                                <label class="flex items-center space-x-3 cursor-pointer">
                                                    <input type="checkbox" id="select-all-halaqoh-promo" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                    <span class="text-sm font-semibold text-gray-700">Pilih Semua</span>
                                                </label>
                                            </div>
                                            <div class="p-1 space-y-1">
                                            <?php foreach ($halaqohList as $halaqoh): ?>
                                                <label class="flex items-center space-x-3 cursor-pointer p-2 rounded-md hover:bg-gray-50">
                                                    <input type="checkbox" name="halaqoh_promo[]" value="<?= htmlspecialchars($halaqoh) ?>" class="halaqoh-checkbox-promo h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?= in_array($halaqoh, $halaqoh_promo) ? 'checked' : '' ?>>
                                                    <span class="text-sm text-gray-700"><?= htmlspecialchars($halaqoh) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">Status Peserta</label>
                                <select name="status_promo" class="form-input">
                                    <option value="proses" <?= $status_promo == 'proses' ? 'selected' : '' ?>>Status: Proses</option>
                                    <option value="selesai" <?= $status_promo == 'selesai' ? 'selected' : '' ?>>Status: Selesai</option>
                                    <option value="semua" <?= $status_promo == 'semua' ? 'selected' : '' ?>>Semua Status</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">Status Pembayaran</label>
                                <select name="pembayaran_promo" class="form-input">
                                    <option value="" <?= $pembayaran_promo == '' ? 'selected' : '' ?>>Semua Pembayaran</option>
                                    <option value="lunas" <?= $pembayaran_promo == 'lunas' ? 'selected' : '' ?>>Lunas</option>
                                    <option value="belum_lunas" <?= $pembayaran_promo == 'belum_lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full btn btn-primary"><i class="fas fa-check-circle mr-2"></i> Terapkan Filter</button>
                    </form>
                </div>
                
                <!-- Konten Utama -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                    <!-- Tabel Target -->
                    <div class="section-card p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-semibold flex items-center text-primary">
                                <i class="fas fa-users text-xl mr-2 text-accent"></i> Daftar Penerima Promosi
                            </h2>
                            <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                <?= count($pesertaPromo) ?> Penerima
                            </span>
                        </div>
                        
                        <div class="table-container">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Nama Lengkap</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Halaqoh</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Pembayaran</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($pesertaPromo)): ?>
                                        <?php foreach ($pesertaPromo as $peserta): ?>
                                            <tr class="hover:bg-gray-50 transition duration-150">
                                                <td class="px-6 py-4 text-sm font-medium text-gray-900"><?= htmlspecialchars($peserta['nama_lengkap']); ?></td>
                                                <td class="px-6 py-4 text-sm text-gray-500"><?= htmlspecialchars($peserta['halaqoh']); ?></td>
                                                <td class="px-6 py-4 text-sm">
                                                    <span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 <?= $peserta['status'] == 'proses' ? 'badge-proses' : 'badge-selesai' ?>">
                                                        <span class="status-indicator <?= $peserta['status'] == 'proses' ? 'status-proses' : 'status-selesai' ?> mr-1.5"></span>
                                                        <?= htmlspecialchars($peserta['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 text-sm">
                                                    <?php if ($peserta['id_pembayaran']): ?>
                                                        <span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 badge-lunas"><i class="fas fa-check-circle text-green-500 mr-1.5"></i>Lunas</span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 badge-belum-lunas"><i class="fas fa-times-circle text-red-500 mr-1.5"></i>Belum Bayar</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center p-8 text-gray-500">Tidak ada data peserta yang cocok dengan filter Anda.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Sidebar Kanan -->
                    <div class="space-y-6">
                        <!-- Preview -->
                        <div class="section-card p-0 overflow-hidden">
                            <div class="preview-header">
                                <div class="preview-avatar"><i class="fas fa-user text-gray-600 text-sm"></i></div>
                                <div class="preview-info">
                                    <div class="preview-name">Jawwada Tahfidz</div>
                                    <div class="preview-status">online</div>
                                </div>
                                <div class="flex space-x-2">
                                    <i class="fas fa-video cursor-pointer opacity-80 hover:opacity-100"></i>
                                    <i class="fas fa-phone cursor-pointer opacity-80 hover:opacity-100"></i>
                                    <i class="fas fa-ellipsis-v cursor-pointer opacity-80 hover:opacity-100"></i>
                                </div>
                            </div>
                            <div class="whatsapp-preview p-4">
                                <div class="message-received" id="preview-message">
                                    <div class="message-image-container" id="image-container">
                                        <div class="image-placeholder" id="image-placeholder">
                                            <i class="fas fa-image text-gray-400 text-2xl mb-2"></i>
                                            <span class="text-gray-500 text-sm text-center">Preview gambar akan muncul di sini</span>
                                        </div>
                                    </div>
                                    <div class="message-text" id="text-preview">Teks promosi akan muncul di sini...</div>
                                    <div class="message-time">10:00</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="section-card p-6">
                            <h2 class="text-lg font-semibold mb-4 flex items-center text-primary"><i class="fas fa-sparkle text-xl mr-2 text-accent"></i> Template Cepat</h2>
                            <button type="button" id="usePromoTemplateBtn" class="w-full text-left text-sm bg-gray-100 hover:bg-blue-100 text-gray-700 px-4 py-2.5 rounded-lg transition font-medium">
                                Gunakan Template Promosi Pendaftaran
                            </button>
                        </div>
                        
                        <!-- FORM KIRIM -->
                        <form id="promo-form" class="section-card p-6">
                            <h2 class="text-lg font-semibold mb-4 flex items-center text-primary">
                                <i class="fas fa-megaphone text-xl mr-2 text-accent"></i> Buat & Kirim Promosi
                            </h2>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="promo_image" class="block font-semibold text-sm text-gray-700 mb-1">Upload Gambar (Wajib - Max 5MB)</label>
                                    <input type="file" id="promo_image" name="promo_image" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer" accept="image/png, image/jpeg, image/gif">
                                </div>
                                
                                <div>
                                    <label for="caption_text" class="block font-semibold text-sm text-gray-700 mb-1">Teks / Caption</label>
                                    <textarea id="caption_text" name="caption_text" rows="8" placeholder="Assalamualaikum, Kak {nama}..." class="form-input"></textarea>
                                </div>
                                
                                <div>
                                    <label for="registration_link" class="block font-semibold text-sm text-gray-700 mb-1">Tautan Tambahan (Opsional)</label>
                                    <input type="url" id="registration_link" name="registration_link" placeholder="https://..." class="form-input">
                                </div>
                                
                                <div>
                                    <button type="submit" name="kirim_promosi" class="w-full btn btn-primary text-lg font-bold py-3">
                                        <i class="fas fa-rocket text-2xl mr-2"></i> Kirim ke <?= count($pesertaPromo) ?> Penerima
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Tanam Data Peserta ke dalam JavaScript -->
    <script>
        const targetPeserta = <?= json_encode($pesertaPromo ?? []) ?>;
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // ==========================================
            // AJAX LOOPING UNTUK KIRIM PROMOSI
            // ==========================================
            const promoForm = document.getElementById('promo-form');
            
            promoForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                // 1. Validasi
                if (targetPeserta.length === 0) {
                    alert("Gagal! Tidak ada peserta yang cocok dengan filter saat ini."); return;
                }
                
                const fileInput = document.getElementById('promo_image');
                const captionText = document.getElementById('caption_text').value.trim();
                
                if (captionText === "") {
                    alert("Gagal! Isi teks caption tidak boleh kosong."); return;
                }
                if (fileInput.files.length === 0) {
                    alert("Gagal! Anda wajib mengupload gambar untuk promosi."); return;
                }
                if (fileInput.files.length > 0) {
                    const fileSize = fileInput.files[0].size;
                    if (fileSize > (5 * 1024 * 1024)) { // Maks 5 MB
                        alert("Gagal! Ukuran gambar terlalu besar. Maksimal pengunggahan adalah 5 MB."); return;
                    }
                }

                // 2. Siapkan Layar Loading (Banner)
                const loader = document.getElementById('loader');
                const pBar = document.getElementById('progressBar');
                const pText = document.getElementById('progressText');
                const pStat = document.getElementById('progressStatus');
                const submitBtn = document.querySelector('button[name="kirim_promosi"]');

                // Tampilkan banner
                loader.classList.remove('hidden');
                loader.classList.add('block');
                
                // Scroll ke atas dengan animasi halus agar user melihat prosesnya
                window.scrollTo({ top: 0, behavior: 'smooth' });
                
                if (submitBtn) {
                    submitBtn.style.pointerEvents = 'none'; 
                    submitBtn.classList.add('opacity-75');
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin text-2xl mr-2"></i> Mengirim...';
                }

                // 3. Looping Kirim AJAX
                let successCount = 0;
                let failCount = 0;
                let total = targetPeserta.length;

                pBar.style.width = '0%';
                pText.innerText = `0 / ${total}`;

                for (let i = 0; i < total; i++) {
                    let peserta = targetPeserta[i];
                    pStat.innerHTML = `Mengirim pesan promosi ke <b>${peserta.nama_lengkap}</b>...`;

                    let formData = new FormData();
                    formData.append('ajax_kirim_promosi', '1');
                    formData.append('nowa', peserta.nowa);
                    formData.append('nama', peserta.nama_lengkap);
                    formData.append('caption_text', document.getElementById('caption_text').value);
                    formData.append('registration_link', document.getElementById('registration_link').value);
                    
                    if (fileInput.files.length > 0) {
                        formData.append('promo_image', fileInput.files[0]);
                    }

                    try {
                        let response = await fetch(window.location.href, { method: 'POST', body: formData });
                        let json = await response.json();
                        if (json.status === 'success') successCount++; else failCount++;
                    } catch (error) {
                        failCount++;
                        console.error("Fetch Error:", error);
                    }

                    // Update UI Progress
                    let pct = Math.round(((i + 1) / total) * 100);
                    pBar.style.width = pct + '%';
                    pText.innerText = `${i + 1} / ${total}`;

                    // Jeda 500ms agar API stabil
                    await new Promise(r => setTimeout(r, 500));
                }

                // 4. Selesai
                pStat.innerHTML = `<span class="text-emerald-600 font-bold">Proses Selesai!</span> ${successCount} Berhasil, ${failCount} Gagal. Memuat ulang data...`;
                setTimeout(() => { window.location.reload(); }, 1500);
            });


            // ==========================================
            // LOGIKA UI DAN PREVIEW (TIDAK DIUBAH)
            // ==========================================
            function setupHalaqohDropdown(buttonId, popupId, selectAllId, checkboxClass) {
                const btn = document.getElementById(buttonId);
                const popup = document.getElementById(popupId);
                const selectAllCheckbox = document.getElementById(selectAllId);
                const checkboxes = popup.querySelectorAll(`input[type="checkbox"].${checkboxClass}`);
                const btnText = btn.querySelector('span');
                
                if (!btn || !popup || !selectAllCheckbox) return;
                
                function updateButtonText() {
                    const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
                    if (selectedCount === 0) { 
                        btnText.textContent = `Pilih Halaqoh`; 
                    } else if (selectedCount === checkboxes.length) { 
                        btnText.textContent = 'Semua Halaqoh Terpilih'; 
                    } else { 
                        btnText.textContent = `${selectedCount} Halaqoh Terpilih`; 
                    }
                }
                
                function updateSelectAllState() {
                    const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
                    if (selectedCount === 0) { 
                        selectAllCheckbox.checked = false; 
                        selectAllCheckbox.indeterminate = false; 
                    } else if (selectedCount === checkboxes.length) { 
                        selectAllCheckbox.checked = true; 
                        selectAllCheckbox.indeterminate = false; 
                    } else { 
                        selectAllCheckbox.checked = false; 
                        selectAllCheckbox.indeterminate = true; 
                    }
                }
                
                btn.addEventListener('click', (e) => { 
                    e.stopPropagation(); 
                    popup.classList.toggle('hidden'); 
                });
                
                selectAllCheckbox.addEventListener('change', () => { 
                    checkboxes.forEach(cb => { cb.checked = selectAllCheckbox.checked; }); 
                    updateButtonText(); 
                });
                
                checkboxes.forEach(cb => { 
                    cb.addEventListener('change', () => { 
                        updateSelectAllState(); 
                        updateButtonText(); 
                    }); 
                });
                
                document.addEventListener('click', (e) => { 
                    if (!popup.contains(e.target) && !btn.contains(e.target)) { 
                        popup.classList.add('hidden'); 
                    } 
                });
                
                updateButtonText();
                updateSelectAllState();
            }
            
            function updateChatPreview() {
                const captionText = document.getElementById('caption_text').value;
                const registrationLink = document.getElementById('registration_link').value;
                const imageInput = document.getElementById('promo_image');
                const previewMessage = document.getElementById('preview-message');
                const imageContainer = document.getElementById('image-container');
                const imagePlaceholder = document.getElementById('image-placeholder');
                const textPreview = document.getElementById('text-preview');
                
                let finalText = captionText;
                if (registrationLink) {
                    finalText += '\n' + registrationLink;
                }
                finalText = finalText.replace(/{nama}/g, 'Ahmad');
                
                textPreview.textContent = finalText || 'Teks promosi akan muncul di sini...';
                textPreview.className = 'message-text ' + (finalText ? 'text-gray-800' : 'text-gray-500');
                
                if (imageInput.files && imageInput.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const img = new Image();
                        img.onload = function() {
                            let imageSizeClass = 'image-medium';
                            let bubbleSizeClass = 'bubble-medium';
                            
                            if (img.width <= 200 || img.height <= 150) {
                                imageSizeClass = 'image-small';
                                bubbleSizeClass = 'bubble-small';
                            } else if (img.width >= 400 || img.height >= 300) {
                                imageSizeClass = 'image-large';
                                bubbleSizeClass = 'bubble-large';
                            }
                            
                            imageContainer.innerHTML = `<img src="${e.target.result}" class="message-image ${imageSizeClass}" alt="Preview Gambar Promosi">`;
                            previewMessage.className = 'message-received ' + bubbleSizeClass;
                        };
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(imageInput.files[0]);
                } else {
                    imageContainer.innerHTML = '';
                    imageContainer.appendChild(imagePlaceholder);
                    adjustBubbleToText(finalText);
                }
            }
            
            function adjustBubbleToText(text) {
                const previewMessage = document.getElementById('preview-message');
                const textLength = text ? text.length : 0;
                previewMessage.classList.remove('bubble-small', 'bubble-medium', 'bubble-large');
                
                if (textLength === 0) { previewMessage.classList.add('bubble-medium'); } 
                else if (textLength < 50) { previewMessage.classList.add('bubble-small'); } 
                else if (textLength < 150) { previewMessage.classList.add('bubble-medium'); } 
                else { previewMessage.classList.add('bubble-large'); }
            }
            
            setupHalaqohDropdown('halaqoh-filter-btn-promo', 'halaqoh-filter-popup-promo', 'select-all-halaqoh-promo', 'halaqoh-checkbox-promo');
            
            const usePromoBtn = document.getElementById('usePromoTemplateBtn');
            if (usePromoBtn) {
                const promoText = `Assalamualaikum, Kak {nama}.
Bolehkah minta tolong sebarkan informasi pendaftaran program Tahfidz Privat Jawwada di WhatsApp Story atau media sosial lainnya?
Semoga menjadi amal jariyah yang terus mengalir untuk kita semua. Aamiin.

---
✨ *TAHFIDZ PRIVAT JAWWADA* ✨
Pendaftaran Gelombang Baru!

📋 *Informasi & Pendaftaran:*
WA: +62 882-2305-3149
🌐 Website: reqra.my.id/jawwada-tahfidz-private

🗓️ *Pendaftaran Ditutup:*
Kamis, 11 September 2025

Jazakumullahu Khairan Katsiran atas bantuannya. 😊`;

                usePromoBtn.addEventListener('click', () => {
                    document.getElementById('caption_text').value = promoText;
                    document.getElementById('registration_link').value = '';
                    updateChatPreview();
                });
            }
            
            document.getElementById('caption_text').addEventListener('input', updateChatPreview);
            document.getElementById('registration_link').addEventListener('input', updateChatPreview);
            document.getElementById('promo_image').addEventListener('change', updateChatPreview);
            
            updateChatPreview();
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