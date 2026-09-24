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
if(!in_array('template_history', $cols)) @$conn->query("ALTER TABLE log_wa ADD template_history TEXT NULL");

// [FITUR BARU] AUTO-CREATE TABEL RIWAYAT PESAN CUSTOM
@$conn->query("CREATE TABLE IF NOT EXISTS custom_msg_history (id INT AUTO_INCREMENT PRIMARY KEY, msg_text TEXT, created_at DATETIME)");

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
    
    // [PERBAIKAN] Sertakan detail error dari API Onesender
    $errorDetail = $response ? substr(strip_tags($response), 0, 100) : 'No Response';
    return ['status' => 'GAGAL', 'msg' => "API Code $httpCode | $errorDetail"];
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
    $disq = []; if (!$conn) return $disq;
    
    // 1. Pengecualian Nomor Sendiri (Hardcoded)
    $disq['6288223053149'] = true;

    // 2. Tambahkan 'pengajar' ke dalam tabel yang dicek
    $tables = ['peserta', 'calon_peserta', 'pengampu', 'pengajar'];
    
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
    $blk = []; if(!$conn) return $blk;
    $q = $conn->query("SELECT nowa FROM blocked_peserta");
    if ($q) while($r = $q->fetch_assoc()) {
        $n = preg_replace('/\D/', '', $r['nowa']); 
        if(strpos($n,'0')===0) $n = '62'.substr($n,1); 
        if($n) $blk[$n] = true;
    }
    return $blk;
}

// --- 1. HANDLE AJAX KIRIM (EKSEKUSI LANGSUNG API) ---
if (isset($_POST['ajax_send'])) {
    header('Content-Type: application/json');
    $cid = $_POST['contact_id'] ?? '';
    $tmplId = $_POST['template_id'] ?? '';
    $customMsg = $_POST['custom_message'] ?? ''; 

    $msgTmpl = ''; $tmplName = '';

    if (!empty($customMsg)) {
        // [FITUR BARU] Gunakan pesan custom
        $msgTmpl = $customMsg;
        $tmplName = 'Pesan Custom Langsung';
        
        $stmtCheck = $conn->prepare("SELECT id FROM custom_msg_history WHERE msg_text = ? LIMIT 1");
        $stmtCheck->bind_param("s", $customMsg); $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows === 0) {
            $stmtC = $conn->prepare("INSERT INTO custom_msg_history (msg_text, created_at) VALUES (?, NOW())");
            $stmtC->bind_param("s", $customMsg); $stmtC->execute();
        }
    } else {
        // Logika Lama (Pakai Template)
        $stT = $conn->prepare("SELECT name, content FROM poloap_templates WHERE id = ?");
        $stT->bind_param("i", $tmplId); $stT->execute(); 
        $tmplData = $stT->get_result()->fetch_assoc();
        $msgTmpl = $tmplData['content'] ?? '';
        $tmplName = $tmplData['name'] ?? '';
    }

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
        
        global $apiUrl, $apiToken; 
        $hasil_kirim = kirimPesan($cid, $pesan, $apiUrl, $apiToken);
        
        if($hasil_kirim['status'] === 'TERKIRIM') {
            
            $tmplNameSafe = $conn->real_escape_string($tmplName);
            $historyLog = date('d/m/Y H:i') . " - " . $tmplNameSafe;
            $newHistory = empty($currentHistory) ? $historyLog : $currentHistory . '|||' . $historyLog;
            $newHistorySafe = $conn->real_escape_string($newHistory);

            $conn->query("UPDATE log_wa SET last_followup_at = NOW(), is_form_sent = GREATEST(is_form_sent, $isForm), last_template_name = '$tmplNameSafe', template_history = '$newHistorySafe' WHERE nowa = '$cid'");
            
            if (!in_array($cid, $_SESSION['followed_up_today'])) $_SESSION['followed_up_today'][] = $cid;
            
            echo json_encode([
                'status' => 'success', 
                'nama' => $nama, 
                'new_history' => $newHistory, 
                'fu_tmpl' => $tmplNameSafe, 
                'fu_time' => date('d/m H:i')
            ]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Gagal mengirim pesan: ' . $hasil_kirim['msg']]);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Template atau kontak tidak valid']);
    }
    exit;
}

// --- 1B. HANDLE AJAX POLLING (REAL-TIME FETCH) ---
if (isset($_GET['ajax_poll'])) {
    header('Content-Type: application/json');
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    $new_count = 0;
    
    // Hitung apakah ada ID yang lebih besar (pesan baru) dari ID terakhir di layar
    $stmt = $conn->prepare("SELECT COUNT(id) as c FROM log_wa WHERE id > ? AND message != 'Data CSV/Manual' AND message != ''");
    $stmt->bind_param("i", $last_id);
    $stmt->execute();
    $resCount = $stmt->get_result();
    
    if ($resCount) {
        $row = $resCount->fetch_assoc();
        $new_count = (int)$row['c'];
    }
    
    echo json_encode(['status' => 'success', 'new_count' => $new_count]);
    exit;
}

$disqualified = getDisqualifiedNumbers($conn);
$blocked = getBlockedNumbers($conn);

// --- 2. FUNGSI POST BIASA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_send'])) {
    if (isset($_POST['tambah_prospek'])) {
        $n = preg_replace('/\D/', '', $_POST['nowa_baru']); if (strpos($n, '0') === 0) $n = '62' . substr($n, 1);
        $check = $conn->prepare("SELECT id FROM log_wa WHERE nowa = ? OR nowa = ? LIMIT 1");
        $n_raw = $_POST['nowa_baru']; $check->bind_param("ss", $n, $n_raw); $check->execute();
        
        if (isset($disqualified[$n]) || isset($blocked[$n])) {
            $_SESSION['notification'] = "❌ Gagal: Nomor sudah terdaftar / diblokir!"; $_SESSION['notificationType'] = 'error';
        } elseif ($check->get_result()->num_rows > 0) {
            $_SESSION['notification'] = "⚠️ Nomor sudah ada di dalam database (Organik / Manual)."; $_SESSION['notificationType'] = 'warning';
        } else {
            // [PERBAIKAN SQL INJECTION]
            $stmtInsert = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, 'Data CSV/Manual', NOW())");
            $stmtInsert->bind_param("ss", $n, $_POST['nama_baru']);
            $stmtInsert->execute();
            $_SESSION['notification'] = "Prospek manual berhasil ditambahkan!"; $_SESSION['notificationType'] = 'success';
        }
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['upload_csv']) && isset($_FILES['file_csv'])) {
        set_time_limit(0); ini_set('memory_limit', '512M'); 
        if (($handle = fopen($_FILES['file_csv']['tmp_name'], "r")) !== FALSE) {
            $suksesUpload = 0; $ditolak = 0; $sudahAda = 0;
            $existing_data = [];
            $resAll = $conn->query("SELECT nowa FROM log_wa");
            if($resAll) {
                while($rowAll = $resAll->fetch_assoc()) {
                    $nAll = preg_replace('/\D/', '', $rowAll['nowa']);
                    if(strpos($nAll,'0')===0) $nAll = '62'.substr($nAll,1);
                    if($nAll) $existing_data[$nAll] = true;
                }
            }
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT IGNORE INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, 'Data CSV/Manual', NOW())");
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (strtolower($data[0]) === 'nama' || empty($data[0])) continue;
                $n = preg_replace('/\D/', '', $data[1]); if (strpos($n, '0') === 0) $n = '62' . substr($n, 1);
                if (isset($disqualified[$n]) || isset($blocked[$n])) { $ditolak++; continue; }
                if (isset($existing_data[$n])) { $sudahAda++; continue; }
                $stmt->bind_param("ss", $n, $data[0]); if($stmt->execute()) $suksesUpload++;
            }
            $conn->commit();
            fclose($handle); 
            $msg = "$suksesUpload Data CSV di-import.";
            if($sudahAda > 0) $msg .= " $sudahAda dilewati karena duplikat.";
            $_SESSION['notification'] = $msg; $_SESSION['notificationType'] = 'success';
        }
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['hapus_semua_manual'])) {
        $conn->query("DELETE FROM log_wa WHERE message = 'Data CSV/Manual'");
        $_SESSION['notification'] = "Seluruh antrean manual & CSV berhasil dihapus!"; $_SESSION['notificationType'] = 'success';
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['delete_prospect'])) { 
        // [PERBAIKAN SQL INJECTION]
        $stmtDel = $conn->prepare("DELETE FROM log_wa WHERE nowa = ?");
        $stmtDel->bind_param("s", $_POST['contact_id']);
        $stmtDel->execute();
        header("Location: pesan.php"); exit; 
    }
    if (isset($_POST['clear_fu'])) { $_SESSION['followed_up_today'] = []; header("Location: pesan.php"); exit; }
}

// ==================================================================
// DATA FETCHING & FILTER (OPTIMIZED LOOP)
// ==================================================================
$jsTemplates = []; $pesanTemplates = [];
$res = $conn->query("SELECT id, name, content FROM poloap_templates ORDER BY name");
while($r = $res->fetch_assoc()) { $pesanTemplates[] = $r; $jsTemplates[$r['id']] = $r['content']; }

// [FITUR BARU] AMBIL RIWAYAT PESAN CUSTOM (8 Terakhir)
$customHistories = [];
$resCH = $conn->query("SELECT DISTINCT msg_text FROM custom_msg_history ORDER BY id DESC LIMIT 8");
if ($resCH) {
    while($r = $resCH->fetch_assoc()) $customHistories[] = $r;
}

$trend_7d = [];
$processed_trend = []; 
$tgl_mulai_trend = date('Y-m-d', strtotime('-7 days'));
$tgl_akhir_trend = date('Y-m-d');

$res_7d = $conn->query("SELECT nowa, message FROM log_wa WHERE created_at >= '$tgl_mulai_trend 00:00:00' AND created_at <= '$tgl_akhir_trend 23:59:59' AND message != 'Data CSV/Manual' AND message != '' ORDER BY created_at ASC");

if ($res_7d) {
    while($r7 = $res_7d->fetch_assoc()) {
        $n_log = preg_replace('/\D/', '', $r7['nowa']);
        if(strpos($n_log,'0')===0) $n_log = '62'.substr($n_log,1);
        if(empty($n_log)) continue;
        
        if(isset($processed_trend[$n_log])) continue;
        $processed_trend[$n_log] = true;
        
        if (isset($disqualified[$n_log]) || isset($blocked[$n_log])) continue;

        $k = classifyMessage($r7['message']);
        if ($k !== 'Lainnya' && $k !== 'Data CSV/Manual') {
            $trend_7d[$k] = ($trend_7d[$k] ?? 0) + 1;
        }
    }
}
arsort($trend_7d);

$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$m_index = (int)date('m', strtotime($tgl_mulai_trend));
$tgl_teks_trend = date('d', strtotime($tgl_mulai_trend)) . ' ' . $namaBulan[$m_index] . ' ' . date('Y');

$search = $_GET['search'] ?? ''; 
$search_lower = $search ? strtolower($search) : '';

$f_start = $_GET['from'] ?? ''; 
$f_start_str = $f_start ? $f_start . " 00:00:00" : null;

$f_end = $_GET['to'] ?? ''; 
$f_end_str = $f_end ? $f_end . " 23:59:59" : null;

$f_minat = $_GET['minat'] ?? ''; 
$f_status = $_GET['status_fu'] ?? ''; 

$sql = "SELECT id, nowa, nama, message, created_at, last_followup_at, last_template_name, template_history 
        FROM log_wa 
        ORDER BY CASE WHEN (message = 'Data CSV/Manual' OR message = '') THEN 1 ELSE 0 END ASC, created_at DESC";
$res = $conn->query($sql);

$followed_up_session = $_SESSION['followed_up_today'] ?? [];

// [PERBAIKAN BUG] Inisialisasi Array PHP 8
$targetOrganik_raw = [];
$targetManual_raw = [];
$idsToDelete = [];
$statistikMinat = [];
$processed = [];
$max_log_id = 0; // <-- 1. TAMBAHKAN BARIS INI

while ($row = $res->fetch_assoc()) {
    if ($row['id'] > $max_log_id) $max_log_id = $row['id'];
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
    
    $klas = classifyMessage($row['message']);
    if ($klas === 'Lainnya') continue;

    if (!isset($statistikMinat[$klas])) $statistikMinat[$klas] = 0;
    $statistikMinat[$klas]++;

    if ($f_minat && $klas !== $f_minat) continue;
    if ($f_start_str && $row['created_at'] < $f_start_str) continue;
    if ($f_end_str && $row['created_at'] > $f_end_str) continue;
    
    if ($f_status === 'belum' && !empty($row['last_followup_at'])) continue;
    if ($f_status === 'sudah' && empty($row['last_followup_at'])) continue;

    if ($search_lower) {
        if (strpos(strtolower($row['nama']), $search_lower) === false && strpos(strtolower($row['nowa']), $search_lower) === false) continue;
    }

    $is_fu_today = (in_array($raw_nowa, $followed_up_session) || in_array($n_log, $followed_up_session));

    $row['clean_wa'] = $n_log;
    $row['klas'] = $klas;
    $row['is_fu_today'] = $is_fu_today;

    if ($klas === 'Data CSV/Manual') {
        $targetManual_raw[] = $row;
    } else {
        $targetOrganik_raw[] = $row;
    }
}

if (!empty($idsToDelete)) $conn->query("DELETE FROM log_wa WHERE id IN (" . implode(',', $idsToDelete) . ")");

$page_o = isset($_GET['page_o']) ? max(1, (int)$_GET['page_o']) : 1;
$page_m = isset($_GET['page_m']) ? max(1, (int)$_GET['page_m']) : 1;
$per_page = 10;
$total_o = count($targetOrganik_raw); $total_m = count($targetManual_raw);
$pages_o = max(1, ceil($total_o / $per_page)); $pages_m = max(1, ceil($total_m / $per_page));

$paged_organik_raw = array_slice($targetOrganik_raw, ($page_o - 1) * $per_page, $per_page);
$paged_manual_raw = array_slice($targetManual_raw, ($page_m - 1) * $per_page, $per_page);

function formatFinalData($row) {
    $msgRaw = $row['message'] ?? '';
    $namaDb = trim($row['nama']);
    $extractedName = ''; $extractedGender = '-';

    if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $msgRaw, $m)) $extractedName = trim($m[1]);
    elseif (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $msgRaw, $m)) $extractedName = trim($m[1]);
    if (preg_match('/\((ikhwan|akhwat|laki-laki|perempuan|laki|pr)\)/i', $msgRaw, $m)) $extractedGender = ucfirst(strtolower(trim($m[1])));
    
    $finalName = !empty($extractedName) ? $extractedName : (!empty($namaDb) ? $namaDb : 'Hamba Allah');

    return [
        'id' => $row['id'],
        'nowa' => $row['nowa'], 
        'clean_wa' => $row['clean_wa'], 
        'nama' => $finalName, 
        'gender' => $extractedGender,
        'klas' => $row['klas'],
        'msgRaw' => $msgRaw,
        'fu_text' => $row['last_followup_at'] ? date('d/m H:i', strtotime($row['last_followup_at'])) : 'Belum Pernah',
        'fu_tmpl' => $row['last_template_name'] ? $row['last_template_name'] : 'Baru',
        'history' => $row['template_history'] ?? ''
    ];
}

$targetOrganik_paged = array_map('formatFinalData', $paged_organik_raw);
$targetManual_paged = array_map('formatFinalData', $paged_manual_raw);

$organikSudahDichat = array_map('formatFinalData', array_filter($targetOrganik_raw, function($r) { return $r['is_fu_today']; }));
$manualSudahDichat = array_map('formatFinalData', array_filter($targetManual_raw, function($r) { return $r['is_fu_today']; }));

function buildPageUrl($pageType, $pageNum) {
    $params = $_GET; $params[$pageType] = $pageNum;
    return '?' . http_build_query($params);
}

if (isset($_GET['export_csv_action'])) {
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename=Prospek_Filtered.csv');
    $output = fopen('php://output', 'w'); 
    fputcsv($output, ['Nama', 'Gender', 'Nomor WA', 'Minat', 'Tgl Follow-up Terakhir', 'Template Terakhir']);
    
    $allExportRaw = array_merge($targetOrganik_raw, $targetManual_raw);
    foreach($allExportRaw as $r) {
        $formatted = formatFinalData($r);
        fputcsv($output, [$formatted['nama'], $formatted['gender'], $formatted['nowa'], $formatted['klas'], $formatted['fu_text'], $formatted['fu_tmpl']]); 
    }
    fclose($output); exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Follow-Up | Bento UI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; color: #334155; }
        
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
        
        /* Sidebar Desktop & Mobile */
        .crm-sidebar { width: 18rem; background-color: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-right: 1px solid rgba(255,255,255,0.4); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); flex-shrink: 0; box-shadow: 1px 0 20px rgba(0,0,0,0.02); z-index: 50; }
        .crm-sidebar.collapsed { width: 4.5rem; }
        .crm-sidebar.collapsed .hide-on-collapse { opacity: 0; pointer-events: none; visibility: hidden; width: 0; height: 0; overflow: hidden; margin: 0; padding: 0; }
        .crm-sidebar.collapsed .icon-center { justify-content: center; width: 100%; padding-left: 0; padding-right: 0; }
        .crm-sidebar.collapsed .icon-center i { margin-right: 0 !important; font-size: 1.1rem; }
        .crm-sidebar.collapsed .logo-wrapper { padding: 1.25rem 0; justify-content: center; }
        
        @media (max-width: 768px) {
            .crm-sidebar { position: fixed; left: -100%; top: 0; bottom: 0; height: 100%; width: 18rem; box-shadow: 10px 0 25px -5px rgba(0, 0, 0, 0.1); }
            .crm-sidebar.active { left: 0; }
            #waPreview { width: 100% !important; left: 0 !important; right: 0 !important; bottom: 0 !important; border-radius: 16px 16px 0 0 !important; }
        }
        
        .crm-input { background: #f8fafc; border: 1px solid #e2e8f0; color: #334155; border-radius: 8px; transition: all 0.2s; }
        .crm-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); background: #ffffff; }
        
        .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06); }
        
        @keyframes fadeInScale { from { opacity: 0; transform: scale(0.97) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .animate-fade-in { animation: fadeInScale 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards; }
        
        .chat-bg { background-color: #efeae2; background-image: radial-gradient(#cbd5e1 1px, transparent 0); background-size: 20px 20px; }
        .bubble-left { background: #ffffff; border-radius: 0 12px 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); }
        .bubble-right { background: #dcf8c6; border-radius: 12px 0 12px 12px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); }
        #waPreview { display: none; position: fixed; bottom: 24px; right: 24px; width: 340px; z-index: 50; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; }
        
        .input-group { display: flex; }
        .input-group select { border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: 0; }
        .input-group button { border-top-left-radius: 0; border-bottom-left-radius: 0; }
        .timeline-line::before { content: ''; position: absolute; left: 11px; top: 20px; bottom: 0; width: 2px; background: #e2e8f0; z-index: 0; }
    </style>
</head>
<body class="overflow-hidden">

<div id="toast-container" class="fixed top-5 right-5 z-[9999] flex flex-col gap-3 pointer-events-none"></div>

<div class="flex h-screen w-full font-sans">
    
    <!-- ================== SIDEBAR ================== -->
    <aside id="mainSidebar" class="crm-sidebar flex flex-col h-full overflow-y-auto overflow-x-hidden custom-scroll relative">
        <div class="border-b border-slate-200/50 sticky top-0 bg-white/95 backdrop-blur z-10 flex items-center justify-between logo-wrapper px-5 py-4">
            <div class="flex items-center gap-3 hide-on-collapse">
                <div class="bg-indigo-600 text-white w-8 h-8 flex justify-center items-center rounded-xl shadow-md"><i class="fas fa-layer-group"></i></div>
                <h1 class="text-base font-black tracking-tight text-slate-800">CRM Bento</h1>
            </div>
            <button onclick="toggleSidebar()" class="text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 w-8 h-8 rounded-lg flex justify-center items-center transition-colors shadow-sm hidden md:flex">
                <i class="fas fa-bars"></i>
            </button>
            <button onclick="toggleSidebar()" class="text-slate-400 hover:text-rose-600 w-8 h-8 rounded-lg flex justify-center items-center md:hidden">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="p-5 space-y-6 flex-1">
            <section class="hide-on-collapse">
                <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-4 flex items-center"><i class="fas fa-filter mr-2"></i> Filter Pencarian</h3>
                <form method="GET" class="space-y-3">
                    <input type="hidden" name="minat" value="<?= htmlspecialchars($f_minat) ?>">
                    <div>
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Nama/WhatsApp..." class="crm-input w-full pl-8 py-2 text-xs font-medium">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-[9px] font-bold text-slate-500 mb-1 block uppercase">Mulai Tanggal</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="crm-input w-full px-2 py-1.5 text-[11px]">
                        </div>
                        <div>
                            <label class="text-[9px] font-bold text-slate-500 mb-1 block uppercase">Sampai Tanggal</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="crm-input w-full px-2 py-1.5 text-[11px]">
                        </div>
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-slate-500 mb-1 block uppercase">Status Follow-Up</label>
                        <select name="status_fu" class="crm-input w-full px-2 py-1.5 text-[11px] font-medium">
                            <option value="">Semua Status</option>
                            <option value="belum" <?= $f_status === 'belum' ? 'selected' : '' ?>>Belum Diproses</option>
                            <option value="sudah" <?= $f_status === 'sudah' ? 'selected' : '' ?>>Sudah Diproses</option>
                        </select>
                    </div>
                    <div class="pt-2 flex gap-2">
                        <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-xl font-bold text-xs transition-colors shadow-md shadow-indigo-200"><i class="fas fa-check mr-1.5"></i>Terapkan</button>
                        <?php if($search || $f_start || $f_end || $f_minat): ?>
                            <a href="pesan.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 py-2 px-3 rounded-xl font-medium text-xs transition-colors flex items-center justify-center"><i class="fas fa-undo"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
            
            <section>
                <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center hide-on-collapse"><i class="fas fa-cog mr-2"></i> Manajemen</h3>
                <div class="space-y-1">
                    <a href="manage_templates.php" class="w-full flex items-center icon-center p-2.5 rounded-xl text-slate-600 hover:bg-white hover:shadow-sm hover:text-indigo-700 transition-all text-xs font-semibold">
                        <i class="fas fa-comment-dots w-5 text-center text-slate-400 mr-2"></i><span class="hide-on-collapse">Kelola Template</span>
                    </a>
                    <a href="grafik.php" class="w-full flex items-center icon-center p-2.5 rounded-xl text-slate-600 hover:bg-white hover:shadow-sm hover:text-indigo-700 transition-all text-xs font-semibold">
                        <i class="fas fa-chart-line w-5 text-center text-slate-400 mr-2"></i><span class="hide-on-collapse">Statistik Data</span>
                    </a>
                    <form method="POST" class="m-0" onsubmit="return confirm('Reset sesi harian?')">
                        <input type="hidden" name="clear_fu" value="1">
                        <button type="submit" class="w-full flex items-center icon-center p-2.5 rounded-xl text-slate-600 hover:bg-rose-50 hover:text-rose-600 transition-all text-xs font-semibold">
                            <i class="fas fa-sync-alt w-5 text-center text-slate-400 mr-2"></i><span class="hide-on-collapse">Reset Sesi Harian</span>
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </aside>

    <!-- ================== MAIN CONTENT ================== -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-slate-100/50 relative">
        
        <header class="h-[70px] shrink-0 bg-white/80 backdrop-blur-md border-b border-slate-200/60 px-4 md:px-6 flex items-center justify-between z-10 shadow-sm">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="block md:hidden text-slate-500 hover:text-indigo-600 mr-3 p-2 bg-slate-100 rounded-lg"><i class="fas fa-filter"></i></button>
                <h2 class="text-sm md:text-base font-black text-slate-800">Daftar Antrean Pesan</h2>
            </div>
            <div class="flex gap-2 custom-scroll overflow-x-auto pb-1 sm:pb-0">
                <button onclick="openModal('modalTambah')" class="shrink-0 bg-white border border-slate-200 text-slate-600 hover:text-indigo-600 px-3 py-1.5 rounded-xl text-[11px] font-bold shadow-sm transition-all hover:shadow-md hover:-translate-y-0.5"><i class="fas fa-plus mr-1"></i> Manual</button>
                <button onclick="openModal('modalCSV')" class="shrink-0 bg-indigo-600 text-white hover:bg-indigo-700 px-3 py-1.5 rounded-xl text-[11px] font-bold shadow-md shadow-indigo-200 transition-all hover:shadow-lg hover:-translate-y-0.5"><i class="fas fa-file-csv mr-1"></i> Import CSV</button>
                <a href="?export_csv_action=1" class="shrink-0 bg-slate-800 text-white hover:bg-slate-900 px-3 py-1.5 rounded-xl text-[11px] font-bold shadow-md shadow-slate-300 transition-all hover:shadow-lg hover:-translate-y-0.5 hidden md:inline-block"><i class="fas fa-download mr-1"></i> Export</a>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-3 md:p-6 custom-scroll relative">
            
            <!-- Loader -->
            <div id="loader" class="hidden bg-white/90 backdrop-blur-sm p-5 rounded-2xl shadow-lg border border-indigo-100 animate-fade-in mb-5">
                <div class="flex items-center gap-4">
                    <i class="fas fa-circle-notch fa-spin text-2xl text-indigo-600"></i>
                    <div class="flex-1">
                        <div class="flex justify-between items-end mb-2"><h3 class="font-bold text-slate-800 text-sm">Sistem Sedang Bekerja...</h3><p id="progressText" class="text-sm font-black text-indigo-600">0%</p></div>
                        <div class="w-full bg-slate-100 rounded-full h-2"><div id="progressBar" class="bg-gradient-to-r from-indigo-500 to-purple-600 h-full rounded-full transition-all duration-300 w-0"></div></div>
                        <p id="progressStatus" class="text-[10px] text-slate-500 mt-1.5 font-medium">Mohon tunggu sebentar...</p>
                    </div>
                </div>
            </div>

            <!-- BENTO GRID CONTAINER -->
            <div class="grid grid-cols-12 gap-4 md:gap-6 max-w-[1600px] mx-auto pb-10">
                
                <!-- BENTO 1: TREND SEPEKAN -->
                <div class="col-span-12 md:col-span-8 bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 rounded-3xl p-6 text-white border border-slate-700 shadow-xl shadow-slate-200/50 flex flex-col justify-between min-h-[160px] relative overflow-hidden animate-fade-in">
                    <!-- Glass effect decoration -->
                    <div class="absolute -right-10 -top-10 w-40 h-40 bg-indigo-500/20 blur-3xl rounded-full pointer-events-none"></div>
                    <div class="relative z-10 flex justify-between items-start">
                        <div>
                            <span class="text-[9px] bg-white/10 backdrop-blur border border-white/10 text-indigo-200 font-bold px-2 py-1 rounded-full uppercase tracking-wider">Statistik • <?= $tgl_teks_trend ?></span>
                            <h3 class="font-black text-lg md:text-xl mt-3 tracking-tight">Trend Minat Sepekan</h3>
                        </div>
                        <div class="w-10 h-10 bg-white/10 rounded-2xl flex items-center justify-center backdrop-blur-md border border-white/20"><i class="fas fa-fire text-orange-400 text-lg"></i></div>
                    </div>
                    <div class="relative z-10 flex gap-2.5 overflow-x-auto pb-2 mt-5 custom-scroll">
                        <?php if(empty($trend_7d)): ?>
                            <div class="text-xs text-indigo-200 italic">Belum ada data baru.</div>
                        <?php else: ?>
                            <?php foreach(array_slice($trend_7d, 0, 5) as $m => $c): ?>
                                <div class="bg-white/10 backdrop-blur-sm border border-white/10 rounded-2xl px-3.5 py-2.5 flex items-center gap-3 shrink-0 hover:bg-white/20 transition-colors">
                                    <span class="text-[11px] font-bold text-slate-200"><?= $m ?></span>
                                    <span class="bg-indigo-500 text-white text-[10px] font-black px-2 py-1 rounded-xl shadow-inner"><?= $c ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- BENTO 2: STATS SESI HARIAN -->
                <div class="col-span-12 md:col-span-4 bg-white border border-slate-200/60 rounded-3xl p-6 shadow-xl shadow-slate-200/40 flex flex-col justify-between min-h-[160px] animate-fade-in" style="animation-delay: 0.1s;">
                    <div class="flex justify-between items-start">
                        <div>
                            <span class="text-[9px] bg-emerald-50 border border-emerald-100 text-emerald-600 font-bold px-2 py-1 rounded-full uppercase tracking-wider">Sesi Hari Ini</span>
                            <h3 class="font-black text-slate-800 text-base mt-3">Prospek Disapa</h3>
                        </div>
                        <div class="w-10 h-10 bg-emerald-50 text-emerald-500 rounded-2xl flex items-center justify-center border border-emerald-100"><i class="fas fa-check-double"></i></div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-black text-slate-800 tracking-tighter"><?= count($organikSudahDichat) + count($manualSudahDichat) ?></span>
                        <span class="text-xs text-slate-400 font-bold">kontak selesai</span>
                    </div>
                </div>

                <!-- BENTO 3: METRIK MINAT -->
                <?php if(!empty($statistikMinat)): ?>
                <div class="col-span-12 bg-white/60 backdrop-blur-md border border-slate-200/60 rounded-3xl p-5 shadow-lg shadow-slate-200/30 animate-fade-in" style="animation-delay: 0.15s;">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-4"><i class="fas fa-tags mr-1.5"></i> Filter Berdasarkan Minat Program</div>
                    <div class="flex gap-3 overflow-x-auto pb-2 custom-scroll snap-x">
                        <?php foreach($statistikMinat as $namaMinat => $jumlah): 
                            $isActive = ($f_minat === $namaMinat);
                            $filterUrl = preg_replace('/&page_[om]=\d+/', '', buildPageUrl('minat', $isActive ? '' : $namaMinat));
                        ?>
                        <a href="<?= $filterUrl ?>" class="flex-shrink-0 min-w-[140px] bg-white border <?= $isActive ? 'border-indigo-500 ring-4 ring-indigo-500/10' : 'border-slate-100' ?> rounded-2xl p-4 hover-lift snap-center transition-all shadow-sm">
                            <div class="flex justify-between items-start mb-2">
                                <div class="text-[10px] font-bold text-slate-400 uppercase truncate pr-2"><?= htmlspecialchars($namaMinat) ?></div>
                                <?php if($isActive): ?><i class="fas fa-check-circle text-indigo-500 text-sm drop-shadow-sm"></i><?php endif; ?>
                            </div>
                            <div class="text-2xl font-black text-slate-800"><?= $jumlah ?></div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- BENTO 4: BROADCAST MASSAL -->
                <div class="col-span-12 bg-white border border-indigo-100 rounded-3xl p-5 shadow-xl shadow-indigo-100/50 flex flex-col md:flex-row items-center justify-between gap-4 relative overflow-hidden animate-fade-in" style="animation-delay: 0.2s;">
                    <div class="absolute inset-0 bg-gradient-to-r from-indigo-50/50 to-purple-50/50 opacity-50 pointer-events-none"></div>
                    <div class="flex items-center gap-4 relative z-10 w-full md:w-auto">
                        <div class="bg-white text-indigo-600 w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 border border-indigo-100 shadow-sm"><i class="fas fa-paper-plane text-lg"></i></div>
                        <div>
                            <h3 class="font-black text-sm text-slate-800">Kirim Pesan Massal</h3>
                            <p class="text-[11px] text-slate-500 font-medium mt-0.5"><span id="countCheck" class="font-bold text-indigo-700 bg-indigo-100 px-1.5 py-0.5 rounded-md">0</span> kontak dicentang di tabel bawah</p>
                        </div>
                    </div>
                    <form id="formMassal" class="w-full md:w-auto flex gap-2 relative z-10">
                        <select name="template_id_multi" onchange="showWA(this)" class="crm-input px-4 py-2.5 text-xs w-full md:w-56 cursor-pointer font-bold bg-white" required>
                            <option value="">-- Pilih Template --</option>
                            <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                        </select>
                        <button type="button" onclick="submitMassAjax(event)" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-xl font-bold text-xs transition-colors shadow-md shadow-slate-300 whitespace-nowrap">Broadcast</button>
                    </form>
                </div>

                <!-- BENTO 5: DATA ORGANIK -->
                <div class="col-span-12 xl:col-span-7 bg-white border border-slate-200/60 rounded-3xl shadow-xl shadow-slate-200/30 overflow-hidden flex flex-col animate-fade-in" style="animation-delay: 0.25s;">
                    <div class="p-5 border-b border-slate-100 bg-slate-50/80 flex justify-between items-center">
                        <h3 class="font-black text-sm text-slate-800 flex items-center gap-2.5">
                            <div class="w-3 h-3 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]"></div> Prospek Organik Baru
                            <span class="bg-indigo-50 text-indigo-600 text-[10px] px-2 py-1 rounded-lg ml-1"><?= $total_o ?> Data</span>
                        </h3>
                        <label class="text-[11px] font-bold text-slate-600 cursor-pointer flex items-center hover:text-indigo-600 transition-colors bg-white px-2 py-1 rounded-lg border border-slate-200 shadow-sm"><input type="checkbox" id="checkAllOrganik" class="mr-2 w-3.5 h-3.5 accent-indigo-600 rounded">Pilih Semua</label>
                    </div>
                    
                    <div class="flex-1 custom-scroll max-h-[600px] bg-white">
                        <!-- Desktop Table -->
                        <div class="hidden md:block overflow-x-auto">
                            <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">
                                <thead class="text-[10px] text-slate-400 font-bold bg-white border-b border-slate-100 uppercase tracking-wider">
                                    <tr><th class="p-4 w-10 text-center">#</th><th class="p-4">Info Prospek</th><th class="p-4">Status</th><th class="p-4 text-right">Aksi Cepat</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php if(empty($targetOrganik_paged)): ?>
                                        <tr><td colspan="4" class="p-8 text-center text-slate-400 text-xs font-semibold">Tabel kosong.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($targetOrganik_paged as $r): ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors group">
                                            <td class="p-4 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-organik w-4 h-4 accent-indigo-600 rounded cursor-pointer"></td>
                                            <td class="p-4">
                                                <div class="font-black text-slate-800 text-[13px] cursor-pointer hover:text-indigo-600 flex items-center gap-1.5" onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                                    <?= $r['nama'] ?>
                                                    <?php if($r['gender'] !== '-'): ?><span class="text-[9px] px-1.5 py-0.5 rounded-md text-slate-500 bg-slate-100 border border-slate-200"><?= $r['gender'] ?></span><?php endif; ?>
                                                </div>
                                                <div class="text-[11px] text-slate-500 mt-1 flex items-center gap-1.5 font-medium">
                                                    <?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500 hover:scale-110 transition-transform"><i class="fab fa-whatsapp"></i></a><span class="w-1 h-1 rounded-full bg-slate-300"></span><span class="text-indigo-600 font-bold"><?= $r['klas'] ?></span>
                                                </div>
                                            </td>
                                            <td class="p-4 status-cell">
                                                <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                                    <span class="bg-amber-50 text-amber-600 text-[10px] font-black px-2 py-1 rounded-lg border border-amber-100">BELUM DIPROSES</span>
                                                <?php else: ?>
                                                    <div class="flex items-center gap-2">
                                                        <div>
                                                            <div class="text-[11px] font-bold text-slate-700 flex items-center gap-1"><i class="fas fa-check-double text-indigo-500"></i> <span class="tmpl-text truncate max-w-[120px] inline-block"><?= $r['fu_tmpl'] ?></span></div>
                                                            <div class="text-[10px] text-slate-400 mt-0.5 tmpl-time"><i class="far fa-clock mr-1"></i><?= $r['fu_text'] ?></div>
                                                        </div>
                                                        <button type="button" class="history-btn bg-white hover:bg-indigo-50 text-slate-400 hover:text-indigo-600 px-2 py-1 rounded-lg border border-slate-200 transition-colors shadow-sm text-[10px]" data-history="<?= htmlspecialchars($r['history'], ENT_QUOTES) ?>" onclick="showHistoryModal(this)"><i class="fas fa-list"></i></button>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4 text-right">
                                                <div class="flex justify-end gap-1.5 items-center">
                                                    <form class="input-group shadow-sm w-36" onsubmit="submitSingleAjax(event, this)">
                                                        <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                        <select name="template_id" onchange="showWA(this)" class="crm-input w-full p-2 text-[11px] font-bold m-0 border-r-0 focus:ring-0 focus:border-indigo-500" required>
                                                            <option value="">Kirim...</option>
                                                            <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="bg-indigo-50 border border-indigo-200 border-l-0 text-indigo-600 px-3 rounded-r-lg hover:bg-indigo-600 hover:text-white transition-colors"><i class="fas fa-paper-plane text-[10px]"></i></button>
                                                    </form>
                                                    <button type="button" onclick="openCustomMsgModal('<?= $r['nowa'] ?>', '<?= htmlspecialchars($r['nama'], ENT_QUOTES) ?>')" class="bg-purple-50 border border-purple-200 text-purple-600 p-2 rounded-lg hover:bg-purple-600 hover:text-white transition-colors shadow-sm ml-0.5" title="Ketik Pesan"><i class="fas fa-keyboard text-[11px]"></i></button>
                                                    <form method="POST" class="m-0" onsubmit="return confirm('Hapus prospek organik ini?')">
                                                        <input type="hidden" name="delete_prospect" value="1"><input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                        <button type="submit" class="bg-white border border-slate-200 text-slate-400 p-2 rounded-lg hover:bg-rose-50 hover:text-rose-500 hover:border-rose-200 transition-colors shadow-sm ml-0.5"><i class="fas fa-trash-alt text-[11px]"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View -->
                        <div class="block md:hidden divide-y divide-slate-100">
                            <?php if(empty($targetOrganik_paged)): ?>
                                <div class="p-6 text-center text-slate-400 text-xs font-semibold">Data kosong.</div>
                            <?php else: ?>
                                <?php foreach($targetOrganik_paged as $r): ?>
                                <div class="p-4 space-y-3 bg-white">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex items-center gap-3">
                                            <input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-organik w-5 h-5 accent-indigo-600 rounded cursor-pointer">
                                            <div onclick="showDetail(this.dataset.user, this.dataset.sys)" data-user="<?= htmlspecialchars($r['msgRaw'], ENT_QUOTES) ?>" data-sys="<?= htmlspecialchars($r['fu_tmpl'] !== 'Baru' ? $r['fu_tmpl'] : '', ENT_QUOTES) ?>">
                                                <div class="font-black text-slate-800 text-[14px]"><?= $r['nama'] ?></div>
                                                <div class="text-[11px] text-slate-500 font-semibold mt-0.5"><?= $r['nowa'] ?></div>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if($r['fu_tmpl'] === 'Baru'): ?>
                                                <span class="bg-amber-50 text-amber-600 text-[9px] font-black px-2 py-1 rounded border border-amber-100">BARU</span>
                                            <?php else: ?>
                                                <span class="bg-indigo-50 text-indigo-700 text-[9px] font-black px-2 py-1 rounded border border-indigo-100 truncate max-w-[80px] inline-block text-center"><?= $r['fu_tmpl'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between text-[11px] text-slate-500 bg-slate-50 p-2.5 rounded-xl font-medium border border-slate-100">
                                        <div>Minat: <span class="font-bold text-indigo-600"><?= $r['klas'] ?></span></div>
                                        <?php if($r['fu_tmpl'] !== 'Baru'): ?>
                                            <div>FU: <span class="font-bold"><?= $r['fu_text'] ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex gap-2">
                                        <form class="flex-1 input-group shadow-sm" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" onchange="showWA(this)" class="crm-input w-full p-2 text-[11px] font-bold m-0 border-r-0 focus:ring-0 focus:border-indigo-500" required>
                                                <option value="">Pilih Template...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-indigo-50 border border-indigo-200 border-l-0 text-indigo-600 px-4 rounded-r-xl hover:bg-indigo-600 hover:text-white transition-colors"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                        <button type="button" onclick="openCustomMsgModal('<?= $r['nowa'] ?>', '<?= htmlspecialchars($r['nama'], ENT_QUOTES) ?>')" class="bg-purple-50 border border-purple-200 text-purple-600 px-3 py-2 rounded-xl hover:bg-purple-600 hover:text-white transition-all shadow-sm flex items-center justify-center">
                                            <i class="fas fa-keyboard"></i>
                                        </button>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Hapus prospek ini?')">
                                            <input type="hidden" name="delete_prospect" value="1"><input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <button type="submit" class="bg-white border border-slate-200 text-slate-400 px-3 py-2 rounded-xl hover:bg-rose-50 hover:text-rose-500 hover:border-rose-200 transition-all shadow-sm flex items-center justify-center">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($pages_o > 1): ?>
                    <div class="p-3 border-t border-slate-100 bg-white flex justify-between items-center text-[11px] text-slate-500 font-bold">
                        <span>Hal <?= $page_o ?> / <?= $pages_o ?></span>
                        <div class="flex gap-2">
                            <?php if($page_o > 1): ?><a href="<?= buildPageUrl('page_o', $page_o-1) ?>" class="bg-white border border-slate-200 px-3 py-1.5 rounded-lg hover:text-indigo-600 shadow-sm transition-colors">Prev</a><?php endif; ?>
                            <?php if($page_o < $pages_o): ?><a href="<?= buildPageUrl('page_o', $page_o+1) ?>" class="bg-white border border-slate-200 px-3 py-1.5 rounded-lg hover:text-indigo-600 shadow-sm transition-colors">Next</a><?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- BENTO 6: DATA MANUAL -->
                <div class="col-span-12 xl:col-span-5 bg-white border border-slate-200/60 rounded-3xl shadow-xl shadow-slate-200/30 overflow-hidden flex flex-col animate-fade-in" style="animation-delay: 0.3s;">
                    <div class="p-5 border-b border-slate-100 bg-slate-50/80 flex justify-between items-center">
                        <h3 class="font-black text-sm text-slate-800 flex items-center gap-2.5">
                            <div class="w-3 h-3 rounded-full bg-amber-500 shadow-[0_0_10px_rgba(245,158,11,0.5)]"></div> Manual / CSV
                            <span class="bg-indigo-50 text-indigo-600 text-[10px] px-2 py-1 rounded-lg ml-1"><?= $total_m ?> Data</span>
                        </h3>
                        <div class="flex items-center gap-3">
                            <form method="POST" onsubmit="return confirm('Peringatan: Hapus semua antrean Manual?')" class="m-0 hidden md:block">
                                <input type="hidden" name="hapus_semua_manual" value="1">
                                <button type="submit" class="text-rose-500 hover:text-rose-700 text-[10px] font-bold flex items-center"><i class="fas fa-trash mr-1"></i>Hapus Semua</button>
                            </form>
                            <div class="w-px h-4 bg-slate-200 hidden md:block"></div>
                            <label class="text-[11px] font-bold text-slate-600 cursor-pointer flex items-center hover:text-indigo-600 bg-white px-2 py-1 rounded-lg border border-slate-200 shadow-sm"><input type="checkbox" id="checkAllManual" class="mr-2 w-3.5 h-3.5 accent-amber-500 rounded">Pilih Semua</label>
                        </div>
                    </div>
                    
                    <div class="flex-1 custom-scroll max-h-[600px] bg-white">
                        <!-- Desktop Table -->
                        <div class="hidden md:block overflow-x-auto">
                            <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">
                                <thead class="text-[10px] text-slate-400 font-bold bg-white border-b border-slate-100 uppercase tracking-wider">
                                    <tr><th class="p-4 w-10 text-center">#</th><th class="p-4">Info Prospek</th><th class="p-4 text-right">Aksi</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php if(empty($targetManual_paged)): ?>
                                        <tr><td colspan="3" class="p-8 text-center text-slate-400 text-xs font-semibold">Tabel kosong.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($targetManual_paged as $r): ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors group">
                                            <td class="p-4 text-center"><input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-manual w-4 h-4 accent-amber-500 rounded cursor-pointer"></td>
                                            <td class="p-4">
                                                <div class="font-black text-slate-800 text-[13px]"><?= $r['nama'] ?></div>
                                                <div class="text-[11px] text-slate-500 mt-1 font-medium"><?= $r['nowa'] ?> <a href="https://wa.me/<?= $r['clean_wa'] ?>" target="_blank" class="text-emerald-500"><i class="fab fa-whatsapp"></i></a></div>
                                                <div class="mt-1 status-cell">
                                                    <?php if($r['fu_tmpl'] === 'Baru'): ?><span class="text-[9px] text-amber-500 font-bold">Belum disapa</span>
                                                    <?php else: ?><span class="text-[9px] text-indigo-500 font-bold"><i class="fas fa-check-double"></i> <?= $r['fu_tmpl'] ?></span><?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="p-4 text-right">
                                                <div class="flex justify-end gap-1.5 items-center">
                                                    <form class="input-group shadow-sm w-36" onsubmit="submitSingleAjax(event, this)">
                                                        <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                        <select name="template_id" onchange="showWA(this)" class="crm-input w-full p-2 text-[11px] font-bold m-0 border-r-0 focus:ring-0 focus:border-indigo-500" required>
                                                            <option value="">Kirim...</option>
                                                            <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="bg-amber-50 border border-amber-200 border-l-0 text-amber-600 px-3 rounded-r-lg hover:bg-amber-500 hover:text-white transition-colors"><i class="fas fa-paper-plane text-[10px]"></i></button>
                                                    </form>
                                                    <button type="button" onclick="openCustomMsgModal('<?= $r['nowa'] ?>', '<?= htmlspecialchars($r['nama'], ENT_QUOTES) ?>')" class="bg-purple-50 border border-purple-200 text-purple-600 p-2 rounded-lg hover:bg-purple-600 hover:text-white transition-colors shadow-sm ml-0.5" title="Ketik Pesan"><i class="fas fa-keyboard text-[11px]"></i></button>
                                                    <form method="POST" class="m-0" onsubmit="return confirm('Hapus data ini?')">
                                                        <input type="hidden" name="delete_prospect" value="1"><input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                                        <button type="submit" class="bg-white border border-slate-200 text-slate-400 p-2 rounded-lg hover:bg-rose-50 hover:text-rose-500 transition-colors shadow-sm ml-0.5"><i class="fas fa-trash-alt text-[11px]"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View (Manual) -->
                        <div class="block md:hidden divide-y divide-slate-100">
                            <?php if(empty($targetManual_paged)): ?>
                                <div class="p-6 text-center text-slate-400 text-xs font-semibold">Data kosong.</div>
                            <?php else: ?>
                                <?php foreach($targetManual_paged as $r): ?>
                                <div class="p-4 space-y-3 bg-white">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex items-center gap-3">
                                            <input type="checkbox" value="<?= $r['nowa'] ?>" class="cb-target cb-manual w-5 h-5 accent-amber-500 rounded cursor-pointer">
                                            <div>
                                                <div class="font-black text-slate-800 text-[14px]"><?= $r['nama'] ?></div>
                                                <div class="text-[11px] text-slate-500 font-semibold mt-0.5"><?= $r['nowa'] ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <form class="flex-1 input-group shadow-sm" onsubmit="submitSingleAjax(event, this)">
                                            <input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <select name="template_id" onchange="showWA(this)" class="crm-input w-full p-2 text-[11px] font-bold m-0 border-r-0 focus:ring-0 focus:border-indigo-500" required>
                                                <option value="">Pilih Template...</option>
                                                <?php foreach($pesanTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="bg-amber-50 border border-amber-200 border-l-0 text-amber-600 px-4 rounded-r-xl hover:bg-amber-500 hover:text-white transition-colors"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                        <button type="button" onclick="openCustomMsgModal('<?= $r['nowa'] ?>', '<?= htmlspecialchars($r['nama'], ENT_QUOTES) ?>')" class="bg-purple-50 border border-purple-200 text-purple-600 px-3 py-2 rounded-xl hover:bg-purple-600 hover:text-white transition-all shadow-sm flex items-center justify-center">
                                            <i class="fas fa-keyboard"></i>
                                        </button>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Hapus data?')">
                                            <input type="hidden" name="delete_prospect" value="1"><input type="hidden" name="contact_id" value="<?= $r['nowa'] ?>">
                                            <button type="submit" class="bg-white border border-slate-200 text-slate-400 px-3 py-2 rounded-xl hover:bg-rose-50 hover:text-rose-500 transition-all shadow-sm flex items-center justify-center">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($pages_m > 1): ?>
                    <div class="p-3 border-t border-slate-100 bg-white flex justify-between items-center text-[11px] text-slate-500 font-bold">
                        <span>Hal <?= $page_m ?> / <?= $pages_m ?></span>
                        <div class="flex gap-2">
                            <?php if($page_m > 1): ?><a href="<?= buildPageUrl('page_m', $page_m-1) ?>" class="bg-white border border-slate-200 px-3 py-1.5 rounded-lg hover:text-amber-600 shadow-sm transition-colors">Prev</a><?php endif; ?>
                            <?php if($page_m < $pages_m): ?><a href="<?= buildPageUrl('page_m', $page_m+1) ?>" class="bg-white border border-slate-200 px-3 py-1.5 rounded-lg hover:text-amber-600 shadow-sm transition-colors">Next</a><?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- ================== MODALS & POPUPS ================== -->

<!-- Modal Riwayat Template -->
<div id="modalHistory" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in border border-slate-100">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80">
            <h3 class="font-black text-slate-800 text-sm"><i class="fas fa-history text-indigo-500 mr-2"></i> Riwayat Follow-Up</h3>
            <button type="button" onclick="closeModal('modalHistory')" class="w-8 h-8 rounded-full bg-white border border-slate-200 text-slate-400 hover:text-rose-500 hover:border-rose-200 transition-colors shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 max-h-[60vh] overflow-y-auto custom-scroll relative bg-white">
            <div id="historyContent" class="space-y-5 timeline-line relative pl-4"></div>
        </div>
    </div>
</div>

<!-- Live Preview WA -->
<div id="waPreview" class="animate-fade-in">
    <div class="bg-[#075e54] text-white p-3 flex items-center justify-between shadow-md">
        <div class="flex items-center gap-2"><i class="fas fa-eye text-sm"></i> <p class="text-xs font-bold tracking-wide">Live Preview Teks</p></div>
        <button onclick="closeWA()" class="opacity-70 hover:opacity-100 p-1 transition-opacity"><i class="fas fa-times text-lg"></i></button>
    </div>
    <div class="chat-bg p-5 h-56 overflow-y-auto custom-scroll shadow-inner relative">
        <div class="bubble-left p-3.5 text-[13px] text-[#111b21] mb-2 inline-block whitespace-pre-wrap leading-relaxed border border-white/50 font-medium" id="waText">...</div>
    </div>
</div>

<!-- Modal Tambah Prospek -->
<div id="modalTambah" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80">
            <h3 class="font-black text-slate-800 text-sm"><i class="fas fa-user-plus text-indigo-500 mr-2"></i> Tambah Prospek</h3>
            <button type="button" onclick="closeModal('modalTambah')" class="w-8 h-8 rounded-full bg-white border border-slate-200 text-slate-400 hover:text-rose-500 transition-colors shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4 bg-white">
            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase mb-1.5 block">Nama Lengkap</label>
                <input type="text" name="nama_baru" required class="crm-input w-full p-3 text-xs font-medium" placeholder="Contoh: Budi Santoso">
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase mb-1.5 block">WhatsApp</label>
                <input type="number" name="nowa_baru" required class="crm-input w-full p-3 text-xs font-medium" placeholder="Awali dengan 08 / 62">
            </div>
            <button type="submit" name="tambah_prospek" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold text-xs hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-200 mt-2">Simpan Data</button>
        </form>
    </div>
</div>

<!-- Modal Import CSV -->
<div id="modalCSV" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl animate-fade-in">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80">
            <h3 class="font-black text-slate-800 text-sm"><i class="fas fa-file-csv text-indigo-500 mr-2"></i> Import File CSV</h3>
            <button type="button" onclick="closeModal('modalCSV')" class="w-8 h-8 rounded-full bg-white border border-slate-200 text-slate-400 hover:text-rose-500 transition-colors shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-5 bg-white" onsubmit="showManualLoading()">
            <div class="bg-indigo-50/80 p-3.5 rounded-xl border border-indigo-100 text-[11px] text-indigo-700 font-semibold flex items-start gap-2">
                <i class="fas fa-info-circle mt-0.5"></i> <span>Format CSV: Kolom 1 = <b>Nama</b>, Kolom 2 = <b>WhatsApp</b></span>
            </div>
            <input type="file" name="file_csv" accept=".csv" required class="w-full p-5 border-2 border-dashed border-slate-300 bg-slate-50 rounded-2xl text-xs font-bold text-slate-500 cursor-pointer hover:bg-slate-100 hover:border-indigo-400 transition-colors focus:outline-none">
            <button type="submit" name="upload_csv" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold text-xs hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-200">Mulai Upload</button>
        </form>
    </div>
</div>

<!-- Modal Chat Detail User -->
<div id="modalDetailChat" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4 w-full">
    <div class="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl animate-fade-in flex flex-col h-[80vh] max-h-[600px] border border-slate-200">
        <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center border border-emerald-200 shadow-sm"><i class="fab fa-whatsapp text-lg"></i></div>
                <h3 class="font-black text-sm text-slate-800">Review Percakapan</h3>
            </div>
            <button type="button" onclick="closeModal('modalDetailChat')" class="w-8 h-8 rounded-full bg-white text-slate-500 border border-slate-200 hover:bg-rose-50 hover:text-rose-500 flex items-center justify-center transition-colors shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <div class="chat-bg flex-1 p-5 overflow-y-auto custom-scroll flex flex-col gap-5 shadow-inner" id="detailChatContent"></div>
    </div>
</div>

<!-- [FITUR BARU] MODAL PESAN CUSTOM -->
<div id="modalCustomMsg" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl w-[96%] sm:max-w-md mx-auto overflow-hidden shadow-2xl animate-fade-in border border-slate-100">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80">
            <h3 class="font-black text-slate-800 text-sm"><i class="fas fa-keyboard text-purple-600 mr-2"></i> Ketik Pesan Langsung</h3>
            <button type="button" onclick="closeModal('modalCustomMsg')" class="w-8 h-8 rounded-full bg-white border border-slate-200 text-slate-400 hover:text-rose-500 transition-colors shadow-sm"><i class="fas fa-times"></i></button>
        </div>
        <form onsubmit="submitCustomMsgAjax(event)" class="p-5 sm:p-6 space-y-5 bg-white">
            <input type="hidden" id="custom_contact_id" name="contact_id">
            
            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase mb-2 block">Kirim ke: <span id="custom_contact_name" class="text-purple-600 bg-purple-50 px-2 py-0.5 rounded ml-1"></span></label>
                <textarea id="custom_msg_text" name="custom_message" rows="4" required class="crm-input w-full p-3.5 text-[13px] font-medium leading-relaxed" placeholder="Ketik pesan bebas di sini...&#10;(Gunakan tag [nama] otomatis memanggil prospek)"></textarea>
            </div>
            
            <div class="bg-slate-50/50 p-4 rounded-2xl border border-slate-100">
                <label class="text-[10px] font-black text-slate-400 uppercase mb-2 block">
                    <i class="fas fa-history mr-1"></i> Riwayat Teks (Klik untuk pakai)
                </label>
                <div class="flex flex-wrap gap-2 max-h-[100px] overflow-y-auto custom-scroll">
                    <?php if(empty($customHistories)): ?>
                        <span class="text-[11px] text-slate-400 font-medium italic p-1">Belum ada riwayat pesan custom.</span>
                    <?php else: ?>
                        <?php foreach($customHistories as $ch): ?>
                            <button type="button" onclick="useCustomHistory(this)" data-text="<?= htmlspecialchars($ch['msg_text'], ENT_QUOTES) ?>" class="bg-white hover:bg-purple-50 text-slate-600 hover:text-purple-700 px-3 py-1.5 rounded-lg border border-slate-200 hover:border-purple-300 text-[11px] font-semibold text-left truncate max-w-full transition-colors shadow-sm">
                                <?= htmlspecialchars(substr($ch['msg_text'], 0, 40)) ?>...
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" id="btnSendCustom" class="w-full bg-purple-600 text-white py-3.5 rounded-xl font-black text-[13px] hover:bg-purple-700 transition-colors shadow-lg shadow-purple-200 mt-2 flex items-center justify-center"><i class="fas fa-paper-plane mr-2"></i> Kirim Pesan Sekarang</button>
        </form>
    </div>
</div>

<!-- ================== SCRIPT JAVASCRIPT ================== -->
<script>
const templates = <?= json_encode($jsTemplates) ?>;

document.addEventListener("DOMContentLoaded", function() { 
    let scrollpos = sessionStorage.getItem('scrollpos');
    if (scrollpos) { window.scrollTo(0, scrollpos); sessionStorage.removeItem('scrollpos'); }
    
    <?php if ($notification): ?>showToast("<?= addslashes($notification) ?>", "<?= $notificationType ?>");<?php endif; ?>
    <?php if ($f_minat || $search || $f_start || $f_end): ?>
        let filterMsg = "Filter berhasil diterapkan!";
        <?php if($f_minat): ?> filterMsg = "Menampilkan data untuk minat: <?= addslashes($f_minat) ?>"; <?php endif; ?>
        showToast(filterMsg, 'info');
    <?php endif; ?>
});

window.addEventListener("beforeunload", function() { sessionStorage.setItem('scrollpos', window.scrollY); });

function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    
    let colors = 'bg-emerald-500 text-white shadow-emerald-500/30';
    let icon = 'fa-check-circle';
    
    if (type === 'error') { colors = 'bg-rose-500 text-white shadow-rose-500/30'; icon = 'fa-times-circle'; }
    else if (type === 'info' || type === 'warning') { colors = 'bg-indigo-600 text-white shadow-indigo-500/30'; icon = 'fa-info-circle'; }
    
    toast.className = `flex items-center gap-3 px-5 py-3.5 rounded-2xl shadow-xl text-sm font-bold transform transition-all duration-300 translate-x-full opacity-0 pointer-events-auto border border-white/10 ${colors}`;
    toast.innerHTML = `<i class="fas ${icon} text-lg"></i> <span>${message}</span>`;
    
    container.appendChild(toast);
    setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
    setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 4000);
}

function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    if (window.innerWidth <= 768) { sidebar.classList.toggle('active'); } 
    else { sidebar.classList.toggle('collapsed'); }
}

function showWA(s) { 
    const p = document.getElementById('waPreview'), t = document.getElementById('waText'); 
    if(s.value && templates[s.value]) { t.innerText = templates[s.value].replace(/\[nama\]|\{nama\}/gi, '[Nama Prospek]'); p.style.display = 'block'; } 
    else { p.style.display = 'none'; }
}
function closeWA() { document.getElementById('waPreview').style.display = 'none'; }
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function showManualLoading() { document.getElementById('loader').classList.remove('hidden'); document.getElementById('progressStatus').innerText = "Memproses unggahan CSV..."; }

function updateCount() { document.getElementById('countCheck').innerText = document.querySelectorAll('.cb-target:checked').length; }
document.getElementById('checkAllOrganik')?.addEventListener('change', function() { document.querySelectorAll('.cb-organik').forEach(c => c.checked = this.checked); updateCount(); });
document.getElementById('checkAllManual')?.addEventListener('change', function() { document.querySelectorAll('.cb-manual').forEach(c => c.checked = this.checked); updateCount(); });
document.querySelectorAll('.cb-target').forEach(c => c.addEventListener('change', updateCount));

function showDetail(userMsg, sysMsg) {
    if (!userMsg || userMsg.trim() === '') userMsg = '(Format Manual / Pesan kosong)';
    const formatMsg = (str) => str.replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, '<br>');
    let html = `
    <div class="flex items-end gap-2.5 pr-10 animate-fade-in">
        <div class="w-7 h-7 rounded-full bg-slate-300 border border-white flex items-center justify-center shrink-0 shadow-sm"><i class="fas fa-user text-white text-[11px]"></i></div>
        <div class="bubble-left p-3.5 text-[13px] text-[#111b21] relative leading-relaxed font-medium">
            <div class="text-[10px] text-slate-400 font-bold mb-1.5 opacity-80 uppercase tracking-wider">User:</div>${formatMsg(userMsg)}
        </div>
    </div>`;
    if (sysMsg && sysMsg.trim() !== '') {
        html += `
        <div class="flex items-end gap-2.5 pl-10 flex-row-reverse animate-fade-in" style="animation-delay: 0.1s">
            <div class="w-7 h-7 rounded-full bg-indigo-500 border border-white flex items-center justify-center shrink-0 shadow-sm"><i class="fas fa-robot text-white text-[11px]"></i></div>
            <div class="bubble-right p-3.5 text-[13px] text-[#111b21] relative leading-relaxed font-medium">
                <div class="text-[10px] text-indigo-700 font-bold mb-1.5 border-b border-indigo-200/50 pb-1.5 flex justify-between items-center uppercase tracking-wider"><span>Sistem:</span><i class="fas fa-check-double text-indigo-500"></i></div>${formatMsg(sysMsg)}
            </div>
        </div>`;
    }
    document.getElementById('detailChatContent').innerHTML = html; openModal('modalDetailChat');
}

function showHistoryModal(btn) {
    const rawHist = btn.dataset.history;
    const content = document.getElementById('historyContent');
    if(!rawHist) { content.innerHTML = '<div class="text-xs text-slate-400 italic font-semibold p-4 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">Belum ada riwayat sistem.</div>'; } 
    else {
        let items = rawHist.split('|||'); let html = '';
        items.forEach((item, index) => {
            let parts = item.split(' - '); let time = parts[0] || '?'; let tmpl = parts[1] || 'Pesan Langsung';
            html += `
            <div class="relative z-10 animate-fade-in mb-3" style="animation-delay: ${index * 0.05}s">
                <div class="absolute -left-5 top-2 w-3.5 h-3.5 bg-indigo-500 border-[3px] border-white rounded-full shadow-sm"></div>
                <div class="bg-white border border-slate-100 p-3.5 rounded-2xl shadow-sm hover:shadow-md transition-shadow">
                    <div class="text-[10px] font-black text-slate-400 mb-1"><i class="far fa-clock mr-1"></i>${time}</div>
                    <div class="text-[12px] font-bold text-slate-700 leading-snug"><i class="fas fa-paper-plane text-indigo-500 mr-1.5 text-[10px]"></i> ${tmpl}</div>
                </div>
            </div>`;
        });
        content.innerHTML = html;
    }
    openModal('modalHistory');
}

// SCRIPT FITUR PESAN CUSTOM BARU
function openCustomMsgModal(contactId, nama) {
    document.getElementById('custom_contact_id').value = contactId;
    document.getElementById('custom_contact_name').innerText = nama;
    document.getElementById('custom_msg_text').value = '';
    openModal('modalCustomMsg');
}
function useCustomHistory(btn) { document.getElementById('custom_msg_text').value = btn.dataset.text; }

async function submitCustomMsgAjax(e) {
    e.preventDefault();
    const contactId = document.getElementById('custom_contact_id').value;
    const msg = document.getElementById('custom_msg_text').value;
    const btn = document.getElementById('btnSendCustom');
    
    if(!msg.trim()) { showToast('Pesan tidak boleh kosong!', 'warning'); return; }
    
    const oriHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Mengirim...'; btn.disabled = true;
    
    try {
        let fd = new FormData(); fd.append('ajax_send', '1'); fd.append('contact_id', contactId); fd.append('custom_message', msg);
        let res = await fetch('', { method: 'POST', body: fd }); let json = await res.json();
        if(json.status === 'success') {
            showToast(`Pesan terkirim ke ${json.nama}`, 'success');
            closeModal('modalCustomMsg');
            setTimeout(() => { sessionStorage.setItem('scrollpos', window.scrollY); location.reload(); }, 1200);
        } else showToast('Gagal Terkirim: ' + json.msg, 'error');
    } catch(err) { showToast('Terjadi kesalahan jaringan.', 'error'); } 
    finally { btn.innerHTML = oriHtml; btn.disabled = false; }
}

async function submitMassAjax(e) { 
    e.preventDefault();
    const sel = document.querySelectorAll('.cb-target:checked'); const tmplId = document.querySelector('select[name="template_id_multi"]').value;
    if(!sel.length || !tmplId) { showToast('Pilih minimal 1 prospek dan 1 template!', 'warning'); return; } 
    const loader = document.getElementById('loader'); const pBar = document.getElementById('progressBar'); const pText = document.getElementById('progressText'); const pStat = document.getElementById('progressStatus');
    loader.classList.remove('hidden'); window.scrollTo(0, 0);
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
    sessionStorage.setItem('scrollpos', window.scrollY); 
    pStat.innerHTML = `<span class="text-emerald-600 font-bold">Selesai!</span> ${success} Terkirim, ${fail} Gagal. Merefresh...`;
    setTimeout(() => location.reload(), 1000);
}

async function submitSingleAjax(e, form) {
    e.preventDefault();
    const contactId = form.querySelector('input[name="contact_id"]').value;
    const tmplId = form.querySelector('select[name="template_id"]').value;
    if(!tmplId) { showToast('Pilih template dahulu!', 'warning'); return; }
    
    const btn = form.querySelector('button[type="submit"]');
    const oriHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin text-[10px]"></i>'; btn.disabled = true;
    
    try {
        let fd = new FormData(); fd.append('ajax_send', '1'); fd.append('contact_id', contactId); fd.append('template_id', tmplId);
        let res = await fetch('', { method: 'POST', body: fd }); let json = await res.json();
        
        if(json.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check text-[10px]"></i>';
            btn.classList.replace('bg-indigo-50', 'bg-emerald-500'); btn.classList.replace('text-indigo-600', 'text-white');
            
            showToast(`Pesan berhasil dikirim kepada ${json.nama}`, 'success');
            setTimeout(() => { sessionStorage.setItem('scrollpos', window.scrollY); location.reload(); }, 1200);
        } else {
            showToast('Gagal Terkirim: ' + json.msg, 'error'); btn.innerHTML = oriHtml; btn.disabled = false;
        }
    } catch(err) {
        showToast('Terjadi kesalahan jaringan.', 'error'); btn.innerHTML = oriHtml; btn.disabled = false;
    }
}
// --- FITUR REAL-TIME POLLING ---
let lastLogId = <?= $max_log_id ?? 0 ?>;

function checkForNewMessages() {
    if (lastLogId === 0) return; // Abaikan jika database masih kosong
    
    fetch(`?ajax_poll=1&last_id=${lastLogId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success' && data.new_count > 0) {
                showRealtimeToast(data.new_count);
            }
        })
        .catch(err => console.error('Polling error:', err));
}

function showRealtimeToast(count) {
    // Cegah duplikasi notifikasi jika sudah muncul
    if (document.getElementById('realtime-toast')) return;
    
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.id = 'realtime-toast';
    toast.className = `flex items-center justify-between gap-4 px-5 py-4 rounded-2xl shadow-2xl text-sm font-bold transform transition-all duration-300 translate-x-full opacity-0 pointer-events-auto border border-indigo-200 bg-indigo-50 text-indigo-700 cursor-pointer hover:bg-indigo-100 hover:scale-[1.02]`;
    
    toast.innerHTML = `
        <div class="flex items-center gap-3">
            <i class="fas fa-bell animate-bounce text-xl"></i> 
            <div class="flex flex-col">
                <span>Pesan Baru Masuk!</span>
                <span class="text-xs font-medium text-indigo-500">Ada ${count} prospek baru menunggu.</span>
            </div>
        </div>
        <button class="bg-indigo-600 text-white px-4 py-2 rounded-xl text-xs hover:bg-indigo-700 shadow-md transition-all">Muat Ulang</button>
    `;
    
    // Saat diklik, simpan posisi scroll dan refresh
    toast.onclick = function() {
        sessionStorage.setItem('scrollpos', window.scrollY);
        location.reload();
    };

    container.appendChild(toast);
    setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 50);
}

// Jalankan pengecekan setiap 10 detik (10000 ms)
setInterval(checkForNewMessages, 10000);
</script>
</body>
</html>