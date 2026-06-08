<?php
session_start();
require_once 'auth_checkwa.php';

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

try {
    require_once 'config.php';
} catch (Exception $e) {
    error_log("Config loading failed: " . $e->getMessage());
    die("Configuration error");
}

if (!isset($apiUrl) && defined('ONESENDER_API_URL')) $apiUrl = ONESENDER_API_URL;
if (!isset($apiToken) && defined('ONESENDER_API_TOKEN')) $apiToken = ONESENDER_API_TOKEN;

if (empty($apiUrl) || empty($apiToken)) {
    die("Error: WhatsApp API configuration is missing. Check config.php");
}

if (isset($conn) && $conn) {
    mysqli_set_charset($conn, "utf8mb4");
    
    // AUTO-MIGRASI LITE: Menambahkan 2 kolom untuk keperluan Filter & Auto-Hide
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM log_wa");
    if($res) while($r = $res->fetch_assoc()) $cols[] = $r['Field'];
    
    if(!in_array('last_followup_at', $cols)) $conn->query("ALTER TABLE log_wa ADD last_followup_at DATETIME NULL");
    if(!in_array('is_form_sent', $cols)) $conn->query("ALTER TABLE log_wa ADD is_form_sent TINYINT(1) DEFAULT 0");
}

// Inisialisasi session follow-up (Sistem Harian)
if (!isset($_SESSION['followed_up_today'])) {
    $_SESSION['followed_up_today'] = [];
}

// Notifikasi
$notification = $notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notificationType'];
    unset($_SESSION['notification'], $_SESSION['notificationType']);
}

// ==================================================================
// FUNGSI UTAMA KIRIM PESAN
// ==================================================================
function kirimPesan($recipient, $message, $apiUrl, $apiToken) {
    if (empty($recipient) || empty($message)) return ['status' => 'GAGAL', 'message' => 'Parameter tidak lengkap'];
    
    $cleanNumber = preg_replace('/\D/', '', $recipient);
    if (strlen($cleanNumber) > 0 && $cleanNumber[0] === '0') $cleanNumber = '62' . substr($cleanNumber, 1);
    if (!preg_match('/^62\d{9,12}$/', $cleanNumber)) return ['status' => 'GAGAL', 'message' => 'Nomor tidak valid'];
    
    $data = ["recipient_type" => "individual", "to" => $cleanNumber, "type" => "text", "text" => ["body" => $message]];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['status' => 'GAGAL', 'message' => "Error: $error"];
    if ($httpCode === 200) return ['status' => 'TERKIRIM', 'message' => 'Berhasil'];
    return ['status' => 'GAGAL', 'message' => "HTTP $httpCode: Gagal mengirim"];
}

// ==================================================================
// ATURAN FILTER KETAT & UTILITAS
// ==================================================================
function classifyMessage($message) {
    if (empty($message)) return 'Data CSV/Manual';
    $m = strtolower($message);
    
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

function getExcludedContacts($conn) {
    $excluded = [];
    if (!$conn) return $excluded;
    $tables = ['peserta', 'calon_peserta', 'pengampu'];
    foreach ($tables as $tbl) {
        $res = $conn->query("SHOW TABLES LIKE '$tbl'");
        if ($res && $res->num_rows > 0) {
            $q = $conn->query("SELECT DISTINCT nowa FROM $tbl WHERE nowa IS NOT NULL AND nowa != ''");
            if ($q) {
                while($r = $q->fetch_assoc()) { 
                    $n = preg_replace('/\D/', '', $r['nowa']); 
                    if(strpos($n,'0')===0)$n='62'.substr($n,1); 
                    $excluded[$n] = true; 
                }
            }
        }
    }
    return $excluded;
}

function getBlockedContacts($conn) {
    $blocked = [];
    if (!$conn) return $blocked;
    $result = $conn->query("SELECT DISTINCT nowa FROM blocked_peserta");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $n = preg_replace('/\D/', '', $row['nowa']);
            if (substr($n,0,1)==='0') $n = '62'.substr($n,1);
            $blocked[$n] = true;
        }
    }
    return $blocked;
}

// ==================================================================
// PROSES POST & AKSI
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // EXPORT DATA (CSV)
    if (isset($_POST['export_csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Prospek_JWD_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Nama', 'Nomor WhatsApp', 'Minat Program', 'Tanggal Masuk', 'Tgl Terakhir Follow-Up', 'Pesan Awal']);
        
        $expSql = "SELECT nowa, MAX(nama) as nama, message as last_message, MAX(created_at) as created_at, MAX(last_followup_at) as last_followup_at FROM log_wa WHERE is_form_sent = 0 GROUP BY nowa ORDER BY created_at DESC";
        $expRes = $conn->query($expSql);
        while($r = $expRes->fetch_assoc()) {
            $minat = classifyMessage($r['last_message']);
            if($minat !== 'Lainnya') {
                fputcsv($output, [$r['nama'], $r['nowa'], $minat, $r['created_at'], $r['last_followup_at'], $r['last_message']]);
            }
        }
        fclose($output); exit;
    }

    $excludedNow = getExcludedContacts($conn);

    // 1. TAMBAH PROSPEK MANUAL
    if (isset($_POST['tambah_prospek'])) {
        $nama = trim($_POST['nama_baru']);
        $nowa = trim($_POST['nowa_baru']);
        $cleanNumber = preg_replace('/\D/', '', $nowa);
        if (strlen($cleanNumber) > 0 && $cleanNumber[0] === '0') $cleanNumber = '62' . substr($cleanNumber, 1);
        
        if (!empty($nama) && !empty($cleanNumber)) {
            $checkStmt = $conn->prepare("SELECT id FROM log_wa WHERE nowa = ? LIMIT 1");
            $checkStmt->bind_param("s", $cleanNumber);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0 || isset($excludedNow[$cleanNumber])) {
                $_SESSION['notification'] = "⚠️ Nomor sudah ada di database atau sudah terdaftar.";
                $_SESSION['notificationType'] = 'warning';
            } else {
                $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, 'Data CSV/Manual', NOW())");
                $stmt->bind_param("ss", $cleanNumber, $nama);
                if ($stmt->execute()) {
                    $_SESSION['notification'] = "✅ Calon santri '$nama' berhasil ditambahkan.";
                    $_SESSION['notificationType'] = 'success';
                }
                $stmt->close();
            }
            $checkStmt->close();
        }
        header("Location: pesan.php"); exit;
    }

    // 2. UPLOAD CSV
    if (isset($_POST['upload_csv']) && isset($_FILES['file_csv'])) {
        $file = $_FILES['file_csv']['tmp_name'];
        if (strtolower(pathinfo($_FILES['file_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $_SESSION['notification'] = "❌ Format salah! Harap upload file .csv";
            $_SESSION['notificationType'] = 'error';
            header("Location: pesan.php"); exit;
        }

        if (($handle = fopen($file, "r")) !== FALSE) {
            $sukses = $duplikat = 0;
            $stmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, 'Data CSV/Manual', NOW())");
            $checkStmt = $conn->prepare("SELECT id FROM log_wa WHERE nowa = ? LIMIT 1");

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $nama = trim($data[0] ?? '');
                $nowa = trim($data[1] ?? '');
                if(strtolower($nama) === 'nama' || strtolower($nowa) === 'nomor wa') continue;
                
                $cleanNumber = preg_replace('/\D/', '', $nowa);
                if (strlen($cleanNumber) > 0 && $cleanNumber[0] === '0') $cleanNumber = '62' . substr($cleanNumber, 1);
                
                if (!empty($nama) && !empty($cleanNumber) && is_numeric($cleanNumber)) {
                    $checkStmt->bind_param("s", $cleanNumber);
                    $checkStmt->execute();
                    if ($checkStmt->get_result()->num_rows > 0 || isset($excludedNow[$cleanNumber])) {
                        $duplikat++; continue;
                    }

                    $stmt->bind_param("ss", $cleanNumber, $nama);
                    if ($stmt->execute()) $sukses++;
                }
            }
            fclose($handle); $stmt->close(); $checkStmt->close();
            if ($sukses > 0) {
                $_SESSION['notification'] = "✅ Berhasil import $sukses data baru. ($duplikat dilewati).";
                $_SESSION['notificationType'] = 'success';
            } else if ($duplikat > 0) {
                $_SESSION['notification'] = "⚠️ Tidak ada data baru. $duplikat data terdeteksi duplikat.";
                $_SESSION['notificationType'] = 'warning';
            }
        }
        header("Location: pesan.php"); exit;
    }

    // 3. Update status blokir
    if (isset($_POST['update_block_status'])) {
        $contactId = $_POST['contact_id'] ?? '';
        $currentStatus = $_POST['current_status'] ?? '';
        if ($contactId) {
            if ($currentStatus === 'unblock') {
                $conn->query("DELETE FROM blocked_peserta WHERE nowa = '$contactId'");
                $_SESSION['notification'] = "✅ Kontak dibuka blokirnya"; $_SESSION['notificationType'] = 'success';
            } else {
                $conn->query("INSERT IGNORE INTO blocked_peserta (nowa) VALUES ('$contactId')");
                $_SESSION['notification'] = "🔒 Kontak diblokir"; $_SESSION['notificationType'] = 'warning';
            }
        }
        $qString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
        header("Location: pesan.php" . $qString); exit;
    }

    // 4. Hapus / Arsipkan prospek
    if (isset($_POST['delete_prospect'])) {
        $contactId = $_POST['contact_id'] ?? '';
        if ($contactId) {
            $conn->query("DELETE FROM log_wa WHERE nowa = '$contactId'");
            $_SESSION['notification'] = "✅ Data dihapus permanen"; $_SESSION['notificationType'] = 'success';
        }
        header("Location: pesan.php"); exit;
    }
    
    if (isset($_POST['archive_prospect'])) {
        $contactId = $_POST['contact_id'] ?? '';
        if ($contactId) {
            // Gunakan is_form_sent = 1 sebagai penanda "Selesai/Arsip" agar hilang dari dashboard
            $conn->query("UPDATE log_wa SET is_form_sent = 1 WHERE nowa = '$contactId'");
            $_SESSION['notification'] = "✅ Prospek diarsipkan (Disembunyikan dari antrean)"; $_SESSION['notificationType'] = 'success';
        }
        header("Location: pesan.php"); exit;
    }

    if (isset($_POST['hapus_semua_blokir'])) { $conn->query("TRUNCATE TABLE blocked_peserta"); header("Location: pesan.php"); exit; }
    if (isset($_POST['hapus_semua_calon_peserta'])) { $conn->query("TRUNCATE TABLE calon_peserta"); header("Location: pesan.php"); exit; }

    // 5. KIRIM MASSAL & SINGLE
    if (isset($_POST['send_reminder_multi']) || isset($_POST['send_reminder'])) {
        $contacts = isset($_POST['send_reminder_multi']) ? ($_POST['selected_contacts'] ?? []) : [$_POST['contact_id'] ?? ''];
        $tmplId = (int)($_POST['template_id_multi'] ?? $_POST['template_id'] ?? 0);
        
        if (empty($contacts) || !$tmplId) {
            $_SESSION['notification'] = "Pilih kontak & template!"; $_SESSION['notificationType'] = 'error';
        } else {
            $stmt = $conn->prepare("SELECT content FROM poloap_templates WHERE id = ?");
            $stmt->bind_param("i", $tmplId); $stmt->execute();
            $msgTemplate = $stmt->get_result()->fetch_assoc()['content'] ?? '';
            $stmt->close();
            
            if (!empty($msgTemplate)) {
                $sukses = $gagal = 0;
                
                // DETEKSI AUTO-HILANG (Jika template mengandung kata form)
                $isFormSent = (stripos($msgTemplate, 'penempatan halaqoh') !== false) ? 1 : 0;

                foreach ($contacts as $contactId) {
                    $stmt = $conn->prepare("SELECT nama FROM log_wa WHERE nowa = ? ORDER BY created_at DESC LIMIT 1");
                    $stmt->bind_param("s", $contactId); $stmt->execute();
                    $namaProspek = $stmt->get_result()->fetch_assoc()['nama'] ?? 'Kak';
                    $stmt->close();
                    if(empty($namaProspek)) $namaProspek = 'Kak';

                    $clean = preg_replace('/\D/', '', $contactId);
                    if (substr($clean,0,1)==='0') $clean = '62'.substr($clean,1);
                    
                    $pesanPersonal = str_ireplace('{nama}', $namaProspek, $msgTemplate);
                    $res = kirimPesan($clean, $pesanPersonal, $apiUrl, $apiToken);
                    
                    if ($res['status'] === 'TERKIRIM') {
                        $sukses++;
                        // Update Data: Tanggal Last Follow up, dan status auto-hide
                        $conn->query("UPDATE log_wa SET last_followup_at = NOW(), is_form_sent = GREATEST(is_form_sent, $isFormSent) WHERE nowa = '$clean'");
                        
                        // Masukkan ke Session harian agar pindah ke tabel bawah
                        if (!in_array($clean, $_SESSION['followed_up_today'])) $_SESSION['followed_up_today'][] = $clean;
                    } else {
                        $gagal++;
                    }
                    usleep(300000); 
                }
                
                if (isset($_POST['send_reminder'])) { 
                    $_SESSION['notification'] = $sukses ? "✅ Pesan terkirim." : "❌ Gagal dikirim.";
                    $_SESSION['notificationType'] = $sukses ? 'success' : 'error';
                } else { 
                    $_SESSION['notification'] = $sukses > 0 ? "✅ Broadcast selesai: $sukses terkirim, $gagal gagal" : "❌ Gagal mengirim";
                    $_SESSION['notificationType'] = $sukses > 0 ? 'success' : 'error';
                }
            }
        }
        header("Location: pesan.php"); exit;
    }
}

// ==================================================================
// AMBIL DATA & FILTERING
// ==================================================================
$pesanTemplates = [];
if (isset($conn) && $conn) {
    $result = $conn->query("SELECT id, name, content FROM poloap_templates ORDER BY name");
    if ($result) while ($row = $result->fetch_assoc()) $pesanTemplates[] = $row;
}

// Parameter Filter
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$fuFrom = $_GET['fu_from'] ?? '';
$fuTo = $_GET['fu_to'] ?? '';

$excluded = getExcludedContacts($conn);
$blocked = getBlockedContacts($conn);
$followedUpToday = $_SESSION['followed_up_today'] ?? [];

$targetOrganik = [];
$targetManual = [];
$sudahDichat = [];
$prospekMerespon = []; // Array baru khusus yang membalas

if ($conn) {
    // Hanya ambil data yang is_form_sent = 0 (Belum dikirimi link form / belum diarsipkan)
    $sql = "SELECT nowa as contact_id, MAX(created_at) as timestamp, MAX(nama) as nama, 
            MAX(last_followup_at) as last_followup_at, message as last_message 
            FROM log_wa WHERE is_form_sent = 0 ";
    
    $params = []; $types = '';
    
    if ($fromDate) { $sql .= " AND DATE(created_at) >= ?"; $params[] = $fromDate; $types .= 's'; }
    if ($toDate) { $sql .= " AND DATE(created_at) <= ?"; $params[] = $toDate; $types .= 's'; }
    if ($fuFrom) { $sql .= " AND DATE(last_followup_at) >= ?"; $params[] = $fuFrom; $types .= 's'; }
    if ($fuTo) { $sql .= " AND DATE(last_followup_at) <= ?"; $params[] = $fuTo; $types .= 's'; }

    $sql .= " GROUP BY nowa ORDER BY timestamp DESC";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute(); $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $klasifikasi = classifyMessage($row['last_message']);
            if ($klasifikasi === 'Lainnya') continue;

            $contactId = $row['contact_id'];
            $n = preg_replace('/\D/', '', $contactId);
            if (substr($n,0,1)==='0') $n = '62'.substr($n,1);
            if (isset($excluded[$n]) || isset($blocked[$n])) continue;

            if ($searchQuery) {
                $searchLower = strtolower($searchQuery);
                if (strpos(strtolower($row['last_message']), $searchLower) === false &&
                    strpos(strtolower($contactId), $searchLower) === false &&
                    strpos(strtolower($row['nama'] ?? ''), $searchLower) === false) {
                    continue;
                }
            }

            $daysSince = floor((time() - strtotime($row['timestamp'])) / (60 * 60 * 24));
            
            // LOGIKA SUPER: Deteksi apakah dia merespon (Waktu masuk pesan > Waktu terakhir kita FU)
            $timeMsg = strtotime($row['timestamp']);
            $timeFu = !empty($row['last_followup_at']) ? strtotime($row['last_followup_at']) : 0;
            $isMerespon = ($timeFu > 0 && $timeMsg > $timeFu);

            $lastFuText = '-';
            if($timeFu > 0) {
                $fuDays = floor((time() - $timeFu) / (60 * 60 * 24));
                $lastFuText = $fuDays == 0 ? 'Hari ini' : ($fuDays . ' hr lalu');
            }

            $prospectData = [
                'contact_id' => $contactId, 'clean_number' => $n, 'last_message' => $row['last_message'],
                'timestamp' => $row['timestamp'], 'nama' => $row['nama'], 'classification' => $klasifikasi,
                'days_since' => $daysSince, 'last_fu_text' => $lastFuText
            ];

            // PISAHKAN BERDASARKAN STATUS BALASAN DAN SESI
            if ($isMerespon) {
                $prospekMerespon[$contactId] = $prospectData; // Masuk ke tabel "Sudah Membalas"
            } else if (in_array($n, $followedUpToday)) {
                $sudahDichat[$contactId] = $prospectData; // Selesai difollow up hari ini
            } else {
                if ($klasifikasi === 'Data CSV/Manual') {
                    $targetManual[$contactId] = $prospectData;
                } else {
                    $targetOrganik[$contactId] = $prospectData;
                }
            }
        }
        $stmt->close();
    }
}

$blockedList = array_keys($blocked);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Follow-Up & Broadcast | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .header-bg { background: linear-gradient(135deg, #0f172a, #3b82f6); }
        .card-soft { background: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .btn-modern { transition: all 0.2s; }
        .btn-modern:active { transform: scale(0.95); }
        .btn-modern:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .modal-enter { animation: modalFadeIn 0.3s ease forwards; }
        @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
    </style>
</head>
<body class="p-4 md:p-6 lg:p-8">

<div class="max-w-[1400px] mx-auto">
    <header class="header-bg text-white rounded-2xl p-6 mb-6 flex flex-col xl:flex-row justify-between items-center gap-6 shadow-lg">
        <div class="flex items-center gap-4 w-full xl:w-auto">
            <div class="w-14 h-14 bg-white/10 backdrop-blur rounded-xl flex items-center justify-center border border-white/20">
                <i class="fas fa-paper-plane text-2xl text-blue-200"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Yuk Follow-Up</h1>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 w-full xl:w-auto">
            <button onclick="document.getElementById('modalCSV').classList.remove('hidden')" class="bg-blue-500 hover:bg-blue-400 text-white px-4 py-2.5 rounded-lg font-medium transition-colors flex items-center gap-2 text-sm shadow flex-1 md:flex-none justify-center">
                <i class="fas fa-file-csv"></i> Upload CSV
            </button>
            <button onclick="document.getElementById('modalTambah').classList.remove('hidden')" class="bg-emerald-500 hover:bg-emerald-400 text-white px-4 py-2.5 rounded-lg font-medium transition-colors flex items-center gap-2 text-sm shadow flex-1 md:flex-none justify-center">
                <i class="fas fa-user-plus"></i> Tambah
            </button>
            <form method="POST" action="pesan.php" class="flex-1 md:flex-none">
                <button type="submit" name="export_csv" class="w-full bg-slate-800 hover:bg-slate-700 text-white px-4 py-2.5 rounded-lg font-medium transition-colors flex items-center gap-2 text-sm shadow justify-center">
                    <i class="fas fa-download"></i> Export Data
                </button>
            </form>
            <a href="manage_templates.php" class="bg-white/10 hover:bg-white/20 text-white px-4 py-2.5 rounded-lg font-medium transition-all border border-white/20 flex items-center gap-2 text-sm flex-1 md:flex-none justify-center">
                <i class="fas fa-edit"></i> Template
            </a>
        </div>
    </header>

    <?php if (!empty($notification)): ?>
    <div class="mb-6 p-4 rounded-xl border <?= $notificationType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : ($notificationType === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-red-50 border-red-200 text-red-800') ?> flex items-center gap-3 font-medium shadow-sm">
        <i class="fas <?= $notificationType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> text-lg"></i>
        <span><?= htmlspecialchars($notification) ?></span>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        
        <div class="xl:col-span-3 space-y-6">
            
            <div class="card-soft p-5">
                <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-filter text-blue-500"></i> Saring Data
                </h3>
                <form method="get" action="pesan.php" class="space-y-4">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Cari nama / nomor..." class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                    </div>
                    
                    <div class="p-3 bg-slate-50 border border-slate-100 rounded-lg space-y-3">
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Berdasarkan Tanggal Masuk</p>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>" class="w-full px-2 py-1.5 border border-slate-200 rounded outline-none text-[11px] text-slate-600">
                            <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>" class="w-full px-2 py-1.5 border border-slate-200 rounded outline-none text-[11px] text-slate-600">
                        </div>
                    </div>

                    <div class="p-3 bg-blue-50/50 border border-blue-100 rounded-lg space-y-3">
                        <p class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Berdasarkan Terakhir Di-Chat</p>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="date" name="fu_from" value="<?= htmlspecialchars($fuFrom) ?>" class="w-full px-2 py-1.5 border border-blue-200 bg-white rounded outline-none text-[11px] text-slate-600">
                            <input type="date" name="fu_to" value="<?= htmlspecialchars($fuTo) ?>" class="w-full px-2 py-1.5 border border-blue-200 bg-white rounded outline-none text-[11px] text-slate-600">
                        </div>
                    </div>

                    <div class="flex gap-2 pt-1">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-bold text-sm transition-colors">Terapkan</button>
                        <a href="pesan.php" class="bg-slate-200 hover:bg-slate-300 text-slate-600 px-4 py-2 rounded-lg font-bold text-sm transition-colors flex items-center justify-center"><i class="fas fa-redo"></i></a>
                    </div>
                </form>
            </div>

            <div class="card-soft p-5 border-rose-100">
                <h3 class="font-bold text-slate-800 mb-3 flex items-center gap-2 text-sm">
                    <i class="fas fa-shield-alt text-rose-500"></i> Zona Manajemen
                </h3>
                <div class="space-y-2">
                    <form method="POST" action="pesan.php" onsubmit="return confirm('Kosongkan semua daftar blokir?');">
                        <button type="submit" name="hapus_semua_blokir" class="w-full bg-white hover:bg-rose-50 border border-rose-200 text-rose-600 py-2 rounded-lg text-xs font-semibold transition-colors flex justify-center items-center gap-2">
                            <i class="fas fa-unlock-alt"></i> Reset Daftar Blokir (<?= count($blockedList) ?>)
                        </button>
                    </form>
                    <form method="POST" action="pesan.php" onsubmit="return confirm('Hapus semua Calon Peserta?');">
                        <button type="submit" name="hapus_semua_calon_peserta" class="w-full bg-white hover:bg-rose-50 border border-rose-200 text-rose-600 py-2 rounded-lg text-xs font-semibold transition-colors flex justify-center items-center gap-2">
                            <i class="fas fa-user-times"></i> Bersihkan Calon Peserta
                        </button>
                    </form>
                </div>
                
                <?php if(!empty($blockedList)): ?>
                <div class="mt-4 max-h-40 overflow-y-auto custom-scrollbar border-t border-slate-100 pt-3">
                    <div class="flex flex-col gap-1.5">
                        <?php foreach($blockedList as $bContact): ?>
                        <div class="bg-slate-50 border border-slate-200 rounded px-2 py-1 flex justify-between items-center">
                            <span class="font-mono text-[10px] font-semibold text-slate-500"><?= htmlspecialchars($bContact) ?></span>
                            <form method="POST" action="pesan.php" class="m-0">
                                <input type="hidden" name="contact_id" value="<?= htmlspecialchars($bContact) ?>">
                                <input type="hidden" name="current_status" value="unblock">
                                <button type="submit" name="update_block_status" class="text-[9px] text-emerald-600 font-bold hover:underline">Buka</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="xl:col-span-9 space-y-6">
            
            <?php if (!empty($prospekMerespon)): ?>
            <div class="card-soft overflow-hidden border-2 border-amber-300 shadow-amber-100 shadow-xl bg-amber-50/20">
                <div class="bg-gradient-to-r from-amber-100 to-amber-50 px-5 py-4 border-b border-amber-200 flex justify-between items-center">
                    <h3 class="text-sm font-extrabold text-amber-800 flex items-center gap-2">
                        <i class="fas fa-comment-dots text-amber-500 text-lg"></i> Telah Membalas! (Butuh Balasan Manual)
                        <span class="bg-amber-500 text-white px-2 py-0.5 rounded-full text-[10px] ml-1 shadow-sm"><?= count($prospekMerespon) ?></span>
                    </h3>
                    <span class="text-[10px] font-semibold text-amber-600 bg-white px-2 py-1 rounded border border-amber-200">Aman dari Broadcast Massal</span>
                </div>
                
                <div class="max-h-[300px] overflow-y-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <tbody class="divide-y divide-amber-100">
                            <?php foreach($prospekMerespon as $contactId => $data): ?>
                            <tr class="hover:bg-amber-50/50 transition-colors">
                                <td class="p-4 align-top w-1/3">
                                    <div class="font-bold text-slate-800 text-xs mb-0.5"><?= htmlspecialchars($data['nama'] ?: 'Tanpa Nama') ?></div>
                                    <div class="font-mono text-[10px] text-slate-500"><i class="fab fa-whatsapp text-emerald-500"></i> <?= htmlspecialchars($contactId) ?></div>
                                </td>
                                <td class="p-4 align-top w-1/2">
                                    <div class="text-[11px] font-bold text-amber-700 bg-white p-2 rounded border border-amber-200 shadow-sm relative">
                                        <i class="fas fa-caret-left absolute -left-2 top-2 text-white drop-shadow-sm"></i>
                                        "<?= htmlspecialchars($data['last_message']) ?>"
                                    </div>
                                    <div class="mt-2 text-[9px] font-semibold text-slate-400">Pesan masuk: <?= date('d M H:i', strtotime($data['timestamp'])) ?></div>
                                </td>
                                <td class="p-4 align-top text-right">
                                    <div class="flex flex-col gap-2 justify-end">
                                        <a href="https://wa.me/<?= htmlspecialchars($data['clean_number']) ?>" target="_blank" class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-1.5 rounded text-[10px] font-bold transition-all shadow flex items-center justify-center gap-1.5">
                                            <i class="fab fa-whatsapp"></i> Balas Manual
                                        </a>
                                        <form method="POST" action="pesan.php" onsubmit="return confirm('Tandai sudah selesai? Nomor ini akan hilang dari antrean dashboard.');">
                                            <input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>">
                                            <button type="submit" name="archive_prospect" class="w-full bg-slate-200 hover:bg-slate-300 text-slate-600 px-3 py-1.5 rounded text-[10px] font-bold transition-all flex items-center justify-center gap-1.5">
                                                <i class="fas fa-archive"></i> Arsipkan
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="card-soft p-5 md:p-6 bg-slate-800 text-white shadow-xl shadow-slate-800/20 border-none">
                <div class="flex flex-col md:flex-row gap-5 items-center">
                    <div class="md:w-1/3 w-full">
                        <h3 class="text-lg font-bold flex items-center gap-2 mb-1">
                            <i class="fas fa-bullhorn text-blue-400"></i> Broadcast Massal
                        </h3>
                    </div>
                    <form method="POST" action="pesan.php" id="formMassal" class="md:w-2/3 w-full flex flex-col sm:flex-row gap-3">
                        <div class="flex-1 relative">
                            <select name="template_id_multi" class="w-full px-3 py-2.5 bg-slate-700 border border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm outline-none text-white appearance-none" required>
                                <option value="">-- Pilih Template Pesan --</option>
                                <?php foreach($pesanTemplates as $tmpl): ?>
                                <option value="<?= $tmpl['id'] ?>"><?= htmlspecialchars($tmpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                            <div class="hidden" id="selectedContactsContainer"></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-semibold text-slate-300"><span id="countSelected" class="text-blue-400 text-base font-bold">0</span> Dipilih</span>
                            <button type="submit" name="send_reminder_multi" id="btnMassal" onclick="return processMassSend(event)" class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2.5 rounded-lg font-bold transition-colors shadow-lg shadow-blue-600/30 flex items-center gap-2 whitespace-nowrap btn-modern text-sm">
                                <i class="fas fa-paper-plane" id="iconMassal"></i> <span id="textMassal">Kirim</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card-soft overflow-hidden">
                <div class="bg-white px-5 py-4 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                        <i class="fas fa-crosshairs text-blue-500"></i> Organik (Belum Merespons)
                        <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full text-[10px] ml-1"><?= count($targetOrganik) ?></span>
                    </h3>
                    <label class="flex items-center gap-2 cursor-pointer bg-slate-50 px-2.5 py-1 rounded border border-slate-200 hover:bg-slate-100">
                        <input type="checkbox" id="selectAllOrganik" class="w-3.5 h-3.5 text-blue-600 rounded">
                        <span class="text-[10px] font-bold text-slate-600">Pilih Semua</span>
                    </label>
                </div>
                
                <div class="max-h-[350px] overflow-y-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 sticky top-0 z-10">
                            <tr class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                <th class="p-3 border-b w-10 text-center">Pilih</th>
                                <th class="p-3 border-b w-1/3">Prospek</th>
                                <th class="p-3 border-b w-1/4">Minat & Info</th>
                                <th class="p-3 border-b text-center">Eksekusi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php if (empty($targetOrganik)): ?>
                            <tr><td colspan="4" class="p-8 text-center text-slate-400"><i class="fas fa-check text-2xl mb-2 text-slate-300"></i><p class="text-xs">Antrean organik bersih!</p></td></tr>
                            <?php else: ?>
                                <?php foreach($targetOrganik as $contactId => $data): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="p-3 text-center align-top">
                                        <input type="checkbox" value="<?= htmlspecialchars($contactId) ?>" class="cb-target cb-organik w-4 h-4 text-blue-600 rounded mt-1 cursor-pointer">
                                    </td>
                                    <td class="p-3 align-top">
                                        <div class="font-bold text-slate-700 text-xs mb-0.5"><?= htmlspecialchars($data['nama'] ?: 'Tanpa Nama') ?></div>
                                        <div class="font-mono text-[10px] text-slate-400"><i class="fab fa-whatsapp text-emerald-400"></i> <?= htmlspecialchars($contactId) ?></div>
                                    </td>
                                    <td class="p-3 align-top">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold bg-indigo-50 text-indigo-600 border border-indigo-100 mb-1 uppercase">
                                            <?= htmlspecialchars($data['classification']) ?>
                                        </span>
                                        <div class="text-[10px] text-slate-500 italic line-clamp-2 leading-tight mb-1">"<?= htmlspecialchars($data['last_message']) ?>"</div>
                                        <div class="flex gap-2 text-[9px] font-medium text-slate-400">
                                            <span>Msk: <?= date('d/m', strtotime($data['timestamp'])) ?></span>
                                            <span class="text-blue-400">• Fu: <?= $data['last_fu_text'] ?></span>
                                        </div>
                                    </td>
                                    <td class="p-3 align-top">
                                        <form method="POST" action="pesan.php" class="flex flex-col sm:flex-row gap-1.5 frm-single">
                                            <input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>">
                                            <input type="hidden" name="nama_prospek" value="<?= htmlspecialchars($data['nama']) ?>">
                                            <select name="template_id" class="flex-1 text-[10px] border border-slate-200 rounded px-1.5 py-1 focus:ring-1 focus:ring-blue-500 outline-none" required>
                                                <option value="">Template...</option>
                                                <?php foreach($pesanTemplates as $tmpl): ?><option value="<?= $tmpl['id'] ?>"><?= htmlspecialchars($tmpl['name']) ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="send_reminder" class="btn-single bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white border border-blue-100 px-2 py-1 rounded text-[10px] font-bold transition-all">Kirim</button>
                                        </form>
                                        <div class="flex justify-between items-center mt-2 border-t border-slate-100 pt-1.5">
                                            <form method="POST" action="pesan.php" class="inline"><input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>"><input type="hidden" name="current_status" value="block"><button type="submit" name="update_block_status" class="text-[9px] font-semibold text-slate-400 hover:text-amber-500 transition-colors">Blokir</button></form>
                                            <form method="POST" action="pesan.php" class="inline" onsubmit="return confirm('Hapus nomor ini?');"><input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>"><button type="submit" name="delete_prospect" class="text-[9px] font-semibold text-slate-400 hover:text-rose-500 transition-colors">Hapus</button></form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-soft overflow-hidden">
                <div class="bg-white px-5 py-4 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                        <i class="fas fa-database text-emerald-500"></i> Database (Upload/Manual)
                        <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full text-[10px] ml-1"><?= count($targetManual) ?></span>
                    </h3>
                    <label class="flex items-center gap-2 cursor-pointer bg-slate-50 px-2.5 py-1 rounded border border-slate-200 hover:bg-slate-100">
                        <input type="checkbox" id="selectAllManual" class="w-3.5 h-3.5 text-blue-600 rounded">
                        <span class="text-[10px] font-bold text-slate-600">Pilih Semua</span>
                    </label>
                </div>
                
                <div class="max-h-[250px] overflow-y-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 sticky top-0 z-10">
                            <tr class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                <th class="p-3 border-b w-10 text-center">Pilih</th>
                                <th class="p-3 border-b w-1/3">Data Santri</th>
                                <th class="p-3 border-b w-1/4">Status FU</th>
                                <th class="p-3 border-b text-center">Eksekusi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php if (empty($targetManual)): ?>
                            <tr><td colspan="4" class="p-6 text-center text-slate-400"><p class="text-xs">Tidak ada data upload baru.</p></td></tr>
                            <?php else: ?>
                                <?php foreach($targetManual as $contactId => $data): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="p-3 text-center align-middle"><input type="checkbox" value="<?= htmlspecialchars($contactId) ?>" class="cb-target cb-manual w-4 h-4 text-blue-600 rounded cursor-pointer"></td>
                                    <td class="p-3 align-middle">
                                        <div class="font-bold text-slate-700 text-xs mb-0.5"><?= htmlspecialchars($data['nama'] ?: 'Tanpa Nama') ?></div>
                                        <div class="font-mono text-[10px] text-slate-400"><i class="fab fa-whatsapp text-emerald-400"></i> <?= htmlspecialchars($contactId) ?></div>
                                    </td>
                                    <td class="p-3 align-middle text-[9px] font-medium text-slate-500">
                                        Tgl: <?= date('d/m/y', strtotime($data['timestamp'])) ?><br>
                                        <span class="text-blue-500">FU: <?= $data['last_fu_text'] ?></span>
                                    </td>
                                    <td class="p-3 align-middle">
                                        <form method="POST" action="pesan.php" class="flex gap-1.5 justify-end frm-single">
                                            <input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>">
                                            <input type="hidden" name="nama_prospek" value="<?= htmlspecialchars($data['nama']) ?>">
                                            <select name="template_id" class="w-24 text-[10px] border border-slate-200 rounded px-1.5 py-1 focus:ring-1 focus:ring-blue-500 outline-none" required>
                                                <option value="">Template...</option>
                                                <?php foreach($pesanTemplates as $tmpl): ?><option value="<?= $tmpl['id'] ?>"><?= htmlspecialchars($tmpl['name']) ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="send_reminder" class="btn-single bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white border border-blue-100 px-2 py-1 rounded text-[10px] font-bold transition-all">Kirim</button>
                                            <button type="submit" name="delete_prospect" class="text-rose-400 hover:text-white hover:bg-rose-500 border border-transparent hover:border-rose-600 px-2 py-1 rounded text-[10px] transition-colors" onclick="return confirm('Hapus data ini?');"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!empty($sudahDichat)): ?>
            <div class="card-soft overflow-hidden border-emerald-100 opacity-90 hover:opacity-100 transition-opacity mt-4">
                <div class="bg-emerald-50/50 px-5 py-3 border-b border-emerald-100 flex justify-between items-center">
                    <h3 class="text-xs font-bold text-emerald-700 flex items-center gap-2">
                        <i class="fas fa-check-double text-emerald-500"></i> sudah di follow up
                    </h3>
                </div>
                <div class="max-h-[200px] overflow-y-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <tbody class="divide-y divide-emerald-50 bg-white">
                            <?php foreach($sudahDichat as $contactId => $data): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="p-2 pl-5 w-1/2">
                                    <div class="font-bold text-slate-600 text-[11px]"><?= htmlspecialchars($data['nama'] ?: 'Tanpa Nama') ?></div>
                                    <div class="font-mono text-[9px] text-slate-400"><?= htmlspecialchars($contactId) ?></div>
                                </td>
                                <td class="p-2 w-1/4">
                                    <span class="inline-flex px-1.5 py-0.5 rounded text-[8px] font-bold bg-slate-100 text-slate-500 uppercase"><?= htmlspecialchars($data['classification']) ?></span>
                                </td>
                                <td class="p-2 pr-5 text-right">
                                    <form method="POST" action="pesan.php" class="flex gap-1 justify-end frm-single" onsubmit="return confirm('Yakin mengirim pesan ulang hari ini?');">
                                        <input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>"><input type="hidden" name="nama_prospek" value="<?= htmlspecialchars($data['nama']) ?>">
                                        <select name="template_id" class="w-20 text-[9px] border border-slate-200 rounded px-1 py-1 outline-none" required>
                                            <option value="">Tmpl...</option>
                                            <?php foreach($pesanTemplates as $tmpl): ?><option value="<?= $tmpl['id'] ?>"><?= htmlspecialchars($tmpl['name']) ?></option><?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="send_reminder" class="btn-single bg-slate-100 text-slate-500 hover:bg-slate-200 px-2 py-1 rounded text-[9px] font-bold"><i class="fas fa-redo"></i></button>
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
    </div>
</div>

<div id="modalCSV" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm modal-enter overflow-hidden">
        <div class="bg-slate-50 px-5 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-sm"><i class="fas fa-file-csv text-blue-500 mr-2"></i>Upload Database</h3>
            <button onclick="document.getElementById('modalCSV').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="pesan.php" enctype="multipart/form-data" class="p-5" onsubmit="loadState('btnCSV', 'Mengunggah...')">
            <div class="mb-5">
                <p class="text-[11px] text-slate-500 mb-3 leading-relaxed">Format kolom wajib (Gunakan Koma):<br><b>Kolom 1: Nama, Kolom 2: No WhatsApp</b></p>
                <input type="file" name="file_csv" accept=".csv" required class="w-full border border-slate-300 rounded p-1.5 focus:ring-1 focus:ring-blue-500 outline-none text-xs text-slate-600">
            </div>
            <button type="submit" name="upload_csv" id="btnCSV" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded font-bold text-sm transition-colors shadow">Import Data</button>
        </form>
    </div>
</div>

<div id="modalTambah" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm modal-enter overflow-hidden">
        <div class="bg-slate-50 px-5 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-sm"><i class="fas fa-user-plus text-emerald-500 mr-2"></i>Tambah Manual</h3>
            <button onclick="document.getElementById('modalTambah').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="pesan.php" class="p-5 space-y-4" onsubmit="loadState('btnManual', 'Menyimpan...')">
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Nama</label>
                <input type="text" name="nama_baru" required placeholder="Cth: Budi" class="w-full border border-slate-300 rounded px-3 py-2 outline-none focus:border-emerald-500 text-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Nomor WA</label>
                <input type="number" name="nowa_baru" required placeholder="08123..." class="w-full border border-slate-300 rounded px-3 py-2 outline-none focus:border-emerald-500 text-sm">
            </div>
            <button type="submit" name="tambah_prospek" id="btnManual" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-2 rounded font-bold text-sm transition-colors shadow mt-2">Simpan</button>
        </form>
    </div>
</div>

<script>
function loadState(btnId, text) {
    const btn = document.getElementById(btnId);
    if(btn) { btn.innerHTML = `<i class="fas fa-spinner fa-spin mr-1"></i> ${text}`; btn.disabled = true; }
}

document.querySelectorAll('.frm-single').forEach(form => {
    form.addEventListener('submit', function() {
        const btn = this.querySelector('.btn-single');
        if(btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true; }
    });
});

const selectAllOrganik = document.getElementById('selectAllOrganik');
const selectAllManual = document.getElementById('selectAllManual');
const cbAll = document.querySelectorAll('.cb-target');
const countLabel = document.getElementById('countSelected');

function updateCount() { countLabel.innerText = document.querySelectorAll('.cb-target:checked').length; }

if(selectAllOrganik) { selectAllOrganik.addEventListener('change', function() { document.querySelectorAll('.cb-organik').forEach(cb => cb.checked = this.checked); updateCount(); }); }
if(selectAllManual) { selectAllManual.addEventListener('change', function() { document.querySelectorAll('.cb-manual').forEach(cb => cb.checked = this.checked); updateCount(); }); }
cbAll.forEach(cb => { cb.addEventListener('change', updateCount); });

function processMassSend(e) {
    const container = document.getElementById('selectedContactsContainer');
    container.innerHTML = ''; 
    const selected = document.querySelectorAll('.cb-target:checked');
    if(selected.length === 0) { e.preventDefault(); alert('Centang minimal 1 kontak di Target Organik atau Database!'); return false; }
    if(!confirm(`Kirim broadcast ke ${selected.length} prospek terpilih? (Orang yang sudah merespons aman dari broadcast ini)`)) { e.preventDefault(); return false; }
    selected.forEach(cb => {
        const input = document.createElement('input'); input.type = 'hidden'; input.name = 'selected_contacts[]'; input.value = cb.value; container.appendChild(input);
    });
    const btn = document.getElementById('btnMassal');
    document.getElementById('iconMassal').className = 'fas fa-spinner fa-spin'; document.getElementById('textMassal').innerText = 'Memproses...';
    setTimeout(() => btn.disabled = true, 10);
    return true;
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