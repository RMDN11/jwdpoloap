<?php
require_once 'auth_checkwa.php';
require_once 'config.php';

// ============================================
// ENDPOINT AJAX UNTUK REAL-TIME POLLING LOG
// ============================================
if (isset($_GET['ajax_polling'])) {
    header('Content-Type: application/json');
    $logPesan = [];
    $logResult = $conn->query("SELECT l.id, l.nowa, l.message, l.created_at, (SELECT nama_lengkap FROM peserta p WHERE p.nowa = l.nowa LIMIT 1) as nama FROM log_wa l ORDER BY l.id DESC LIMIT 20");
    if ($logResult) { $logPesan = $logResult->fetch_all(MYSQLI_ASSOC); }
    
    echo json_encode(['logs' => $logPesan]);
    exit;
}

// ============================================
// ENDPOINT AJAX UNTUK LIVE SEARCH & PAGINASI
// ============================================
if (isset($_GET['ajax_fetch_peserta'])) {
    header('Content-Type: application/json');
    
    $search = $_GET['search'] ?? '';
    $filterHalaqoh = $_GET['halaqoh'] ?? '';
    if (!empty($filterHalaqoh)) {
        $filterHalaqoh = explode(',', $filterHalaqoh);
    } else {
        $filterHalaqoh = [];
    }
    $filterStatus = $_GET['status'] ?? 'semua';
    $filterPembayaran = $_GET['pembayaran'] ?? '';
    $filterBulanBayar = $_GET['bulan_bayar'] ?? '';
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $itemsPerPage = 50; 
    $offset = ($page - 1) * $itemsPerPage;

    // Hitung Total Data
    $innerCountSql = "SELECT p.id FROM peserta p LEFT JOIN pembayaran pemb ON p.id = pemb.peserta_id WHERE p.nowa IS NOT NULL AND p.nowa != ''";
    $countParams = [];
    $countTypes = '';

    if ($search) { $innerCountSql .= " AND p.nama_lengkap LIKE ?"; $countTypes .= 's'; $countParams[] = '%' . $search . '%'; }
    if (!empty($filterHalaqoh)) {
        $placeholders = implode(',', array_fill(0, count($filterHalaqoh), '?'));
        $innerCountSql .= " AND p.halaqoh IN ($placeholders)";
        $countTypes .= str_repeat('s', count($filterHalaqoh));
        $countParams = array_merge($countParams, $filterHalaqoh);
    }
    if ($filterStatus && $filterStatus !== 'semua') { $innerCountSql .= " AND p.status = ?"; $countTypes .= 's'; $countParams[] = $filterStatus; }
    if (!empty($filterBulanBayar)) {
        if ($filterPembayaran === 'lunas') {
            $innerCountSql .= " AND EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
            $countTypes .= 's'; $countParams[] = $filterBulanBayar;
        } elseif ($filterPembayaran === 'belum_lunas') {
            $innerCountSql .= " AND NOT EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
            $countTypes .= 's'; $countParams[] = $filterBulanBayar;
        }
    }
    $innerCountSql .= " GROUP BY p.id";

    if (empty($filterBulanBayar)) {
        if ($filterPembayaran === 'lunas') { $innerCountSql .= " HAVING MAX(pemb.id) IS NOT NULL"; }
        elseif ($filterPembayaran === 'belum_lunas') { $innerCountSql .= " HAVING MAX(pemb.id) IS NULL"; }
    }

    $countSql = "SELECT COUNT(*) as total FROM ($innerCountSql) as sub";
    $totalCount = 0;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        if (!empty($countParams)) {
            $refs = []; foreach ($countParams as $key => $value) { $refs[$key] = &$countParams[$key]; }
            call_user_func_array([$countStmt, 'bind_param'], array_merge([$countTypes], $refs));
        }
        $countStmt->execute();
        $totalCount = $countStmt->get_result()->fetch_assoc()['total'] ?? 0;
        $countStmt->close();
    }
    $totalPages = ceil($totalCount / $itemsPerPage);

    // Ambil Data Peserta
    $sql = "SELECT p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status, MAX(pemb.id) as id_pembayaran_terakhir 
            FROM peserta p LEFT JOIN pembayaran pemb ON p.id = pemb.peserta_id 
            WHERE p.nowa IS NOT NULL AND p.nowa != ''";
    $params = [];
    $types = '';

    if ($search) { $sql .= " AND p.nama_lengkap LIKE ?"; $types .= 's'; $params[] = '%' . $search . '%'; }
    if (!empty($filterHalaqoh)) {
        $placeholders = implode(',', array_fill(0, count($filterHalaqoh), '?'));
        $sql .= " AND p.halaqoh IN ($placeholders)";
        $types .= str_repeat('s', count($filterHalaqoh));
        $params = array_merge($params, $filterHalaqoh);
    }
    if ($filterStatus && $filterStatus !== 'semua') { $sql .= " AND p.status = ?"; $types .= 's'; $params[] = $filterStatus; }
    if (!empty($filterBulanBayar)) {
        if ($filterPembayaran === 'lunas') {
            $sql .= " AND EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
            $types .= 's'; $params[] = $filterBulanBayar;
        } elseif ($filterPembayaran === 'belum_lunas') {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM pembayaran p_check WHERE p_check.peserta_id = p.id AND p_check.bulan_pembayaran = ?)";
            $types .= 's'; $params[] = $filterBulanBayar;
        }
    }
    $sql .= " GROUP BY p.id";

    if (empty($filterBulanBayar)) {
        if ($filterPembayaran === 'lunas') { $sql .= " HAVING id_pembayaran_terakhir IS NOT NULL"; } 
        elseif ($filterPembayaran === 'belum_lunas') { $sql .= " HAVING id_pembayaran_terakhir IS NULL"; }
    }
    $sql .= " ORDER BY p.halaqoh, p.nama_lengkap LIMIT ? OFFSET ?";
    $types .= 'ii'; $params[] = $itemsPerPage; $params[] = $offset;

    $pesertaAktif = [];
    $pesertaIds = [];
    $pesertaNoWas = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $refs = []; foreach ($params as $key => $value) { $refs[$key] = &$params[$key]; }
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()) {
            $pesertaAktif[] = $row;
            $pesertaIds[] = $row['id'];
            if(!empty($row['nowa'])) $pesertaNoWas[] = "'" . $conn->real_escape_string($row['nowa']) . "'";
        }
        $stmt->close();
    }

    // Mapping Riwayat Log
    $logDataMap = [];
    $cekLunasBulanMap = [];

    if(!empty($pesertaNoWas)) {
        $noWaList = implode(',', $pesertaNoWas);
        $logSql = "SELECT nowa, message, created_at FROM log_wa WHERE nowa IN ($noWaList) ORDER BY created_at DESC";
        $logRes = $conn->query($logSql);
        if($logRes) {
            while($l = $logRes->fetch_assoc()) {
                $n = $l['nowa'];
                if(!isset($logDataMap[$n])) { $logDataMap[$n] = []; }
                if (count($logDataMap[$n]) < 3) {
                    $logDataMap[$n][] = ['msg' => $l['message'], 'date' => $l['created_at']];
                }
            }
        }
    }

    if(!empty($filterBulanBayar) && !empty($pesertaIds)) {
        $idList = implode(',', $pesertaIds);
        $safeBulan = $conn->real_escape_string($filterBulanBayar);
        $cekSql = "SELECT peserta_id FROM pembayaran WHERE peserta_id IN ($idList) AND bulan_pembayaran = '$safeBulan'";
        $cekRes = $conn->query($cekSql);
        if($cekRes) { while($c = $cekRes->fetch_assoc()) { $cekLunasBulanMap[$c['peserta_id']] = true; } }
    }

    $dataPesertaFormatted = [];
    foreach($pesertaAktif as $p) {
        $isLunas = !empty($filterBulanBayar) ? isset($cekLunasBulanMap[$p['id']]) : !empty($p['id_pembayaran_terakhir']);
        $dataPesertaFormatted[] = [
            'id' => $p['id'], 'nama_lengkap' => $p['nama_lengkap'], 'nowa' => $p['nowa'],
            'halaqoh' => $p['halaqoh'] ?? '-', 'status' => $p['status'] ?? 'proses', 'is_lunas' => $isLunas,
            'riwayat' => $logDataMap[$p['nowa']] ?? []
        ];
    }

    echo json_encode(['peserta' => $dataPesertaFormatted, 'total' => $totalCount, 'page' => $page, 'total_pages' => $totalPages]);
    exit;
}

function kirimPesan($recipient, $message, $apiUrl, $apiToken) {
    $data = ["recipient_type" => "individual", "to" => $recipient, "type" => "text", "text" => ["body" => $message]];
    $jsonData = json_encode($data);
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) { return ['status' => 'GAGAL', 'message' => "cURL Error: " . $curlError]; }
    $responseData = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) { return ['status' => 'TERKIRIM', 'message' => "Berhasil dikirim."]; } 
    else {
        $errorMessage = isset($responseData['message']) ? $responseData['message'] : "Unknown error.";
        return ['status' => 'GAGAL', 'message' => "Pengiriman tidak berhasil: " . $errorMessage];
    }
}

// ============================================
// PROSES PENGIRIMAN LANGSUNG (TANPA ANTREAN CRON)
// ============================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_add_to_queue'])) {
    header('Content-Type: application/json');
    $templatePesan = $_POST['template_pesan'] ?? '';
    if (empty($templatePesan)) { echo json_encode(['status' => 'error', 'msg' => 'Template pesan kosong!']); exit; }

    $selectedPeserta = $_POST['selected_peserta'] ?? [];
    if (is_string($selectedPeserta)) { $selectedPeserta = json_decode($selectedPeserta, true) ?? []; }
    
    $countSuccess = 0;
    $countFailed = 0;
    global $apiUrl, $apiToken; 
    
    $stmt_log = $conn->prepare("INSERT INTO log_wa (nowa, message) VALUES (?, ?)");
    
    foreach ($selectedPeserta as $p_info) {
        $parts = explode('|', $p_info);
        $nowa = $parts[0];
        $namaPeserta = count($parts) > 1 ? $parts[1] : $nowa;
        if (empty($nowa)) continue;

        $pesanBody = str_replace('{nama}', $namaPeserta, $templatePesan);
        $hasil = kirimPesan($nowa, $pesanBody, $apiUrl, $apiToken);

        if ($hasil['status'] === 'TERKIRIM') {
            $countSuccess++;
        } else {
            $countFailed++;
        }

        if ($stmt_log) {
            $stmt_log->bind_param("ss", $nowa, $pesanBody);
            $stmt_log->execute();
        }
    }
    
    if ($stmt_log) { $stmt_log->close(); }
    
    $pesanAkhir = "$countSuccess pesan berhasil dikirim langsung.";
    if ($countFailed > 0) $pesanAkhir .= " ($countFailed gagal).";
    
    echo json_encode(['status' => 'success', 'msg' => $pesanAkhir]);
    exit;
}

// ============================================
// PROSES NOTIFICATION & LAINNYA
// ============================================
$notification = '';
$notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notification_type'];
    unset($_SESSION['notification']);
    unset($_SESSION['notification_type']);
}

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
    if ($stmt->execute()) { $_SESSION['notification_type'] = 'success'; } 
    else { $_SESSION['notification'] = "Gagal menyimpan template: " . $stmt->error; $_SESSION['notification_type'] = 'error'; }
    $stmt->close();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_template_id'])) {
    $templateIdToDelete = $_POST['delete_template_id'];
    $stmt = $conn->prepare("DELETE FROM wa_templates WHERE id = ?");
    $stmt->bind_param("i", $templateIdToDelete);
    if ($stmt->execute()) { $_SESSION['notification'] = "Template berhasil dihapus."; $_SESSION['notification_type'] = 'success'; } 
    else { $_SESSION['notification'] = "Gagal menghapus: " . $stmt->error; $_SESSION['notification_type'] = 'error'; }
    $stmt->close();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all_logs'])) {
    if ($conn->query("TRUNCATE TABLE log_wa")) {
        $_SESSION['notification'] = "Semua riwayat log berhasil dihapus."; $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus riwayat: " . $conn->error; $_SESSION['notification_type'] = 'error';
    }
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_log_id'])) {
    $logIdToDelete = $_POST['delete_log_id'];
    $stmt = $conn->prepare("DELETE FROM log_wa WHERE id = ?"); 
    $stmt->bind_param("i", $logIdToDelete);
    if ($stmt->execute()) { $_SESSION['notification'] = "Log berhasil dihapus."; $_SESSION['notification_type'] = 'success'; }
    else { $_SESSION['notification'] = "Gagal menghapus log: " . $stmt->error; $_SESSION['notification_type'] = 'error'; }
    $stmt->close();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

// ============================================
// AMBIL DATA UNTUK TAMPILAN
// ============================================
$halaqohList = [];
$halaqohResult = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh != '' ORDER BY halaqoh");
if ($halaqohResult) { while ($row = $halaqohResult->fetch_assoc()) { $halaqohList[] = $row['halaqoh']; } }

$bulanPembayaranList = [];
$bulanResult = $conn->query("SELECT DISTINCT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran != ''");
if ($bulanResult) { 
    while ($row = $bulanResult->fetch_assoc()) { $bulanPembayaranList[] = $row['bulan_pembayaran']; } 
}
usort($bulanPembayaranList, function($a, $b) {
    $bulanIndo = [
        'januari' => '01', 'februari' => '02', 'maret' => '03', 'april' => '04',
        'mei' => '05', 'juni' => '06', 'juli' => '07', 'agustus' => '08',
        'september' => '09', 'oktober' => '10', 'november' => '11', 'desember' => '12'
    ];
    $parseDate = function($str) use ($bulanIndo) {
        $str = strtolower(trim($str));
        foreach ($bulanIndo as $indo => $angka) {
            if (strpos($str, $indo) !== false) {
                $str = str_replace($indo, $angka, $str);
                break;
            }
        }
        return strtotime('01-' . str_replace(' ', '-', $str));
    };
    return $parseDate($b) <=> $parseDate($a);
});

$dbTemplatesResult = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY category, title");
$dbTemplates = $dbTemplatesResult ? $dbTemplatesResult->fetch_all(MYSQLI_ASSOC) : [];
$allTemplates = [];
foreach($dbTemplates as $tpl) {
    if (!isset($allTemplates[$tpl['category']])) { $allTemplates[$tpl['category']] = []; }
    $allTemplates[$tpl['category']][] = $tpl;
}
$templatePesanDefault = "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kirim Reminder - JWD</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    
    <!-- Tailwind & FontAwesome -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- SweetAlert2 untuk Notifikasi Cantik -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .bento-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 1.25rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
        }
        .bento-card:hover { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04); }
        .modal-backdrop { background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); }
        .badge-lunas { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .badge-belum-lunas { background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .badge-proses { background-color: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .badge-selesai { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .table-container { max-height: 500px; overflow-y: auto; overflow-x: auto; border-radius: 0.75rem; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.4); border-radius: 10px; }
        .btn-loading { opacity: 0.75; pointer-events: none; cursor: not-allowed; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800">

    <main class="flex-grow p-4 sm:p-6 lg:p-8">
        <div class="max-w-[90rem] mx-auto relative">
            
            <!-- Loader -->
            <div id="loader" class="hidden mb-6 bg-white/90 backdrop-blur-md rounded-2xl shadow-lg border border-indigo-100 overflow-hidden relative z-50">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-400 via-indigo-500 to-purple-500"></div>
                <div class="p-5 flex flex-col sm:flex-row items-center gap-5">
                    <div class="bg-indigo-50 w-14 h-14 rounded-full flex items-center justify-center shrink-0 shadow-inner border border-indigo-100">
                        <i class="fas fa-paper-plane text-2xl text-indigo-500 animate-bounce"></i>
                    </div>
                    <div class="flex-1 w-full">
                        <h3 class="font-extrabold text-slate-800 text-base">Mengirim Pesan...</h3>
                        <p id="progressStatus" class="text-xs font-medium text-slate-500 mt-0.5">Memproses data secara langsung (real-time)...</p>
                    </div>
                </div>
            </div>

            <!-- Notifikasi PHP via SweetAlert2 (Lebih Cantik) -->
            <?php if (!empty($notification)): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: '<?php echo $notificationType === 'success' ? 'success' : 'error'; ?>',
                    title: '<?php echo $notificationType === 'success' ? 'Berhasil!' : 'Gagal!'; ?>',
                    text: <?php echo json_encode($notification); ?>,
                    confirmButtonColor: '<?php echo $notificationType === 'success' ? '#10b981' : '#ef4444'; ?>',
                    timer: 3500,
                    timerProgressBar: true,
                    showConfirmButton: false
                });
            });
            </script>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
                <!-- Panel Aksi -->
                <div class="xl:col-span-4 space-y-6 order-2 xl:order-1 relative z-10">
                    <div class="bento-card p-6 sticky top-6">
                        <h2 class="text-lg font-bold mb-5 flex items-center text-slate-800">
                            <i class="fas fa-bolt text-indigo-500 text-xl mr-2"></i> Panel Aksi
                        </h2>
                        
                        <div class="space-y-4 mb-5">
                            <div>
                                <div class="flex justify-between items-center mb-3">
                                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Pilih Template Cepat</h4>
                                    <button type="button" id="manageTemplatesBtn" class="text-xs text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg font-bold transition">
                                        Kelola Template
                                    </button>
                                </div>
                                <?php foreach ($allTemplates as $category => $group): ?>
                                    <div class="mb-3">
                                        <h5 class="text-[11px] font-bold text-slate-500 mb-2"><?= htmlspecialchars($category) ?></h5>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach ($group as $tpl): ?>
                                                <button type="button" class="template-btn text-xs bg-white border border-slate-200 hover:border-indigo-400 hover:bg-indigo-50 text-slate-700 px-3 py-1.5 rounded-xl shadow-sm transition" 
                                                        data-template="<?= htmlspecialchars($tpl['content']) ?>" data-id="<?= $tpl['id'] ?>">
                                                    <?= htmlspecialchars($tpl['title']) ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-5 relative">
                            <div class="flex justify-between items-center mb-2">
                                <label class="block font-bold text-sm text-slate-700">Isi Pesan</label>
                            </div>
                            <textarea id="template-textarea" rows="6" class="w-full border-slate-200 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm transition bg-white shadow-inner resize-none" placeholder="Ketik pesan Anda di sini..."><?= htmlspecialchars($templatePesanDefault) ?></textarea>
                            <div class="absolute bottom-3 right-3 text-[11px] text-slate-400 font-medium bg-white px-1">
                                Gunakan <span class="bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded-md">{nama}</span>
                            </div>
                        </div>
                        
                        <button type="button" id="btn-kirim-reminder"
                                class="w-full bg-slate-800 hover:bg-slate-900 text-white px-6 py-3.5 rounded-xl shadow-lg shadow-slate-900/20 font-bold text-sm transition duration-300 flex items-center justify-center transform active:scale-95">
                            <i class="fas fa-paper-plane text-lg mr-2"></i> Kirim Langsung Ke (<span id="total-selected-count">0</span>) Peserta
                        </button>
                        
                        <!-- TOMBOL POPUP BARU KE CEK_LANJUT.PHP -->
                        <button type="button" id="btn-cek-lanjut"
                                class="w-full mt-3 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white px-6 py-3.5 rounded-xl shadow-lg shadow-indigo-500/30 font-bold text-sm transition duration-300 flex items-center justify-center transform active:scale-95">
                            <i class="fas fa-external-link-alt text-lg mr-2"></i> Cek Halaman Lanjut
                        </button>
                        
                        <hr class="my-6 border-slate-100">
                        
                        <!-- REAL-TIME LOG AREA -->
                        <div class="mt-2">
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="text-sm font-bold text-slate-800 flex items-center">
                                    <i class="fas fa-list-ul text-slate-400 mr-2"></i> Status Pengiriman (Live)
                                </h3>
                                <form method="POST" action="">
                                    <button type="submit" name="delete_all_logs" value="1" onclick="return confirm('Hapus semua log riwayat?');" class="text-[10px] text-red-500 hover:underline font-bold uppercase tracking-wider">Bersihkan</button>
                                </form>
                            </div>
                            <div id="live-log-container" class="space-y-2 max-h-[300px] overflow-y-auto pr-1">
                                <div class="p-4 text-center text-xs text-slate-400"><i class="fas fa-spinner fa-spin mr-1"></i> Memuat log...</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Panel Filter & Tabel -->
                <div class="xl:col-span-8 space-y-6 order-1 xl:order-2 relative">
                    <div class="bento-card p-6 relative z-30">
                        <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                            <div class="md:col-span-12 relative">
                                <i class="fas fa-search absolute left-4 top-3.5 text-slate-400"></i>
                                <input type="text" id="live-search-input" placeholder="Cari nama peserta instan..." class="w-full border-slate-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-indigo-500 font-medium text-sm transition shadow-sm bg-white">
                            </div>
                            
                            <div class="md:col-span-3">
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Halaqoh</label>
                                <div class="relative">
                                    <button type="button" id="halaqoh-filter-btn" class="w-full bg-white border border-slate-200 shadow-sm rounded-xl py-2.5 px-3 text-left flex justify-between items-center text-sm font-medium focus:ring-2 focus:ring-indigo-500">
                                        <span id="halaqoh-filter-btn-text" class="truncate pr-2">Pilih Halaqoh</span> <i class="fas fa-chevron-down text-slate-400 text-xs"></i>
                                    </button>
                                    <div id="halaqoh-filter-popup" class="hidden absolute z-50 mt-2 w-full bg-white border border-slate-100 rounded-xl shadow-xl max-h-60 overflow-y-auto p-2">
                                        <label class="flex items-center p-2 hover:bg-slate-50 rounded-lg cursor-pointer border-b border-slate-100 mb-1">
                                            <input type="checkbox" id="select-all-halaqoh" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                            <span class="ml-2 text-sm font-bold text-slate-700">Pilih Semua</span>
                                        </label>
                                        <?php foreach ($halaqohList as $halaqoh): ?>
                                            <label class="flex items-center p-2 hover:bg-slate-50 rounded-lg cursor-pointer">
                                                <input type="checkbox" value="<?= htmlspecialchars($halaqoh) ?>" class="halaqoh-checkbox h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                                <span class="ml-2 text-sm text-slate-600"><?= htmlspecialchars($halaqoh) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Status</label>
                                <select id="filter-status" class="w-full bg-white border border-slate-200 shadow-sm rounded-xl py-2.5 px-3 text-sm font-medium focus:ring-2 focus:ring-indigo-500 appearance-none">
                                    <option value="semua">Semua</option>
                                    <option value="proses">Proses</option>
                                    <option value="selesai">Selesai</option>
                                </select>
                            </div>
                            
                            <div class="md:col-span-3">
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Bulan Bayar</label>
                                <select id="filter-bulan" class="w-full bg-white border border-slate-200 shadow-sm rounded-xl py-2.5 px-3 text-sm font-medium focus:ring-2 focus:ring-indigo-500 appearance-none">
                                    <option value="">- Semua Bulan -</option>
                                    <?php foreach ($bulanPembayaranList as $bulan): ?>
                                        <option value="<?= htmlspecialchars($bulan) ?>"><?= htmlspecialchars($bulan) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Pembayaran</label>
                                <select id="filter-pembayaran" class="w-full bg-white border border-slate-200 shadow-sm rounded-xl py-2.5 px-3 text-sm font-medium focus:ring-2 focus:ring-indigo-500 appearance-none">
                                    <option value="">Semua</option>
                                    <option value="lunas">Lunas</option>
                                    <option value="belum_lunas">Belum</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bento-card p-0 md:p-6 relative z-10 bg-transparent md:bg-white/70">
                        <div class="p-4 md:p-0 flex flex-col sm:flex-row justify-between items-start sm:items-center bg-white/80 md:bg-transparent rounded-t-xl backdrop-blur-md mb-0 md:mb-6 gap-4 border-b border-slate-100 md:border-none">
                            <div class="flex items-center flex-wrap gap-3">
                                <h2 class="text-lg font-bold text-slate-800 flex items-center">
                                    <i class="fas fa-users text-indigo-500 mr-2"></i> Daftar Peserta
                                </h2>
                                <span id="total-count-badge" class="bg-indigo-100 text-indigo-800 text-xs font-bold px-3 py-1 rounded-full border border-indigo-200">0 Data</span>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" id="clearSelectionBtn" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-2 rounded-xl font-bold transition hidden shadow-sm">Reset Pilihan</button>
                                <button type="button" id="selectAllCurrentPageBtn" class="text-xs bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-xl transition flex items-center shadow-sm font-semibold">
                                    <i class="fas fa-check-square mr-2"></i> Pilih Semua (Halaman Ini)
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-container border-none md:border md:border-slate-200">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="hidden md:table-header-group bg-slate-50/90 backdrop-blur-md">
                                    <tr>
                                        <th class="checkbox-cell px-4 py-4 text-center"><input type="checkbox" id="selectAllCheckbox" class="h-4 w-4 text-indigo-600 rounded"></th>
                                        <th class="px-4 py-4 text-left text-xs font-bold text-slate-500 uppercase">Nama Peserta</th>
                                        <th class="px-4 py-4 text-left text-xs font-bold text-slate-500 uppercase">Halaqoh & Status</th>
                                        <th class="px-4 py-4 text-left text-xs font-bold text-slate-500 uppercase">Pembayaran</th>
                                        <th class="px-4 py-4 text-left text-xs font-bold text-slate-500 uppercase w-1/3">Riwayat Terakhir</th>
                                    </tr>
                                </thead>
                                <tbody id="peserta-table-body" class="block md:table-row-group space-y-3 md:space-y-0 p-2 md:p-0">
                                    <!-- Rendered via JS -->
                                </tbody>
                            </table>
                        </div>
                        
                        <div id="pagination-container" class="mt-4 flex justify-between items-center bg-white/80 backdrop-blur-md p-3 rounded-xl border border-slate-100 text-xs font-bold shadow-sm"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL TEMPLATE -->
    <div id="manageTemplatesModal" class="fixed inset-0 z-50 items-center justify-center p-4 hidden">
        <div class="fixed inset-0 modal-backdrop"></div>
        <div class="relative bg-white/95 backdrop-blur-xl rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col border border-slate-200">
            <div class="flex justify-between items-center p-6 border-b border-slate-100">
                <h3 class="text-lg font-bold text-slate-800 flex items-center"><i class="fas fa-folder-open text-indigo-500 mr-2"></i> Kelola Template Pesan</h3>
                <button id="closeManageModalBtn" class="text-slate-400 hover:text-red-500 text-2xl font-light transition">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto">
                <div class="space-y-4 mb-6">
                    <h4 class="text-xs font-bold text-slate-500 uppercase">Template Cepat Bawaan:</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <button type="button" class="btn-preset-template text-xs bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-slate-700 px-3 py-2 rounded-xl font-medium transition" 
                                data-category="Reminder Pembayaran" data-title="H-5" 
                                data-content="Assalamualaikum, Kak {nama}. Mengingatkan kembali, H-5 adalah batas akhir pembayaran...">
                            <i class="fas fa-plus-circle mr-1"></i> Tambah Template H-5
                        </button>
                    </div>
                </div>
                <button id="addTemplateBtn" class="mb-5 w-full bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-4 py-2.5 rounded-xl font-bold transition">
                    <i class="fas fa-plus mr-2"></i> Buat Template Baru
                </button>
                <div class="space-y-3">
                    <?php foreach($dbTemplates as $tpl): ?>
                        <div class="flex justify-between items-center p-4 bg-slate-50 rounded-xl border border-slate-200 hover:border-indigo-200 transition">
                            <div>
                                <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($tpl['title']) ?></p>
                                <p class="text-[11px] text-slate-500 font-medium uppercase mt-0.5"><?= htmlspecialchars($tpl['category']) ?></p>
                            </div>
                            <div class="flex items-center space-x-3">
                                <button class="edit-template-btn text-indigo-500 hover:text-indigo-700 text-xs font-bold bg-white px-2.5 py-1.5 rounded-lg border border-slate-200 shadow-sm" 
                                        data-id="<?= $tpl['id'] ?>" data-category="<?= htmlspecialchars($tpl['category']) ?>" 
                                        data-title="<?= htmlspecialchars($tpl['title']) ?>" data-content="<?= htmlspecialchars($tpl['content']) ?>">
                                    Ubah
                                </button>
                                <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus template \'<?= htmlspecialchars(addslashes($tpl['title'])) ?>\'?');" class="inline-block">
                                    <input type="hidden" name="delete_template_id" value="<?= $tpl['id'] ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-bold bg-white px-2.5 py-1.5 rounded-lg border border-slate-200 shadow-sm">Hapus</button>
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
        <div class="relative bg-white/95 backdrop-blur-xl rounded-2xl shadow-2xl w-full max-w-lg border border-slate-200">
            <form method="POST" action="">
                <div class="p-6">
                    <h3 id="templateModalTitle" class="text-lg font-bold mb-5 text-slate-800">Tambah Template Baru</h3>
                    <input type="hidden" name="template_id" id="template_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block font-bold mb-1.5 text-xs text-slate-600 uppercase">Kategori</label>
                            <input type="text" name="template_category" id="template_category" class="w-full border-slate-200 rounded-xl p-2.5 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500 text-sm font-medium" required>
                        </div>
                        <div>
                            <label class="block font-bold mb-1.5 text-xs text-slate-600 uppercase">Judul Template</label>
                            <input type="text" name="template_title" id="template_title" class="w-full border-slate-200 rounded-xl p-2.5 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500 text-sm font-medium" required>
                        </div>
                        <div>
                            <label class="block font-bold mb-1.5 text-xs text-slate-600 uppercase">Isi Pesan</label>
                            <textarea name="template_content" id="template_content" rows="6" class="w-full border-slate-200 rounded-xl p-2.5 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500 text-sm" required></textarea>
                            <p class="text-[11px] text-slate-500 mt-1.5">Gunakan <code class="bg-indigo-100 text-indigo-700 px-1 rounded">{nama}</code> untuk personalisasi nama peserta.</p>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end items-center p-5 bg-slate-50/80 border-t border-slate-200 rounded-b-2xl space-x-3">
                    <button type="button" id="closeTemplateModalBtn" class="bg-white text-slate-600 font-bold px-4 py-2.5 rounded-xl border border-slate-200 hover:bg-slate-100 text-sm">Batal</button>
                    <button type="submit" name="save_template" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md text-sm transition">Simpan Template</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCRIPT UTAMA -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let selectedPesertaSet = new Set();
            let currentPage = 1;
            
            const tableBody = document.getElementById('peserta-table-body');
            const paginationContainer = document.getElementById('pagination-container');
            const searchInput = document.getElementById('live-search-input');
            const filterStatus = document.getElementById('filter-status');
            const filterBulan = document.getElementById('filter-bulan');
            const filterPembayaran = document.getElementById('filter-pembayaran');
            
            const totalCountBadge = document.getElementById('total-count-badge');
            const totalSelectedCountSpan = document.getElementById('total-selected-count');
            const clearSelectionBtn = document.getElementById('clearSelectionBtn');
            const selectAllCurrentPageBtn = document.getElementById('selectAllCurrentPageBtn');
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            
            function loadPesertaData(page = 1) {
                currentPage = page;
                const searchVal = encodeURIComponent(searchInput.value.trim());
                const statusVal = encodeURIComponent(filterStatus.value);
                const bulanVal = encodeURIComponent(filterBulan.value);
                const bayarVal = encodeURIComponent(filterPembayaran.value);
                const halaqohChecked = Array.from(document.querySelectorAll('.halaqoh-checkbox:checked')).map(cb => cb.value).join(',');
                const halaqohVal = encodeURIComponent(halaqohChecked);
                
                tableBody.innerHTML = `<tr><td colspan="5" class="text-center py-10 text-slate-400"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat data...</td></tr>`;
                
                fetch(`?ajax_fetch_peserta=1&search=${searchVal}&status=${statusVal}&bulan_bayar=${bulanVal}&pembayaran=${bayarVal}&halaqoh=${halaqohVal}&page=${page}`)
                    .then(res => res.json())
                    .then(data => {
                        renderTable(data.peserta);
                        renderPagination(data.page, data.total_pages);
                        totalCountBadge.textContent = data.total + ' Data';
                        updateSelectAllPageCheckbox();
                    })
                    .catch(err => {
                        console.error("Error Fetching Data:", err);
                        tableBody.innerHTML = `<tr><td colspan="5" class="text-center py-10 text-red-500">Gagal memuat data.</td></tr>`;
                    });
            }

            function renderTable(pesertaList) {
                if (pesertaList.length === 0) {
                    tableBody.innerHTML = `<tr><td colspan="5" class="text-center py-10 text-slate-400 bg-white/50 backdrop-blur-md rounded-xl">Peserta tidak ditemukan.</td></tr>`;
                    return;
                }
                
                tableBody.innerHTML = '';
                pesertaList.forEach(p => {
                    const valueKey = `${p.nowa}|${p.nama_lengkap}`;
                    const isChecked = selectedPesertaSet.has(valueKey) ? 'checked' : '';
                    
                    let statusLunasBadge = p.is_lunas 
                        ? `<span class="badge-lunas text-[10px] font-bold px-2 py-0.5 rounded-md"><i class="fas fa-check mr-1"></i>Lunas</span>`
                        : `<span class="badge-belum-lunas text-[10px] font-bold px-2 py-0.5 rounded-md"><i class="fas fa-times mr-1"></i>Belum</span>`;

                    let riwayatHtml = '';
                    if(p.riwayat && p.riwayat.length > 0) {
                        p.riwayat.forEach(r => {
                            riwayatHtml += `
                                <div class="bg-slate-50 p-1.5 rounded-lg border border-slate-100 mb-1 shadow-sm">
                                    <div class="text-[9px] font-bold text-indigo-500"><i class="fas fa-history mr-1"></i>${r.date}</div>
                                    <div class="text-[10px] text-slate-600 truncate">${r.msg}</div>
                                </div>`;
                        });
                    } else {
                        riwayatHtml = `<div class="text-[10px] text-slate-400 italic">Belum ada riwayat</div>`;
                    }

                    const row = document.createElement('tr');
                    row.className = 'block md:table-row bg-white/90 md:bg-transparent backdrop-blur-md rounded-2xl md:rounded-none shadow-sm md:shadow-none border border-slate-100 md:border-b hover:bg-slate-50 transition relative overflow-hidden mb-3 md:mb-0';
                    row.innerHTML = `
                        <td class="block md:table-cell p-3 md:px-4 md:py-3 border-b border-slate-50 md:border-none text-center">
                            <div class="flex items-center justify-between md:justify-center">
                                <span class="md:hidden text-xs font-bold text-slate-400">Pilih</span>
                                <input type="checkbox" class="peserta-checkbox h-5 w-5 md:h-4 md:w-4 text-indigo-600 rounded" value="${valueKey}" ${isChecked} ${!p.nowa ? 'disabled title="Nomor Kosong"' : ''}>
                            </div>
                        </td>
                        <td class="block md:table-cell px-4 py-2 md:py-3 border-b border-slate-50 md:border-none">
                            <div class="font-bold text-slate-800 text-sm">${p.nama_lengkap}</div>
                            <div class="text-[11px] text-slate-500 font-medium">${p.nowa || 'Tanpa Nomor'}</div>
                        </td>
                        <td class="block md:table-cell px-4 py-2 md:py-3 border-b border-slate-50 md:border-none">
                            <div class="text-sm font-medium text-slate-600">${p.halaqoh}</div>
                            <span class="inline-flex items-center text-[10px] font-bold rounded-md px-1.5 py-0.5 mt-1 ${p.status == 'proses' ? 'badge-proses' : 'badge-selesai'}">${p.status.toUpperCase()}</span>
                        </td>
                        <td class="block md:table-cell px-4 py-2 md:py-3 border-b border-slate-50 md:border-none">
                            <div class="flex justify-between md:justify-start items-center">
                                <span class="md:hidden text-xs font-bold text-slate-400">Status Bayar</span>
                                ${statusLunasBadge}
                            </div>
                        </td>
                        <td class="block md:table-cell px-4 py-2 md:py-3 bg-slate-50/50 md:bg-transparent">
                            <div class="max-h-24 overflow-y-auto pr-1">${riwayatHtml}</div>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
                bindCheckboxEvents();
            }

            function bindCheckboxEvents() {
                document.querySelectorAll('.peserta-checkbox').forEach(cb => {
                    cb.addEventListener('change', function() {
                        if (this.checked) selectedPesertaSet.add(this.value);
                        else selectedPesertaSet.delete(this.value);
                        updateSelectedCounter();
                        updateSelectAllPageCheckbox();
                    });
                });
            }

            function updateSelectedCounter() {
                const count = selectedPesertaSet.size;
                totalSelectedCountSpan.textContent = count;
                if (count > 0) clearSelectionBtn.classList.remove('hidden');
                else clearSelectionBtn.classList.add('hidden');
            }

            function updateSelectAllPageCheckbox() {
                const visibleCheckboxes = document.querySelectorAll('.peserta-checkbox:not(:disabled)');
                const checkedVisible = document.querySelectorAll('.peserta-checkbox:not(:disabled):checked');
                selectAllCheckbox.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.length === checkedVisible.length;
                
                if(checkedVisible.length > 0 && checkedVisible.length === visibleCheckboxes.length) {
                    selectAllCurrentPageBtn.innerHTML = '<i class="fas fa-times mr-2"></i> Batal Semua (Hal Ini)';
                    selectAllCurrentPageBtn.classList.replace('bg-slate-800', 'bg-red-600');
                    selectAllCurrentPageBtn.classList.replace('hover:bg-slate-700', 'hover:bg-red-700');
                } else {
                    selectAllCurrentPageBtn.innerHTML = '<i class="fas fa-check-square mr-2"></i> Pilih Semua (Hal Ini)';
                    selectAllCurrentPageBtn.classList.replace('bg-red-600', 'bg-slate-800');
                    selectAllCurrentPageBtn.classList.replace('hover:bg-red-700', 'hover:bg-slate-700');
                }
            }

            function renderPagination(page, totalPages) {
                if(totalPages <= 1) { paginationContainer.innerHTML = ''; return; }
                let html = `<button type="button" onclick="changePage(${page - 1})" class="px-4 py-2 bg-white border border-slate-200 rounded-lg shadow-sm hover:bg-slate-50 transition ${page <= 1 ? 'opacity-50 pointer-events-none' : ''}">Prev</button>`;
                html += `<span class="text-slate-500 font-medium">Hal ${page} / ${totalPages}</span>`;
                html += `<button type="button" onclick="changePage(${page + 1})" class="px-4 py-2 bg-white border border-slate-200 rounded-lg shadow-sm hover:bg-slate-50 transition ${page >= totalPages ? 'opacity-50 pointer-events-none' : ''}">Next</button>`;
                paginationContainer.innerHTML = html;
            }

            window.changePage = function(newPage) { loadPesertaData(newPage); }

            let debounceTimer;
            searchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => { loadPesertaData(1); }, 300);
            });

            filterStatus.addEventListener('change', () => loadPesertaData(1));
            filterBulan.addEventListener('change', () => loadPesertaData(1));
            filterPembayaran.addEventListener('change', () => loadPesertaData(1));

            const btnHalaqoh = document.getElementById('halaqoh-filter-btn');
            const popupHalaqoh = document.getElementById('halaqoh-filter-popup');
            const selectAllHalaqoh = document.getElementById('select-all-halaqoh');
            const checkboxesHalaqoh = popupHalaqoh.querySelectorAll('.halaqoh-checkbox');
            const btnTextHalaqoh = btnHalaqoh.querySelector('span');

            function updateHalaqohText() {
                const count = Array.from(checkboxesHalaqoh).filter(cb => cb.checked).length;
                if (count === 0) btnTextHalaqoh.textContent = 'Pilih Halaqoh';
                else if (count === checkboxesHalaqoh.length) btnTextHalaqoh.textContent = 'Semua Halaqoh';
                else btnTextHalaqoh.textContent = `${count} Terpilih`;
            }

            btnHalaqoh.addEventListener('click', (e) => { e.stopPropagation(); popupHalaqoh.classList.toggle('hidden'); });
            document.addEventListener('click', (e) => { if (!popupHalaqoh.contains(e.target) && !btnHalaqoh.contains(e.target)) popupHalaqoh.classList.add('hidden'); });

            selectAllHalaqoh.addEventListener('change', () => {
                checkboxesHalaqoh.forEach(cb => cb.checked = selectAllHalaqoh.checked);
                updateHalaqohText(); loadPesertaData(1);
            });

            checkboxesHalaqoh.forEach(cb => {
                cb.addEventListener('change', () => {
                    const checkedCount = Array.from(checkboxesHalaqoh).filter(c => c.checked).length;
                    selectAllHalaqoh.checked = (checkedCount === checkboxesHalaqoh.length);
                    selectAllHalaqoh.indeterminate = (checkedCount > 0 && checkedCount < checkboxesHalaqoh.length);
                    updateHalaqohText(); loadPesertaData(1);
                });
            });

            clearSelectionBtn.addEventListener('click', () => {
                selectedPesertaSet.clear();
                updateSelectedCounter();
                document.querySelectorAll('.peserta-checkbox').forEach(cb => cb.checked = false);
                updateSelectAllPageCheckbox();
            });

            selectAllCurrentPageBtn.addEventListener('click', () => {
                const visibleCheckboxes = document.querySelectorAll('.peserta-checkbox:not(:disabled)');
                const allChecked = Array.from(visibleCheckboxes).every(cb => cb.checked);
                visibleCheckboxes.forEach(cb => {
                    cb.checked = !allChecked;
                    if(!allChecked) selectedPesertaSet.add(cb.value);
                    else selectedPesertaSet.delete(cb.value);
                });
                updateSelectedCounter();
                updateSelectAllPageCheckbox();
            });

            selectAllCheckbox.addEventListener('change', function() {
                const visibleCheckboxes = document.querySelectorAll('.peserta-checkbox:not(:disabled)');
                visibleCheckboxes.forEach(cb => {
                    cb.checked = this.checked;
                    if(this.checked) selectedPesertaSet.add(cb.value);
                    else selectedPesertaSet.delete(cb.value);
                });
                updateSelectedCounter();
                updateSelectAllPageCheckbox();
            });

            const manageModal = document.getElementById('manageTemplatesModal');
            const templateModal = document.getElementById('templateModal');
            
            function showModal(modal) { modal.classList.remove('hidden'); modal.classList.add('flex'); }
            function hideModal(modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }

            document.getElementById('manageTemplatesBtn').addEventListener('click', () => showModal(manageModal));
            document.getElementById('closeManageModalBtn').addEventListener('click', () => hideModal(manageModal));
            
            const templateModalTitle = document.getElementById('templateModalTitle');
            const templateIdInput = document.getElementById('template_id');
            const templateCategoryInput = document.getElementById('template_category');
            const templateTitleInput = document.getElementById('template_title');
            const templateContentInput = document.getElementById('template_content');
            
            document.getElementById('addTemplateBtn').addEventListener('click', () => {
                templateModalTitle.textContent = 'Tambah Template Baru';
                templateIdInput.value = ''; templateCategoryInput.value = ''; templateTitleInput.value = ''; templateContentInput.value = '';
                hideModal(manageModal); showModal(templateModal);
            });
            
            document.querySelectorAll('.edit-template-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    templateModalTitle.textContent = 'Ubah Template';
                    templateIdInput.value = this.dataset.id; templateCategoryInput.value = this.dataset.category;
                    templateTitleInput.value = this.dataset.title; templateContentInput.value = this.dataset.content;
                    hideModal(manageModal); showModal(templateModal);
                });
            });
            
            document.getElementById('closeTemplateModalBtn').addEventListener('click', () => hideModal(templateModal));
            document.querySelectorAll('.modal-backdrop').forEach(b => { b.addEventListener('click', () => { hideModal(manageModal); hideModal(templateModal); }); });
            
            document.querySelectorAll('.template-btn, .btn-preset-template').forEach(button => {
                button.addEventListener('click', function() {
                    document.getElementById('template-textarea').value = this.dataset.content || this.dataset.template;
                });
            });

            // ============================================
            // SUBMIT AJAX REAL-TIME DENGAN NOTIFIKASI CANTIK
            // ============================================
            const btnKirimReminder = document.getElementById('btn-kirim-reminder');
            btnKirimReminder.addEventListener('click', async function() {
                const templatePesan = document.getElementById('template-textarea').value.trim();
                if (!templatePesan) { 
                    Swal.fire({ icon: 'warning', title: 'Oops...', text: 'Template pesan tidak boleh kosong!', confirmButtonColor: '#f59e0b' });
                    return; 
                }
                if (selectedPesertaSet.size === 0) { 
                    Swal.fire({ icon: 'warning', title: 'Oops...', text: 'Pilih minimal satu peserta!', confirmButtonColor: '#f59e0b' });
                    return; 
                }

                document.getElementById('loader').classList.remove('hidden');
                btnKirimReminder.classList.add('btn-loading');

                const formData = new FormData();
                formData.append('ajax_add_to_queue', '1');
                formData.append('template_pesan', templatePesan);
                formData.append('selected_peserta', JSON.stringify(Array.from(selectedPesertaSet)));

                try {
                    let res = await fetch(window.location.href, { method: 'POST', body: formData });
                    let json = await res.json();
                    if(json.status === 'success') {
                        // NOTIFIKASI CANTIK SAAT BERHASIL
                        Swal.fire({
                            title: 'Berhasil Terkirim! 🎉',
                            text: json.msg,
                            icon: 'success',
                            confirmButtonColor: '#10b981',
                            timer: 3000,
                            timerProgressBar: true,
                            showConfirmButton: false
                        });
                        selectedPesertaSet.clear();
                        updateSelectedCounter();
                        loadPesertaData(currentPage);
                        fetchLiveQueue();
                    } else { 
                        Swal.fire({
                            title: 'Gagal Mengirim',
                            text: json.msg || "Terjadi kesalahan yang tidak diketahui.",
                            icon: 'error',
                            confirmButtonColor: '#ef4444'
                        });
                    }
                } catch(e) { 
                    Swal.fire({
                        title: 'Kesalahan Koneksi',
                        text: "Gagal terhubung ke server saat memproses pengiriman.",
                        icon: 'error',
                        confirmButtonColor: '#ef4444'
                    });
                } finally {
                    // Pastikan loader mati dan tombol aktif kembali meski ada error
                    document.getElementById('loader').classList.add('hidden');
                    btnKirimReminder.classList.remove('btn-loading');
                }
            });

            // ============================================
            // TOMBOL POPUP KE HALAMAN CEK_LANJUT.PHP
            // ============================================
            document.getElementById('btn-cek-lanjut').addEventListener('click', function() {
                Swal.fire({
                    title: 'Konfirmasi Arahkan',
                    text: 'Anda akan diarahkan ke halaman cek_lanjut.php',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#4f46e5',
                    cancelButtonColor: '#94a3b8',
                    confirmButtonText: 'Ya, Lanjutkan',
                    cancelButtonText: 'Batal',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'cek_lanjut.php';
                    }
                });
            });

            // ============================================
            // LIVE LOG POLLING
            // ============================================
            function fetchLiveQueue() {
                fetch(window.location.href.split('?')[0] + '?ajax_polling=1')
                    .then(res => res.json())
                    .then(data => {
                        const container = document.getElementById('live-log-container');
                        container.innerHTML = '';
                        if(data.logs.length === 0) {
                            container.innerHTML = '<div class="p-4 text-center text-xs text-slate-400">Belum ada riwayat pengiriman.</div>';
                        } else {
                            data.logs.forEach(log => {
                                let icon = '<i class="fas fa-check-circle text-emerald-500"></i>';
                                let bgClass = 'bg-emerald-50';
                                let dateStr = new Date(log.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                                container.innerHTML += `
                                    <div class="relative flex items-center justify-between p-3 rounded-xl border border-slate-100 ${bgClass} transition-all mb-2 group">
                                        <div class="flex items-center space-x-3 overflow-hidden">
                                            <div class="text-lg">${icon}</div>
                                            <div>
                                                <p class="font-bold text-slate-700 text-xs truncate max-w-[150px]">${log.nama || 'Tanpa Nama'}</p>
                                                <p class="text-[10px] text-slate-500 font-medium">${log.nowa} &bull; ${dateStr}</p>
                                            </div>
                                        </div>
                                        <form method="POST" action="" class="opacity-0 group-hover:opacity-100 transition-opacity">
                                            <input type="hidden" name="delete_log_id" value="${log.id}">
                                            <button type="submit" class="text-slate-400 hover:text-red-500 p-1 rounded-full hover:bg-white/50"><i class="fas fa-trash text-[10px]"></i></button>
                                        </form>
                                    </div>`;
                            });
                        }
                    });
            }
            fetchLiveQueue(); 
            setInterval(fetchLiveQueue, 10000); 

            loadPesertaData(1);
        }); 
    </script>
    <script>
        if (window.self !== window.top) {
            document.body.style.backgroundColor = "transparent";
            document.querySelectorAll('.bento-card').forEach(card => card.style.background = 'rgba(255, 255, 255, 0.95)');
        }
    </script>
</body>
</html>