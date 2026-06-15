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
    <title>CRM Follow-Up Pipeline | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #334155; overflow: hidden; }
        
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .crm-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05); }
        .crm-input { width: 100%; padding: 0.5rem 0.75rem; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.75rem; transition: all 0.2s; outline: none; }
        .crm-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1); }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeIn { animation: fadeIn 0.3s ease-out forwards; }
        
        .chat-bg { background-color: #f0f2f5; background-image: radial-gradient(#cbd5e1 1px, transparent 0); background-size: 20px 20px; }
        .bubble-left { background: #ffffff; border-radius: 0 12px 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); }
        .bubble-right { background: #dcf8c6; border-radius: 12px 0 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); }
        
        #waPreview { display: none; position: fixed; bottom: 20px; right: 20px; width: 300px; z-index: 100; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; }
        .hover-row:hover { background-color: #f8fafc; }
    </style>
</head>
<body class="h-screen flex text-slate-700">

    <aside id="app-sidebar" class="w-64 bg-[#0f172a] text-slate-300 flex-shrink-0 flex flex-col transition-all duration-300 z-20 hidden md:flex">
        <div class="h-16 flex items-center px-6 border-b border-slate-800 shrink-0">
            <div class="w-8 h-8 rounded bg-blue-600 flex items-center justify-center mr-3 shadow-lg shadow-blue-900/50">
                <i class="fas fa-paper-plane text-white text-sm"></i>
            </div>
            <span class="text-white font-bold tracking-wide">JWD CRM</span>
        </div>
        <div class="p-4 flex-1 overflow-y-auto custom-scroll">
            <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-3 pl-2">Menu Utama</div>
            <nav class="space-y-1">
                <a href="index.php" class="flex items-center px-3 py-2.5 rounded-lg hover:bg-slate-800 transition-colors text-xs font-medium text-slate-400 hover:text-white">
                    <i class="fas fa-home w-6 text-center text-slate-500"></i> Dashboard
                </a>
                <a href="pesan.php" class="flex items-center px-3 py-2.5 rounded-lg bg-blue-600/10 text-blue-400 font-semibold transition-colors text-xs border border-blue-500/20">
                    <i class="fas fa-reply-all w-6 text-center"></i> Pipeline Follow-Up
                </a>
                <a href="manage_templates.php" class="flex items-center px-3 py-2.5 rounded-lg hover:bg-slate-800 transition-colors text-xs font-medium text-slate-400 hover:text-white">
                    <i class="fas fa-comment-dots w-6 text-center text-slate-500"></i> Kelola Template
                </a>
                <a href="grafik.php" class="flex items-center px-3 py-2.5 rounded-lg hover:bg-slate-800 transition-colors text-xs font-medium text-slate-400 hover:text-white">
                    <i class="fas fa-chart-pie w-6 text-center text-slate-500"></i> Analytics Chat
                </a>
            </nav>
            <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-3 pl-2 mt-8">Pengaturan</div>
            <nav class="space-y-1">
                <a href="wa-tut.php" class="flex items-center px-3 py-2.5 rounded-lg hover:bg-slate-800 transition-colors text-xs font-medium text-slate-400 hover:text-white">
                    <i class="fas fa-cog w-6 text-center text-slate-500"></i> Sistem Server
                </a>
            </nav>
        </div>
        <div class="p-4 border-t border-slate-800 shrink-0">
            <a href="wa.php?logout=true" onclick="return confirm('Apakah Anda yakin ingin keluar?')" class="flex items-center px-3 py-2.5 rounded-lg hover:bg-rose-500/10 hover:text-rose-400 transition-colors text-xs font-medium text-slate-400">
                <i class="fas fa-sign-out-alt w-6 text-center text-rose-500/70"></i> Keluar Sesi
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen relative min-w-0 bg-[#f8fafc]">
        
        <header id="app-header" class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-6 shrink-0 z-10">
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-slate-800">Pipeline Follow-Up</h1>
                <span class="hidden md:inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-medium bg-blue-100 text-blue-800">
                    Live
                </span>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="openModal('modalTambah')" class="px-3 py-1.5 text-xs font-semibold flex items-center bg-white border border-slate-300 text-slate-600 rounded-md hover:bg-slate-50 transition-colors shadow-sm">
                    <i class="fas fa-plus mr-1.5 text-slate-400"></i> Manual
                </button>
                <button onclick="openModal('modalCSV')" class="px-3 py-1.5 text-xs font-semibold flex items-center bg-white border border-slate-300 text-slate-600 rounded-md hover:bg-slate-50 transition-colors shadow-sm">
                    <i class="fas fa-file-csv mr-1.5 text-slate-400"></i> CSV
                </button>
                <a href="?export_csv_action=1&search=<?=urlencode($search)?>&from=<?=urlencode($f_start)?>&to=<?=urlencode($f_end)?>&minat=<?=urlencode($f_minat)?>" class="px-3 py-1.5 text-xs font-semibold flex items-center bg-slate-800 text-white rounded-md hover:bg-slate-900 transition-colors shadow-sm">
                    <i class="fas fa-download mr-1.5"></i> Export
                </a>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 custom-scroll">
            
            <div id="loader" class="hidden animate-fadeIn mb-6 crm-card p-4 border-l-4 border-l-blue-500 bg-blue-50/30">
                <div class="flex items-center gap-4">
                    <i class="fas fa-circle-notch fa-spin text-xl text-blue-500"></i>
                    <div class="flex-1">
                        <div class="flex justify-between items-end mb-1">
                            <h3 class="font-bold text-slate-700 text-sm">Mengirim Broadcast...</h3>
                            <p id="progressText" class="text-sm font-bold text-blue-600">0%</p>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-1.5 overflow-hidden"><div id="progressBar" class="bg-blue-500 h-full transition-all duration-300 w-0"></div></div>
                        <p id="progressStatus" class="text-[10px] text-slate-500 mt-1.5 font-medium">Mempersiapkan data antrean...</p>
                    </div>
                </div>
            </div>

            <?php if ($notification): ?>
            <div class="mb-6 p-3 rounded-md border <?= $notificationType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : ($notificationType === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-rose-50 border-rose-200 text-rose-700') ?> flex items-center gap-2 text-xs font-semibold shadow-sm animate-fadeIn">
                <i class="fas <?= $notificationType === 'success' ? 'fa-check-circle' : ($notificationType === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle') ?>"></i> <?= $notification ?>
            </div>
            <?php endif; ?>

            <?php if(!empty($statistikMinat)): ?>
            <div class="mb-6">
                <div class="flex justify-between items-center mb-2">
                    <h2 class="text-xs font-bold text-slate-500 uppercase tracking-wider">Stages Klasifikasi</h2>
                    <?php if($f_minat): ?>
                    <a href="pesan.php" class="text-[10px] text-rose-500 hover:text-rose-700 font-bold"><i class="fas fa-times mr-1"></i>Reset Filter</a>
                    <?php endif; ?>
                </div>
                <div class="flex gap-3 overflow-x-auto pb-2 custom-scroll">
                    <?php foreach($statistikMinat as $namaMinat => $jumlah): 
                        $isActive = ($f_minat === $namaMinat) ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-slate-200 hover:border-slate-300 bg-white';
                    ?>
                    <a href="?minat=<?= urlencode($namaMinat) ?>" class="crm-card flex-shrink-0 w-40 p-3 transition-colors cursor-pointer border <?= $isActive ?>">
                        <div class="text-[10px] font-bold text-slate-500 mb-1 truncate"><?= htmlspecialchars($namaMinat) ?></div>
                        <div class="text-lg font-black text-slate-800"><?= $jumlah ?> <span class="text-[10px] font-medium text-slate-400">Leads</span></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">
                
                <div class="xl:col-span-1 space-y-5">
                    
                    <div class="crm-card p-4">
                        <h3 class="font-bold text-slate-800 text-xs mb-3 flex items-center"><i class="fas fa-filter text-slate-400 mr-2"></i> Filter Data</h3>
                        <form method="GET" class="space-y-3">
                            <div>
                                <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 block">Kata Kunci</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / No. WhatsApp..." class="crm-input">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 block">Dari</label>
                                    <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="crm-input">
                                </div>
                                <div>
                                    <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 block">Sampai</label>
                                    <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="crm-input">
                                </div>
                            </div>
                            <button type="submit" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 py-1.5 rounded-md font-bold text-xs transition-colors border border-slate-300">Terapkan Filter</button>
                        </form>
                    </div>

                    <div class="crm-card p-4">
                        <h3 class="font-bold text-slate-800 text-xs mb-3 flex items-center"><i class="fas fa-cog text-slate-400 mr-2"></i> Aksi Sesi</h3>
                        <form method="POST" onsubmit="return confirm('Kosongkan histori Selesai hari ini? Data tidak akan dihapus dari database, hanya direset dari daftar selesai sesi ini.')">
                            <input type="hidden" name="clear_fu" value="1">
                            <button type="submit" class="w-full bg-white hover:bg-rose-50 text-slate-600 hover:text-rose-600 py-1.5 rounded-md text-xs font-semibold border border-slate-200 transition-colors shadow-sm">
                                <i class="fas fa-sync-alt mr-1.5"></i> Reset Sesi Harian
                            </button>
                        </form>
                    </div>

                    <?php if(!empty($organikSudahDichat) || !empty($manualSudahDichat)): ?>
                    <div class="space-y-4">
                        <?php if(!empty($organikSudahDichat)): ?>
                        <div class="crm-card overflow-hidden">
                            <div class="p-2.5 bg-emerald-50 border-b border-emerald-100 flex justify-between items-center">
                                <h3 class="font-bold text-[9px] text-emerald-700 uppercase tracking-wider"><i class="fas fa-check mr-1"></i> Organik Selesai</h3>
                                <span class="text-[9px] font-bold text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded"><?= count($organikSudahDichat) ?></span>
                            </div>
                            <div class="max-h-[250px] overflow-y-auto custom-scroll p-1">
                                <?php foreach($organikSudahDichat as $r): ?>
                                <div class="p-2 hover:bg-slate-50 rounded transition-colors group border-b border-slate-50 last:border-0">
                                    <div class="font-semibold text-[10px] text-slate-700 cursor-pointer group-hover:text-blue-600 truncate" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>"><?= $r['nama'] ?></div>
                                    <div class="flex items-center gap-1 mt-1">
                                        <form class="flex w-full gap-1" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="crm-input p-1 text-[9px] flex-1" required onchange="showWA(this)">
                                                <option value="">Ulangi...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-slate-100 text-slate-500 w-6 rounded hover:bg-emerald-500 hover:text-white transition-colors border border-slate-200"><i class="fas fa-paper-plane text-[8px]"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($manualSudahDichat)): ?>
                        <div class="crm-card overflow-hidden">
                            <div class="p-2.5 bg-amber-50 border-b border-amber-100 flex justify-between items-center">
                                <h3 class="font-bold text-[9px] text-amber-700 uppercase tracking-wider"><i class="fas fa-check-double mr-1"></i> Manual Selesai</h3>
                                <span class="text-[9px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded"><?= count($manualSudahDichat) ?></span>
                            </div>
                            <div class="max-h-[250px] overflow-y-auto custom-scroll p-1">
                                <?php foreach($manualSudahDichat as $r): ?>
                                <div class="p-2 hover:bg-slate-50 rounded transition-colors group border-b border-slate-50 last:border-0">
                                    <div class="font-semibold text-[10px] text-slate-700 cursor-pointer group-hover:text-blue-600 truncate" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>"><?= $r['nama'] ?></div>
                                    <div class="flex items-center gap-1 mt-1">
                                        <form class="flex w-full gap-1" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="crm-input p-1 text-[9px] flex-1" required onchange="showWA(this)">
                                                <option value="">Ulangi...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-slate-100 text-slate-500 w-6 rounded hover:bg-amber-500 hover:text-white transition-colors border border-slate-200"><i class="fas fa-paper-plane text-[8px]"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="xl:col-span-3 space-y-6">
                    
                    <div class="crm-card p-4 flex flex-col sm:flex-row gap-4 justify-between items-center bg-white border-l-4 border-l-blue-500">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-blue-50 text-blue-500 flex items-center justify-center shrink-0 border border-blue-100"><i class="fas fa-bullhorn text-sm"></i></div>
                            <div>
                                <h3 class="font-bold text-xs text-slate-800">Tindakan Massal</h3>
                                <p class="text-[10px] text-slate-500"><span id="countCheck" class="font-bold text-blue-600 bg-blue-50 px-1 rounded">0</span> kontak terpilih di halaman ini</p>
                            </div>
                        </div>
                        <form id="formMassal" class="w-full sm:w-auto flex gap-2">
                            <select name="template_id_multi" onchange="showWA(this)" class="crm-input py-1.5" required>
                                <option value="">Pilih Template Broadcast...</option>
                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                            </select>
                            <button type="button" onclick="submitMassAjax(event)" class="bg-blue-600 hover:bg-blue-700 px-4 py-1.5 rounded-md font-bold text-xs text-white transition-colors shadow-sm whitespace-nowrap">Kirim Massal</button>
                        </form>
                    </div>

                    <div class="crm-card overflow-hidden flex flex-col">
                        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex justify-between items-center shrink-0">
                            <h3 class="font-bold text-xs text-slate-800 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Leads Organik <span class="text-[9px] font-normal text-slate-500 ml-1">(Total: <?= $total_o ?>)</span>
                            </h3>
                            <label class="text-[10px] font-bold text-slate-600 cursor-pointer flex items-center hover:text-blue-600 transition-colors">
                                <input type="checkbox" id="checkAllOrganik" class="mr-1.5 w-3 h-3 accent-blue-600">Pilih Semua Halaman Ini
                            </label>
                        </div>
                        <div class="overflow-x-auto custom-scroll bg-white">
                            <table class="w-full text-left text-xs whitespace-nowrap">
                                <thead class="text-slate-500 text-[10px] uppercase tracking-wider border-b border-slate-200 bg-white">
                                    <tr><th class="p-3 w-10 text-center">#</th><th class="p-3">Identitas Prospek</th><th class="p-3">Status Pipeline</th><th class="p-3 text-right">Aksi</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if(empty($targetOrganik_paged)): ?>
                                        <tr><td colspan="4" class="p-8 text-center text-slate-400 text-xs">Tidak ada data prospek organik.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach($targetOrganik_paged as $r): ?>
                                    <tr class="hover-row transition-colors group">
                                        <td class="p-3 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-organik w-3 h-3 accent-blue-600 cursor-pointer"></td>
                                        <td class="p-3">
                                            <div class="font-bold text-slate-800 text-[11px] mb-0.5 cursor-pointer hover:text-blue-600 flex items-center gap-2" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                                <?= $r['nama'] ?>
                                                <?php if($r['gender'] !== '-'): ?>
                                                    <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 font-medium"><?= $r['gender'] ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[10px] text-slate-500 flex items-center gap-1 font-mono">
                                                <?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 hover:scale-110 transition-transform"><i class="fab fa-whatsapp"></i></a>
                                            </div>
                                        </td>
                                        <td class="p-3">
                                            <span class="bg-slate-100 border border-slate-200 text-slate-600 px-1.5 py-0.5 rounded text-[9px] font-bold mb-1 inline-block"><?= $r['klas'] ?></span>
                                            <div class="text-[9px] text-slate-500 flex items-center mt-0.5">
                                                <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                                    <i class="fas fa-circle text-[6px] text-blue-500 mr-1.5"></i> Baru Masuk
                                                <?php else: ?>
                                                    <i class="fas fa-check-double text-blue-500 mr-1.5"></i> <span class="truncate max-w-[120px]" title="<?= $r['fu_tmpl'] ?>"><?= $r['fu_tmpl'] ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="p-3 text-right">
                                            <div class="flex gap-1.5 justify-end items-center opacity-80 group-hover:opacity-100 transition-opacity">
                                                <form class="inline flex gap-1 items-center" onsubmit="submitSingleAjax(event, this)">
                                                    <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                    <select name="template_id" class="crm-input py-1 w-24 text-[9px]" required onchange="showWA(this)">
                                                        <option value="">Pilih...</option>
                                                        <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="bg-white border border-slate-300 text-blue-600 w-6 h-6 rounded flex items-center justify-center hover:bg-blue-50 hover:border-blue-300 transition-colors shadow-sm"><i class="fas fa-paper-plane text-[9px]"></i></button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Hapus prospek ini?')" class="inline">
                                                    <input type="hidden" name="delete_prospect" value="1">
                                                    <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                    <button type="submit" class="bg-white border border-slate-300 text-rose-500 w-6 h-6 rounded flex items-center justify-center hover:bg-rose-50 hover:border-rose-300 transition-colors shadow-sm"><i class="fas fa-trash-alt text-[9px]"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($pages_o > 1): ?>
                        <div class="p-2 bg-slate-50 border-t border-slate-200 flex justify-between items-center text-[10px] font-medium text-slate-500 shrink-0">
                            <span>Hal <?= $page_o ?> dari <?= $pages_o ?></span>
                            <div class="flex gap-1">
                                <?php if($page_o > 1): ?><a href="<?= buildPageUrl('page_o', $page_o-1) ?>" class="px-2 py-1 bg-white border border-slate-200 rounded hover:bg-slate-100 transition-colors">Prev</a><?php endif; ?>
                                <?php if($page_o < $pages_o): ?><a href="<?= buildPageUrl('page_o', $page_o+1) ?>" class="px-2 py-1 bg-white border border-slate-200 rounded hover:bg-slate-100 transition-colors">Next</a><?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="crm-card overflow-hidden flex flex-col">
                        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex justify-between items-center shrink-0">
                            <h3 class="font-bold text-xs text-slate-800 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-amber-500"></span> Leads Manual / CSV <span class="text-[9px] font-normal text-slate-500 ml-1">(Total: <?= $total_m ?>)</span>
                            </h3>
                            <div class="flex items-center gap-3">
                                <form method="POST" onsubmit="return confirm('Hapus SEMUA daftar antrean manual?')" class="inline">
                                    <input type="hidden" name="hapus_semua_manual" value="1">
                                    <button type="submit" class="text-[10px] text-rose-500 hover:text-rose-700 font-bold flex items-center bg-rose-50 px-2 py-0.5 rounded border border-rose-100 transition-colors"><i class="fas fa-trash-alt mr-1"></i>Bersihkan</button>
                                </form>
                                <label class="text-[10px] font-bold text-slate-600 cursor-pointer flex items-center hover:text-amber-600 transition-colors">
                                    <input type="checkbox" id="checkAllManual" class="mr-1.5 w-3 h-3 accent-amber-500">Pilih Semua Halaman Ini
                                </label>
                            </div>
                        </div>
                        <div class="overflow-x-auto custom-scroll bg-white">
                            <table class="w-full text-left text-xs whitespace-nowrap">
                                <thead class="text-slate-500 text-[10px] uppercase tracking-wider border-b border-slate-200 bg-white">
                                    <tr><th class="p-3 w-10 text-center">#</th><th class="p-3">Identitas Prospek</th><th class="p-3">Status Pipeline</th><th class="p-3 text-right">Aksi</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if(empty($targetManual_paged)): ?>
                                        <tr><td colspan="4" class="p-8 text-center text-slate-400 text-xs">Tidak ada antrean manual/CSV.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach($targetManual_paged as $r): ?>
                                    <tr class="hover-row transition-colors group">
                                        <td class="p-3 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-manual w-3 h-3 accent-amber-500 cursor-pointer"></td>
                                        <td class="p-3">
                                            <div class="font-bold text-slate-800 text-[11px] mb-0.5 cursor-pointer hover:text-amber-600 flex items-center gap-2" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                                <?= $r['nama'] ?>
                                            </div>
                                            <div class="text-[10px] text-slate-500 flex items-center gap-1 font-mono">
                                                <?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 hover:scale-110 transition-transform"><i class="fab fa-whatsapp"></i></a>
                                            </div>
                                        </td>
                                        <td class="p-3">
                                            <div class="text-[9px] text-slate-500 flex items-center mt-0.5">
                                                <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                                    <i class="fas fa-circle text-[6px] text-amber-500 mr-1.5"></i> Menunggu
                                                <?php else: ?>
                                                    <i class="fas fa-check-double text-blue-500 mr-1.5"></i> <span class="truncate max-w-[120px]" title="<?= $r['fu_tmpl'] ?>"><?= $r['fu_tmpl'] ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="p-3 text-right">
                                            <div class="flex gap-1.5 justify-end items-center opacity-80 group-hover:opacity-100 transition-opacity">
                                                <form class="inline flex gap-1 items-center" onsubmit="submitSingleAjax(event, this)">
                                                    <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                    <select name="template_id" class="crm-input py-1 w-24 text-[9px]" required onchange="showWA(this)">
                                                        <option value="">Pilih...</option>
                                                        <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="bg-white border border-slate-300 text-amber-600 w-6 h-6 rounded flex items-center justify-center hover:bg-amber-50 hover:border-amber-300 transition-colors shadow-sm"><i class="fas fa-paper-plane text-[9px]"></i></button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Hapus prospek ini?')" class="inline">
                                                    <input type="hidden" name="delete_prospect" value="1">
                                                    <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                    <button type="submit" class="bg-white border border-slate-300 text-rose-500 w-6 h-6 rounded flex items-center justify-center hover:bg-rose-50 hover:border-rose-300 transition-colors shadow-sm"><i class="fas fa-trash-alt text-[9px]"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($pages_m > 1): ?>
                        <div class="p-2 bg-slate-50 border-t border-slate-200 flex justify-between items-center text-[10px] font-medium text-slate-500 shrink-0">
                            <span>Hal <?= $page_m ?> dari <?= $pages_m ?></span>
                            <div class="flex gap-1">
                                <?php if($page_m > 1): ?><a href="<?= buildPageUrl('page_m', $page_m-1) ?>" class="px-2 py-1 bg-white border border-slate-200 rounded hover:bg-slate-100 transition-colors">Prev</a><?php endif; ?>
                                <?php if($page_m < $pages_m): ?><a href="<?= buildPageUrl('page_m', $page_m+1) ?>" class="px-2 py-1 bg-white border border-slate-200 rounded hover:bg-slate-100 transition-colors">Next</a><?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <div class="h-12"></div>
        </div>
    </main>

    <div id="waPreview" class="animate-fadeIn">
        <div class="bg-[#075e54] text-white p-2 flex items-center justify-between">
            <p class="text-[10px] font-bold"><i class="fas fa-eye mr-1.5"></i> Live Preview Mode</p>
            <button onclick="closeWA()" class="opacity-70 hover:opacity-100 p-1"><i class="fas fa-times text-xs"></i></button>
        </div>
        <div class="chat-bg p-3 h-40 overflow-y-auto custom-scroll">
            <div class="bubble-left p-2.5 text-[11px] text-[#111b21] mb-2 inline-block whitespace-pre-wrap" id="waText">...</div>
        </div>
    </div>

    <div id="modalTambah" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
        <div class="crm-card w-full max-w-sm overflow-hidden shadow-2xl animate-fadeIn">
            <div class="p-4 border-b border-slate-200 font-bold flex justify-between items-center text-slate-700 bg-slate-50 text-xs">
                <span><i class="fas fa-user-plus mr-1.5 text-slate-400"></i> Tambah Prospek Manual</span>
                <button type="button" onclick="closeModal('modalTambah')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" class="p-5 space-y-4 bg-white">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase">Nama Lengkap</label>
                    <input type="text" name="nama_baru" required class="crm-input mt-1">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase">WhatsApp (Contoh: 0812...)</label>
                    <input type="number" name="nowa_baru" required class="crm-input mt-1">
                </div>
                <div class="pt-2">
                    <button type="submit" name="tambah_prospek" class="w-full bg-slate-800 text-white py-2 rounded-md font-bold text-xs hover:bg-slate-900 transition-colors shadow-sm">Simpan Prospek</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalCSV" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
        <div class="crm-card w-full max-w-sm overflow-hidden shadow-2xl animate-fadeIn">
            <div class="p-4 border-b border-slate-200 font-bold flex justify-between items-center text-slate-700 bg-slate-50 text-xs">
                <span><i class="fas fa-file-csv mr-1.5 text-slate-400"></i> Import Data via CSV</span>
                <button type="button" onclick="closeModal('modalCSV')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="p-5 space-y-4 bg-white" onsubmit="showManualLoading()">
                <div class="bg-blue-50/50 p-2.5 rounded border border-blue-100 text-[10px] text-blue-700 font-medium">
                    <i class="fas fa-info-circle mr-1"></i> Baris pertama pada file CSV harus berisi header berurutan: <b>Nama, WhatsApp</b>
                </div>
                <input type="file" name="file_csv" accept=".csv" required class="w-full p-3 border border-dashed border-slate-300 bg-slate-50 rounded-md text-xs cursor-pointer focus:border-blue-400 outline-none">
                <div class="pt-2">
                    <button type="submit" name="upload_csv" class="w-full bg-blue-600 text-white py-2 rounded-md font-bold text-xs hover:bg-blue-700 transition-colors shadow-sm">Mulai Import Data</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalDetailChat" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
        <div class="crm-card w-full max-w-md overflow-hidden shadow-2xl animate-fadeIn flex flex-col h-[65vh] max-h-[550px]">
            <div class="p-3 border-b border-slate-200 font-bold flex justify-between items-center text-slate-800 bg-white z-10 shadow-sm shrink-0">
                <div class="flex items-center gap-2 text-xs">
                    <i class="fab fa-whatsapp text-emerald-500 text-base"></i> <span>History Pesan</span>
                </div>
                <button type="button" onclick="closeModal('modalDetailChat')" class="text-slate-400 hover:text-slate-600 w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100"><i class="fas fa-times"></i></button>
            </div>
            <div class="chat-bg flex-1 p-4 overflow-y-auto custom-scroll flex flex-col gap-4" id="detailChatContent">
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
    
    const formatMsg = (str) => str.replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
    
    let html = '';
    
    // Chat Kiri (User)
    html += `
    <div class="flex items-end gap-2 pr-12 animate-fadeIn" style="animation-delay: 0.1s">
        <div class="w-5 h-5 rounded-full bg-slate-300 flex items-center justify-center shrink-0 mb-1 shadow-sm"><i class="fas fa-user text-white text-[8px]"></i></div>
        <div class="bubble-left p-2.5 text-[11px] text-[#111b21] relative">
            ${formatMsg(userMsg)}
            <div class="text-[8px] text-slate-400 mt-1 text-right">Prospek</div>
        </div>
    </div>`;

    // Chat Kanan (Sistem)
    if (sysMsg && sysMsg.trim() !== '') {
        html += `
        <div class="flex items-end gap-2 pl-12 flex-row-reverse animate-fadeIn" style="animation-delay: 0.2s">
            <div class="w-5 h-5 rounded-full bg-emerald-500 flex items-center justify-center shrink-0 mb-1 shadow-sm"><i class="fas fa-robot text-white text-[8px]"></i></div>
            <div class="bubble-right p-2.5 text-[11px] text-[#111b21] relative">
                <div class="text-[9px] text-emerald-700 font-bold mb-1 border-b border-emerald-200/50 pb-0.5">Template Follow-up:</div>
                ${formatMsg(sysMsg)}
                <div class="text-[8px] text-emerald-600/80 mt-1 text-right flex items-center justify-end gap-1"><i class="fas fa-check-double text-blue-500"></i> CRM</div>
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
    document.getElementById('progressStatus').innerText = "Mengunggah data CSV...";
}

function updateCount() { document.getElementById('countCheck').innerText = document.querySelectorAll('.cb-target:checked').length; }

document.getElementById('checkAllOrganik')?.addEventListener('change', function() { document.querySelectorAll('.cb-organik').forEach(c => c.checked = this.checked); updateCount(); });
document.getElementById('checkAllManual')?.addEventListener('change', function() { document.querySelectorAll('.cb-manual').forEach(c => c.checked = this.checked); updateCount(); });
document.querySelectorAll('.cb-target').forEach(c => c.addEventListener('change', updateCount));

async function submitMassAjax(e) { 
    e.preventDefault();
    const sel = document.querySelectorAll('.cb-target:checked'); 
    const tmplId = document.querySelector('select[name="template_id_multi"]').value;
    
    if(!sel.length || !tmplId) { alert('Pilih minimal 1 prospek dan pilih template broadcast!'); return; } 
    
    const modal = document.getElementById('loader'); 
    const pBar = document.getElementById('progressBar');
    const pText = document.getElementById('progressText');
    const pStat = document.getElementById('progressStatus');
    
    modal.classList.remove('hidden');
    
    let success = 0, fail = 0; let total = sel.length;

    for (let i = 0; i < total; i++) {
        let contactId = sel[i].value;
        pStat.innerText = `Memproses pengiriman ke ${contactId}... (${i+1}/${total})`;
        
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
    btn.innerHTML = '<i class="fas fa-spinner fa-spin text-[8px]"></i>'; btn.disabled = true;
    
    try {
        let formData = new FormData();
        formData.append('ajax_send', '1');
        formData.append('contact_id', contactId);
        formData.append('template_id', tmplId);

        let res = await fetch('', { method: 'POST', body: formData });
        let json = await res.json();
        
        if(json.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check text-[8px]"></i>';
            btn.classList.replace('bg-white', 'bg-emerald-500');
            btn.classList.replace('bg-slate-100', 'bg-emerald-500');
            btn.classList.replace('text-blue-600', 'text-white');
            btn.classList.replace('text-slate-500', 'text-white');
            btn.classList.replace('border-slate-300', 'border-emerald-600');
            setTimeout(() => location.reload(), 400);
        } else {
            alert('Gagal API: ' + json.msg);
            btn.innerHTML = oriHtml; btn.disabled = false;
        }
    } catch(err) {
        alert('Koneksi Error.');
        btn.innerHTML = oriHtml; btn.disabled = false;
    }
}

// IFRAME INTEGRATION: Sembunyikan Header dan Sidebar CRM bila diload dari dalam dashboard utama
if (window.self !== window.top) {
    const sidebar = document.getElementById('app-sidebar');
    const header = document.getElementById('app-header');
    if (sidebar) sidebar.style.display = 'none';
    if (header) header.style.display = 'none';
    document.body.style.backgroundColor = "transparent";
    document.querySelector('main').style.backgroundColor = "transparent";
}
</script>
</body>
</html>