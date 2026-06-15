<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// 1. PENGATURAN LOG ERROR
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// 2. PROTEKSI FILE AUTHENTIKASI
if (!file_exists('auth_checkwa.php')) {
    error_log("CRITICAL ERROR: File auth_checkwa.php hilang.");
    die("Sistem dihentikan: File otentikasi tidak ditemukan.");
}
require_once 'auth_checkwa.php';

// 3. PROTEKSI KONEKSI DATABASE
try {
    if (!file_exists('config.php')) throw new Exception("File config.php tidak ditemukan di server.");
    require_once 'config.php';
    if (!isset($conn)) throw new Exception("Variabel \$conn tidak terdeteksi di dalam file config.php.");
    if ($conn->connect_error) throw new Exception("Koneksi Database Gagal: " . $conn->connect_error);
} catch (Throwable $e) { 
    error_log("DATABASE ERROR: " . $e->getMessage());
    die("<div style='padding:30px; text-align:center; color:#333;'><h2 style='color:#e11d48;'>Sistem Mengalami Gangguan ⚠️</h2><p>Mohon maaf, sistem tidak dapat terhubung ke database. Cek file php_errors.log</p></div>");
}

if (!isset($apiUrl) && defined('ONESENDER_API_URL')) $apiUrl = ONESENDER_API_URL;
if (!isset($apiToken) && defined('ONESENDER_API_TOKEN')) $apiToken = ONESENDER_API_TOKEN;

if (!function_exists('curl_init')) die("Sistem Error: Ekstensi PHP 'cURL' belum diaktifkan.");

// AUTO-UPDATE SCHEMA DATABASE
mysqli_set_charset($conn, "utf8mb4");
$cols = [];
$res = $conn->query("SHOW COLUMNS FROM log_wa");
if ($res) while($r = $res->fetch_assoc()) $cols[] = $r['Field'];

if(!in_array('last_followup_at', $cols)) @$conn->query("ALTER TABLE log_wa ADD last_followup_at DATETIME NULL");
if(!in_array('is_form_sent', $cols)) @$conn->query("ALTER TABLE log_wa ADD is_form_sent TINYINT(1) DEFAULT 0");
if(!in_array('last_template_name', $cols)) @$conn->query("ALTER TABLE log_wa ADD last_template_name VARCHAR(150) NULL");

if (!isset($_SESSION['followed_up_today'])) $_SESSION['followed_up_today'] = [];
$notification = $notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification']; 
    $notificationType = $_SESSION['notificationType'];
    unset($_SESSION['notification'], $_SESSION['notificationType']);
}

// ==================================================================
// FUNGSI API & LOGIKA UTAMA
// ==================================================================
function kirimPesan($recipient, $message, $apiUrl, $apiToken) {
    if (empty($recipient) || empty($message)) return ['status' => 'GAGAL', 'msg' => 'Parameter kosong'];
    $cleanNumber = preg_replace('/\D/', '', $recipient);
    if (strlen($cleanNumber) > 0 && $cleanNumber[0] === '0') $cleanNumber = '62' . substr($cleanNumber, 1);
    
    $data = ["recipient_type" => "individual", "to" => $cleanNumber, "type" => "text", "text" => ["body" => $message]];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) return ['status' => 'GAGAL', 'msg' => "cURL Error: $curlError"];
    if ($httpCode === 200) return ['status' => 'TERKIRIM', 'msg' => 'Sukses API'];
    return ['status' => 'GAGAL', 'msg' => "API Code: $httpCode"];
}

function classifyMessage($message) {
    if (empty($message)) return 'Data CSV/Manual';
    $m = strtolower($message);
    
    if (strpos($m, 'bingung mau pilih program') !== false || strpos($m, 'saya bingung') !== false) return 'Bingung';
    if (strpos($m, 'ziyadah pemula') !== false) return 'Ziyadah Pemula';
    if (strpos($m, 'ziyadah lanjutan') !== false) return 'Ziyadah Lanjutan';
    if (strpos($m, "muroja'ah") !== false || strpos($m, 'murojaah') !== false) return "Muroja'ah";
    if (strpos($m, 'tahfidz cilik') !== false) return 'Tahfidz Cilik';
    if (strpos($m, 'intensif') !== false) return 'Mode Intensif';
    if (strpos($m, 'normal') !== false) return 'Mode Normal';
    if (strpos($m, 'kak, mau') !== false || strpos($m, 'mau ikut') !== false || strpos($m, 'minat') !== false) return 'Ekspresi Minat';
    if (strpos($m, 'input manual') !== false || strpos($m, 'csv') !== false) return 'Data CSV/Manual';
    return 'Lainnya'; 
}

function getDisqualifiedNumbers($conn) {
    $disq = [];
    if (!$conn) return $disq;
    $tables = ['peserta', 'calon_peserta', 'pengampu'];
    foreach ($tables as $tbl) {
        $res = $conn->query("SHOW TABLES LIKE '$tbl'");
        if ($res && $res->num_rows > 0) {
            $q = $conn->query("SELECT nowa FROM $tbl WHERE nowa IS NOT NULL AND nowa != ''");
            if ($q) while($r = $q->fetch_assoc()) { 
                $n = preg_replace('/\D/', '', $r['nowa']); 
                if(strpos($n,'0')===0) $n = '62'.substr($n,1); 
                if($n) $disq[$n] = true; 
            }
        }
    }
    $q2 = $conn->query("SELECT nowa FROM log_wa WHERE is_form_sent = 1 OR message LIKE '%penempatan halaqoh%'");
    if ($q2) while($r = $q2->fetch_assoc()) {
        $n = preg_replace('/\D/', '', $r['nowa']); 
        if(strpos($n,'0')===0) $n = '62'.substr($n,1); 
        if($n) $disq[$n] = true;
    }
    return $disq;
}

function getBlockedNumbers($conn) {
    $blk = [];
    if(!$conn) return $blk;
    $q = $conn->query("SELECT nowa FROM blocked_peserta");
    if ($q) while($r = $q->fetch_assoc()) {
        $n = preg_replace('/\D/', '', $r['nowa']); 
        if(strpos($n,'0')===0) $n = '62'.substr($n,1); 
        if($n) $blk[$n] = true;
    }
    return $blk;
}

function isAlreadyInOrganic($conn, $number) {
    $n = preg_replace('/\D/', '', $number);
    if(strpos($n,'0')===0) $n = '62'.substr($n,1);
    $check = $conn->prepare("SELECT id FROM log_wa WHERE (nowa = ? OR nowa = ?) AND message != 'Data CSV/Manual' AND message != '' LIMIT 1");
    $check->bind_param("ss", $number, $n);
    $check->execute();
    return $check->get_result()->num_rows > 0;
}

// --- 1. HANDLE AJAX KIRIM ---
if (isset($_POST['ajax_send'])) {
    header('Content-Type: application/json');
    $cid = $_POST['contact_id'] ?? '';
    $tmplId = $_POST['template_id'] ?? '';

    $stT = $conn->prepare("SELECT name, content FROM poloap_templates WHERE id = ?");
    $stT->bind_param("i", $tmplId); $stT->execute(); 
    $tmplData = $stT->get_result()->fetch_assoc();
    $msgTmpl = $tmplData['content'] ?? '';
    $tmplName = $tmplData['name'] ?? '';

    if($msgTmpl && $cid) {
        $isForm = (stripos($msgTmpl, 'penempatan halaqoh') !== false) ? 1 : 0;
        
        $stN = $conn->prepare("SELECT nama, message FROM log_wa WHERE nowa = ? LIMIT 1");
        $stN->bind_param("s", $cid); $stN->execute(); 
        $dbRow = $stN->get_result()->fetch_assoc();
        
        $nama = trim($dbRow['nama'] ?? 'Kak');
        $msgText = $dbRow['message'] ?? '';
        
        if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $msgText, $m)) {
            $nama = trim($m[1]);
        } elseif (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $msgText, $m)) {
            $nama = trim($m[1]);
        }
        if (empty($nama) || strtolower($nama) == 'kak') $nama = 'Kak';

        $pesan = str_ireplace(['[nama]', '[NAMA]', '{nama}', '{NAMA}'], $nama, $msgTmpl);
        $pesan = str_replace('  ', ' ', $pesan);
        
        $hasilAPI = kirimPesan($cid, $pesan, $apiUrl, $apiToken);
        
        if($hasilAPI['status'] === 'TERKIRIM') {
            $tmplNameSafe = $conn->real_escape_string($tmplName);
            $conn->query("UPDATE log_wa SET last_followup_at = NOW(), is_form_sent = GREATEST(is_form_sent, $isForm), last_template_name = '$tmplNameSafe' WHERE nowa = '$cid'");
            if (!in_array($cid, $_SESSION['followed_up_today'])) $_SESSION['followed_up_today'][] = $cid;
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => $hasilAPI['msg']]);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Template atau kontak tidak valid']);
    }
    exit;
}

$disqualified = getDisqualifiedNumbers($conn);
$blocked = getBlockedNumbers($conn);

// --- 2. FUNGSI POST BIASA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_send'])) {
    if (isset($_POST['tambah_prospek'])) {
        $n = preg_replace('/\D/', '', $_POST['nowa_baru']); if (strpos($n, '0') === 0) $n = '62' . substr($n, 1);
        if (isset($disqualified[$n]) || isset($blocked[$n])) {
            $_SESSION['notification'] = "❌ Gagal: Nomor sudah terdaftar / diblokir!"; $_SESSION['notificationType'] = 'error';
        } elseif (isAlreadyInOrganic($conn, $_POST['nowa_baru'])) {
            $_SESSION['notification'] = "⚠️ Nomor sudah ada di Daftar Organik."; $_SESSION['notificationType'] = 'warning';
        } else {
            $conn->query("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES ('$n', '{$_POST['nama_baru']}', 'Data CSV/Manual', NOW())");
            $_SESSION['notification'] = "✅ Prospek manual ditambahkan!"; $_SESSION['notificationType'] = 'success';
        }
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['upload_csv']) && isset($_FILES['file_csv'])) {
        set_time_limit(0); ini_set('memory_limit', '256M'); 
        if (($handle = fopen($_FILES['file_csv']['tmp_name'], "r")) !== FALSE) {
            $suksesUpload = 0; $ditolak = 0; $sudahOrganik = 0;
            $stmt = $conn->prepare("INSERT IGNORE INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, 'Data CSV/Manual', NOW())");
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (strtolower($data[0]) === 'nama' || empty($data[0])) continue;
                $n = preg_replace('/\D/', '', $data[1]); if (strpos($n, '0') === 0) $n = '62' . substr($n, 1);
                
                if (isset($disqualified[$n]) || isset($blocked[$n])) { $ditolak++; continue; }
                if (isAlreadyInOrganic($conn, $n)) { $sudahOrganik++; continue; }

                $stmt->bind_param("ss", $n, $data[0]); if($stmt->execute()) $suksesUpload++;
            }
            fclose($handle); 
            $msg = "✅ $suksesUpload Data CSV di-import.";
            if($sudahOrganik > 0) $msg .= " $sudahOrganik ditolak karena sudah masuk via Organik.";
            $_SESSION['notification'] = $msg; $_SESSION['notificationType'] = 'success';
        }
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['hapus_semua_manual'])) {
        $conn->query("DELETE FROM log_wa WHERE message = 'Data CSV/Manual'");
        $_SESSION['notification'] = "✅ Seluruh daftar antrean manual dan CSV berhasil dihapus!";
        $_SESSION['notificationType'] = 'success';
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['delete_prospect'])) { $conn->query("DELETE FROM log_wa WHERE nowa = '{$_POST['contact_id']}'"); header("Location: pesan.php"); exit; }
    if (isset($_POST['clear_fu'])) { $_SESSION['followed_up_today'] = []; header("Location: pesan.php"); exit; }
}

// ==================================================================
// DATA FETCHING & FILTER
// ==================================================================
$jsTemplates = []; $pesanTemplates = [];
$res = $conn->query("SELECT id, name, content FROM poloap_templates ORDER BY name");
while($r = $res->fetch_assoc()) { $pesanTemplates[] = $r; $jsTemplates[$r['id']] = $r['content']; }

$search = $_GET['search'] ?? ''; $f_start = $_GET['from'] ?? ''; $f_end = $_GET['to'] ?? ''; $f_minat = $_GET['minat'] ?? ''; 

$targetOrganik = []; $targetManual = []; $organikSudahDichat = []; $manualSudahDichat = [];
$processed = []; $statistikMinat = []; $idsToDelete = [];

$sql = "SELECT * FROM log_wa ORDER BY CASE WHEN (message = 'Data CSV/Manual' OR message = '') THEN 1 ELSE 0 END ASC, created_at DESC";
$res = $conn->query($sql);

while ($row = $res->fetch_assoc()) {
    $raw_nowa = $row['nowa'];
    if (strpos($raw_nowa, '@g') !== false || strpos($raw_nowa, '-') !== false) continue;

    $n_log = preg_replace('/\D/', '', $raw_nowa);
    if(strpos($n_log,'0')===0) $n_log = '62'.substr($n_log,1);
    if(empty($n_log)) continue;

    if(isset($processed[$n_log])) {
        if ($row['message'] === 'Data CSV/Manual') $idsToDelete[] = $row['id']; 
        continue;
    }
    $processed[$n_log] = true;

    if (isset($disqualified[$n_log]) || isset($blocked[$n_log])) continue;
    if ($f_start && date('Y-m-d', strtotime($row['created_at'])) < $f_start) continue;
    if ($f_end && date('Y-m-d', strtotime($row['created_at'])) > $f_end) continue;
    
    if ($search) {
        $s = strtolower($search);
        if (strpos(strtolower($row['nama']), $s) === false && strpos(strtolower($row['nowa']), $s) === false) continue;
    }

    $klas = classifyMessage($row['message']);
    if ($klas === 'Lainnya') continue;

    if (!isset($statistikMinat[$klas])) $statistikMinat[$klas] = 0;
    $statistikMinat[$klas]++;
    if ($f_minat && $klas !== $f_minat) continue;

    $is_fu_today = (in_array($raw_nowa, $_SESSION['followed_up_today']) || in_array($n_log, $_SESSION['followed_up_today']));

    $namaDb = trim($row['nama']);
    $msgRaw = $row['message'] ?? '';
    $extractedName = ''; $extractedGender = '-';

    if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $msgRaw, $m)) $extractedName = trim($m[1]);
    elseif (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $msgRaw, $m)) $extractedName = trim($m[1]);

    if (preg_match('/\((ikhwan|akhwat|laki-laki|perempuan|laki|pr)\)/i', $msgRaw, $m)) $extractedGender = ucfirst(strtolower(trim($m[1])));
    $finalName = !empty($extractedName) ? $extractedName : (!empty($namaDb) ? $namaDb : 'Hamba Allah');

    $data = [
        'id' => $row['id'],
        'nowa' => $row['nowa'], 
        'clean_wa' => $n_log, 
        'nama' => $finalName, 
        'gender' => $extractedGender,
        'klas' => $klas,
        'msgRaw' => $msgRaw,
        'fu_text' => $row['last_followup_at'] ? date('d/m H:i', strtotime($row['last_followup_at'])) : 'Belum Pernah',
        'fu_tmpl' => $row['last_template_name'] ? $row['last_template_name'] : 'Baru'
    ];

    if ($klas === 'Data CSV/Manual') {
        if ($is_fu_today) $manualSudahDichat[] = $data; else $targetManual[] = $data;
    } else {
        if ($is_fu_today) $organikSudahDichat[] = $data; else $targetOrganik[] = $data;
    }
}

if (!empty($idsToDelete)) $conn->query("DELETE FROM log_wa WHERE id IN (" . implode(',', $idsToDelete) . ")");

$page_o = isset($_GET['page_o']) ? max(1, (int)$_GET['page_o']) : 1;
$page_m = isset($_GET['page_m']) ? max(1, (int)$_GET['page_m']) : 1;
$per_page = 10;
$total_o = count($targetOrganik); $total_m = count($targetManual);
$pages_o = max(1, ceil($total_o / $per_page)); $pages_m = max(1, ceil($total_m / $per_page));

$targetOrganik_paged = array_slice($targetOrganik, ($page_o - 1) * $per_page, $per_page);
$targetManual_paged = array_slice($targetManual, ($page_m - 1) * $per_page, $per_page);

function buildPageUrl($pageType, $pageNum) {
    $params = $_GET; $params[$pageType] = $pageNum;
    return '?' . http_build_query($params);
}

if (isset($_GET['export_csv_action'])) {
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename=Prospek_Filtered.csv');
    $output = fopen('php://output', 'w'); 
    fputcsv($output, ['Nama', 'Gender', 'Nomor WA', 'Minat', 'Tgl Follow-up Terakhir', 'Template Terakhir']);
    $allExportData = array_merge($targetOrganik, $targetManual);
    foreach($allExportData as $r) fputcsv($output, [$r['nama'], $r['gender'], $r['nowa'], $r['klas'], $r['fu_text'], $r['fu_tmpl']]); 
    fclose($output); exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Minimalist | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #334155; }
        .custom-scroll { max-height: 400px; overflow-y: auto; scrollbar-width: thin; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
        
        .minimal-card { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 20px -5px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; transition: all 0.3s ease; }
        .btn-clean { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; transition: all 0.2s ease; }
        .btn-clean:hover { background: #f8fafc; transform: translateY(-1px); border-color: #cbd5e1; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeIn { animation: fadeIn 0.3s ease-out forwards; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        
        .chat-bg { background-color: #f0f2f5; background-image: radial-gradient(#cbd5e1 1px, transparent 0); background-size: 20px 20px; }
        .bubble-left { background: #ffffff; border-radius: 0 16px 16px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .bubble-right { background: #dcf8c6; border-radius: 16px 0 16px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        
        #waPreview { display: none; position: fixed; bottom: 20px; right: 20px; width: 320px; z-index: 100; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; }
        .hover-row:hover { background-color: #f8fafc; transform: translateY(-1px); box-shadow: 0 2px 10px -2px rgba(0,0,0,0.02); z-index: 10; position: relative; }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-[1500px] mx-auto relative">
    
    <header class="bg-white/80 backdrop-blur-md border border-slate-100 py-5 px-6 rounded-2xl mb-6 flex flex-wrap justify-between items-center gap-4 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="bg-blue-50 w-10 h-10 rounded-full flex items-center justify-center text-blue-500">
                <i class="fas fa-paper-plane"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold tracking-tight text-slate-800">Follow-Up</h1>
                <p class="text-[11px] text-slate-500 font-medium mt-0.5">Sistem Manajemen Antrean Pesan</p>
            </div>
        </div>
        
        <div class="flex flex-wrap gap-2.5">
            <a href="grafik.php" class="btn-clean px-4 py-2 text-xs font-bold flex items-center text-slate-600"><i class="fas fa-chart-line mr-2"></i>Statistik</a>
            <button onclick="openModal('modalTambah')" class="btn-clean px-4 py-2 text-xs font-bold flex items-center text-slate-600"><i class="fas fa-plus mr-2"></i>Manual</button>
            <button onclick="openModal('modalCSV')" class="px-4 py-2 text-xs font-bold flex items-center bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-colors shadow-sm"><i class="fas fa-file-csv mr-2"></i>Import CSV</button>
            <a href="?export_csv_action=1&search=<?=urlencode($search)?>&from=<?=urlencode($f_start)?>&to=<?=urlencode($f_end)?>&minat=<?=urlencode($f_minat)?>" class="px-4 py-2 text-xs font-bold flex items-center bg-slate-800 text-white rounded-xl hover:bg-slate-900 transition-colors shadow-sm"><i class="fas fa-download mr-2"></i>Export</a>
        </div>
    </header>

    <div id="loader" class="hidden animate-fadeIn mb-6 bg-white minimal-card overflow-hidden relative">
        <div class="absolute top-0 left-0 right-0 h-1 bg-blue-500"></div>
        <div class="p-5 flex items-center gap-4">
            <i class="fas fa-circle-notch fa-spin text-2xl text-blue-500"></i>
            <div class="flex-1">
                <div class="flex justify-between items-end mb-2">
                    <h3 class="font-bold text-slate-700 text-sm">Mengirim Pesan...</h3>
                    <p id="progressText" class="text-sm font-bold text-blue-600">0%</p>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden"><div id="progressBar" class="bg-blue-500 h-full rounded-full transition-all duration-300 w-0"></div></div>
                <p id="progressStatus" class="text-[10px] text-slate-400 mt-2">Mempersiapkan data...</p>
            </div>
        </div>
    </div>

    <?php if ($notification): ?>
    <div class="mb-6 p-4 rounded-xl border <?= $notificationType === 'success' ? 'bg-emerald-50 border-emerald-100 text-emerald-700' : ($notificationType === 'warning' ? 'bg-amber-50 border-amber-100 text-amber-700' : 'bg-rose-50 border-rose-100 text-rose-700') ?> flex items-center gap-3 text-sm font-medium shadow-sm animate-fadeIn">
        <i class="fas <?= $notificationType === 'success' ? 'fa-check-circle' : ($notificationType === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle') ?> text-lg"></i> <?= $notification ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($statistikMinat)): ?>
    <div class="mb-6 flex gap-3 overflow-x-auto pb-2 custom-scroll">
        <?php if($f_minat): ?>
        <a href="pesan.php" class="bg-rose-50 text-rose-600 border border-rose-100 rounded-xl flex-shrink-0 px-4 py-3 text-xs font-bold hover:bg-rose-100 transition-colors flex items-center"><i class="fas fa-times mr-2"></i>Hapus Filter</a>
        <?php endif; ?>
        <?php foreach($statistikMinat as $namaMinat => $jumlah): 
            $isActive = ($f_minat === $namaMinat) ? 'bg-blue-600 text-white border-blue-600 shadow-md transform -translate-y-0.5' : 'bg-white text-slate-600 border-slate-200 hover:border-blue-300 hover:bg-blue-50';
        ?>
        <a href="?minat=<?= urlencode($namaMinat) ?>" class="minimal-card flex-shrink-0 px-5 py-3 transition-all cursor-pointer border <?= $isActive ?>">
            <div class="text-[10px] font-bold uppercase tracking-wider opacity-80 mb-1"><?= htmlspecialchars($namaMinat) ?></div>
            <div class="text-xl font-black"><?= $jumlah ?> <span class="text-[10px] font-medium opacity-70">Leads</span></div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <div class="xl:col-span-3 space-y-6">
            
            <div class="minimal-card p-5">
                <h3 class="font-bold text-slate-700 mb-4 text-xs uppercase tracking-wider"><i class="fas fa-search text-slate-400 mr-2"></i> Pencarian</h3>
                <form method="GET" class="space-y-3">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / No WhatsApp..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg outline-none focus:border-blue-400 text-xs transition-colors">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="w-full text-[10px] p-2 bg-slate-50 border border-slate-200 rounded-lg outline-none">
                        <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="w-full text-[10px] p-2 bg-slate-50 border border-slate-200 rounded-lg outline-none">
                    </div>
                    <button type="submit" class="w-full bg-slate-800 text-white py-2 rounded-lg font-bold text-xs hover:bg-slate-700 transition-colors">Cari Data</button>
                    <a href="pesan.php" class="block text-center text-[10px] text-slate-400 hover:text-slate-600 mt-2">Reset Form</a>
                </form>
            </div>
            
            <div class="minimal-card p-5 border-blue-50">
                <h3 class="font-bold text-slate-700 mb-4 text-xs uppercase tracking-wider"><i class="fas fa-cog text-slate-400 mr-2"></i> Manajemen</h3>
                <div class="space-y-2">
                    <form method="POST" onsubmit="return confirm('Kosongkan histori follow-up hari ini?')"><input type="hidden" name="clear_fu" value="1"><button type="submit" class="w-full bg-white hover:bg-slate-50 text-slate-600 py-2 rounded-lg text-xs font-semibold border border-slate-200 transition-colors"><i class="fas fa-sync-alt mr-2"></i>Reset Sesi Harian</button></form>
                    <a href="manage_templates.php" class="block text-center w-full bg-white hover:bg-slate-50 text-slate-600 py-2 rounded-lg text-xs font-semibold border border-slate-200 transition-colors"><i class="fas fa-comment-dots mr-2"></i>Kelola Template</a>
                </div>
            </div>

            <?php if(!empty($organikSudahDichat) || !empty($manualSudahDichat)): ?>
            <div class="space-y-4">
                <?php if(!empty($organikSudahDichat)): ?>
                <div class="minimal-card overflow-hidden border-emerald-100">
                    <div class="p-3 bg-emerald-50/50 border-b border-emerald-100 flex justify-between items-center">
                        <h3 class="font-bold text-[10px] text-emerald-700 uppercase"><i class="fas fa-check mr-1.5"></i> Selesai Organik</h3>
                        <span class="text-[9px] font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full"><?= count($organikSudahDichat) ?></span>
                    </div>
                    <div class="max-h-[250px] overflow-y-auto custom-scroll">
                        <div class="divide-y divide-slate-100">
                            <?php foreach($organikSudahDichat as $r): ?>
                            <div class="p-3 hover:bg-slate-50 transition-colors">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <div class="font-bold text-[11px] text-slate-700 cursor-pointer hover:text-blue-600" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>"><?= $r['nama'] ?></div>
                                        <div class="text-[9px] text-slate-400"><?= $r['nowa'] ?></div>
                                    </div>
                                </div>
                                <form class="flex gap-1.5" onsubmit="submitSingleAjax(event, this)">
                                    <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                    <select name="template_id" class="bg-white border border-slate-200 rounded text-[9px] p-1 flex-1 outline-none focus:border-emerald-300" required onchange="showWA(this)">
                                        <option value="">Kirim Lagi...</option>
                                        <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="bg-slate-100 text-slate-500 px-2 rounded hover:bg-emerald-500 hover:text-white transition-colors"><i class="fas fa-paper-plane text-[9px]"></i></button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($manualSudahDichat)): ?>
                <div class="minimal-card overflow-hidden border-amber-100">
                    <div class="p-3 bg-amber-50/50 border-b border-amber-100 flex justify-between items-center">
                        <h3 class="font-bold text-[10px] text-amber-700 uppercase"><i class="fas fa-check-double mr-1.5"></i> Selesai Manual</h3>
                        <span class="text-[9px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full"><?= count($manualSudahDichat) ?></span>
                    </div>
                    <div class="max-h-[250px] overflow-y-auto custom-scroll">
                        <div class="divide-y divide-slate-100">
                            <?php foreach($manualSudahDichat as $r): ?>
                            <div class="p-3 hover:bg-slate-50 transition-colors">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <div class="font-bold text-[11px] text-slate-700 cursor-pointer hover:text-blue-600" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>"><?= $r['nama'] ?></div>
                                        <div class="text-[9px] text-slate-400"><?= $r['nowa'] ?></div>
                                    </div>
                                </div>
                                <form class="flex gap-1.5" onsubmit="submitSingleAjax(event, this)">
                                    <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                    <select name="template_id" class="bg-white border border-slate-200 rounded text-[9px] p-1 flex-1 outline-none focus:border-amber-300" required onchange="showWA(this)">
                                        <option value="">Kirim Lagi...</option>
                                        <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="bg-slate-100 text-slate-500 px-2 rounded hover:bg-amber-500 hover:text-white transition-colors"><i class="fas fa-paper-plane text-[9px]"></i></button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="xl:col-span-9 space-y-6">
            
            <div class="minimal-card p-5 flex flex-col md:flex-row gap-4 justify-between items-center bg-blue-50/30">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0"><i class="fas fa-broadcast-tower text-sm"></i></div>
                    <div>
                        <h3 class="font-bold text-sm text-slate-800">Broadcast Pintar</h3>
                        <p class="text-[10px] text-slate-500"><span id="countCheck" class="font-bold text-blue-600">0</span> kontak dipilih</p>
                    </div>
                </div>
                <form id="formMassal" class="w-full md:w-auto flex gap-2">
                    <select name="template_id_multi" onchange="showWA(this)" class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:border-blue-400" required>
                        <option value="">Pilih Template Broadcast...</option>
                        <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" onclick="submitMassAjax(event)" class="bg-slate-800 hover:bg-slate-900 px-4 py-2 rounded-lg font-bold text-xs text-white transition-colors">Kirim Massal</button>
                </form>
            </div>

            <div class="minimal-card overflow-hidden">
                <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-white sticky top-0 z-10">
                    <h3 class="font-bold text-sm text-slate-800 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Daftar Customer Baru (Organik)
                    </h3>
                    <div class="flex items-center gap-3">
                        <span class="text-[10px] bg-slate-100 text-slate-600 px-2 py-1 rounded font-medium">Total: <?= $total_o ?></span>
                        <label class="text-[11px] font-bold text-slate-600 cursor-pointer flex items-center"><input type="checkbox" id="checkAllOrganik" class="mr-1.5 w-3.5 h-3.5 accent-blue-600">Pilih Semua</label>
                    </div>
                </div>
                <div class="custom-scroll bg-white">
                    <table class="w-full text-left text-xs">
                        <thead class="text-slate-400 text-[10px] uppercase tracking-wider border-b border-slate-100 bg-slate-50/50">
                            <tr><th class="p-3 w-10 text-center">#</th><th class="p-3">Prospek</th><th class="p-3">Status Terakhir</th><th class="p-3 text-right">Aksi Cepat</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($targetOrganik_paged as $r): ?>
                            <tr class="hover-row transition-all">
                                <td class="p-3 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-organik w-3.5 h-3.5 accent-blue-600 cursor-pointer"></td>
                                <td class="p-3">
                                    <div class="font-bold text-slate-800 text-sm mb-0.5 cursor-pointer hover:text-blue-600 flex items-center gap-2" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                        <?= $r['nama'] ?>
                                        <?php if($r['gender'] !== '-'): ?>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded-md font-medium <?= strtolower($r['gender']) == 'ikhwan' || strtolower($r['gender']) == 'laki-laki' ? 'bg-blue-50 text-blue-500' : 'bg-pink-50 text-pink-500' ?>"><?= $r['gender'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] text-slate-500 mb-1 flex items-center gap-1">
                                        <?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 hover:scale-110 transition-transform"><i class="fab fa-whatsapp"></i></a>
                                    </div>
                                    <span class="bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded text-[9px] font-bold"><?= $r['klas'] ?></span>
                                </td>
                                <td class="p-3">
                                    <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                        <span class="text-slate-400 text-[10px] italic">Baru/Belum diproses</span>
                                    <?php else: ?>
                                        <div class="text-[10px] font-medium text-slate-700 bg-white border border-slate-200 inline-block px-2 py-1 rounded cursor-pointer hover:border-blue-300 hover:text-blue-600 transition-colors mb-1" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                            <i class="fas fa-history text-slate-400 mr-1"></i> <?= $r['fu_tmpl'] ?>
                                        </div>
                                        <div class="text-[9px] text-slate-400"><i class="far fa-clock mr-1"></i><?= $r['fu_text'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-right">
                                    <div class="flex gap-1.5 justify-end items-center">
                                        <form class="inline flex gap-1 items-center" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="bg-white border border-slate-200 rounded p-1.5 text-[10px] w-28 outline-none focus:border-blue-400" required onchange="showWA(this)">
                                                <option value="">Pilih...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-blue-50 text-blue-600 w-7 h-7 rounded flex items-center justify-center hover:bg-blue-600 hover:text-white transition-colors"><i class="fas fa-paper-plane text-[10px]"></i></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Hapus prospek ini?')" class="inline">
                                            <input type="hidden" name="delete_prospect" value="1">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <button type="submit" class="bg-rose-50 text-rose-500 w-7 h-7 rounded flex items-center justify-center hover:bg-rose-500 hover:text-white transition-colors"><i class="fas fa-trash-alt text-[10px]"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages_o > 1): ?>
                <div class="p-3 bg-white border-t border-slate-100 flex justify-between items-center text-[11px] font-medium text-slate-500">
                    <span>Halaman <?= $page_o ?> / <?= $pages_o ?></span>
                    <div class="flex gap-1">
                        <?php if($page_o > 1): ?><a href="<?= buildPageUrl('page_o', $page_o-1) ?>" class="px-2.5 py-1 bg-white border border-slate-200 rounded hover:bg-slate-50 transition-colors">Prev</a><?php endif; ?>
                        <?php if($page_o < $pages_o): ?><a href="<?= buildPageUrl('page_o', $page_o+1) ?>" class="px-2.5 py-1 bg-white border border-slate-200 rounded hover:bg-slate-50 transition-colors">Next</a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="minimal-card overflow-hidden mt-6">
                <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-white">
                    <h3 class="font-bold text-sm text-slate-800 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span> Daftar Antrean Manual / CSV
                    </h3>
                    <div class="flex items-center gap-4">
                        <span class="text-[10px] bg-slate-100 text-slate-600 px-2 py-1 rounded font-medium">Total: <?= $total_m ?></span>
                        <form method="POST" onsubmit="return confirm('Hapus SEMUA daftar antrean manual?')" class="inline">
                            <input type="hidden" name="hapus_semua_manual" value="1">
                            <button type="submit" class="text-rose-500 hover:text-rose-700 text-[10px] font-bold flex items-center"><i class="fas fa-trash-alt mr-1"></i>Hapus Semua</button>
                        </form>
                        <label class="text-[11px] font-bold text-slate-600 cursor-pointer flex items-center"><input type="checkbox" id="checkAllManual" class="mr-1.5 w-3.5 h-3.5 accent-amber-500">Pilih Semua</label>
                    </div>
                </div>
                <div class="custom-scroll bg-white">
                    <table class="w-full text-left text-xs">
                        <thead class="text-slate-400 text-[10px] uppercase tracking-wider border-b border-slate-100 bg-slate-50/50">
                            <tr><th class="p-3 w-10 text-center">#</th><th class="p-3">Prospek</th><th class="p-3">Status Terakhir</th><th class="p-3 text-right">Aksi Cepat</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($targetManual_paged as $r): ?>
                            <tr class="hover-row transition-all">
                                <td class="p-3 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-manual w-3.5 h-3.5 accent-amber-500 cursor-pointer"></td>
                                <td class="p-3">
                                    <div class="font-bold text-slate-800 text-sm mb-0.5 cursor-pointer hover:text-amber-600 flex items-center gap-2" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>"><?= $r['nama'] ?></div>
                                    <div class="text-[10px] text-slate-500 flex items-center gap-1">
                                        <?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 hover:scale-110 transition-transform"><i class="fab fa-whatsapp"></i></a>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                        <span class="text-slate-400 text-[10px] italic">Baru/Belum diproses</span>
                                    <?php else: ?>
                                        <div class="text-[10px] font-medium text-slate-700 bg-white border border-slate-200 inline-block px-2 py-1 rounded cursor-pointer hover:border-amber-300 hover:text-amber-600 transition-colors mb-1" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                            <i class="fas fa-history text-slate-400 mr-1"></i> <?= $r['fu_tmpl'] ?>
                                        </div>
                                        <div class="text-[9px] text-slate-400"><i class="far fa-clock mr-1"></i><?= $r['fu_text'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-right">
                                    <div class="flex gap-1.5 justify-end items-center">
                                        <form class="inline flex gap-1 items-center" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="bg-white border border-slate-200 rounded p-1.5 text-[10px] w-24 outline-none focus:border-amber-400" required onchange="showWA(this)">
                                                <option value="">Pilih...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-amber-50 text-amber-600 w-7 h-7 rounded flex items-center justify-center hover:bg-amber-500 hover:text-white transition-colors"><i class="fas fa-paper-plane text-[10px]"></i></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Hapus prospek ini?')" class="inline">
                                            <input type="hidden" name="delete_prospect" value="1">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <button type="submit" class="bg-rose-50 text-rose-500 w-7 h-7 rounded flex items-center justify-center hover:bg-rose-500 hover:text-white transition-colors"><i class="fas fa-trash-alt text-[10px]"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages_m > 1): ?>
                <div class="p-3 bg-white border-t border-slate-100 flex justify-between items-center text-[11px] font-medium text-slate-500">
                    <span>Halaman <?= $page_m ?> / <?= $pages_m ?></span>
                    <div class="flex gap-1">
                        <?php if($page_m > 1): ?><a href="<?= buildPageUrl('page_m', $page_m-1) ?>" class="px-2.5 py-1 bg-white border border-slate-200 rounded hover:bg-slate-50 transition-colors">Prev</a><?php endif; ?>
                        <?php if($page_m < $pages_m): ?><a href="<?= buildPageUrl('page_m', $page_m+1) ?>" class="px-2.5 py-1 bg-white border border-slate-200 rounded hover:bg-slate-50 transition-colors">Next</a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<div id="waPreview" class="animate-slideIn">
    <div class="bg-[#075e54] text-white p-2.5 flex items-center justify-between">
        <p class="text-[11px] font-bold"><i class="fas fa-eye mr-1.5"></i> Live Preview</p>
        <button onclick="closeWA()" class="opacity-70 hover:opacity-100 p-1"><i class="fas fa-times text-sm"></i></button>
    </div>
    <div class="chat-bg p-4 h-48 overflow-y-auto custom-scroll">
        <div class="bubble-left p-2.5 text-[12px] text-[#111b21] mb-2 inline-block whitespace-pre-wrap" id="waText">...</div>
    </div>
</div>

<div id="modalTambah" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="minimal-card w-full max-w-sm overflow-hidden shadow-2xl animate-fadeIn">
        <div class="p-5 border-b border-slate-100 font-bold flex justify-between items-center text-slate-700 bg-white"><span>Tambah Manual</span><button type="button" onclick="closeModal('modalTambah')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button></div>
        <form method="POST" class="p-6 space-y-4 bg-slate-50/50">
            <div>
                <label class="text-[10px] font-bold text-slate-500 uppercase">Nama Lengkap</label>
                <input type="text" name="nama_baru" required class="w-full mt-1 p-2.5 bg-white border border-slate-200 rounded-lg text-sm outline-none focus:border-blue-400">
            </div>
            <div>
                <label class="text-[10px] font-bold text-slate-500 uppercase">WhatsApp (08...)</label>
                <input type="number" name="nowa_baru" required class="w-full mt-1 p-2.5 bg-white border border-slate-200 rounded-lg text-sm outline-none focus:border-blue-400">
            </div>
            <button type="submit" name="tambah_prospek" class="w-full bg-slate-800 text-white py-3 rounded-lg font-bold text-sm hover:bg-slate-900 transition-colors mt-2">Simpan Prospek</button>
        </form>
    </div>
</div>

<div id="modalCSV" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="minimal-card w-full max-w-sm overflow-hidden shadow-2xl animate-fadeIn">
        <div class="p-5 border-b border-slate-100 font-bold flex justify-between items-center text-slate-700 bg-white"><span>Upload Bulk CSV</span><button type="button" onclick="closeModal('modalCSV')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button></div>
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4 bg-slate-50/50" onsubmit="showManualLoading()">
            <div class="bg-blue-50/50 p-3 rounded-lg border border-blue-100 text-[11px] text-blue-700 font-medium"><i class="fas fa-info-circle mr-1"></i> Format CSV baris pertama: <b>Nama, WhatsApp</b></div>
            <input type="file" name="file_csv" accept=".csv" required class="w-full p-4 border border-dashed border-slate-300 bg-white rounded-lg text-xs cursor-pointer focus:border-blue-400">
            <button type="submit" name="upload_csv" class="w-full bg-blue-600 text-white py-3 rounded-lg font-bold text-sm hover:bg-blue-700 transition-colors mt-2">Proses Import</button>
        </form>
    </div>
</div>

<div id="modalDetailChat" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="minimal-card w-full max-w-md overflow-hidden shadow-2xl animate-fadeIn flex flex-col h-[70vh] max-h-[600px]">
        <div class="p-4 border-b border-slate-200 font-bold flex justify-between items-center text-slate-800 bg-white z-10 shadow-sm">
            <div class="flex items-center gap-2">
                <i class="fab fa-whatsapp text-emerald-500 text-lg"></i> <span>Detail Percakapan</span>
            </div>
            <button type="button" onclick="closeModal('modalDetailChat')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="chat-bg flex-1 p-4 overflow-y-auto custom-scroll flex flex-col gap-4" id="detailChatContent">
            </div>
        <div class="p-3 border-t border-slate-100 bg-white text-right">
            <button onclick="closeModal('modalDetailChat')" class="bg-slate-100 text-slate-600 px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-200 transition-colors">Tutup</button>
        </div>
    </div>
</div>

<script>
const templates = <?= json_encode($jsTemplates) ?>;

function showWA(s) { 
    const p = document.getElementById('waPreview'), t = document.getElementById('waText'); 
    if(s.value && templates[s.value]) { 
        t.innerText = templates[s.value].replace(/\[nama\]|\{nama\}/gi, '[Nama Prospek]'); 
        p.style.display = 'block'; 
    } else { p.style.display = 'none'; }
}

function showDetail(userMsg, sysMsg) {
    if (!userMsg || userMsg.trim() === '') userMsg = '(Pesan masuk kosong)';
    
    // Format escape line breaks untuk HTML
    const formatMsg = (str) => str.replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
    
    let html = '';
    
    // Chat Kiri (User)
    html += `
    <div class="flex items-end gap-2 pr-12 animate-fadeIn" style="animation-delay: 0.1s">
        <div class="w-6 h-6 rounded-full bg-slate-300 flex items-center justify-center shrink-0 mb-1"><i class="fas fa-user text-white text-[10px]"></i></div>
        <div class="bubble-left p-3 text-[12px] text-[#111b21] relative">
            ${formatMsg(userMsg)}
            <div class="text-[9px] text-slate-400 mt-1 text-right">Pesan User</div>
        </div>
    </div>`;

    // Chat Kanan (Sistem / Terakhir)
    if (sysMsg && sysMsg.trim() !== '') {
        html += `
        <div class="flex items-end gap-2 pl-12 flex-row-reverse animate-fadeIn" style="animation-delay: 0.2s">
            <div class="w-6 h-6 rounded-full bg-emerald-500 flex items-center justify-center shrink-0 mb-1"><i class="fas fa-robot text-white text-[10px]"></i></div>
            <div class="bubble-right p-3 text-[12px] text-[#111b21] relative">
                <div class="text-[10px] text-emerald-700 font-bold mb-1 border-b border-emerald-200/50 pb-1">Terkirim:</div>
                ${formatMsg(sysMsg)}
                <div class="text-[9px] text-emerald-600/70 mt-1 text-right flex items-center justify-end gap-1"><i class="fas fa-check-double text-blue-500"></i> Sistem</div>
            </div>
        </div>`;
    }

    document.getElementById('detailChatContent').innerHTML = html;
    openModal('modalDetailChat');
}

function closeWA() { document.getElementById('waPreview').style.display = 'none'; }
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function showManualLoading() { 
    document.getElementById('loader').classList.remove('hidden');
    document.getElementById('progressStatus').innerText = "Mengunggah data...";
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateCount() { document.getElementById('countCheck').innerText = document.querySelectorAll('.cb-target:checked').length; }

document.getElementById('checkAllOrganik')?.addEventListener('change', function() { document.querySelectorAll('.cb-organik').forEach(c => c.checked = this.checked); updateCount(); });
document.getElementById('checkAllManual')?.addEventListener('change', function() { document.querySelectorAll('.cb-manual').forEach(c => c.checked = this.checked); updateCount(); });
document.querySelectorAll('.cb-target').forEach(c => c.addEventListener('change', updateCount));

async function submitMassAjax(e) { 
    e.preventDefault();
    const sel = document.querySelectorAll('.cb-target:checked'); 
    const tmplId = document.querySelector('select[name="template_id_multi"]').value;
    
    if(!sel.length || !tmplId) { alert('Harap pilih prospek dan template!'); return; } 
    
    const modal = document.getElementById('loader'); 
    const pBar = document.getElementById('progressBar');
    const pText = document.getElementById('progressText');
    const pStat = document.getElementById('progressStatus');
    
    modal.classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    
    let success = 0, fail = 0; let total = sel.length;

    for (let i = 0; i < total; i++) {
        let contactId = sel[i].value;
        pStat.innerText = `Mengirim ke ${contactId}... (${i+1}/${total})`;
        
        try {
            let formData = new FormData();
            formData.append('ajax_send', '1');
            formData.append('contact_id', contactId);
            formData.append('template_id', tmplId);

            let res = await fetch('', { method: 'POST', body: formData });
            let json = await res.json();
            if(json.status === 'success') success++; else fail++;
        } catch(err) { fail++; }
        
        let pct = Math.round(((i + 1) / total) * 100);
        pBar.style.width = pct + '%';
        pText.innerText = pct + '%';
        await new Promise(r => setTimeout(r, 100)); 
    }
    
    pStat.innerHTML = `<span class="text-emerald-600 font-bold">Selesai!</span> ${success} Sukses, ${fail} Gagal.`;
    setTimeout(() => location.reload(), 1500);
}

async function submitSingleAjax(e, form) {
    e.preventDefault();
    const contactId = form.querySelector('input[name="contact_id"]').value;
    const tmplId = form.querySelector('select[name="template_id"]').value;
    if(!tmplId) { alert('Pilih template!'); return; }
    const btn = form.querySelector('button[type="submit"]');
    const oriHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin text-[10px]"></i>'; btn.disabled = true;
    
    try {
        let formData = new FormData();
        formData.append('ajax_send', '1');
        formData.append('contact_id', contactId);
        formData.append('template_id', tmplId);

        let res = await fetch('', { method: 'POST', body: formData });
        let json = await res.json();
        
        if(json.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check text-[10px]"></i>';
            btn.classList.replace('bg-blue-50', 'bg-emerald-500');
            btn.classList.replace('bg-amber-50', 'bg-emerald-500');
            btn.classList.replace('bg-slate-100', 'bg-emerald-500');
            btn.classList.add('text-white');
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Gagal API: ' + json.msg);
            btn.innerHTML = oriHtml; btn.disabled = false;
        }
    } catch(err) {
        alert('Koneksi Error.');
        btn.innerHTML = oriHtml; btn.disabled = false;
    }
}

if (window.self !== window.top) {
    const headerElement = document.querySelector('header');
    if (headerElement) headerElement.style.display = 'none';
    document.body.style.backgroundColor = "transparent";
}
</script>
</body>
</html>