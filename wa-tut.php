<?php
// wa-tut.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cek keberadaan file sebelum di-require
if (!file_exists('auth_checkwa.php')) die("File auth_checkwa.php tidak ditemukan!");
if (!file_exists('config.php')) die("File config.php tidak ditemukan!");

require_once 'auth_checkwa.php';
require_once 'config.php';

// Pastikan variabel $conn tersedia dari config.php
if (!isset($conn) || $conn->connect_error) {
    die("Koneksi Database Gagal: " . ($conn->connect_error ?? "Variabel \$conn tidak terdefinisi"));
}
// ==================================================================
// FUNGSI UTAMA KIRIM WA
// ==================================================================
function kirimPesan($recipient, $message, $apiUrl, $apiToken) {
    $data = [
        "recipient_type" => "individual", 
        "to" => $recipient, 
        "type" => "text", 
        "text" => ["body" => $message]
    ];
    
    $jsonData = json_encode($data);
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json', 
        'Authorization: Bearer ' . $apiToken
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

// ============================================
// PROSES AJAX REQUEST (PROGRESS BAR)
// ============================================

// 1. AJAX: Ambil daftar pengajar untuk dikirim
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_get_pengajar'])) {
    header('Content-Type: application/json');
    $selectedPengajar = [];
    $isAllFiltered = isset($_POST['selected_all_filtered_mode']) && $_POST['selected_all_filtered_mode'] === 'true';
    
    if ($isAllFiltered) {
        $search = $_POST['search_hidden'] ?? '';
        $filterJenis = $_POST['jenis_hidden'] ?? 'semua';
        
        $sql = "SELECT nowa, nama, halaqoh FROM pengampu WHERE nowa IS NOT NULL AND nowa != ''";
        
        $params = [];
        $types = '';
        
        if ($search) { 
            $sql .= " AND nama LIKE ?"; 
            $types .= 's'; 
            $params[] = '%' . $search . '%'; 
        }
        
        if ($filterJenis !== 'semua') {
            if ($filterJenis === 'AK') {
                $sql .= " AND halaqoh LIKE 'AK%'";
            } elseif ($filterJenis === 'IK') {
                $sql .= " AND halaqoh LIKE 'IK%'";
            }
        }
        
        $sql .= " ORDER BY 
            CASE 
                WHEN halaqoh LIKE 'AK%' THEN 1 
                WHEN halaqoh LIKE 'IK%' THEN 2 
                ELSE 3 
            END,
            CAST(SUBSTRING(halaqoh, 4) AS UNSIGNED),
            nama";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) { 
            $stmt->bind_param($types, ...$params); 
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($p = $result->fetch_assoc()) {
            $selectedPengajar[] = $p['nowa'] . '|' . $p['nama'] . '|' . $p['halaqoh'];
        }
        $stmt->close();
    } else {
        $selectedPengajar = $_POST['selected_pengajar'] ?? [];
    }
    
    echo json_encode(['status' => 'success', 'data' => $selectedPengajar]);
    exit;
}

// 2. AJAX: Kirim Pesan Satuan
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_kirim_satuan'])) {
    header('Content-Type: application/json');
    $nowa = $_POST['nowa'] ?? '';
    $nama = $_POST['nama'] ?? '';
    $halaqoh = $_POST['halaqoh'] ?? '';
    $templatePesan = $_POST['template_pesan'] ?? '';

    if (empty($nowa)) {
        echo json_encode(['status' => 'error', 'msg' => 'Nomor kosong']); exit;
    }

    $pesanBody = str_replace('{nama}', $nama, $templatePesan);
    $pesanBody = str_replace('{halaqoh}', $halaqoh, $pesanBody);
    
    $result = kirimPesan($nowa, $pesanBody, $apiUrl, $apiToken);
    
    $finalLogMessage = "[".$result['status']."] " . $result['message'] . " - " . htmlspecialchars($pesanBody);
    
    $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param("sss", $nowa, $nama, $finalLogMessage);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode([
        'status' => ($result['status'] == 'TERKIRIM') ? 'success' : 'error',
        'msg' => $result['message']
    ]);
    exit;
}

// ============================================
// PROSES NOTIFICATION
// ============================================

$notification = '';
$notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notification_type'];
    unset($_SESSION['notification']);
    unset($_SESSION['notification_type']);
}

// ============================================
// PROSES POST REQUEST LAINNYA
// ============================================

// PROSES HAPUS SEMUA LOG
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all_logs'])) {
    if ($conn->query("TRUNCATE TABLE log_wa")) {
        $_SESSION['notification'] = "Semua riwayat log berhasil dihapus.";
        $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus semua riwayat log: " . $conn->error;
        $_SESSION['notification_type'] = 'error';
    }
    
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// PROSES HAPUS LOG
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_log_id'])) {
    $logIdToDelete = $_POST['delete_log_id'];
    $stmt = $conn->prepare("DELETE FROM log_wa WHERE id = ?");
    $stmt->bind_param("i", $logIdToDelete);
    
    if ($stmt->execute()) {
        $_SESSION['notification'] = "Log berhasil dihapus.";
        $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus log: " . $stmt->error;
        $_SESSION['notification_type'] = 'error';
    }
    $stmt->close();
    
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}


// ============================================
// AMBIL DATA UNTUK TAMPILAN
// ============================================

// Ambil daftar pengajar
$pengajarList = [];
$pengajarResult = $conn->query("SELECT id, nama, nowa, halaqoh FROM pengampu ORDER BY 
    CASE 
        WHEN halaqoh LIKE 'AK%' THEN 1 
        WHEN halaqoh LIKE 'IK%' THEN 2 
        ELSE 3 
    END,
    CAST(SUBSTRING(halaqoh, 4) AS UNSIGNED),
    nama");
if ($pengajarResult) {
    while ($row = $pengajarResult->fetch_assoc()) {
        $pengajarList[] = $row;
    }
}

// Hitung statistik
$total_pengajar = count($pengajarList);
$total_ak = 0;
$total_ik = 0;

foreach ($pengajarList as $p) {
    if (strpos($p['halaqoh'], 'AK') === 0) {
        $total_ak++;
    } elseif (strpos($p['halaqoh'], 'IK') === 0) {
        $total_ik++;
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$filterJenis = $_GET['jenis'] ?? 'semua';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$itemsPerPage = 50;
$offset = ($page - 1) * $itemsPerPage;

// ============================================
// QUERY UTAMA DENGAN PAGINATION
// ============================================

// Query untuk menghitung total
$countSql = "SELECT COUNT(*) as total FROM pengampu WHERE nowa IS NOT NULL AND nowa != ''";
$countParams = [];
$countTypes = '';

if ($search) { 
    $countSql .= " AND nama LIKE ?"; 
    $countTypes .= 's'; 
    $countParams[] = '%' . $search . '%'; 
}

if ($filterJenis !== 'semua') {
    if ($filterJenis === 'AK') {
        $countSql .= " AND halaqoh LIKE 'AK%'";
    } elseif ($filterJenis === 'IK') {
        $countSql .= " AND halaqoh LIKE 'IK%'";
    }
}

// Eksekusi query count
$totalCount = 0;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    if (!empty($countParams)) {
        $countStmt->bind_param($countTypes, ...$countParams);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $totalCount = $countResult['total'] ?? 0;
    $countStmt->close();
}

// Hitung total pages
$totalPages = ceil($totalCount / $itemsPerPage);

// Query untuk data utama
$sql = "SELECT id, nama, nowa, halaqoh FROM pengampu WHERE nowa IS NOT NULL AND nowa != ''";
        
$params = [];
$types = '';

if ($search) { 
    $sql .= " AND nama LIKE ?"; 
    $types .= 's'; 
    $params[] = '%' . $search . '%'; 
}

if ($filterJenis !== 'semua') {
    if ($filterJenis === 'AK') {
        $sql .= " AND halaqoh LIKE 'AK%'";
    } elseif ($filterJenis === 'IK') {
        $sql .= " AND halaqoh LIKE 'IK%'";
    }
}

$sql .= " ORDER BY 
    CASE 
        WHEN halaqoh LIKE 'AK%' THEN 1 
        WHEN halaqoh LIKE 'IK%' THEN 2 
        ELSE 3 
    END,
    CAST(SUBSTRING(halaqoh, 4) AS UNSIGNED),
    nama
    LIMIT ? OFFSET ?";

$types .= 'ii';
$params[] = $itemsPerPage;
$params[] = $offset;

// Eksekusi query utama
$pengajarData = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) { 
        $stmt->bind_param($types, ...$params); 
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $pengajarData = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ============================================
// AMBIL DATA LOG
// ============================================

$logPesan = [];
$logResult = $conn->query("SELECT id, nama, message, created_at FROM log_wa WHERE nama IN (SELECT nama FROM pengampu) ORDER BY created_at DESC LIMIT 20");
if ($logResult) { 
    $logPesan = $logResult->fetch_all(MYSQLI_ASSOC); 
}

// Default message placeholder (Bisa disesuaikan)
$templatePesanDefault = "";

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kirim Pesan ke Tutor - JWD</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
        .bg-success { background-color: #d1fae5; }
        .text-success { color: #065f46; }
        .bg-error { background-color: #fee2e2; }
        .text-error { color: #991b1b; }
        .btn-primary { 
            background: linear-gradient(to right, #10b981, #059669);
            transition: all 0.3s ease;
        }
        .btn-primary:hover { 
            background: linear-gradient(to right, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-secondary { 
            background: linear-gradient(to right, #3b82f6, #2563eb);
            transition: all 0.3s ease;
        }
        .btn-secondary:hover { 
            background: linear-gradient(to right, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .badge-ak { background-color: #dbeafe; color: #1e40af; }
        .badge-ik { background-color: #dcfce7; color: #166534; }
        .table-container { 
            max-height: 400px; 
            overflow-y: auto; 
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
        }
        .table-container thead th { 
            position: sticky; 
            top: 0; 
            background-color: #f9fafb;
            z-index: 10;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .selection-info {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
        }
        .checkbox-cell {
            width: 50px;
            text-align: center;
        }
        .filter-option {
            display: inline-flex;
            align-items: center;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
        }
        .textarea-message {
            min-height: 200px;
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            resize: vertical;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem;
            transition: all 0.2s ease;
        }
        .textarea-message:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
            outline: none;
        }
        .log-item {
            transition: all 0.2s ease;
        }
        .log-item:hover {
            background-color: #f8fafc;
            transform: translateX(4px);
        }
        .char-count {
            font-size: 0.75rem;
            color: #6b7280;
            text-align: right;
            margin-top: 0.25rem;
        }
        .char-count.warning { color: #f59e0b; }
        .char-count.error { color: #ef4444; }

        /* Animasi Loader Inline */
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in-down { animation: fadeInDown 0.4s ease-out forwards; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">

    <!-- Header -->
    <header class="bg-gray-800 text-white shadow-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-3">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                <div class="flex items-center space-x-3">
                    <div class="bg-gradient-to-br from-gray-600 to-gray-700 p-2 sm:p-3 rounded-xl shadow-lg">
                        <i class="fas fa-chalkboard-teacher text-white text-xl sm:text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-bold text-white">Kirim Pesan ke Tutor</h1>
                        <p class="text-xs sm:text-sm text-gray-300">Atur Pesan untuk Pengajar</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                    <a href="reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-bell mr-1"></i> Reminder
                    </a>
                    <a href="promosi.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-bullhorn mr-1"></i> Promosi
                    </a>
                    <a href="kirimgrup.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-users mr-1"></i> Grup
                    </a>
                    <a href="analitikjwd.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-bar-chart mr-1"></i> Analitik
                    </a>
                    <a href="manage_auto_reply.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-lightbulb mr-2"></i> Auto Reply
                    </a>
                    <a href="logoutwa.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-right-from-bracket"></i> Keluar
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <main class="flex-grow p-4 sm:p-6 lg:p-8">
        <div class="max-w-7xl mx-auto relative">
            
            <!-- BANNER LOADER INLINE -->
            <div id="loader" class="hidden animate-fade-in-down mb-6 bg-white rounded-xl shadow-lg border border-emerald-100 overflow-hidden relative">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-400 via-green-500 to-teal-500"></div>
                <div class="p-5 flex flex-col sm:flex-row items-center gap-5">
                    <div class="bg-emerald-50 w-14 h-14 rounded-full flex items-center justify-center shrink-0 shadow-inner border border-emerald-100">
                        <i class="fas fa-paper-plane text-2xl text-emerald-500 animate-bounce"></i>
                    </div>
                    <div class="flex-1 w-full">
                        <div class="flex justify-between items-end mb-2">
                            <div>
                                <h3 class="font-extrabold text-slate-800 text-base">Proses Pengiriman Berjalan...</h3>
                                <p id="progressStatus" class="text-xs font-medium text-slate-500 mt-0.5">Mempersiapkan data pengiriman...</p>
                            </div>
                            <p id="progressText" class="text-lg font-black text-emerald-600">0 / 0</p>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-3 shadow-inner overflow-hidden relative">
                            <div id="progressBar" class="bg-gradient-to-r from-emerald-500 to-green-600 h-full rounded-full transition-all duration-300 w-0 relative">
                                <div class="absolute inset-0 bg-white/20"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($notification)): ?>
            <div class="mb-6 p-4 rounded-lg shadow-md <?php echo $notificationType === 'success' ? 'bg-success text-success border-l-4 border-success' : 'bg-error text-error border-l-4 border-error'; ?>">
                <div class="flex items-center">
                    <i class="fas <?php echo $notificationType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-3"></i>
                    <span><?php echo htmlspecialchars($notification); ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- KOLOM KIRI - 2 KOLOM (DAFTAR PENGAJAR DAN PESAN) -->
                <div class="lg:col-span-2 space-y-6 order-1">
                    <!-- FILTER DAN DAFTAR PENGAJAR -->
                    <div class="bg-card shadow-md rounded-2xl p-6 border border-card">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-semibold flex items-center text-primary">
                                <i class="fas fa-users text-xl mr-2 text-accent"></i> Daftar Pengajar
                            </h2>
                            <div class="flex items-center space-x-4">
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                    <?= $totalCount ?> Ditemukan
                                </span>
                                <button type="button" id="selectAllBtn" 
                                        class="text-xs btn-secondary text-white px-3 py-1.5 rounded-lg hover:from-gray-700 hover:to-gray-800 transition flex items-center space-x-1">
                                    <i class="fas fa-check-square text-xs"></i>
                                    <span>Pilih Semua</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Filter Form -->
                        <form id="filter-form" method="GET" action="" class="space-y-4 mb-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                           placeholder="Cari nama pengajar..." 
                                           class="w-full border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-emerald-400 transition bg-white">
                                </div>
                                <div>
                                    <select name="jenis" class="w-full border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-emerald-400 transition bg-white">
                                        <option value="semua" <?= $filterJenis == 'semua' ? 'selected' : '' ?>>Semua Pengajar</option>
                                        <option value="AK" <?= $filterJenis == 'AK' ? 'selected' : '' ?>>Hanya AK</option>
                                        <option value="IK" <?= $filterJenis == 'IK' ? 'selected' : '' ?>>Hanya IK</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Filter Cepat -->
                            <div class="flex flex-wrap gap-2">
                                <button type="button" onclick="filterByType('AK')" 
                                        class="filter-option px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-sm font-medium hover:bg-blue-200 transition">
                                    <i class="fas fa-user-tie mr-1"></i> AK Saja
                                </button>
                                <button type="button" onclick="filterByType('IK')" 
                                        class="filter-option px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-sm font-medium hover:bg-emerald-200 transition">
                                    <i class="fas fa-user-graduate mr-1"></i> IK Saja
                                </button>
                                <button type="button" onclick="resetFilter()" 
                                        class="filter-option px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200 transition">
                                    <i class="fas fa-undo mr-1"></i> Reset
                                </button>
                                <button type="submit" class="filter-option px-3 py-1.5 bg-emerald-500 text-white rounded-lg text-sm font-medium hover:bg-emerald-600 transition">
                                    <i class="fas fa-filter mr-1"></i> Terapkan Filter
                                </button>
                            </div>
                        </form>
                        
                        <!-- Info Selection -->
                        <div id="selectionInfo" class="selection-info hidden">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-users text-lg"></i>
                                <span class="font-semibold"><span id="selected-count-info">0</span> pengajar terpilih</span>
                            </div>
                            <button type="button" id="clearSelectionBtn" 
                                    class="text-white hover:text-gray-200 text-sm font-semibold flex items-center space-x-1">
                                <i class="fas fa-times"></i>
                                <span>Hapus Pilihan</span>
                            </button>
                        </div>
                        
                        <!-- Form Pengajar -->
                        <form method="POST" action="" id="pengajar-form">
                            <input type="hidden" name="selected_all_filtered_mode" id="selected-all-filtered-mode" value="false">
                            <input type="hidden" name="search_hidden" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="jenis_hidden" value="<?= htmlspecialchars($filterJenis) ?>">
                            
                            <div class="table-container">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="checkbox-cell px-4 py-3 bg-gray-50">
                                                <input type="checkbox" id="selectAllCheckbox" class="h-4 w-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500">
                                            </th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Nama Pengajar</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Halaqoh</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Nomor WA</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($pengajarData)): ?>
                                            <?php foreach ($pengajarData as $pengajar): ?>
                                                <?php
                                                $nama = $pengajar['nama'] ?? 'N/A';
                                                $halaqoh = $pengajar['halaqoh'] ?? '-';
                                                $nowa = $pengajar['nowa'] ?? '';
                                                $isAK = strpos($halaqoh, 'AK') === 0;
                                                ?>
                                                <tr class="hover:bg-gray-50 transition duration-150">
                                                    <td class="checkbox-cell px-4 py-3 whitespace-nowrap">
                                                        <input type="checkbox" name="selected_pengajar[]" 
                                                               class="pengajar-checkbox h-4 w-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500" 
                                                               value="<?= htmlspecialchars($nowa . '|' . $nama . '|' . $halaqoh); ?>" 
                                                               <?= empty($nowa) ? 'disabled title="Nomor WA tidak tersedia"' : '' ?>>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 whitespace-nowrap">
                                                        <?= htmlspecialchars($nama); ?>
                                                        <?php if(empty($nowa)): ?>
                                                            <span class="text-xs text-red-500 ml-1" title="Tidak bisa dikirim - nomor WA kosong">⚠️</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm whitespace-nowrap">
                                                        <span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 <?= $isAK ? 'badge-ak' : 'badge-ik' ?>">
                                                            <i class="fas <?= $isAK ? 'fa-user-tie' : 'fa-user-graduate'; ?> mr-1.5 text-xs"></i>
                                                            <?= htmlspecialchars($halaqoh); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500 font-mono whitespace-nowrap">
                                                        <i class="fas fa-phone-alt text-gray-400 mr-1"></i>
                                                        <?= htmlspecialchars($nowa); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center p-8 text-gray-500">
                                                    <i class="fas fa-user-slash text-2xl mb-2 text-gray-300"></i>
                                                    <p>Tidak ada data pengajar yang cocok dengan filter Anda.</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- PAGINATION -->
                            <?php if ($totalPages > 1): ?>
                            <div class="mt-4 flex justify-between items-center">
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                                   class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>">
                                    <i class="fas fa-arrow-left mr-2"></i> Sebelumnya
                                </a>
                                <div class="flex space-x-2">
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($totalPages, $page + 2);
                                    if ($page - 2 > 1) { 
                                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="px-3 py-1 text-sm font-semibold rounded-lg text-gray-700 hover:bg-gray-100">1</a>'; 
                                        if ($page - 2 > 2) { 
                                            echo '<span class="px-3 py-1 text-sm text-gray-400">...</span>'; 
                                        } 
                                    }
                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                                           class="px-3 py-1 text-sm font-semibold rounded-lg <?= $page == $i ? 'bg-emerald-600 text-white' : 'text-gray-700 hover:bg-gray-100' ?>">
                                            <?= $i ?>
                                        </a>
                                    <?php 
                                    endfor;
                                    if ($page + 2 < $totalPages) { 
                                        if ($page + 2 < $totalPages - 1) { 
                                            echo '<span class="px-3 py-1 text-sm text-gray-400">...</span>'; 
                                        } 
                                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '" class="px-3 py-1 text-sm font-semibold rounded-lg text-gray-700 hover:bg-gray-100">' . $totalPages . '</a>'; 
                                    }
                                    ?>
                                </div>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                                   class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>">
                                    Selanjutnya <i class="fas fa-arrow-right ml-2"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <!-- AREA PESAN -->
                    <div class="bg-card shadow-md rounded-2xl p-6 border border-card">
                        <h2 class="text-lg font-semibold mb-4 flex items-center text-primary">
                            <i class="fas fa-edit text-emerald-600 text-xl mr-2"></i> Isi Pesan
                        </h2>
                        <div class="mb-2">
                            <p class="text-xs text-gray-500 mb-2">Gunakan <b>{nama}</b> untuk menyapa nama tutor, dan <b>{halaqoh}</b> untuk menyebutkan halaqohnya.</p>
                        </div>
                        <div class="mb-4">
                            <textarea id="template-textarea" name="template_pesan" form="pengajar-form" rows="10" 
                                      class="w-full textarea-message bg-white"><?= htmlspecialchars($templatePesanDefault) ?></textarea>
                            <div id="charCount" class="char-count">0/1000 karakter</div>
                        </div>
                        
                        <button type="button" id="btn-kirim-pesan" 
                                class="w-full btn-primary text-white px-6 py-4 rounded-lg shadow-lg font-bold text-lg transition duration-300 flex items-center justify-center transform hover:scale-105">
                            <i class="fas fa-paper-plane text-2xl mr-2"></i> 
                            <span>Kirim ke <span id="selected-count" class="ml-1 font-bold bg-white/20 px-2 py-1 rounded">0</span> Pengajar</span>
                        </button>
                    </div>
                </div>
                
                <!-- KOLOM KANAN - 1 KOLOM (LOG PESAN) -->
                <div class="space-y-6 order-2">
                    <div class="bg-card shadow-md rounded-2xl p-6 sticky top-24 border border-card">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-semibold flex items-center text-primary">
                                <i class="fas fa-history text-xl mr-2 text-accent"></i> Log Pesan Terkirim
                            </h2>
                            <form method="POST" action="">
                                <button type="submit" name="delete_all_logs" value="1" 
                                        onclick="return confirm('Anda yakin ingin menghapus SEMUA riwayat log? Tindakan ini tidak dapat dibatalkan.');" 
                                        class="text-xs text-red-500 hover:underline font-semibold">
                                    Hapus Semua
                                </button>
                            </form>
                        </div>
                        
                        <div class="space-y-2 max-h-[700px] overflow-y-auto pr-2">
                            <?php if(!empty($logPesan)): ?>
                                <?php foreach($logPesan as $log): ?>
                                    <?php
                                    $message_status = 'default';
                                    if (strpos($log['message'], '[TERKIRIM]') !== false) {
                                        $message_status = 'success';
                                    } elseif (strpos($log['message'], '[GAGAL]') !== false) {
                                        $message_status = 'failed';
                                    }
                                    ?>
                                    <div class="log-item relative flex items-start justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                                        <div class="flex items-start space-x-3 overflow-hidden flex-1">
                                            <?php if ($message_status == 'success'): ?>
                                                <i class="fas fa-check-circle text-green-500 text-lg mt-0.5"></i>
                                            <?php elseif ($message_status == 'failed'): ?>
                                                <i class="fas fa-times-circle text-red-500 text-lg mt-0.5"></i>
                                            <?php else: ?>
                                                <i class="fas fa-clock text-blue-500 text-lg mt-0.5"></i>
                                            <?php endif; ?>
                                            <div class="flex-1 min-w-0">
                                                <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($log['nama']) ?></p>
                                                <p class="text-gray-400 text-[10px] mb-1"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></p>
                                                <p class="text-xs text-gray-600 truncate">
                                                    <?php 
                                                    $msg = htmlspecialchars($log['message']);
                                                    echo strlen($msg) > 60 ? substr($msg, 0, 60) . '...' : $msg;
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                        <form method="POST" action="" class="opacity-70 hover:opacity-100 transition-opacity">
                                            <input type="hidden" name="delete_log_id" value="<?= $log['id'] ?>">
                                            <button type="submit" class="text-gray-400 hover:text-red-500 p-1 rounded-full hover:bg-red-100" title="Hapus Log">
                                                <i class="fas fa-trash text-sm"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-inbox text-3xl text-gray-300 mb-3"></i>
                                    <p class="text-sm text-gray-500">Belum ada pesan terkirim.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // ---------------------------------------------------------
            // FUNGSI AJAX LOOPING (PENGIRIMAN PESAN KE PENGAJAR)
            // ---------------------------------------------------------
            const btnKirimPesan = document.getElementById('btn-kirim-pesan');
            
            btnKirimPesan.addEventListener('click', async function(e) {
                e.preventDefault();

                const form = document.getElementById('pengajar-form');
                const formData = new FormData(form);
                const templatePesan = document.getElementById('template-textarea').value.trim();
                
                if(!templatePesan) {
                    alert("Gagal! Isi pesan tidak boleh kosong."); 
                    return;
                }
                
                if (templatePesan.length > 1000) {
                    alert('Gagal! Pesan maksimal 1000 karakter!');
                    return;
                }

                if(document.getElementById('selected-all-filtered-mode').value === 'false') {
                    const checkboxes = document.querySelectorAll('.pengajar-checkbox:checked');
                    if(checkboxes.length === 0) {
                        alert("Gagal! Anda belum memilih satupun pengajar.");
                        return;
                    }
                }

                // Konfirmasi user sebelum pengiriman berjalan
                const checkedCount = document.getElementById('selected-count-info').textContent;
                if (!confirm(`Kirim pesan ini ke ${checkedCount} pengajar?`)) {
                    return;
                }

                // Tampilkan Banner Loader
                const loader = document.getElementById('loader');
                const pBar = document.getElementById('progressBar');
                const pText = document.getElementById('progressText');
                const pStat = document.getElementById('progressStatus');

                loader.classList.remove('hidden');
                loader.classList.add('block');
                window.scrollTo({ top: 0, behavior: 'smooth' });

                btnKirimPesan.style.pointerEvents = 'none';
                btnKirimPesan.classList.add('opacity-75');
                btnKirimPesan.innerHTML = '<i class="fas fa-spinner fa-spin text-2xl mr-2"></i> Memproses...';

                // TAHAP 1: Ambil data target
                formData.append('ajax_get_pengajar', '1');
                
                let pengajarList = [];
                try {
                    pStat.innerHTML = `Mengambil data pengajar terpilih...`;
                    let resGet = await fetch(window.location.href, { method: 'POST', body: formData });
                    let jsonGet = await resGet.json();
                    
                    if(jsonGet.status === 'success' && jsonGet.data) {
                        pengajarList = jsonGet.data;
                    }
                } catch(error) {
                    console.error("Error Get Data:", error);
                    alert("Gagal mengambil data pengajar. Periksa koneksi Anda.");
                    window.location.reload();
                    return;
                }

                let total = pengajarList.length;
                if(total === 0) {
                    alert("Tidak ada pengajar valid yang ditemukan.");
                    window.location.reload();
                    return;
                }

                // TAHAP 2: Loop pengiriman AJAX
                let successCount = 0;
                let failCount = 0;

                pBar.style.width = '0%';
                pText.innerText = `0 / ${total}`;

                for (let i = 0; i < total; i++) {
                    let parts = pengajarList[i].split('|');
                    let nowa = parts[0];
                    let namaPengajar = parts.length > 1 ? parts[1] : nowa;
                    let halaqoh = parts.length > 2 ? parts[2] : '-';

                    pStat.innerHTML = `Mengirim ke: <b>${namaPengajar}</b>...`;

                    let sendData = new FormData();
                    sendData.append('ajax_kirim_satuan', '1');
                    sendData.append('nowa', nowa);
                    sendData.append('nama', namaPengajar);
                    sendData.append('halaqoh', halaqoh);
                    sendData.append('template_pesan', templatePesan);

                    try {
                        let resSend = await fetch(window.location.href, { method: 'POST', body: sendData });
                        let jsonSend = await resSend.json();
                        
                        if (jsonSend.status === 'success') {
                            successCount++;
                        } else {
                            failCount++;
                        }
                    } catch (error) {
                        failCount++;
                        console.error("Fetch Error:", error);
                    }

                    // Update UI Progress
                    let pct = Math.round(((i + 1) / total) * 100);
                    pBar.style.width = pct + '%';
                    pText.innerText = `${i + 1} / ${total}`;

                    // Jeda agar API OneSender stabil
                    await new Promise(r => setTimeout(r, 500));
                }

                pStat.innerHTML = `<span class="text-emerald-600 font-bold">Proses Selesai!</span> ${successCount} Berhasil, ${failCount} Gagal. Memuat ulang riwayat...`;
                
                setTimeout(() => { window.location.reload(); }, 1500);
            });

            // ---------------------------------------------------------
            // FUNGSI SELECTION & FILTER UI BAWAAN
            // ---------------------------------------------------------
            const selectAllBtn = document.getElementById('selectAllBtn');
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const selectedAllHiddenInput = document.getElementById('selected-all-filtered-mode');
            const selectedCountSpan = document.getElementById('selected-count');
            const selectedCountInfo = document.getElementById('selected-count-info');
            const selectionInfo = document.getElementById('selectionInfo');
            const clearSelectionBtn = document.getElementById('clearSelectionBtn');
            const checkboxes = document.querySelectorAll('.pengajar-checkbox');
            const templateTextarea = document.getElementById('template-textarea');
            const charCount = document.getElementById('charCount');

            function updateSelectionDisplay() {
                const checkedCount = document.querySelectorAll('.pengajar-checkbox:checked').length;
                
                selectedCountSpan.textContent = checkedCount;
                selectedCountInfo.textContent = checkedCount;
                
                if (checkedCount > 0) {
                    selectionInfo.classList.remove('hidden');
                } else {
                    selectionInfo.classList.add('hidden');
                }
                
                if (checkedCount === checkboxes.length && checkboxes.length > 0) {
                    selectAllBtn.innerHTML = '<i class="fas fa-times text-xs"></i><span>Hapus Semua</span>';
                    selectAllCheckbox.checked = true;
                    selectedAllHiddenInput.value = 'true';
                } else {
                    selectAllBtn.innerHTML = '<i class="fas fa-check-square text-xs"></i><span>Pilih Semua</span>';
                    selectAllCheckbox.checked = false;
                    selectedAllHiddenInput.value = 'false';
                }
            }

            function updateCharCount() {
                const length = templateTextarea.value.length;
                charCount.textContent = `${length}/1000 karakter`;
                
                charCount.classList.remove('warning', 'error');
                if (length > 900 && length <= 1000) {
                    charCount.classList.add('warning');
                } else if (length > 1000) {
                    charCount.classList.add('error');
                }
            }

            selectAllBtn.addEventListener('click', function() {
                const allChecked = document.querySelectorAll('.pengajar-checkbox:checked').length === checkboxes.length;
                if (allChecked) {
                    checkboxes.forEach(cb => { if(!cb.disabled) cb.checked = false; });
                    selectedAllHiddenInput.value = 'false';
                } else {
                    checkboxes.forEach(cb => { if(!cb.disabled) cb.checked = true; });
                    selectedAllHiddenInput.value = 'true';
                }
                updateSelectionDisplay();
            });

            selectAllCheckbox.addEventListener('change', function() {
                checkboxes.forEach(cb => { if(!cb.disabled) cb.checked = this.checked; });
                selectedAllHiddenInput.value = this.checked ? 'true' : 'false';
                updateSelectionDisplay();
            });

            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateSelectionDisplay);
            });

            clearSelectionBtn.addEventListener('click', function() {
                checkboxes.forEach(cb => cb.checked = false);
                selectedAllHiddenInput.value = 'false';
                selectAllCheckbox.checked = false;
                updateSelectionDisplay();
            });

            templateTextarea.addEventListener('input', updateCharCount);

            document.querySelector('#filter-form button[type="submit"]').addEventListener('click', function() {
                checkboxes.forEach(cb => cb.checked = false);
                updateSelectionDisplay();
            });

            updateSelectionDisplay();
            updateCharCount();
        });

        // Filter functions
        function filterByType(type) {
            const form = document.getElementById('filter-form');
            const jenisSelect = form.querySelector('select[name="jenis"]');
            jenisSelect.value = type;
            form.submit();
        }

        function resetFilter() {
            const form = document.getElementById('filter-form');
            const searchInput = form.querySelector('input[name="search"]');
            const jenisSelect = form.querySelector('select[name="jenis"]');
            
            searchInput.value = '';
            jenisSelect.value = 'semua';
            form.submit();
        }
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