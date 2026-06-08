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
        
        // EKSTRAK NAMA DARI DB & MESSAGE UNTUK REPLACE TEMPLATE
        $stN = $conn->prepare("SELECT nama, message FROM log_wa WHERE nowa = ? LIMIT 1");
        $stN->bind_param("s", $cid); $stN->execute(); 
        $dbRow = $stN->get_result()->fetch_assoc();
        
        $nama = trim($dbRow['nama'] ?? 'Kak');
        $msgText = $dbRow['message'] ?? '';
        
        // Deteksi nama di teks pesan
        if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $msgText, $m)) {
            $nama = trim($m[1]);
        } elseif (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $msgText, $m)) {
            $nama = trim($m[1]);
        }
        if (empty($nama) || strtolower($nama) == 'kak') $nama = 'Kak';

        // Ganti [nama], [NAMA], {nama}, {NAMA}
        $pesan = str_ireplace(['[nama]', '[NAMA]', '{nama}', '{NAMA}'], $nama, $msgTmpl);
        $pesan = str_replace('  ', ' ', $pesan); // Rapikan spasi
        
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

// AMBIL DAFTAR HITAM
$disqualified = getDisqualifiedNumbers($conn);
$blocked = getBlockedNumbers($conn);

// --- 2. FUNGSI POST BIASA (PROTEKSI INPUT) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_send'])) {
    if (isset($_POST['tambah_prospek'])) {
        $n = preg_replace('/\D/', '', $_POST['nowa_baru']); if (strpos($n, '0') === 0) $n = '62' . substr($n, 1);
        if (isset($disqualified[$n]) || isset($blocked[$n])) {
            $_SESSION['notification'] = "❌ Gagal: Nomor sudah terdaftar / diblokir!"; $_SESSION['notificationType'] = 'error';
        } elseif (isAlreadyInOrganic($conn, $_POST['nowa_baru'])) {
            $_SESSION['notification'] = "⚠️ Nomor sudah ada di Daftar Organik. Manual dibatalkan."; $_SESSION['notificationType'] = 'warning';
        } else {
            $conn->query("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES ('$n', '{$_POST['nama_baru']}', 'Data CSV/Manual', NOW())");
            $_SESSION['notification'] = "✅ Prospek manual ditambahkan!"; $_SESSION['notificationType'] = 'success';
        }
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['upload_csv']) && isset($_FILES['file_csv'])) {
        set_time_limit(0); 
        ini_set('memory_limit', '256M'); 

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

    if (isset($_POST['delete_prospect'])) { $conn->query("DELETE FROM log_wa WHERE nowa = '{$_POST['contact_id']}'"); header("Location: pesan.php"); exit; }
    if (isset($_POST['update_block_status'])) {
        if ($_POST['current_status'] === 'unblock') $conn->query("DELETE FROM blocked_peserta WHERE nowa = '{$_POST['contact_id']}'");
        else $conn->query("INSERT IGNORE INTO blocked_peserta (nowa) VALUES ('{$_POST['contact_id']}')");
        header("Location: pesan.php"); exit;
    }
    if (isset($_POST['hapus_semua_blokir'])) { $conn->query("TRUNCATE TABLE blocked_peserta"); header("Location: pesan.php"); exit; }
    if (isset($_POST['clear_fu'])) { $_SESSION['followed_up_today'] = []; header("Location: pesan.php"); exit; }
}

// ==================================================================
// DATA FETCHING & FILTER (DENGAN AUTO-EKSTRAK NAMA & GENDER)
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

    // === EKSTRAKSI NAMA DAN GENDER OTOMATIS ===
    $namaDb = trim($row['nama']);
    $msgRaw = $row['message'] ?? '';
    $extractedName = '';
    $extractedGender = '-';

    // Cari Nama
    if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $msgRaw, $m)) {
        $extractedName = trim($m[1]);
    } elseif (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $msgRaw, $m)) {
        $extractedName = trim($m[1]);
    }

    // Cari Gender
    if (preg_match('/\((ikhwan|akhwat|laki-laki|perempuan|laki|pr)\)/i', $msgRaw, $m)) {
        $extractedGender = ucfirst(strtolower(trim($m[1])));
    }

    $finalName = !empty($extractedName) ? $extractedName : (!empty($namaDb) ? $namaDb : 'Hamba Allah');

    $data = [
        'id' => $row['id'],
        'nowa' => $row['nowa'], 
        'clean_wa' => $n_log, 
        'nama' => $finalName, 
        'gender' => $extractedGender,
        'klas' => $klas,
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

// EXPORT CSV LENGKAP
if (isset($_GET['export_csv_action'])) {
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename=Prospek_Filtered.csv');
    $output = fopen('php://output', 'w'); 
    fputcsv($output, ['Nama', 'Gender', 'Nomor WA', 'Minat', 'Tgl Follow-up Terakhir', 'Template Terakhir']);
    
    // Gabungkan Organik dan Manual untuk export
    $allExportData = array_merge($targetOrganik, $targetManual);
    foreach($allExportData as $r) { 
        fputcsv($output, [$r['nama'], $r['gender'], $r['nowa'], $r['klas'], $r['fu_text'], $r['fu_tmpl']]); 
    }
    fclose($output); exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Dashboard | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; color: #1e293b; }
        .custom-scroll { max-height: 400px; overflow-y: auto; scrollbar-width: thin; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .vibrant-card { border-radius: 28px; box-shadow: 0 15px 35px -5px rgba(0,0,0,0.03); border: 1px solid transparent; }
        #waPreview { display: none; position: fixed; bottom: 20px; right: 20px; width: 320px; z-index: 100; border-radius: 16px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.25); border: 2px solid transparent; }
        @keyframes fadeInDownBanner { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeInDownBanner { animation: fadeInDownBanner 0.4s ease-out forwards; }
        .wa-body { background: #efeae2; padding: 15px; background-image: url('https://w0.peakpx.com/wallpaper/818/148/HD-wallpaper-whatsapp-background-solid-color-backgrounds-whatsapp.jpg'); background-blend-mode: soft-light; }
        .wa-bubble { background: #ffffff; padding: 10px 12px; border-radius: 0 10px 10px 10px; font-size: 13px; color: #111b21; box-shadow: 0 1px 1px rgba(0,0,0,0.1); }
        .btn-glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: 16px; transition: all 0.3s ease; }
        .btn-glass:hover { background: rgba(255, 255, 255, 0.9); transform: translateY(-2px); }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-[1500px] mx-auto relative">
    
    <header class="bg-transparent text-slate-800 py-6 px-4 rounded-3xl mb-6 flex flex-wrap justify-between items-center gap-4 relative overflow-hidden">
        <div class="flex items-center gap-4 relative z-10">
            <div class="bg-white/70 backdrop-blur-md p-4 rounded-3xl border border-slate-200 shadow-lg text-blue-600">
                <i class="fas fa-rocket text-2xl"></i>
            </div>
            <div>
                <h1 class="text-3xl font-black tracking-tighter text-slate-900">Yuk Follow Up!</h1>
                <p class="text-[11px] text-slate-500 uppercase tracking-widest font-semibold mt-1">Nanti customer kabur..</p>
            </div>
        </div>
        
        <div class="flex flex-wrap gap-2.5 relative z-10">
            <a href="grafik.php" class="btn-glass px-5 py-3 text-sm font-bold flex items-center text-blue-600">
                <i class="fas fa-chart-bar mr-2 text-base"></i>Lihat Grafik
            </a>
            <button onclick="openModal('modalTambah')" class="btn-glass px-5 py-3 text-sm font-bold flex items-center text-emerald-600">
                <i class="fas fa-user-plus mr-2 text-base"></i>Manual
            </button>
            <button onclick="openModal('modalCSV')" class="btn-glass btn-glass-primary px-5 py-3 text-sm font-bold flex items-center bg-blue-600 text-white">
                <i class="fas fa-file-csv mr-2 text-base"></i>Upload CSV
            </button>
            <a href="?export_csv_action=1&search=<?=urlencode($search)?>&from=<?=urlencode($f_start)?>&to=<?=urlencode($f_end)?>&minat=<?=urlencode($f_minat)?>" class="btn-glass px-5 py-3 text-sm font-bold flex items-center bg-indigo-600 text-white">
                <i class="fas fa-download mr-2 text-base"></i>Export Terfilter
            </a>
            <a href="wa.php?logout=true" onclick="return confirm('Apakah Anda yakin ingin keluar?')" class="btn-glass px-5 py-3 text-sm font-bold flex items-center text-rose-600 border-rose-200">
                <i class="fas fa-sign-out-alt mr-2 text-base"></i>Logout
            </a>
        </div>
    </header>

    <!-- BANNER INLINE LOADER -->
    <div id="loader" class="hidden animate-fadeInDownBanner mb-8 bg-white vibrant-card overflow-hidden relative border border-blue-100">
        <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500"></div>
        <div class="p-6 flex flex-col sm:flex-row items-center gap-6">
            <div class="bg-blue-50 w-16 h-16 rounded-[20px] flex items-center justify-center shrink-0 border border-blue-100">
                <i class="fas fa-paper-plane text-3xl text-blue-500 animate-bounce"></i>
            </div>
            <div class="flex-1 w-full">
                <div class="flex justify-between items-end mb-3">
                    <div>
                        <h3 class="font-extrabold text-slate-800 text-lg">Proses Pengiriman Berjalan...</h3>
                        <p id="progressStatus" class="text-xs font-medium text-slate-500 mt-1">Mempersiapkan data dan menautkan ke server API...</p>
                    </div>
                    <p id="progressText" class="text-2xl font-black text-blue-600">0%</p>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden relative">
                    <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-purple-600 h-full rounded-full transition-all duration-300 w-0"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($notification): ?>
    <div class="mb-6 p-4 rounded-xl border <?= $notificationType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : ($notificationType === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-rose-50 border-rose-200 text-rose-800') ?> flex items-center gap-3 font-semibold shadow-sm">
        <i class="fas <?= $notificationType === 'success' ? 'fa-check-circle text-emerald-500' : ($notificationType === 'warning' ? 'fa-exclamation-triangle text-amber-500' : 'fa-times-circle text-rose-500') ?> text-xl"></i> <?= $notification ?>
    </div>
    <?php endif; ?>

    <!-- CARD STATISTIK MINAT -->
    <?php if(!empty($statistikMinat)): 
        $colors = ['bg-blue-500', 'bg-emerald-500', 'bg-orange-500', 'bg-purple-500', 'bg-rose-500', 'bg-indigo-500'];
        $i = 0;
    ?>
    <div class="mb-6">
        <div class="flex justify-between items-end mb-3">
            <?php if($f_minat): ?>
            <a href="pesan.php" class="text-[10px] bg-rose-100 text-rose-600 px-3 py-1 rounded-full font-bold hover:bg-rose-200 transition-colors"><i class="fas fa-times mr-1"></i> Hapus Filter</a>
            <?php endif; ?>
        </div>
        <div class="flex gap-4 overflow-x-auto pb-4 custom-scroll snap-x">
            <?php foreach($statistikMinat as $namaMinat => $jumlah): 
                $c = $colors[$i % count($colors)]; $i++;
                $isActive = ($f_minat === $namaMinat) ? 'ring-4 ring-blue-200 ring-offset-2 scale-105 shadow-xl' : 'hover:-translate-y-1 hover:shadow-lg opacity-95 hover:opacity-100';
            ?>
            <a href="?minat=<?= urlencode($namaMinat) ?>" class="<?= $c ?> rounded-[22px] flex-shrink-0 min-w-[170px] block transition-all shadow-md cursor-pointer text-white snap-center <?= $isActive ?>" title="Saring data <?= $namaMinat ?>">
                <div class="px-6 py-5 h-full flex flex-col justify-between">
                    <div class="text-[10px] font-bold uppercase tracking-widest mb-2 text-white/90 truncate"><?= htmlspecialchars($namaMinat) ?></div>
                    <div class="text-4xl font-extrabold drop-shadow-sm"><?= $jumlah ?> <span class="text-xs font-semibold opacity-80 tracking-normal">Orang</span></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <!-- =============== KOLOM KIRI (SIDEBAR) =============== -->
        <div class="xl:col-span-3 space-y-6">
            
            <div class="vibrant-card p-6 bg-white">
                <h3 class="font-extrabold text-slate-800 mb-5 flex items-center gap-2 text-sm"><i class="fas fa-search text-indigo-500 bg-indigo-50 p-2.5 rounded-xl"></i> Filter Prospek</h3>
                <form method="GET" class="space-y-4">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Nama / Nomor..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-200 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="text-[11px] p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                        <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="text-[11px] p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                    </div>
                    <button type="submit" class="w-full bg-slate-800 text-white py-3 rounded-xl font-bold text-sm shadow-md transition-all">Terapkan</button>
                    <a href="pesan.php" class="block text-center text-xs text-slate-400 hover:underline">Reset Form</a>
                </form>
            </div>
            
            <div class="vibrant-card p-6 bg-white border-rose-100 bg-rose-50/20">
                <h3 class="font-extrabold text-rose-600 mb-4 flex items-center gap-2 text-sm"><i class="fas fa-shield-alt text-rose-500 bg-rose-100 p-2 rounded-lg"></i> Zona Manajemen</h3>
                <div class="space-y-2.5">
                    <form method="POST" onsubmit="return confirm('Kosongkan histori follow-up hari ini?')"><input type="hidden" name="clear_fu" value="1"><button type="submit" class="w-full bg-white hover:bg-rose-50 text-slate-600 hover:text-rose-600 py-2.5 rounded-xl text-xs font-bold border border-slate-200 transition-all shadow-sm"><i class="fas fa-sync-alt mr-1"></i> Reset Histori Harian</button></form>
                    <a href="manage_templates.php" class="block text-center w-full bg-white text-blue-600 py-2.5 rounded-xl text-xs font-bold border border-slate-200 hover:border-blue-200 hover:bg-blue-50 transition-all shadow-sm"><i class="fas fa-comment-dots mr-1"></i> Kelola Template</a>
                </div>
            </div>

            <!-- === KOTAK SELESAI HARI INI === -->
            <?php if(!empty($organikSudahDichat) || !empty($manualSudahDichat)): ?>
            <div class="flex flex-col gap-6">
                <!-- ORGANIK SELESAI -->
                <?php if(!empty($organikSudahDichat)): ?>
                <div class="bg-slate-950 rounded-[24px] shadow-lg overflow-hidden border border-slate-800">
                    <div class="p-4 border-b border-slate-800 flex justify-between items-center bg-black/20">
                        <h3 class="font-extrabold text-[10px] text-emerald-400 uppercase tracking-widest"><i class="fas fa-check-circle mr-1.5"></i> Selesai Organik</h3>
                        <span class="text-[9px] font-bold text-slate-300 bg-slate-800 px-2 py-0.5 rounded-full"><?= count($organikSudahDichat) ?></span>
                    </div>
                    <div class="max-h-[300px] overflow-y-auto custom-scroll">
                        <table class="w-full text-left text-xs">
                            <tbody class="divide-y divide-slate-800/40">
                                <?php foreach($organikSudahDichat as $r): ?>
                                <tr class="hover:bg-slate-800/60 bg-transparent text-white block p-4">
                                    <td class="block w-full mb-3">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <div class="font-bold text-[12px] text-emerald-100">
                                                    <?= $r['nama'] ?>
                                                    <?php if($r['gender'] !== '-'): ?>
                                                        <span class="ml-1 text-[9px] px-1.5 py-0.5 rounded-md <?= strtolower($r['gender']) == 'ikhwan' || strtolower($r['gender']) == 'laki-laki' ? 'bg-blue-900 text-blue-200' : 'bg-pink-900 text-pink-200' ?>"><?= $r['gender'] ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-[9px] text-slate-400 font-mono mt-0.5"><?= $r['nowa'] ?></div>
                                            </div>
                                        </div>
                                        <div class="text-[9px] text-emerald-500/80 mt-2 border border-emerald-900 bg-emerald-950/30 inline-block px-1.5 py-0.5 rounded"><i class="fas fa-history mr-1"></i><?= $r['fu_tmpl'] ?></div>
                                    </td>
                                    <td class="block w-full">
                                        <form class="flex gap-1.5 w-full" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="bg-slate-800 border border-slate-700 rounded-lg p-1.5 text-[9px] text-slate-300 save-template flex-1" required>
                                                <option value="">Chat Ulang...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-slate-700 text-white px-3 py-1.5 rounded-lg text-[9px] font-bold hover:bg-emerald-600 transition-all"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- MANUAL SELESAI -->
                <?php if(!empty($manualSudahDichat)): ?>
                <div class="bg-slate-900 rounded-[24px] shadow-lg overflow-hidden border border-slate-700">
                    <div class="p-4 border-b border-slate-700 flex justify-between items-center bg-black/20">
                        <h3 class="font-extrabold text-[10px] text-amber-400 uppercase tracking-widest"><i class="fas fa-check-double mr-1.5"></i> Selesai Manual</h3>
                        <span class="text-[9px] font-bold text-slate-300 bg-slate-800 px-2 py-0.5 rounded-full"><?= count($manualSudahDichat) ?></span>
                    </div>
                    <div class="max-h-[300px] overflow-y-auto custom-scroll">
                        <table class="w-full text-left text-xs">
                            <tbody class="divide-y divide-slate-700/40">
                                <?php foreach($manualSudahDichat as $r): ?>
                                <tr class="hover:bg-slate-800/60 bg-transparent text-white block p-4">
                                    <td class="block w-full mb-3">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <div class="font-bold text-[12px] text-amber-100"><?= $r['nama'] ?></div>
                                                <div class="text-[9px] text-slate-400 font-mono mt-0.5"><?= $r['nowa'] ?></div>
                                            </div>
                                        </div>
                                        <div class="text-[9px] text-amber-500/80 mt-2 border border-amber-900 bg-amber-950/30 inline-block px-1.5 py-0.5 rounded"><i class="fas fa-history mr-1"></i><?= $r['fu_tmpl'] ?></div>
                                    </td>
                                    <td class="block w-full">
                                        <form class="flex gap-1.5 w-full" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="bg-slate-800 border border-slate-600 rounded-lg p-1.5 text-[9px] text-slate-300 save-template flex-1" required>
                                                <option value="">Chat Ulang...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-slate-700 text-white px-3 py-1.5 rounded-lg text-[9px] font-bold hover:bg-amber-600 transition-all"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- =============== KOLOM KANAN (KONTEN UTAMA) =============== -->
        <div class="xl:col-span-9 space-y-6">
            
            <div class="vibrant-card p-6 flex flex-col md:flex-row gap-6 items-center bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100/50">
                <div class="flex-1 flex items-center gap-4">
                    <div class="bg-white p-4 rounded-2xl text-blue-500 border border-blue-100 shadow-sm relative overflow-hidden">
                        <i class="fas fa-bullhorn text-2xl relative z-10 animate-pulse"></i>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-lg text-slate-800">Broadcast Yuk!</h3>
                        <p class="text-[11px] font-medium text-slate-500 mt-1"><span id="countCheck" class="text-blue-600 font-black bg-blue-100 px-2 py-0.5 rounded-md text-sm">0</span> Prospek Terpilih</p>
                    </div>
                </div>
                <form id="formMassal" class="w-full md:w-auto flex flex-wrap gap-2.5 bg-white p-2.5 rounded-2xl shadow-sm">
                    <select name="template_id_multi" onchange="showWA(this)" class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none save-template" required>
                        <option value="">-- Pilih Template --</option>
                        <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" onclick="submitMassAjax(event)" class="bg-blue-600 hover:bg-blue-700 px-6 py-2.5 rounded-xl font-bold text-sm text-white transition-all shadow-lg shadow-blue-500/20">Kirim Cerdas</button>
                </form>
            </div>

            <!-- DAFTAR ORGANIK -->
            <div class="vibrant-card overflow-hidden bg-white border border-slate-100">
                <div class="p-5 bg-white border-b border-slate-100 flex justify-between items-center sticky top-0 z-10">
                    <h3 class="font-extrabold text-sm text-slate-800 flex items-center gap-2"><i class="fas fa-leaf text-emerald-500"></i> Daftar Customer Baru (Organik) - Total Leads: <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-md text-[10px]"><?= count($targetOrganik) ?></span></h3>
                    <label class="text-[11px] font-bold text-slate-500 cursor-pointer"><input type="checkbox" id="checkAllOrganik" class="mr-1.5 accent-blue-600">Pilih Semua</label>
                </div>
                <div class="custom-scroll">
                    <table class="w-full text-left text-xs">
                        <thead class="text-slate-400 uppercase font-extrabold text-[10px] tracking-wider bg-slate-50 border-b border-slate-200">
                            <tr><th class="p-4 w-10 text-center">#</th><th class="p-4">Identitas</th><th class="p-4">Riwayat Follow Up</th><th class="p-4 text-right">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($targetOrganik as $r): ?>
                            <tr class="hover:bg-blue-50/50 bg-white group transition-colors">
                                <td class="p-4 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-organik w-4 h-4 accent-blue-600"></td>
                                <td class="p-4">
                                    <div class="font-extrabold text-slate-800 text-sm mb-0.5">
                                        <?= $r['nama'] ?>
                                        <?php if($r['gender'] !== '-'): ?>
                                            <span class="ml-1 text-[9px] px-1.5 py-0.5 rounded-md font-bold <?= strtolower($r['gender']) == 'ikhwan' || strtolower($r['gender']) == 'laki-laki' ? 'bg-blue-100 text-blue-600' : 'bg-pink-100 text-pink-600' ?>"><?= $r['gender'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] text-slate-500 font-mono mb-1"><?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 ml-1" title="Buka WhatsApp Web"><i class="fab fa-whatsapp"></i></a></div>
                                    <span class="bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded text-[8px] font-extrabold uppercase"><?= $r['klas'] ?></span>
                                </td>
                                <td class="p-4">
                                    <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                        <span class="text-slate-400 text-[10px] italic">Belum ada riwayat</span>
                                    <?php else: ?>
                                        <div class="text-[10px] font-bold text-slate-600 bg-slate-50 inline-block px-2 py-1 rounded border border-slate-200 mb-1"><i class="fas fa-history text-slate-400 mr-1"></i> <?= $r['fu_tmpl'] ?></div>
                                        <div class="text-[9px] text-slate-500"><i class="far fa-clock mr-1"></i><?= $r['fu_text'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right pr-6">
                                    <div class="flex gap-1.5 justify-end">
                                        <form class="inline" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="bg-white border border-slate-200 rounded-lg p-1.5 text-[10px] w-32 save-template" required>
                                                <option value="">Pilih...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-blue-100 text-blue-600 p-2 rounded-lg hover:bg-blue-600 hover:text-white transition-all"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Hapus prospek ini?')" class="inline">
                                            <input type="hidden" name="delete_prospect" value="1">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <button type="submit" class="bg-red-100 text-red-600 p-2 rounded-lg hover:bg-red-600 hover:text-white transition-all" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DAFTAR MANUAL -->
            <div class="vibrant-card overflow-hidden bg-amber-50/10 border border-amber-100">
                <div class="p-5 bg-white border-b border-amber-100 flex justify-between items-center">
                    <h3 class="font-extrabold text-sm text-slate-800 flex items-center gap-2"><i class="fas fa-database text-amber-500"></i> Daftar Antrean Manual/CSV <span class="bg-amber-100 text-amber-700 px-2.5 py-0.5 rounded-md text-[10px]"><?= count($targetManual) ?></span></h3>
                    <label class="text-[11px] font-bold text-slate-500 cursor-pointer"><input type="checkbox" id="checkAllManual" class="mr-1.5 accent-amber-500">Pilih Semua</label>
                </div>
                <div class="custom-scroll">
                    <table class="w-full text-left text-xs">
                        <thead class="text-amber-900/60 uppercase font-extrabold text-[10px] tracking-wider border-b border-amber-100 bg-amber-50/30">
                            <tr><th class="p-4 w-10 text-center">#</th><th class="p-4">Identitas</th><th class="p-4">Riwayat Follow Up</th><th class="p-4 text-right">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-amber-100">
                            <?php foreach($targetManual as $r): ?>
                            <tr class="hover:bg-amber-100/50 bg-white">
                                <td class="p-4 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-manual w-4 h-4 accent-amber-500"></td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-700 text-sm mb-0.5"><?= $r['nama'] ?></div>
                                    <div class="text-[10px] text-slate-500 font-mono"><?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 ml-1"><i class="fab fa-whatsapp"></i></a></div>
                                </td>
                                <td class="p-4">
                                    <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                        <span class="text-slate-400 text-[10px] italic">Belum ada riwayat</span>
                                    <?php else: ?>
                                        <div class="text-[10px] font-bold text-slate-600 bg-amber-50 inline-block px-2 py-1 rounded border border-amber-200 mb-1"><i class="fas fa-history text-slate-400 mr-1"></i> <?= $r['fu_tmpl'] ?></div>
                                        <div class="text-[9px] text-slate-500"><i class="far fa-clock mr-1"></i><?= $r['fu_text'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right pr-6">
                                    <div class="flex gap-1.5 justify-end">
                                        <form class="inline" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" class="bg-amber-50 border border-amber-200 rounded-lg p-1.5 text-[10px] w-28 save-template" required>
                                                <option value="">Tmpl...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-amber-100 text-amber-600 p-2 rounded-lg hover:bg-amber-500 hover:text-white transition-all"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Hapus prospek ini?')" class="inline">
                                            <input type="hidden" name="delete_prospect" value="1">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <button type="submit" class="bg-red-100 text-red-600 p-2 rounded-lg hover:bg-red-600 hover:text-white transition-all" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="waPreview" onclick="closeWA()">
    <div class="bg-[#075e54] text-white p-3 flex items-center justify-between shadow-md">
        <p class="text-[12px] font-bold">Live Preview</p>
        <button class="opacity-60 hover:opacity-100 p-1"><i class="fas fa-times text-lg"></i></button>
    </div>
    <div class="wa-body">
        <div class="wa-bubble" id="waText">...</div>
    </div>
</div>

<div id="modalTambah" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="vibrant-card bg-white w-full max-w-sm overflow-hidden shadow-2xl">
        <div class="p-6 border-b border-slate-100 font-extrabold flex justify-between items-center text-slate-800 bg-slate-50"><span>Tambah Manual</span><button type="button" onclick="closeModal('modalTambah')" class="text-slate-400 hover:text-rose-500"><i class="fas fa-times"></i></button></div>
        <form method="POST" class="p-8 space-y-5">
            <input type="text" name="nama_baru" placeholder="Nama Lengkap" required class="w-full p-3 bg-slate-50 border rounded-xl text-sm">
            <input type="number" name="nowa_baru" placeholder="WhatsApp (08...)" required class="w-full p-3 bg-slate-50 border rounded-xl text-sm">
            <button type="submit" name="tambah_prospek" class="w-full bg-slate-800 text-white py-3.5 rounded-xl font-bold shadow-lg shadow-slate-900/20 text-sm">Simpan Prospek</button>
        </form>
    </div>
</div>

<div id="modalCSV" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="vibrant-card bg-white w-full max-w-sm overflow-hidden shadow-2xl">
        <div class="p-6 border-b border-slate-100 font-extrabold flex justify-between items-center text-slate-800 bg-slate-50"><span>Upload Bulk CSV</span><button type="button" onclick="closeModal('modalCSV')" class="text-slate-400 hover:text-rose-500"><i class="fas fa-times"></i></button></div>
        <form method="POST" enctype="multipart/form-data" class="p-8 space-y-6" onsubmit="showManualLoading()">
            <div class="bg-blue-50 p-4 rounded-xl border border-blue-100 text-[11px] text-blue-800">Format CSV: <b>Nama, WhatsApp</b></div>
            <input type="file" name="file_csv" accept=".csv" required class="w-full p-4 border-2 border-dashed rounded-xl text-xs cursor-pointer">
            <button type="submit" name="upload_csv" class="w-full bg-blue-600 text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/20 text-sm">Proses Upload</button>
        </form>
    </div>
</div>

<script>
const templates = <?= json_encode($jsTemplates) ?>;

function showWA(s) { 
    const p = document.getElementById('waPreview'), t = document.getElementById('waText'); 
    if(s.value && templates[s.value]) { 
        // Mengganti [nama] / {nama} menjadi "[Nama Prospek]" di Live Preview
        t.innerText = templates[s.value].replace(/\[nama\]|\{nama\}/gi, '[Nama Prospek]'); 
        p.style.display = 'block'; 
    } else { p.style.display = 'none'; }
}

function closeWA() { document.getElementById('waPreview').style.display = 'none'; }
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function showManualLoading() { 
    const modal = document.getElementById('loader');
    modal.classList.remove('hidden');
    modal.classList.add('block');
    document.getElementById('progressStatus').innerText = "Memproses request...";
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
    modal.classList.add('block');
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
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true;
    
    try {
        let formData = new FormData();
        formData.append('ajax_send', '1');
        formData.append('contact_id', contactId);
        formData.append('template_id', tmplId);

        let res = await fetch('', { method: 'POST', body: formData });
        let json = await res.json();
        
        if(json.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.classList.replace('bg-slate-700', 'bg-emerald-500');
            btn.classList.replace('bg-blue-100', 'bg-emerald-500');
            btn.classList.replace('bg-amber-100', 'bg-emerald-500');
            btn.classList.replace('text-blue-600', 'text-white');
            btn.classList.replace('text-amber-600', 'text-white');
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
</script>
</body>
</html>