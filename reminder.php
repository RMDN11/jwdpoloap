<?php
require_once 'auth_checkwa.php';
require_once 'config.php';

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

// 1. AJAX: Ambil daftar peserta untuk dikirim
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_get_peserta'])) {
    header('Content-Type: application/json');
    $selectedPeserta = [];
    $isAllFiltered = isset($_POST['selected_all_filtered_mode']) && $_POST['selected_all_filtered_mode'] === 'true';
    
    if ($isAllFiltered) {
        $search = $_POST['search_hidden'] ?? '';
        $filterHalaqoh = $_POST['halaqoh_hidden'] ?? [];
        $filterStatus = $_POST['status_hidden'] ?? 'proses';
        $filterPembayaran = $_POST['pembayaran_hidden'] ?? '';
        $filterBulanBayar = $_POST['bulan_bayar_hidden'] ?? '';
        
        $sql = "SELECT DISTINCT p.nowa, p.nama_lengkap 
                FROM peserta p 
                LEFT JOIN pembayaran pemb ON p.id = pemb.peserta_id 
                WHERE p.nowa IS NOT NULL AND p.nowa != ''";
        
        $params = [];
        $types = '';
        
        if ($search) { 
            $sql .= " AND p.nama_lengkap LIKE ?"; 
            $types .= 's'; 
            $params[] = '%' . $search . '%'; 
        }
        if (!empty($filterHalaqoh)) {
            $placeholders = implode(',', array_fill(0, count($filterHalaqoh), '?'));
            $sql .= " AND p.halaqoh IN ($placeholders)";
            $types .= str_repeat('s', count($filterHalaqoh));
            $params = array_merge($params, $filterHalaqoh);
        }
        if ($filterStatus && $filterStatus !== 'semua') { 
            $sql .= " AND p.status = ?"; 
            $types .= 's'; 
            $params[] = $filterStatus; 
        }
        if (!empty($filterBulanBayar)) {
            if ($filterPembayaran === 'lunas') {
                $sql .= " AND EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
                $types .= 's';
                $params[] = $filterBulanBayar;
            } elseif ($filterPembayaran === 'belum_lunas') {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
                $types .= 's';
                $params[] = $filterBulanBayar;
            }
        }
        
        $sql .= " GROUP BY p.id, p.nowa, p.nama_lengkap";
        
        if (empty($filterBulanBayar)) {
            if ($filterPembayaran === 'lunas') { 
                $sql .= " HAVING MAX(pemb.id) IS NOT NULL"; 
            } elseif ($filterPembayaran === 'belum_lunas') { 
                $sql .= " HAVING MAX(pemb.id) IS NULL"; 
            }
        }
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($p = $result->fetch_assoc()) {
            $selectedPeserta[] = $p['nowa'] . '|' . $p['nama_lengkap'];
        }
        $stmt->close();
    } else {
        $selectedPeserta = $_POST['selected_peserta'] ?? [];
    }
    
    echo json_encode(['status' => 'success', 'data' => $selectedPeserta]);
    exit;
}

// 2. AJAX: Kirim Pesan Satuan
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_kirim_reminder'])) {
    header('Content-Type: application/json');
    $nowa = $_POST['nowa'] ?? '';
    $namaPeserta = $_POST['nama'] ?? '';
    $templatePesan = $_POST['template_pesan'] ?? '';

    if (empty($nowa)) {
        echo json_encode(['status' => 'error', 'msg' => 'Nomor kosong']); exit;
    }

    $pesanBody = str_replace('{nama}', $namaPeserta, $templatePesan);
    $result = kirimPesan($nowa, $pesanBody, $apiUrl, $apiToken);
    
    $finalLogMessage = "[".$result['status']."] " . $result['message'] . " - " . htmlspecialchars($pesanBody);
    
    $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param("sss", $nowa, $namaPeserta, $finalLogMessage);
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

// PROSES SIMPAN TEMPLATE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_template'])) {
    $templateId = $_POST['template_id'] ?? '';
    $category = $_POST['template_category'] ?? '';
    $title = $_POST['template_title'] ?? '';
    $content = $_POST['template_content'] ?? '';
    
    if (empty($templateId)) {
        $stmt = $conn->prepare("INSERT INTO wa_templates (category, title, content) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $category, $title, $content);
        $_SESSION['notification'] = "Template baru berhasil disimpan.";
    } else {
        $stmt = $conn->prepare("UPDATE wa_templates SET category = ?, title = ?, content = ? WHERE id = ?");
        $stmt->bind_param("sssi", $category, $title, $content, $templateId);
        $_SESSION['notification'] = "Template berhasil diperbarui.";
    }
    
    if ($stmt->execute()) {
        $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menyimpan template: " . $stmt->error;
        $_SESSION['notification_type'] = 'error';
    }
    $stmt->close();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

// PROSES HAPUS TEMPLATE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_template_id'])) {
    $templateIdToDelete = $_POST['delete_template_id'];
    $stmt = $conn->prepare("DELETE FROM wa_templates WHERE id = ?");
    $stmt->bind_param("i", $templateIdToDelete);
    
    if ($stmt->execute()) {
        $_SESSION['notification'] = "Template berhasil dihapus.";
        $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus template: " . $stmt->error;
        $_SESSION['notification_type'] = 'error';
    }
    $stmt->close();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

// PROSES HAPUS SEMUA LOG
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all_logs'])) {
    if ($conn->query("TRUNCATE TABLE log_wa")) {
        $_SESSION['notification'] = "Semua riwayat log berhasil dihapus.";
        $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus semua riwayat log: " . $conn->error;
        $_SESSION['notification_type'] = 'error';
    }
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
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
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}


// ============================================
// AMBIL DATA UNTUK TAMPILAN
// ============================================

// Ambil daftar halaqoh
$halaqohList = [];
$halaqohResult = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh != '' ORDER BY halaqoh");
if ($halaqohResult) {
    while ($row = $halaqohResult->fetch_assoc()) {
        $halaqohList[] = $row['halaqoh'];
    }
}

// Ambil daftar bulan pembayaran
$bulanPembayaranList = [];
$bulanResult = $conn->query("SELECT DISTINCT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran != '' ORDER BY STR_TO_DATE(CONCAT('01-', bulan_pembayaran), '%d-%M-%Y') DESC");
if ($bulanResult) {
    while ($row = $bulanResult->fetch_assoc()) {
        $bulanPembayaranList[] = $row['bulan_pembayaran'];
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$filterHalaqoh = $_GET['halaqoh'] ?? [];
if (!is_array($filterHalaqoh)) {
    $filterHalaqoh = !empty($filterHalaqoh) ? [$filterHalaqoh] : [];
}
$filterStatus = $_GET['status'] ?? 'proses';
$filterPembayaran = $_GET['pembayaran'] ?? '';
$filterBulanBayar = $_GET['bulan_bayar'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$itemsPerPage = 300;
$offset = ($page - 1) * $itemsPerPage;

// ============================================
// QUERY UTAMA DENGAN PAGINATION
// ============================================

// Query untuk menghitung total
$countSql = "SELECT COUNT(DISTINCT p.id) as total FROM peserta p 
             LEFT JOIN pembayaran pemb ON p.id = pemb.peserta_id 
             WHERE p.nowa IS NOT NULL AND p.nowa != ''";
$countParams = [];
$countTypes = '';

if ($search) { 
    $countSql .= " AND p.nama_lengkap LIKE ?"; 
    $countTypes .= 's'; 
    $countParams[] = '%' . $search . '%'; 
}

if (!empty($filterHalaqoh)) {
    $placeholders = implode(',', array_fill(0, count($filterHalaqoh), '?'));
    $countSql .= " AND p.halaqoh IN ($placeholders)";
    $countTypes .= str_repeat('s', count($filterHalaqoh));
    $countParams = array_merge($countParams, $filterHalaqoh);
}

if ($filterStatus && $filterStatus !== 'semua') { 
    $countSql .= " AND p.status = ?"; 
    $countTypes .= 's'; 
    $countParams[] = $filterStatus; 
}

// Filter pembayaran
if (!empty($filterBulanBayar)) {
    if ($filterPembayaran === 'lunas') {
        $countSql .= " AND EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
        $countTypes .= 's'; 
        $countParams[] = $filterBulanBayar;
    } elseif ($filterPembayaran === 'belum_lunas') {
        $countSql .= " AND NOT EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
        $countTypes .= 's'; 
        $countParams[] = $filterBulanBayar;
    }
} else {
    if ($filterPembayaran === 'lunas') {
        $countSql .= " GROUP BY p.id HAVING MAX(pemb.id) IS NOT NULL";
    } elseif ($filterPembayaran === 'belum_lunas') {
        $countSql .= " GROUP BY p.id HAVING MAX(pemb.id) IS NULL";
    }
}

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

$totalPages = ceil($totalCount / $itemsPerPage);

// Query untuk data utama
$sql = "SELECT p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status, 
        MAX(CASE WHEN l.message LIKE '%pembayaran%' THEN l.created_at END) as last_payment_reminder,
        MAX(CASE WHEN l.message LIKE '%hafalan%' OR l.message LIKE '%setoran%' THEN l.created_at END) as last_setoran_reminder,
        MAX(pemb.id) as id_pembayaran_terakhir
        FROM peserta p 
        LEFT JOIN log_wa l ON p.nowa = l.nowa 
        LEFT JOIN pembayaran pemb ON p.id = pemb.peserta_id 
        WHERE p.nowa IS NOT NULL AND p.nowa != ''";
        
$params = [];
$types = '';

if ($search) { 
    $sql .= " AND p.nama_lengkap LIKE ?"; 
    $types .= 's'; 
    $params[] = '%' . $search . '%'; 
}

if (!empty($filterHalaqoh)) {
    $placeholders = implode(',', array_fill(0, count($filterHalaqoh), '?'));
    $sql .= " AND p.halaqoh IN ($placeholders)";
    $types .= str_repeat('s', count($filterHalaqoh));
    $params = array_merge($params, $filterHalaqoh);
}

if ($filterStatus && $filterStatus !== 'semua') { 
    $sql .= " AND p.status = ?"; 
    $types .= 's'; 
    $params[] = $filterStatus; 
}

if (!empty($filterBulanBayar)) {
    if ($filterPembayaran === 'lunas') {
        $sql .= " AND EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
        $types .= 's';
        $params[] = $filterBulanBayar;
    } elseif ($filterPembayaran === 'belum_lunas') {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
        $types .= 's';
        $params[] = $filterBulanBayar;
    }
}

$sql .= " GROUP BY p.id";

if (empty($filterBulanBayar)) {
    if ($filterPembayaran === 'lunas') { 
        $sql .= " HAVING id_pembayaran_terakhir IS NOT NULL"; 
    } elseif ($filterPembayaran === 'belum_lunas') { 
        $sql .= " HAVING id_pembayaran_terakhir IS NULL"; 
    }
}

$sql .= " ORDER BY p.halaqoh, p.nama_lengkap LIMIT ? OFFSET ?";
$types .= 'ii';
$params[] = $itemsPerPage;
$params[] = $offset;

$pesertaAktif = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) { 
        $stmt->bind_param($types, ...$params); 
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $pesertaAktif = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ============================================
// AMBIL DATA LOG
// ============================================

$logPesan = [];
$logResult = $conn->query("SELECT id, nama, message, created_at FROM log_wa ORDER BY created_at DESC LIMIT 20");
if ($logResult) { 
    $logPesan = $logResult->fetch_all(MYSQLI_ASSOC); 
}

// ============================================
// AMBIL TEMPLATE
// ============================================

$dbTemplatesResult = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY category, title");
$dbTemplates = $dbTemplatesResult ? $dbTemplatesResult->fetch_all(MYSQLI_ASSOC) : [];

$allTemplates = [];
foreach($dbTemplates as $tpl) {
    if (!isset($allTemplates[$tpl['category']])) { 
        $allTemplates[$tpl['category']] = []; 
    }
    $allTemplates[$tpl['category']][] = $tpl;
}

$templatePesanDefault = "";

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kirim Reminder - JWD</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .modal-backdrop { background-color: rgba(0,0,0,0.5); }
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
        .btn-primary { background: linear-gradient(to right, #6b7280, #4b5563); }
        .btn-primary:hover { background: linear-gradient(to right, #4b5563, #374151); }
        .btn-accent { background: linear-gradient(to right, #9ca3af, #6b7280); }
        .btn-accent:hover { background: linear-gradient(to right, #6b7280, #4b5563); }
        .badge-lunas { background-color: #d1fae5; color: #065f46; }
        .badge-belum-lunas { background-color: #fee2e2; color: #991b1b; }
        .badge-proses { background-color: #fef3c7; color: #92400e; }
        .badge-selesai { background-color: #d1fae5; color: #065f46; }
        .status-indicator { width: 0.5rem; height: 0.5rem; border-radius: 50%; }
        .status-proses { background-color: #f59e0b; }
        .status-selesai { background-color: #10b981; }
        .table-container { 
            max-height: 500px; 
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .checkbox-cell {
            width: 50px;
            text-align: center;
        }
        
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
                    <i class="fas fa-bell text-white text-xl sm:text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-lg sm:text-xl font-bold text-white">Reminder Pembayaran</h1>
                    <p class="text-xs sm:text-sm text-gray-300">Atur Reminder Pembayaran</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                <a href="wa-tut.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                    <i class="fas fa-chalkboard-teacher mr-1"></i> Tutor
                </a>
                <a href="promosi.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                    <i class="fas fa-bullhorn mr-1"></i> Promosi
                </a>
                <a href="kelola_reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                    <i class="fas fa-message mr-1"></i> Reminder Peserta
                </a>
                <a href="kirimgrup.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                    <i class="fas fa-users mr-1"></i> Grup
                </a>
                <a href="analitikjwd.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                    <i class="fas fa-bar-chart mr-1"></i> Analitik
                </a>
                <a href="manage_auto_reply.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                    <i class="fas fa-lightbulb mr-2"></i> Set Auto Reply
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
            
            <!-- BANNER LOADER INLINE (DI BAWAH HEADER) -->
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
            <div class="mb-6 p-4 rounded-lg shadow-md <?php echo $notificationType === 'success' ? 'bg-success text-success border-l-4 border-success' : 'bg-error text-error border-l-4 border-error'; ?>">
                <?php echo htmlspecialchars($notification); ?>
            </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- PANEL AKSI -->
                <div class="space-y-6 order-1 lg:order-2">
                    <div class="bg-card shadow-md rounded-2xl p-6 sticky top-24 border border-card">
                        <h2 class="text-lg font-semibold mb-4 flex items-center text-primary">
                            <i class="fas fa-paper-plane text-xl mr-2 text-accent"></i> Panel Aksi
                        </h2>
                        
                        <div class="space-y-4 mb-4">
                            <div>
                                <div class="flex justify-between items-center mb-2">
                                    <h4 class="text-sm font-bold text-gray-600 uppercase">Pilih Template Cepat</h4>
                                    <button type="button" id="manageTemplatesBtn" class="text-xs text-blue-500 hover:underline font-semibold">
                                        Kelola Template
                                    </button>
                                </div>
                                <?php foreach ($allTemplates as $category => $group): ?>
                                    <div class="mb-2">
                                        <h5 class="text-xs font-semibold text-gray-500 mb-2"><?= htmlspecialchars($category) ?></h5>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach ($group as $tpl): ?>
                                                <div class="relative group">
                                                    <button type="button" class="template-btn text-xs bg-gray-200 hover:bg-blue-100 text-gray-700 px-3 py-1.5 rounded-full transition" 
                                                            data-template="<?= htmlspecialchars($tpl['content']) ?>" 
                                                            data-id="<?= $tpl['id'] ?>" 
                                                            data-category="<?= htmlspecialchars($tpl['category']) ?>" 
                                                            data-title="<?= htmlspecialchars($tpl['title']) ?>">
                                                        <?= htmlspecialchars($tpl['title']) ?>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus template \'<?= htmlspecialchars(addslashes($tpl['title'])) ?>\'?');" 
                                                          class="absolute -top-1.5 -right-1.5 opacity-0 group-hover:opacity-100 transition-opacity z-10">
                                                        <input type="hidden" name="delete_template_id" value="<?= $tpl['id'] ?>">
                                                        <button type="submit" class="w-5 h-5 bg-red-500 text-white rounded-full flex items-center justify-center text-xs font-bold hover:bg-red-700 shadow-md" title="Hapus Template">
                                                            &times;
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <div class="flex justify-between items-center mb-2">
                                <label class="block font-semibold text-sm text-gray-700">Isi Pesan</label>
                                <button type="button" id="editSelectedTemplateBtn" class="text-xs text-blue-500 hover:underline font-semibold hidden">
                                    Ubah Template Ini
                                </button>
                            </div>
                            <textarea id="template-textarea" name="template_pesan" form="peserta-form" rows="6" 
                                      class="w-full border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-400 text-sm transition bg-white"><?= htmlspecialchars($templatePesanDefault) ?></textarea>
                        </div>
                        
                        <button type="button" id="btn-kirim-reminder"
                                class="w-full btn-primary text-white px-6 py-3 rounded-lg shadow-lg font-bold text-lg transition duration-300 flex items-center justify-center transform hover:scale-105">
                            <i class="fas fa-paper-plane text-2xl mr-2"></i> Kirim ke 
                            <span id="selected-count" class="ml-1.5 font-bold">0</span> Terpilih
                        </button>
                        
                        <hr class="my-6 border-gray-200">
                        
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-semibold flex items-center text-primary">
                                <i class="fas fa-history text-xl mr-2 text-accent"></i> Log Pesan Terkirim
                            </h2>
                            <form method="POST" action="">
                                <button type="submit" name="delete_all_logs" value="1" 
                                        onclick="return confirm('Anda yakin ingin menghapus SEMUA riwayat log? Tindakan ini tidak dapat dibatalkan.');" 
                                        class="text-xs text-red-500 hover:underline font-semibold">
                                    Hapus Riwayat
                                </button>
                            </form>
                        </div>
                        
                        <div class="space-y-2 max-h-80 overflow-y-auto pr-2">
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
                                    <div class="relative flex items-center justify-between p-2 bg-gray-50 rounded-lg border border-gray-200 group">
                                        <div class="flex items-center space-x-3 overflow-hidden">
                                            <?php if ($message_status == 'success'): ?>
                                                <i class="fas fa-check-circle text-green-500 text-xl"></i>
                                            <?php elseif ($message_status == 'failed'): ?>
                                                <i class="fas fa-times-circle text-red-500 text-xl"></i>
                                            <?php else: ?>
                                                <i class="fas fa-clock text-blue-500 text-xl"></i>
                                            <?php endif; ?>
                                            <div>
                                                <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($log['nama']) ?></p>
                                                <p class="text-gray-400 text-[10px]"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></p>
                                            </div>
                                        </div>
                                        <form method="POST" action="" class="opacity-0 group-hover:opacity-100 transition-opacity">
                                            <input type="hidden" name="delete_log_id" value="<?= $log['id'] ?>">
                                            <button type="submit" class="text-gray-400 hover:text-red-500 p-1 rounded-full hover:bg-red-100" title="Hapus Log">
                                                <i class="fas fa-trash text-sm"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-sm text-gray-500 text-center p-4">Belum ada pesan terkirim.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- FILTER DAN DAFTAR PESERTA -->
                <div class="lg:col-span-2 space-y-6 order-2 lg:order-1">
                    <div class="bg-card shadow-md rounded-2xl p-6 border border-card">
                        <h2 class="text-lg font-semibold mb-4 flex items-center text-primary">
                            <i class="fas fa-filter text-xl mr-2 text-accent"></i> Filter Pencarian
                        </h2>
                        
                        <form id="filter-form" method="GET" action="" class="space-y-4">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Cari nama peserta..." 
                                   class="w-full border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-400 transition bg-white">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-2">Pilih Halaqoh</label>
                                    <div class="relative">
                                        <button type="button" id="halaqoh-filter-btn" 
                                                class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-left flex justify-between items-center focus:ring-2 focus:ring-blue-400 transition">
                                            <span id="halaqoh-filter-btn-text">Pilih Halaqoh</span>
                                            <i class="fas fa-caret-down"></i>
                                        </button>
                                        <div id="halaqoh-filter-popup" class="hidden absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                            <div class="p-2">
                                                <div class="p-2 border-b border-gray-200">
                                                    <label class="flex items-center space-x-3 cursor-pointer">
                                                        <input type="checkbox" id="select-all-halaqoh" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                        <span class="text-sm font-semibold text-gray-700">Pilih Semua</span>
                                                    </label>
                                                </div>
                                                <div class="p-1 space-y-1">
                                                    <?php foreach ($halaqohList as $halaqoh): ?>
                                                        <label class="flex items-center space-x-3 cursor-pointer p-2 rounded-md hover:bg-gray-50">
                                                            <input type="checkbox" name="halaqoh[]" value="<?= htmlspecialchars($halaqoh) ?>" 
                                                                   class="halaqoh-checkbox h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" 
                                                                   <?= in_array($halaqoh, $filterHalaqoh) ? 'checked' : '' ?>>
                                                            <span class="text-sm text-gray-700"><?= htmlspecialchars($halaqoh) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-2">Status Peserta</label>
                                        <select name="status" class="w-full border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-400 transition bg-white">
                                            <option value="proses" <?= $filterStatus == 'proses' ? 'selected' : '' ?>>Status: Proses</option>
                                            <option value="selesai" <?= $filterStatus == 'selesai' ? 'selected' : '' ?>>Status: Selesai</option>
                                            <option value="semua" <?= $filterStatus == 'semua' ? 'selected' : '' ?>>Semua Status</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-2">Status Pembayaran</label>
                                    <select name="pembayaran" class="w-full border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-400 transition bg-white">
                                        <option value="" <?= $filterPembayaran == '' ? 'selected' : '' ?>>Semua Pembayaran</option>
                                        <option value="lunas" <?= $filterPembayaran == 'lunas' ? 'selected' : '' ?>>Lunas</option>
                                        <option value="belum_lunas" <?= $filterPembayaran == 'belum_lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-2">Filter Bulan Bayar</label>
                                    <select name="bulan_bayar" class="w-full border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-400 transition bg-white">
                                        <option value="">Semua Bulan</option>
                                        <?php foreach ($bulanPembayaranList as $bulan): ?>
                                            <option value="<?= htmlspecialchars($bulan) ?>" <?= $filterBulanBayar == $bulan ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($bulan) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">Pilih bulan untuk filter Lunas/Belum Lunas.</p>
                                </div>
                            </div>
                            
                            <button type="submit" class="w-full btn-primary text-white px-4 py-2.5 rounded-lg shadow-md transition duration-300 font-semibold">
                                Terapkan Filter
                            </button>
                        </form>
                    </div>
                    
                    <div class="bg-card shadow-md rounded-2xl p-6 border border-card">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-semibold flex items-center text-primary">
                                <i class="fas fa-users text-xl mr-2 text-accent"></i> Daftar Peserta
                            </h2>
                            <div class="flex items-center space-x-4">
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                    <?= $totalCount ?> Ditemukan
                                </span>
                                <button type="button" id="selectAllBtn" 
                                        class="text-xs btn-accent text-white px-3 py-1.5 rounded-lg hover:from-gray-700 hover:to-gray-800 transition flex items-center space-x-1">
                                    <i class="fas fa-check-square text-xs"></i>
                                    <span>Pilih Semua</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Info Selection -->
                        <div id="selectionInfo" class="selection-info hidden">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-users text-lg"></i>
                                <span class="font-semibold"><span id="selected-count-info">0</span> peserta terpilih</span>
                            </div>
                            <button type="button" id="clearSelectionBtn" 
                                    class="text-white hover:text-gray-200 text-sm font-semibold flex items-center space-x-1">
                                <i class="fas fa-times"></i>
                                <span>Hapus Pilihan</span>
                            </button>
                        </div>
                        
                        <!-- Form dan Tabel -->
                        <form method="POST" action="" id="peserta-form">
                            <input type="hidden" name="selected_all_filtered_mode" id="selected-all-filtered-mode" value="false">
                            <input type="hidden" name="search_hidden" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="status_hidden" value="<?= htmlspecialchars($filterStatus) ?>">
                            <input type="hidden" name="pembayaran_hidden" value="<?= htmlspecialchars($filterPembayaran) ?>">
                            <input type="hidden" name="bulan_bayar_hidden" value="<?= htmlspecialchars($filterBulanBayar) ?>">
                            <div class="halaqoh-hidden-inputs">
                                <?php foreach ($filterHalaqoh as $halaqoh): ?>
                                    <input type="hidden" name="halaqoh_hidden[]" value="<?= htmlspecialchars($halaqoh) ?>">
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="table-container">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="checkbox-cell px-4 py-3 bg-gray-50">
                                                <input type="checkbox" id="selectAllCheckbox" class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                            </th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Nama Lengkap</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Halaqoh</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Pembayaran</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">Riwayat Reminder</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($pesertaAktif)): ?>
                                            <?php foreach ($pesertaAktif as $peserta): ?>
                                                <?php
                                                $status = $peserta['status'] ?? 'proses';
                                                $namaLengkap = $peserta['nama_lengkap'] ?? 'N/A';
                                                $halaqoh = $peserta['halaqoh'] ?? '-';
                                                $nowa = $peserta['nowa'] ?? '';
                                                $idPeserta = $peserta['id'] ?? 0;
                                                $lastPaymentReminder = $peserta['last_payment_reminder'] ?? '';
                                                $idPembayaranTerakhir = $peserta['id_pembayaran_terakhir'] ?? null;
                                                ?>
                                                <tr class="hover:bg-gray-50 transition duration-150">
                                                    <td class="checkbox-cell px-4 py-3 whitespace-nowrap">
                                                        <input type="checkbox" name="selected_peserta[]" 
                                                               class="peserta-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500" 
                                                               value="<?= htmlspecialchars($nowa . '|' . $namaLengkap); ?>" 
                                                               <?= empty($nowa) ? 'disabled title="Nomor WA tidak tersedia"' : '' ?>>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 whitespace-nowrap">
                                                        <?= htmlspecialchars($namaLengkap); ?>
                                                        <?php if(empty($nowa)): ?>
                                                            <span class="text-xs text-red-500 ml-1" title="Tidak bisa dikirim - nomor WA kosong">⚠️</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap"><?= htmlspecialchars($halaqoh); ?></td>
                                                    <td class="px-6 py-4 text-sm whitespace-nowrap">
                                                        <span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 <?= $status == 'proses' ? 'badge-proses' : 'badge-selesai' ?>">
                                                            <span class="status-indicator <?= $status == 'proses' ? 'status-proses' : 'status-selesai' ?> mr-1.5"></span>
                                                            <?= htmlspecialchars($status); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm whitespace-nowrap">
                                                        <?php 
                                                        if (!empty($filterBulanBayar)) {
                                                            $cekLunasBulanIni = false;
                                                            if ($idPeserta) {
                                                                $checkLunasSql = "SELECT id FROM pembayaran WHERE peserta_id = ? AND bulan_pembayaran = ? LIMIT 1";
                                                                $checkStmt = $conn->prepare($checkLunasSql);
                                                                if ($checkStmt) {
                                                                    $checkStmt->bind_param("is", $idPeserta, $filterBulanBayar);
                                                                    $checkStmt->execute();
                                                                    $checkResult = $checkStmt->get_result();
                                                                    if ($checkResult->num_rows > 0) {
                                                                        $cekLunasBulanIni = true;
                                                                    }
                                                                    $checkStmt->close();
                                                                }
                                                            }
                                                            if ($cekLunasBulanIni) {
                                                                echo '<span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 badge-lunas"><i class="fas fa-check-circle text-green-500 mr-1.5"></i>Lunas ' . htmlspecialchars($filterBulanBayar) . '</span>';
                                                            } else {
                                                                echo '<span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 badge-belum-lunas"><i class="fas fa-times-circle text-red-500 mr-1.5"></i>Belum Bayar</span>';
                                                            }
                                                        } else {
                                                            if ($idPembayaranTerakhir): ?>
                                                                <span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 badge-lunas">
                                                                    <i class="fas fa-check-circle text-green-500 mr-1.5"></i>Lunas
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="inline-flex items-center text-xs leading-5 font-semibold rounded-full px-2 py-1 badge-belum-lunas">
                                                                    <i class="fas fa-times-circle text-red-500 mr-1.5"></i>Belum Lunas
                                                                </span>
                                                            <?php endif; 
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-xs text-gray-500 space-y-1">
                                                        <?php if (!empty($lastPaymentReminder)): ?>
                                                            <div>
                                                                <span class="text-[10px] bg-sky-100 text-sky-800 font-semibold px-2 py-0.5 rounded-full whitespace-nowrap">
                                                                     <?= date('d M Y', strtotime($lastPaymentReminder)) ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($lastSetoranReminder)): ?>
                                                            <div>
                                                                <span class="text-[10px] bg-teal-100 text-teal-800 font-semibold px-2 py-0.5 rounded-full whitespace-nowrap">
                                                                    Setoran: <?= date('d M Y', strtotime($lastSetoranReminder)) ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (empty($lastPaymentReminder) && empty($lastSetoranReminder)): ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center p-8 text-gray-500">
                                                    Tidak ada data peserta yang cocok dengan filter Anda.
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
                                           class="px-3 py-1 text-sm font-semibold rounded-lg <?= $page == $i ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-100' ?>">
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
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Templates -->
    <div id="manageTemplatesModal" class="fixed inset-0 z-50 items-center justify-center p-4 hidden">
        <div class="fixed inset-0 modal-backdrop"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col border border-card">
            <div class="flex justify-between items-center p-6 border-b border-card">
                <h3 class="text-xl font-bold text-primary">Kelola Template Pesan</h3>
                <button id="closeManageModalBtn" class="text-gray-400 hover:text-gray-700 text-3xl font-light">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto">
                <div class="space-y-4 mb-4">
                    <h4 class="text-sm font-semibold text-gray-600">Template Cepat Bawaan:</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                        <button type="button" class="btn-preset-template text-xs bg-gray-100 hover:bg-blue-100 text-gray-700 px-3 py-1.5 rounded-full transition" 
                                data-category="Reminder Pembayaran" data-title="H-5" 
                                data-content="Assalamualaikum, Kak {nama}. Mengingatkan kembali, H-5 adalah batas akhir pembayaran...">
                            Tambahkan H-5
                        </button>
                    </div>
                </div>
                <button id="addTemplateBtn" class="mb-4 w-full btn-primary text-white px-4 py-2 rounded-lg shadow hover:from-gray-700 hover:to-gray-800 transition">
                    Tambah Template Baru
                </button>
                <div class="space-y-2">
                    <?php foreach($dbTemplates as $tpl): ?>
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg border border-card">
                            <div>
                                <p class="font-semibold text-primary"><?= htmlspecialchars($tpl['title']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($tpl['category']) ?></p>
                            </div>
                            <div class="flex items-center space-x-4">
                                <button class="edit-template-btn text-blue-500 hover:underline text-sm font-semibold" 
                                        data-id="<?= $tpl['id'] ?>" 
                                        data-category="<?= htmlspecialchars($tpl['category']) ?>" 
                                        data-title="<?= htmlspecialchars($tpl['title']) ?>" 
                                        data-content="<?= htmlspecialchars($tpl['content']) ?>">
                                    Ubah
                                </button>
                                <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus template \'<?= htmlspecialchars(addslashes($tpl['title'])) ?>\'?');" class="inline-block">
                                    <input type="hidden" name="delete_template_id" value="<?= $tpl['id'] ?>">
                                    <button type="submit" class="text-red-500 hover:underline text-sm font-semibold">Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div id="templateModal" class="fixed inset-0 z-50 items-center justify-center p-4 hidden">
        <div class="fixed inset-0 modal-backdrop"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg border border-card">
            <form method="POST" action="">
                <div class="p-6">
                    <h3 id="templateModalTitle" class="text-xl font-bold mb-4 text-primary">Tambah Template Baru</h3>
                    <input type="hidden" name="template_id" id="template_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block font-semibold mb-1 text-sm text-gray-700">Kategori</label>
                            <input type="text" name="template_category" id="template_category" class="w-full border-gray-300 rounded-lg p-2 bg-white" required>
                        </div>
                        <div>
                            <label class="block font-semibold mb-1 text-sm text-gray-700">Judul Template</label>
                            <input type="text" name="template_title" id="template_title" class="w-full border-gray-300 rounded-lg p-2 bg-white" required>
                        </div>
                        <div>
                            <label class="block font-semibold mb-1 text-sm text-gray-700">Isi Pesan</label>
                            <textarea name="template_content" id="template_content" rows="6" class="w-full border-gray-300 rounded-lg p-2 bg-white" required></textarea>
                            <p class="text-xs text-gray-500 mt-1">Gunakan `{nama}` untuk personalisasi nama peserta.</p>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end items-center p-4 bg-gray-50 border-t border-card rounded-b-2xl space-x-2">
                    <button type="button" id="closeTemplateModalBtn" class="bg-white px-4 py-2 rounded-lg border border-gray-300">Batal</button>
                    <button type="submit" name="save_template" class="btn-primary text-white px-4 py-2 rounded-lg shadow hover:from-gray-700 hover:to-gray-800">Simpan Template</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // ---------------------------------------------------------
            // FUNGSI AJAX LOOPING (PENGIRIMAN REMINDER)
            // ---------------------------------------------------------
            const btnKirimReminder = document.getElementById('btn-kirim-reminder');
            
            btnKirimReminder.addEventListener('click', async function(e) {
                e.preventDefault();

                const form = document.getElementById('peserta-form');
                const formData = new FormData(form);
                
                // Ambil manual isi textarea karena ia berada di luar <form> tapi terhubung via ID
                const templatePesan = document.getElementById('template-textarea').value.trim();
                
                if(!templatePesan) {
                    alert("Gagal! Isi pesan template tidak boleh kosong."); 
                    return;
                }

                // Cek apakah ada yang diceklis jika mode All Filtered bernilai false
                if(document.getElementById('selected-all-filtered-mode').value === 'false') {
                    const checkboxes = document.querySelectorAll('.peserta-checkbox:checked');
                    if(checkboxes.length === 0) {
                        alert("Gagal! Anda belum memilih satupun peserta.");
                        return;
                    }
                }

                // Tampilkan Banner Loader
                const loader = document.getElementById('loader');
                const pBar = document.getElementById('progressBar');
                const pText = document.getElementById('progressText');
                const pStat = document.getElementById('progressStatus');

                loader.classList.remove('hidden');
                loader.classList.add('block');
                window.scrollTo({ top: 0, behavior: 'smooth' });

                btnKirimReminder.style.pointerEvents = 'none';
                btnKirimReminder.classList.add('opacity-75');
                btnKirimReminder.innerHTML = '<i class="fas fa-spinner fa-spin text-2xl mr-2"></i> Memproses...';

                // TAHAP 1: Ambil data target (karena mode 'Select All Filtered' butuh query ulang ke DB)
                formData.append('ajax_get_peserta', '1');
                
                let pesertaList = [];
                try {
                    pStat.innerHTML = `Mengambil data peserta terpilih...`;
                    let resGet = await fetch(window.location.href, { method: 'POST', body: formData });
                    let jsonGet = await resGet.json();
                    
                    if(jsonGet.status === 'success' && jsonGet.data) {
                        pesertaList = jsonGet.data;
                    }
                } catch(error) {
                    console.error("Error Get Data:", error);
                    alert("Gagal mengambil data peserta. Periksa koneksi Anda.");
                    window.location.reload();
                    return;
                }

                let total = pesertaList.length;
                if(total === 0) {
                    alert("Tidak ada peserta valid yang ditemukan.");
                    window.location.reload();
                    return;
                }

                // TAHAP 2: Loop pengiriman AJAX
                let successCount = 0;
                let failCount = 0;

                pBar.style.width = '0%';
                pText.innerText = `0 / ${total}`;

                for (let i = 0; i < total; i++) {
                    let parts = pesertaList[i].split('|');
                    let nowa = parts[0];
                    let namaPeserta = parts.length > 1 ? parts[1] : nowa;

                    pStat.innerHTML = `Mengirim ke: <b>${namaPeserta}</b>...`;

                    let sendData = new FormData();
                    sendData.append('ajax_kirim_reminder', '1');
                    sendData.append('nowa', nowa);
                    sendData.append('nama', namaPeserta);
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
            const checkboxes = document.querySelectorAll('.peserta-checkbox');

            function updateSelectionDisplay() {
                const checkedCount = document.querySelectorAll('.peserta-checkbox:checked').length;
                
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

            selectAllBtn.addEventListener('click', function() {
                const allChecked = document.querySelectorAll('.peserta-checkbox:checked').length === checkboxes.length;
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

            // Kosongkan selection saat filter diterapkan
            document.querySelector('#filter-form button[type="submit"]').addEventListener('click', function() {
                checkboxes.forEach(cb => cb.checked = false);
                updateSelectionDisplay();
            });

            updateSelectionDisplay();

            // ---------------------------------------------------------
            // FUNGSI MODAL TEMPLATE
            // ---------------------------------------------------------
            const manageModal = document.getElementById('manageTemplatesModal');
            const templateModal = document.getElementById('templateModal');
            
            function showModal(modal) { 
                modal.classList.remove('hidden'); 
                modal.classList.add('flex'); 
            }
            function hideModal(modal) { 
                modal.classList.add('hidden'); 
                modal.classList.remove('flex'); 
            }

            document.getElementById('manageTemplatesBtn').addEventListener('click', () => showModal(manageModal));
            document.getElementById('closeManageModalBtn').addEventListener('click', () => hideModal(manageModal));
            
            const templateModalTitle = document.getElementById('templateModalTitle');
            const templateIdInput = document.getElementById('template_id');
            const templateCategoryInput = document.getElementById('template_category');
            const templateTitleInput = document.getElementById('template_title');
            const templateContentInput = document.getElementById('template_content');
            
            document.getElementById('addTemplateBtn').addEventListener('click', () => {
                templateModalTitle.textContent = 'Tambah Template Baru';
                templateIdInput.value = ''; 
                templateCategoryInput.value = ''; 
                templateTitleInput.value = ''; 
                templateContentInput.value = '';
                hideModal(manageModal); 
                showModal(templateModal);
            });
            
            document.querySelectorAll('.edit-template-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    templateModalTitle.textContent = 'Ubah Template';
                    templateIdInput.value = this.dataset.id; 
                    templateCategoryInput.value = this.dataset.category;
                    templateTitleInput.value = this.dataset.title; 
                    templateContentInput.value = this.dataset.content;
                    hideModal(manageModal); 
                    showModal(templateModal);
                });
            });
            
            document.getElementById('closeTemplateModalBtn').addEventListener('click', () => hideModal(templateModal));
            
            document.querySelectorAll('.modal-backdrop').forEach(b => {
                b.addEventListener('click', () => { 
                    hideModal(manageModal); 
                    hideModal(templateModal); 
                });
            });
            
            const editBtn = document.getElementById('editSelectedTemplateBtn');
            const templateTextarea = document.getElementById('template-textarea');
            let selectedTemplateData = null;
            
            document.querySelectorAll('.template-btn, .btn-preset-template').forEach(button => {
                button.addEventListener('click', function() {
                    templateTextarea.value = this.dataset.content || this.dataset.template;
                    
                    document.querySelectorAll('.template-btn').forEach(btn => {
                        btn.classList.remove('bg-blue-200', 'text-blue-800', 'font-semibold');
                    });
                    this.classList.add('bg-blue-200', 'text-blue-800', 'font-semibold');
                    
                    if (this.dataset.id) {
                        selectedTemplateData = { 
                            id: this.dataset.id, 
                            category: this.dataset.category, 
                            title: this.dataset.title, 
                            content: this.dataset.template 
                        };
                        editBtn.classList.remove('hidden');
                    } else {
                        selectedTemplateData = null; 
                        editBtn.classList.add('hidden');
                    }
                });
            });
            
            templateTextarea.addEventListener('input', () => {
                document.querySelectorAll('.template-btn').forEach(btn => {
                    btn.classList.remove('bg-blue-200', 'text-blue-800', 'font-semibold');
                });
                selectedTemplateData = null; 
                editBtn.classList.add('hidden');
            });
            
            editBtn.addEventListener('click', () => {
                if (selectedTemplateData) {
                    templateModalTitle.textContent = 'Ubah Template';
                    templateIdInput.value = selectedTemplateData.id; 
                    templateCategoryInput.value = selectedTemplateData.category;
                    templateTitleInput.value = selectedTemplateData.title; 
                    templateContentInput.value = templateTextarea.value;
                    showModal(templateModal);
                }
            });

            // ---------------------------------------------------------
            // FUNGSI DROPDOWN HALAQOH
            // ---------------------------------------------------------
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
                        btnText.textContent = 'Pilih Halaqoh'; 
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
            
            setupHalaqohDropdown(
                'halaqoh-filter-btn',
                'halaqoh-filter-popup',
                'select-all-halaqoh',
                'halaqoh-checkbox'
            );
        });
    <script>
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