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
if(!in_array('template_history', $cols)) @$conn->query("ALTER TABLE log_wa ADD template_history TEXT NULL"); // Kolom baru untuk riwayat

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
        
        $stN = $conn->prepare("SELECT nama, message, template_history FROM log_wa WHERE nowa = ? LIMIT 1");
        $stN->bind_param("s", $cid); $stN->execute(); 
        $dbRow = $stN->get_result()->fetch_assoc();
        
        $nama = trim($dbRow['nama'] ?? 'Kak');
        $msgText = $dbRow['message'] ?? '';
        $currentHistory = $dbRow['template_history'] ?? '';
        
        if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $msgText, $m)) $nama = trim($m[1]);
        elseif (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $msgText, $m)) $nama = trim($m[1]);
        if (empty($nama) || strtolower($nama) == 'kak') $nama = 'Kak';

        $pesan = str_ireplace(['[nama]', '[NAMA]', '{nama}', '{NAMA}'], $nama, $msgTmpl);
        $pesan = str_replace('  ', ' ', $pesan);
        
        $hasilAPI = kirimPesan($cid, $pesan, $apiUrl, $apiToken);
        
        if($hasilAPI['status'] === 'TERKIRIM') {
            $tmplNameSafe = $conn->real_escape_string($tmplName);
            
            // Generate History String
            $historyLog = date('d/m/Y H:i') . " - " . $tmplNameSafe;
            $newHistory = empty($currentHistory) ? $historyLog : $currentHistory . '|||' . $historyLog;
            $newHistorySafe = $conn->real_escape_string($newHistory);

            $conn->query("UPDATE log_wa SET last_followup_at = NOW(), is_form_sent = GREATEST(is_form_sent, $isForm), last_template_name = '$tmplNameSafe', template_history = '$newHistorySafe' WHERE nowa = '$cid'");
            
            if (!in_array($cid, $_SESSION['followed_up_today'])) $_SESSION['followed_up_today'][] = $cid;
            echo json_encode(['status' => 'success', 'new_history' => $newHistory, 'fu_tmpl' => $tmplNameSafe, 'fu_time' => date('d/m H:i')]);
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
        set_time_limit(0); ini_set('memory_limit', '512M'); 
        if (($handle = fopen($_FILES['file_csv']['tmp_name'], "r")) !== FALSE) {
            $suksesUpload = 0; $ditolak = 0; $sudahOrganik = 0;
            
            // OPTIMASI: Tarik data organik existing ke RAM/Array untuk menghilangkan query dalam loop
            $existing_organic = [];
            $resOrg = $conn->query("SELECT nowa FROM log_wa WHERE message != 'Data CSV/Manual' AND message != ''");
            if($resOrg) {
                while($rowOrg = $resOrg->fetch_assoc()) {
                    $nOrg = preg_replace('/\D/', '', $rowOrg['nowa']);
                    if(strpos($nOrg,'0')===0) $nOrg = '62'.substr($nOrg,1);
                    if($nOrg) $existing_organic[$nOrg] = true;
                }
            }

            // Gunakan Transaction untuk kecepatan input ke Database
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT IGNORE INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, 'Data CSV/Manual', NOW())");
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (strtolower($data[0]) === 'nama' || empty($data[0])) continue;
                $n = preg_replace('/\D/', '', $data[1]); if (strpos($n, '0') === 0) $n = '62' . substr($n, 1);
                
                if (isset($disqualified[$n]) || isset($blocked[$n])) { $ditolak++; continue; }
                if (isset($existing_organic[$n])) { $sudahOrganik++; continue; }

                $stmt->bind_param("ss", $n, $data[0]); if($stmt->execute()) $suksesUpload++;
            }
            $conn->commit();
            fclose($handle); 
            
            $msg = "✅ $suksesUpload Data CSV di-import.";
            if($sudahOrganik > 0) $msg .= " $sudahOrganik ditolak karena duplikat dengan Organik.";
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
        'fu_tmpl' => $row['last_template_name'] ? $row['last_template_name'] : 'Baru',
        'history' => $row['template_history'] ?? ''
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
    <title>CRM Follow-Up | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #334155; }
        
        /* Custom Scrollbar */
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
        
        /* Sidebar Collapse Animation */
        .crm-sidebar { width: 20rem; background-color: #ffffff; border-right: 1px solid #e2e8f0; transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); flex-shrink: 0; }
        .crm-sidebar.collapsed { width: 4.5rem; }
        .crm-sidebar.collapsed .hide-on-collapse { opacity: 0; pointer-events: none; visibility: hidden; transition: opacity 0.2s, visibility 0.2s; white-space: nowrap; width: 0; height: 0; overflow: hidden; margin: 0; padding: 0; }
        .crm-sidebar:not(.collapsed) .hide-on-collapse { opacity: 1; visibility: visible; transition: opacity 0.3s 0.1s ease; }
        .crm-sidebar.collapsed .icon-center { justify-content: center; width: 100%; padding-left: 0; padding-right: 0; }
        .crm-sidebar.collapsed .icon-center i { margin-right: 0 !important; font-size: 1.1rem; }
        .crm-sidebar.collapsed .logo-wrapper { padding: 1.25rem 0; justify-content: center; }
        
        .crm-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05); transition: all 0.2s ease-in-out; }
        .crm-input { background: #f8fafc; border: 1px solid #e2e8f0; color: #334155; border-radius: 8px; transition: all 0.2s; }
        .crm-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); background: #ffffff; }
        
        /* Animations */
        .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .row-animate { transition: background-color 0.2s ease; }
        .row-animate:hover { background-color: #f1f5f9; }
        
        @keyframes fadeInScale { from { opacity: 0; transform: scale(0.97) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .animate-fade-in { animation: fadeInScale 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards; }
        
        /* WhatsApp Chat Style */
        .chat-bg { background-color: #efeae2; background-image: radial-gradient(#cbd5e1 1px, transparent 0); background-size: 20px 20px; }
        .bubble-left { background: #ffffff; border-radius: 0 12px 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); }
        .bubble-right { background: #dcf8c6; border-radius: 12px 0 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); }
        #waPreview { display: none; position: fixed; bottom: 24px; right: 24px; width: 340px; z-index: 50; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; }
        
        .input-group { display: flex; }
        .input-group select { border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: 0; }
        .input-group button { border-top-left-radius: 0; border-bottom-left-radius: 0; }
        
        /* Timeline line history */
        .timeline-line::before { content: ''; position: absolute; left: 11px; top: 20px; bottom: 0; width: 2px; background: #e2e8f0; z-index: 0; }
    </style>
</head>
<body class="overflow-hidden">

<div class="flex h-screen w-full font-sans">
    
    <aside id="mainSidebar" class="crm-sidebar flex flex-col h-full z-20 overflow-y-auto overflow-x-hidden custom-scroll relative">
        <div class="border-b border-slate-100 sticky top-0 bg-white/95 backdrop-blur z-10 flex items-center justify-between logo-wrapper px-5 py-4">
            <div class="flex items-center gap-3 hide-on-collapse">
                <div class="bg-blue-600 text-white w-8 h-8 flex justify-center items-center rounded-lg shadow-sm">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <h1 class="text-base font-bold tracking-tight text-slate-800">CRM Follow-Up</h1>
                </div>
            </div>
            <button onclick="toggleSidebar()" class="text-slate-400 hover:text-blue-600 hover:bg-blue-50 w-8 h-8 rounded-lg flex justify-center items-center transition-colors">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <div class="p-5 space-y-6 flex-1">
            
            <section class="hide-on-collapse">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4 flex items-center"><i class="fas fa-filter mr-2"></i> Filter Data</h3>
                <form method="GET" class="space-y-3">
                    <div>
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Nama/WhatsApp..." class="crm-input w-full pl-8 py-2 text-xs">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-[10px] font-semibold text-slate-500 mb-1 block">Mulai Tanggal</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="crm-input w-full px-2 py-1.5 text-[11px]">
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold text-slate-500 mb-1 block">Sampai Tanggal</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="crm-input w-full px-2 py-1.5 text-[11px]">
                        </div>
                    </div>
                    <div class="pt-1 flex gap-2">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-medium text-xs transition-colors shadow-sm"><i class="fas fa-check mr-1.5"></i>Terapkan</button>
                        <?php if($search || $f_start || $f_end || $f_minat): ?>
                            <a href="pesan.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 py-2 px-3 rounded-lg font-medium text-xs transition-colors text-center" title="Reset Filter"><i class="fas fa-undo"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <hr class="border-slate-100 hide-on-collapse">

            <section>
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center hide-on-collapse"><i class="fas fa-cog mr-2"></i> Manajemen</h3>
                <div class="space-y-2">
                    <a href="manage_templates.php" class="w-full flex items-center icon-center p-2 rounded-lg border border-transparent hover:border-blue-200 hover:bg-blue-50 text-slate-600 hover:text-blue-700 transition-all text-xs font-semibold cursor-pointer group">
                        <i class="fas fa-comment-dots w-5 text-center text-slate-400 group-hover:text-blue-600 mr-2"></i>
                        <span class="hide-on-collapse">Kelola Template</span>
                    </a>
                    <a href="grafik.php" class="w-full flex items-center icon-center p-2 rounded-lg border border-transparent hover:border-blue-200 hover:bg-blue-50 text-slate-600 hover:text-blue-700 transition-all text-xs font-semibold cursor-pointer group">
                        <i class="fas fa-chart-line w-5 text-center text-slate-400 group-hover:text-blue-600 mr-2"></i>
                        <span class="hide-on-collapse">Statistik Data</span>
                    </a>
                    <form method="POST" class="m-0" onsubmit="return confirm('Reset sesi harian? Data tidak dihapus.')">
                        <input type="hidden" name="clear_fu" value="1">
                        <button type="submit" class="w-full flex items-center icon-center p-2 rounded-lg border border-transparent hover:border-rose-200 hover:bg-rose-50 text-slate-600 hover:text-rose-600 transition-all text-xs font-semibold cursor-pointer group">
                            <i class="fas fa-sync-alt w-5 text-center text-slate-400 group-hover:text-rose-600 mr-2"></i>
                            <span class="hide-on-collapse">Reset Sesi Harian</span>
                        </button>
                    </form>
                </div>
            </section>

            <?php if(!empty($organikSudahDichat) || !empty($manualSudahDichat)): ?>
            <section class="mt-2 animate-fade-in hide-on-collapse">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center"><i class="fas fa-check-circle mr-2"></i> Log Selesai</h3>
                <div class="space-y-3">
                    <?php if(!empty($organikSudahDichat)): ?>
                    <div class="border border-emerald-100 bg-emerald-50/50 rounded-lg p-2.5">
                        <div class="flex justify-between items-center mb-1.5">
                            <span class="text-[10px] font-bold text-emerald-700 uppercase">Organik Selesai</span>
                            <span class="bg-emerald-100 text-emerald-700 text-[9px] font-bold px-1.5 py-0.5 rounded"><?= count($organikSudahDichat) ?></span>
                        </div>
                        <div class="max-h-[100px] overflow-y-auto custom-scroll space-y-1">
                            <?php foreach($organikSudahDichat as $r): ?>
                                <div class="text-[10px] text-slate-600 p-1 hover:bg-white rounded border border-transparent hover:border-emerald-100 flex justify-between group cursor-pointer transition-colors" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                    <span class="truncate pr-2 font-medium group-hover:text-emerald-700"><?= $r['nama'] ?></span>
                                    <span class="text-slate-400 text-[9px]"><i class="fas fa-eye opacity-0 group-hover:opacity-100 transition-opacity"></i></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full overflow-hidden bg-slate-50 relative">
        
        <header class="h-[70px] shrink-0 bg-white border-b border-slate-200 px-6 flex items-center justify-between z-10 shadow-sm">
            <h2 class="text-base font-bold text-slate-800 hidden sm:block">Daftar Antrean Pesan</h2>
            <div class="flex gap-2 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0 custom-scroll">
                <button onclick="openModal('modalTambah')" class="shrink-0 bg-white border border-slate-200 text-slate-600 hover:text-blue-600 hover:border-blue-300 hover:bg-blue-50 px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm">
                    <i class="fas fa-plus mr-1"></i> Manual
                </button>
                <button onclick="openModal('modalCSV')" class="shrink-0 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm">
                    <i class="fas fa-file-csv mr-1"></i> Import CSV
                </button>
                <a href="?export_csv_action=1&search=<?=urlencode($search)?>&from=<?=urlencode($f_start)?>&to=<?=urlencode($f_end)?>&minat=<?=urlencode($f_minat)?>" class="shrink-0 bg-slate-800 hover:bg-slate-900 text-white px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm">
                    <i class="fas fa-download mr-1"></i> Export Data
                </a>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-5 lg:p-6 space-y-5 custom-scroll relative">
            
            <div id="loader" class="hidden crm-card p-4 border-l-4 border-l-blue-500 animate-fade-in mb-5 bg-white">
                <div class="flex items-center gap-4">
                    <i class="fas fa-circle-notch fa-spin text-xl text-blue-500"></i>
                    <div class="flex-1">
                        <div class="flex justify-between items-end mb-1">
                            <h3 class="font-bold text-slate-700 text-sm">Sistem Sedang Bekerja...</h3>
                            <p id="progressText" class="text-sm font-bold text-blue-600">0%</p>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-1.5"><div id="progressBar" class="bg-blue-500 h-full rounded-full transition-all duration-300 w-0"></div></div>
                        <p id="progressStatus" class="text-[10px] text-slate-500 mt-1">Mohon tunggu sebentar...</p>
                    </div>
                </div>
            </div>

            <?php if ($notification): ?>
            <div class="crm-card p-3 border-l-4 <?= $notificationType === 'success' ? 'border-l-emerald-500 bg-emerald-50' : ($notificationType === 'warning' ? 'border-l-amber-500 bg-amber-50' : 'border-l-rose-500 bg-rose-50') ?> flex items-center gap-3 text-xs font-semibold animate-fade-in mb-5">
                <i class="fas <?= $notificationType === 'success' ? 'fa-check-circle text-emerald-500' : ($notificationType === 'warning' ? 'fa-exclamation-triangle text-amber-500' : 'fa-times-circle text-rose-500') ?> text-base"></i>
                <span class="text-slate-700"><?= $notification ?></span>
            </div>
            <?php endif; ?>

            <?php if(!empty($statistikMinat)): ?>
            <div class="flex gap-3 overflow-x-auto pb-2 custom-scroll snap-x">
                <?php foreach($statistikMinat as $namaMinat => $jumlah): 
                    $isActive = ($f_minat === $namaMinat);
                ?>
                <a href="?minat=<?= urlencode($namaMinat) ?>" class="crm-card flex-shrink-0 min-w-[150px] p-3 hover-lift snap-center <?= $isActive ? 'border-blue-500 ring-1 ring-blue-500 bg-blue-50/50' : 'hover:border-slate-300' ?> cursor-pointer transition-all">
                    <div class="flex justify-between items-start mb-1">
                        <div class="text-[9px] font-bold text-slate-500 uppercase tracking-wider"><?= htmlspecialchars($namaMinat) ?></div>
                        <?php if($isActive): ?><i class="fas fa-check-circle text-blue-500 text-xs"></i><?php endif; ?>
                    </div>
                    <div class="text-xl font-black text-slate-800"><?= $jumlah ?> <span class="text-[9px] font-semibold text-slate-400">Leads</span></div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl p-4 flex flex-col sm:flex-row items-center justify-between gap-4 border border-blue-100 shadow-sm animate-fade-in relative overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-r from-blue-50/50 to-indigo-50/50"></div>
                <div class="flex items-center gap-3 relative z-10">
                    <div class="bg-blue-100 text-blue-600 w-10 h-10 rounded-full flex items-center justify-center shrink-0"><i class="fas fa-paper-plane"></i></div>
                    <div>
                        <h3 class="font-bold text-sm text-slate-800">Kirim Pesan Massal</h3>
                        <p class="text-[10px] text-slate-500 font-medium"><span id="countCheck" class="font-bold text-blue-600 px-1 bg-white rounded shadow-sm border border-blue-100">0</span> kontak dicentang</p>
                    </div>
                </div>
                <form id="formMassal" class="w-full sm:w-auto flex gap-2 relative z-10">
                    <select name="template_id_multi" onchange="showWA(this)" class="crm-input px-3 py-2 text-xs w-full sm:w-48 cursor-pointer font-medium" required>
                        <option value="">-- Pilih Template --</option>
                        <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" onclick="submitMassAjax(event)" class="bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-lg font-bold text-xs transition-colors shadow-sm whitespace-nowrap">Broadcast</button>
                </form>
            </div>

            <div class="crm-card overflow-hidden animate-fade-in">
                <div class="p-3 border-b border-slate-200 bg-white flex justify-between items-center">
                    <h3 class="font-bold text-xs text-slate-800 flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-emerald-500"></div> Data Organik Baru
                        <span class="bg-slate-100 text-slate-600 text-[9px] px-1.5 py-0.5 rounded-full ml-1"><?= $total_o ?></span>
                    </h3>
                    <label class="text-[10px] font-bold text-slate-600 cursor-pointer flex items-center hover:text-blue-600 transition-colors">
                        <input type="checkbox" id="checkAllOrganik" class="mr-1.5 w-3 h-3 accent-blue-600 rounded">Pilih Semua
                    </label>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">
                        <thead class="text-[10px] text-slate-500 uppercase bg-slate-50/80 border-b border-slate-200">
                            <tr>
                                <th class="p-3 w-8 text-center">#</th>
                                <th class="p-3 font-bold">Data Prospek</th>
                                <th class="p-3 font-bold">Status Follow-Up</th>
                                <th class="p-3 font-bold text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if(empty($targetOrganik_paged)): ?>
                                <tr><td colspan="4" class="p-6 text-center text-slate-400 text-[11px] font-medium">Data organik kosong.</td></tr>
                            <?php else: ?>
                                <?php foreach($targetOrganik_paged as $r): ?>
                                <tr class="row-animate group">
                                    <td class="p-3 text-center">
                                        <input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-organik w-3.5 h-3.5 accent-blue-600 rounded cursor-pointer">
                                    </td>
                                    <td class="p-3">
                                        <div class="font-bold text-slate-800 text-[12px] cursor-pointer hover:text-blue-600 flex items-center gap-1.5" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                            <?= $r['nama'] ?>
                                            <?php if($r['gender'] !== '-'): ?>
                                                <span class="text-[8px] px-1 py-0.5 rounded text-slate-500 border border-slate-200"><?= $r['gender'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[10px] text-slate-500 mt-0.5 flex items-center gap-1.5 font-medium">
                                            <?= $r['nowa'] ?> 
                                            <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 hover:scale-125 transition-transform" title="Buka WhatsApp Web"><i class="fab fa-whatsapp text-sm"></i></a>
                                            <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                                            <span class="text-blue-600"><?= $r['klas'] ?></span>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <div class="status-cell">
                                            <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                                <span class="bg-amber-100 text-amber-700 text-[9px] font-bold px-1.5 py-0.5 rounded">BELUM DIPROSES</span>
                                            <?php else: ?>
                                                <div class="flex items-center gap-2">
                                                    <div>
                                                        <div class="text-[10px] font-bold text-slate-700 template-name-display flex items-center gap-1"><i class="fas fa-check-double text-blue-500"></i> <span class="tmpl-text"><?= $r['fu_tmpl'] ?></span></div>
                                                        <div class="text-[9px] text-slate-400 mt-0.5 tmpl-time"><i class="far fa-clock mr-1"></i><?= $r['fu_text'] ?></div>
                                                    </div>
                                                    <button type="button" class="history-btn bg-slate-100 hover:bg-blue-100 text-slate-500 hover:text-blue-600 px-1.5 py-1 rounded border border-slate-200 transition-colors text-[9px] font-bold flex items-center gap-1" title="Lihat History Template" data-history="<?= htmlspecialchars($r['history'], ENT_QUOTES) ?>" onclick="showHistoryModal(this)">
                                                        <i class="fas fa-list-ul"></i> Detail
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="p-3 text-right">
                                        <div class="flex justify-end gap-1.5 items-center">
                                            <form class="input-group w-36 shadow-sm" onsubmit="submitSingleAjax(event, this)">
                                                <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                <select name="template_id" class="crm-input w-full p-1.5 text-[10px] m-0 border-r-0 focus:ring-0 focus:border-blue-500 cursor-pointer font-medium" required onchange="showWA(this)">
                                                    <option value="">Kirim...</option>
                                                    <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="bg-blue-50 border border-blue-200 border-l-0 text-blue-600 px-2.5 rounded-r hover:bg-blue-600 hover:text-white transition-colors" title="Kirim Pesan"><i class="fas fa-paper-plane text-[9px]"></i></button>
                                            </form>
                                            <form method="POST" class="m-0" onsubmit="return confirm('Hapus prospek organik ini?')">
                                                <input type="hidden" name="delete_prospect" value="1">
                                                <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                <button type="submit" class="bg-white border border-slate-200 text-slate-400 p-1.5 rounded hover:bg-rose-50 hover:text-rose-500 hover:border-rose-200 transition-colors shadow-sm" title="Hapus Data"><i class="fas fa-trash-alt text-[9px]"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages_o > 1): ?>
                <div class="p-2.5 border-t border-slate-100 bg-slate-50 flex justify-between items-center text-[10px] text-slate-500 font-bold">
                    <span>Halaman <?= $page_o ?> dari <?= $pages_o ?></span>
                    <div class="flex gap-1">
                        <?php if($page_o > 1): ?><a href="<?= buildPageUrl('page_o', $page_o-1) ?>" class="bg-white border border-slate-200 px-2 py-1 rounded hover:text-blue-600 transition-colors">Prev</a><?php endif; ?>
                        <?php if($page_o < $pages_o): ?><a href="<?= buildPageUrl('page_o', $page_o+1) ?>" class="bg-white border border-slate-200 px-2 py-1 rounded hover:text-blue-600 transition-colors">Next</a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="crm-card overflow-hidden animate-fade-in" style="animation-delay: 0.1s;">
                <div class="p-3 border-b border-slate-200 bg-white flex justify-between items-center">
                    <h3 class="font-bold text-xs text-slate-800 flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-amber-500"></div> Data Manual & CSV
                        <span class="bg-slate-100 text-slate-600 text-[9px] px-1.5 py-0.5 rounded-full ml-1"><?= $total_m ?></span>
                    </h3>
                    <div class="flex items-center gap-3">
                        <form method="POST" onsubmit="return confirm('Peringatan: Menghapus seluruh antrean Manual/CSV?')" class="m-0">
                            <input type="hidden" name="hapus_semua_manual" value="1">
                            <button type="submit" class="text-rose-500 hover:text-rose-700 text-[10px] font-bold flex items-center transition-colors">
                                <i class="fas fa-trash mr-1"></i>Hapus Semua
                            </button>
                        </form>
                        <div class="w-px h-3 bg-slate-200"></div>
                        <label class="text-[10px] font-bold text-slate-600 cursor-pointer flex items-center hover:text-amber-600 transition-colors">
                            <input type="checkbox" id="checkAllManual" class="mr-1.5 w-3 h-3 accent-amber-500 rounded">Pilih Semua
                        </label>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">
                        <thead class="text-[10px] text-slate-500 uppercase bg-slate-50/80 border-b border-slate-200">
                            <tr>
                                <th class="p-3 w-8 text-center">#</th>
                                <th class="p-3 font-bold">Data Prospek</th>
                                <th class="p-3 font-bold">Status Follow-Up</th>
                                <th class="p-3 font-bold text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if(empty($targetManual_paged)): ?>
                                <tr><td colspan="4" class="p-6 text-center text-slate-400 text-[11px] font-medium">Belum ada data manual/CSV.</td></tr>
                            <?php else: ?>
                                <?php foreach($targetManual_paged as $r): ?>
                                <tr class="row-animate group">
                                    <td class="p-3 text-center">
                                        <input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-manual w-3.5 h-3.5 accent-amber-500 rounded cursor-pointer">
                                    </td>
                                    <td class="p-3">
                                        <div class="font-bold text-slate-800 text-[12px] cursor-pointer hover:text-amber-600 transition-colors" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                            <?= $r['nama'] ?>
                                        </div>
                                        <div class="text-[10px] text-slate-500 mt-0.5 flex items-center gap-1.5 font-medium">
                                            <?= $r['nowa'] ?> 
                                            <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 hover:scale-125 transition-transform"><i class="fab fa-whatsapp text-sm"></i></a>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <div class="status-cell">
                                            <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                                <span class="bg-amber-100 text-amber-700 text-[9px] font-bold px-1.5 py-0.5 rounded">BELUM DIPROSES</span>
                                            <?php else: ?>
                                                <div class="flex items-center gap-2">
                                                    <div>
                                                        <div class="text-[10px] font-bold text-slate-700 template-name-display flex items-center gap-1"><i class="fas fa-check-double text-amber-500"></i> <span class="tmpl-text"><?= $r['fu_tmpl'] ?></span></div>
                                                        <div class="text-[9px] text-slate-400 mt-0.5 tmpl-time"><i class="far fa-clock mr-1"></i><?= $r['fu_text'] ?></div>
                                                    </div>
                                                    <button type="button" class="history-btn bg-slate-100 hover:bg-amber-100 text-slate-500 hover:text-amber-600 px-1.5 py-1 rounded border border-slate-200 transition-colors text-[9px] font-bold flex items-center gap-1" title="Lihat History Template" data-history="<?= htmlspecialchars($r['history'], ENT_QUOTES) ?>" onclick="showHistoryModal(this)">
                                                        <i class="fas fa-list-ul"></i> Detail
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="p-3 text-right">
                                        <div class="flex justify-end gap-1.5 items-center">
                                            <form class="input-group w-36 shadow-sm" onsubmit="submitSingleAjax(event, this)">
                                                <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                <select name="template_id" class="crm-input w-full p-1.5 text-[10px] m-0 border-r-0 focus:ring-0 focus:border-amber-500 cursor-pointer font-medium" required onchange="showWA(this)">
                                                    <option value="">Kirim...</option>
                                                    <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="bg-amber-50 border border-amber-200 border-l-0 text-amber-600 px-2.5 rounded-r hover:bg-amber-500 hover:text-white transition-colors" title="Kirim Pesan"><i class="fas fa-paper-plane text-[9px]"></i></button>
                                            </form>
                                            <form method="POST" class="m-0" onsubmit="return confirm('Hapus data manual ini?')">
                                                <input type="hidden" name="delete_prospect" value="1">
                                                <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                <button type="submit" class="bg-white border border-slate-200 text-slate-400 p-1.5 rounded hover:bg-rose-50 hover:text-rose-500 hover:border-rose-200 transition-colors shadow-sm" title="Hapus Data"><i class="fas fa-trash-alt text-[9px]"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages_m > 1): ?>
                <div class="p-2.5 border-t border-slate-100 bg-slate-50 flex justify-between items-center text-[10px] text-slate-500 font-bold">
                    <span>Halaman <?= $page_m ?> dari <?= $pages_m ?></span>
                    <div class="flex gap-1">
                        <?php if($page_m > 1): ?><a href="<?= buildPageUrl('page_m', $page_m-1) ?>" class="bg-white border border-slate-200 px-2 py-1 rounded hover:text-amber-600 transition-colors">Prev</a><?php endif; ?>
                        <?php if($page_m < $pages_m): ?><a href="<?= buildPageUrl('page_m', $page_m+1) ?>" class="bg-white border border-slate-200 px-2 py-1 rounded hover:text-amber-600 transition-colors">Next</a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="h-10"></div>
        </div>
    </main>
</div>

<div id="modalHistory" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in border border-slate-100">
        <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h3 class="font-bold text-slate-800 text-sm flex items-center"><i class="fas fa-history text-blue-500 mr-2"></i> Riwayat Follow-Up</h3>
            <button type="button" onclick="closeModal('modalHistory')" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-5 max-h-[60vh] overflow-y-auto custom-scroll relative">
            <div id="historyContent" class="space-y-4 timeline-line relative pl-3">
                </div>
        </div>
        <div class="p-3 border-t border-slate-100 bg-slate-50 text-right">
            <button onclick="closeModal('modalHistory')" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-1.5 rounded-lg text-xs font-bold transition-colors">Tutup</button>
        </div>
    </div>
</div>

<div id="waPreview" class="animate-fade-in">
    <div class="bg-[#075e54] text-white p-2.5 flex items-center justify-between">
        <div class="flex items-center gap-2"><i class="fas fa-eye text-xs"></i> <p class="text-xs font-semibold tracking-wide">Live Preview</p></div>
        <button onclick="closeWA()" class="opacity-70 hover:opacity-100 p-1 transition-opacity"><i class="fas fa-times"></i></button>
    </div>
    <div class="chat-bg p-4 h-48 overflow-y-auto custom-scroll shadow-inner relative">
        <div class="bubble-left p-3 text-[12px] text-[#111b21] mb-2 inline-block whitespace-pre-wrap leading-relaxed border border-white/50" id="waText">...</div>
    </div>
</div>

<div id="modalTambah" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h3 class="font-bold text-slate-800 text-sm"><i class="fas fa-user-plus text-blue-500 mr-2"></i> Tambah Prospek</h3>
            <button type="button" onclick="closeModal('modalTambah')" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <div>
                <label class="text-[11px] font-bold text-slate-500 uppercase mb-1 block">Nama Lengkap</label>
                <input type="text" name="nama_baru" required class="crm-input w-full p-2.5 text-xs" placeholder="Contoh: Budi Santoso">
            </div>
            <div>
                <label class="text-[11px] font-bold text-slate-500 uppercase mb-1 block">WhatsApp</label>
                <input type="number" name="nowa_baru" required class="crm-input w-full p-2.5 text-xs" placeholder="Awali dengan 08 / 62">
            </div>
            <button type="submit" name="tambah_prospek" class="w-full bg-blue-600 text-white py-2.5 rounded-lg font-bold text-xs hover:bg-blue-700 transition-colors shadow-sm mt-2">Simpan Data</button>
        </form>
    </div>
</div>

<div id="modalCSV" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h3 class="font-bold text-slate-800 text-sm"><i class="fas fa-file-csv text-blue-500 mr-2"></i> Import File CSV</h3>
            <button type="button" onclick="closeModal('modalCSV')" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-5 space-y-4" onsubmit="showManualLoading()">
            <div class="bg-blue-50/80 p-3 rounded-lg border border-blue-100 text-[10px] text-blue-700 font-medium">
                <i class="fas fa-info-circle mr-1"></i> Kolom 1 = <b>Nama</b>, Kolom 2 = <b>WhatsApp</b>
            </div>
            <input type="file" name="file_csv" accept=".csv" required class="w-full p-4 border-2 border-dashed border-slate-300 bg-slate-50 rounded-lg text-xs cursor-pointer hover:bg-slate-100 transition-colors focus:outline-none focus:border-blue-400">
            <button type="submit" name="upload_csv" class="w-full bg-blue-600 text-white py-2.5 rounded-lg font-bold text-xs hover:bg-blue-700 transition-colors shadow-sm mt-2">Upload Sekarang</button>
        </form>
    </div>
</div>

<div id="modalDetailChat" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl w-full max-w-md overflow-hidden shadow-2xl animate-fade-in flex flex-col h-[75vh] max-h-[600px]">
        <div class="p-3 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center"><i class="fab fa-whatsapp"></i></div>
                <h3 class="font-bold text-[13px] text-slate-800">Review Percakapan</h3>
            </div>
            <button type="button" onclick="closeModal('modalDetailChat')" class="w-7 h-7 rounded-full bg-slate-200 text-slate-500 hover:bg-rose-100 hover:text-rose-500 flex items-center justify-center transition-colors"><i class="fas fa-times"></i></button>
        </div>
        <div class="chat-bg flex-1 p-4 overflow-y-auto custom-scroll flex flex-col gap-4 shadow-inner" id="detailChatContent"></div>
    </div>
</div>

<script>
// PENGATURAN SMART SCROLL (Agar tidak refresh ke atas)
document.addEventListener("DOMContentLoaded", function() { 
    let scrollpos = sessionStorage.getItem('scrollpos');
    if (scrollpos) { window.scrollTo(0, scrollpos); sessionStorage.removeItem('scrollpos'); }
});
window.addEventListener("beforeunload", function() { sessionStorage.setItem('scrollpos', window.scrollY); });

const templates = <?= json_encode($jsTemplates) ?>;

function toggleSidebar() { document.getElementById('mainSidebar').classList.toggle('collapsed'); }

function showWA(s) { 
    const p = document.getElementById('waPreview'), t = document.getElementById('waText'); 
    if(s.value && templates[s.value]) { t.innerText = templates[s.value].replace(/\[nama\]|\{nama\}/gi, '[Nama Prospek]'); p.style.display = 'block'; } 
    else { p.style.display = 'none'; }
}

function showDetail(userMsg, sysMsg) {
    if (!userMsg || userMsg.trim() === '') userMsg = '(Format Manual / Pesan kosong)';
    const formatMsg = (str) => str.replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
    let html = `
    <div class="flex items-end gap-2 pr-8 animate-fade-in">
        <div class="w-6 h-6 rounded-full bg-slate-300 border border-white flex items-center justify-center shrink-0 mb-1"><i class="fas fa-user text-white text-[10px]"></i></div>
        <div class="bubble-left p-2.5 text-[12px] text-[#111b21] relative leading-relaxed">
            <div class="text-[9px] text-slate-500 font-bold mb-1 opacity-70">Pesan User:</div>${formatMsg(userMsg)}
        </div>
    </div>`;
    if (sysMsg && sysMsg.trim() !== '') {
        html += `
        <div class="flex items-end gap-2 pl-8 flex-row-reverse animate-fade-in" style="animation-delay: 0.1s">
            <div class="w-6 h-6 rounded-full bg-blue-500 border border-white flex items-center justify-center shrink-0 mb-1"><i class="fas fa-robot text-white text-[10px]"></i></div>
            <div class="bubble-right p-2.5 text-[12px] text-[#111b21] relative leading-relaxed">
                <div class="text-[9px] text-blue-700 font-bold mb-1 border-b border-blue-200/40 pb-1 flex justify-between items-center"><span>Sistem:</span><i class="fas fa-check-double text-blue-500"></i></div>${formatMsg(sysMsg)}
            </div>
        </div>`;
    }
    document.getElementById('detailChatContent').innerHTML = html; openModal('modalDetailChat');
}

function showHistoryModal(btn) {
    const rawHist = btn.dataset.history;
    const content = document.getElementById('historyContent');
    if(!rawHist) { content.innerHTML = '<div class="text-xs text-slate-400 italic">Belum ada riwayat follow-up sistem.</div>'; } 
    else {
        let items = rawHist.split('|||'); let html = '';
        items.forEach((item, index) => {
            let parts = item.split(' - '); let time = parts[0] || '?'; let tmpl = parts[1] || 'Unknown';
            html += `
            <div class="relative z-10 animate-fade-in" style="animation-delay: ${index * 0.05}s">
                <div class="absolute -left-3.5 top-1.5 w-3 h-3 bg-blue-500 border-2 border-white rounded-full shadow-sm"></div>
                <div class="bg-white border border-slate-200 p-2.5 rounded-lg shadow-sm">
                    <div class="text-[9px] font-bold text-slate-400 mb-0.5"><i class="far fa-clock mr-1"></i>${time}</div>
                    <div class="text-[11px] font-bold text-slate-700"><i class="fas fa-paper-plane text-blue-500 mr-1 text-[10px]"></i> ${tmpl}</div>
                </div>
            </div>`;
        });
        content.innerHTML = html;
    }
    openModal('modalHistory');
}

function closeWA() { document.getElementById('waPreview').style.display = 'none'; }
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function showManualLoading() { document.getElementById('loader').classList.remove('hidden'); document.getElementById('progressStatus').innerText = "Memproses impor data CSV..."; }

function updateCount() { document.getElementById('countCheck').innerText = document.querySelectorAll('.cb-target:checked').length; }
document.getElementById('checkAllOrganik')?.addEventListener('change', function() { document.querySelectorAll('.cb-organik').forEach(c => c.checked = this.checked); updateCount(); });
document.getElementById('checkAllManual')?.addEventListener('change', function() { document.querySelectorAll('.cb-manual').forEach(c => c.checked = this.checked); updateCount(); });
document.querySelectorAll('.cb-target').forEach(c => c.addEventListener('change', updateCount));

async function submitMassAjax(e) { 
    e.preventDefault();
    const sel = document.querySelectorAll('.cb-target:checked'); const tmplId = document.querySelector('select[name="template_id_multi"]').value;
    if(!sel.length || !tmplId) { alert('Pilih minimal 1 prospek dan 1 template!'); return; } 
    const loader = document.getElementById('loader'); const pBar = document.getElementById('progressBar'); const pText = document.getElementById('progressText'); const pStat = document.getElementById('progressStatus');
    loader.classList.remove('hidden');
    let success = 0, fail = 0; let total = sel.length;
    for (let i = 0; i < total; i++) {
        let contactId = sel[i].value; pStat.innerText = `Menghubungkan ke API... (${contactId}) [${i+1}/${total}]`;
        try {
            let fd = new FormData(); fd.append('ajax_send', '1'); fd.append('contact_id', contactId); fd.append('template_id', tmplId);
            let res = await fetch('', { method: 'POST', body: fd }); let json = await res.json();
            if(json.status === 'success') success++; else fail++;
        } catch(err) { fail++; }
        let pct = Math.round(((i + 1) / total) * 100); pBar.style.width = pct + '%'; pText.innerText = pct + '%';
        await new Promise(r => setTimeout(r, 100)); 
    }
    sessionStorage.setItem('scrollpos', window.scrollY); // Simpan scroll massal
    pStat.innerHTML = `<span class="text-emerald-600 font-bold">Selesai!</span> ${success} Terkirim, ${fail} Gagal. Merefresh status...`;
    setTimeout(() => location.reload(), 1000);
}

// KIRIM SATUAN TANPA REFRESH (FULL DOM MANIPULATION)
async function submitSingleAjax(e, form) {
    e.preventDefault();
    const contactId = form.querySelector('input[name="contact_id"]').value;
    const tmplId = form.querySelector('select[name="template_id"]').value;
    if(!tmplId) { alert('Pilih template dahulu!'); return; }
    
    const btn = form.querySelector('button[type="submit"]');
    const oriHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin text-[10px]"></i>'; btn.disabled = true;
    
    try {
        let fd = new FormData(); fd.append('ajax_send', '1'); fd.append('contact_id', contactId); fd.append('template_id', tmplId);
        let res = await fetch('', { method: 'POST', body: fd }); let json = await res.json();
        
        if(json.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check text-[10px]"></i>';
            btn.classList.remove('bg-blue-50', 'bg-amber-50', 'text-blue-600', 'text-amber-600');
            btn.classList.add('bg-emerald-500', 'text-white', 'border-emerald-600');
            
            // Update Tampilan Baris Langsung Tanpa Refresh
            const tr = form.closest('tr');
            let statusCell = tr.querySelector('.status-cell');
            if(statusCell) {
                let badgeRaw = statusCell.querySelector('span.bg-amber-100');
                if(badgeRaw) {
                    statusCell.innerHTML = `
                        <div class="flex items-center gap-2">
                            <div>
                                <div class="text-[10px] font-bold text-slate-700 template-name-display flex items-center gap-1"><i class="fas fa-check-double text-blue-500"></i> <span class="tmpl-text">${json.fu_tmpl}</span></div>
                                <div class="text-[9px] text-slate-400 mt-0.5 tmpl-time"><i class="far fa-clock mr-1"></i>${json.fu_time}</div>
                            </div>
                            <button type="button" class="history-btn bg-slate-100 text-slate-500 px-1.5 py-1 rounded border border-slate-200 text-[9px] font-bold flex items-center gap-1" onclick="showHistoryModal(this)" data-history="${json.new_history}">
                                <i class="fas fa-list-ul"></i> Detail
                            </button>
                        </div>
                    `;
                } else {
                    tr.querySelector('.tmpl-text').innerText = json.fu_tmpl;
                    tr.querySelector('.tmpl-time').innerHTML = `<i class="far fa-clock mr-1"></i>${json.fu_time}`;
                    let historyBtn = tr.querySelector('.history-btn');
                    if(historyBtn) historyBtn.dataset.history = json.new_history;
                }
            }
            setTimeout(() => { btn.innerHTML = oriHtml; btn.disabled = false; }, 2000);
        } else {
            alert('Gagal Terkirim: ' + json.msg); btn.innerHTML = oriHtml; btn.disabled = false;
        }
    } catch(err) {
        alert('Kesalahan jaringan.'); btn.innerHTML = oriHtml; btn.disabled = false;
    }
}

if (window.self !== window.top) { document.body.style.backgroundColor = "transparent"; }
</script>
</body>
</html>