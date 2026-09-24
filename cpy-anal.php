<?php
session_start();
require_once 'auth_checkwa.php';

// Error reporting - safe untuk production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Include file config dengan error handling
try {
    require_once 'config.php';
} catch (Exception $e) {
    error_log("Config loading failed: " . $e->getMessage());
    die("Configuration error");
}
// PERBAIKAN: Ambil API dari konstanta jika variabel tidak ada
if (!isset($apiUrl) && defined('ONESENDER_API_URL')) $apiUrl = ONESENDER_API_URL;
if (!isset($apiToken) && defined('ONESENDER_API_TOKEN')) $apiToken = ONESENDER_API_TOKEN;

// Validasi
if (empty($apiUrl) || empty($apiToken)) {
    die("Error: WhatsApp API configuration is missing. Check config.php");
}
// Set charset jika koneksi tersedia
if (isset($conn) && $conn) {
    mysqli_set_charset($conn, "utf8mb4");
}

// Notifikasi
$notification = $notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notificationType'];
    unset($_SESSION['notification'], $_SESSION['notificationType']);
}

// ==================================================================
// FUNGSI UTAMA KIRIM PESAN - SIMPLE & STABLE
// ==================================================================
function kirimPesan($recipient, $message, $apiUrl, $apiToken) {
    // Validasi dasar
    if (empty($recipient) || empty($message)) {
        return ['status' => 'GAGAL', 'message' => 'Parameter tidak lengkap'];
    }
    
    // Format nomor
    $cleanNumber = preg_replace('/\D/', '', $recipient);
    
    if (strlen($cleanNumber) > 0 && $cleanNumber[0] === '0') {
        $cleanNumber = '62' . substr($cleanNumber, 1);
    }
    
    if (!preg_match('/^62\d{9,12}$/', $cleanNumber)) {
        return ['status' => 'GAGAL', 'message' => 'Nomor tidak valid'];
    }
    
    // Payload sederhana
    $data = [
        "recipient_type" => "individual",
        "to" => $cleanNumber,
        "type" => "text",
        "text" => ["body" => $message]
    ];

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 'GAGAL', 'message' => "Error: $error"];
    }
    
    if ($httpCode === 200) {
        return ['status' => 'TERKIRIM', 'message' => 'Berhasil'];
    }

    return ['status' => 'GAGAL', 'message' => "HTTP $httpCode: Gagal mengirim"];
}

// ==================================================================
// FUNGSI UTILITAS - AMBIL DATA DARI DATABASE
// ==================================================================
function classifyMessage($message) {
    if (empty($message)) return 'Pesan Lainnya';
    
    $m = strtolower($message);
    if (strpos($m, 'intensif') !== false) return 'Mode Intensif';
    if (strpos($m, 'normal') !== false) return 'Mode Normal';
    if (strpos($m, 'tahfidz cilik') !== false) return 'Tahfidz Cilik';
    if (strpos($m, 'tahfidz private') !== false) return 'Tahfidz Private';
    if (strpos($m, 'ziyadah pemula') !== false) return 'Ziyadah Pemula';
    if (strpos($m, 'ziyadah lanjutan') !== false) return 'Ziyadah Lanjutan';
    if (strpos($m, 'murojaah') !== false) return 'Murojaah';
    if (strpos($m, 'kak, mau ikut') !== false) return 'Ekspresi Minat';
    
    return 'Pesan Lainnya';
}

function parseProspectsFromDB($conn, $fromDate = null, $toDate = null) {
    $prospek = [];
    
    if (!$conn) return $prospek;
    
    // Query untuk mengambil data dari log_wa
    $sql = "SELECT 
                l.nowa AS contact_id,
                l.message AS last_message,
                l.created_at AS timestamp,
                l.nama
            FROM log_wa l
            WHERE 1=1";
    
    $params = [];
    $types = '';
    
    if ($fromDate) {
        $sql .= " AND DATE(l.created_at) >= ?";
        $params[] = $fromDate;
        $types .= 's';
    }
    
    if ($toDate) {
        $sql .= " AND DATE(l.created_at) <= ?";
        $params[] = $toDate;
        $types .= 's';
    }
    
    $sql .= " ORDER BY l.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $contactId = $row['contact_id'];
            $message = $row['last_message'];
            
            // Skip jika bukan pesan yang relevan untuk prospek
            if (stripos($message, 'kak, mau') !== 0 && 
                stripos($message, 'mau ikut') === false &&
                stripos($message, 'ikut mode') === false) {
                continue;
            }
            
            if (!isset($prospek[$contactId])) {
                $prospek[$contactId] = [
                    'contact_id' => $contactId,
                    'last_message' => $message,
                    'timestamp' => $row['timestamp'],
                    'nama' => $row['nama'],
                    'classification' => classifyMessage($message)
                ];
            }
        }
        $stmt->close();
    }
    
    return $prospek;
}

// PERBAIKAN: Fungsi untuk mendapatkan kontak yang sudah terdaftar
function getRegisteredContacts($conn) {
    $registered = [];
    if (!$conn) return $registered;
    
    // Ambil dari tabel peserta
    $result = $conn->query("SELECT DISTINCT nowa FROM peserta");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $n = preg_replace('/\D/', '', $row['nowa']);
            if (substr($n,0,1)==='0') $n = '62'.substr($n,1);
            $registered[$n] = true;
        }
    }
    
    // Ambil dari tabel calon_peserta
    $result2 = $conn->query("SELECT DISTINCT nowa FROM calon_peserta");
    if ($result2) {
        while ($row = $result2->fetch_assoc()) {
            $n = preg_replace('/\D/', '', $row['nowa']);
            if (substr($n,0,1)==='0') $n = '62'.substr($n,1);
            $registered[$n] = true;
        }
    }
    
    return $registered;
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

function getAutoReplyLogs($conn, $contactId = null) {
    $logs = [];
    if (!$conn) return $logs;
    
    $sql = "SELECT * FROM auto_reply_logs";
    if ($contactId) {
        $sql .= " WHERE contact_id = ?";
    }
    $sql .= " ORDER BY created_at DESC LIMIT 100";
    
    if ($contactId) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $contactId);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
    }
    
    return $logs;
}

function getStatistics($conn, $fromDate = null, $toDate = null) {
    $stats = [
        'total_messages' => 0,
        'auto_replies' => 0,
        'failed_replies' => 0,
        'no_rule' => 0,
        'registered' => 0
    ];
    
    if (!$conn) return $stats;
    
    // Statistik dari auto_reply_logs
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'no_rule' THEN 1 ELSE 0 END) as no_rule,
                SUM(CASE WHEN status = 'registered' THEN 1 ELSE 0 END) as registered
            FROM auto_reply_logs";
    
    $params = [];
    $types = '';
    
    if ($fromDate || $toDate) {
        $conditions = [];
        if ($fromDate) {
            $conditions[] = "DATE(created_at) >= ?";
            $params[] = $fromDate;
            $types .= 's';
        }
        if ($toDate) {
            $conditions[] = "DATE(created_at) <= ?";
            $params[] = $toDate;
            $types .= 's';
        }
        if ($conditions) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
    }
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['total_messages'] = $row['total'] ?? 0;
            $stats['auto_replies'] = $row['sent'] ?? 0;
            $stats['failed_replies'] = $row['failed'] ?? 0;
            $stats['no_rule'] = $row['no_rule'] ?? 0;
            $stats['registered'] = $row['registered'] ?? 0;
        }
        $stmt->close();
    }
    
    return $stats;
}

// ==================================================================
// PROSES POST - STABLE VERSION
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update status blokir
    if (isset($_POST['update_block_status'])) {
        $contactId = $_POST['contact_id'] ?? '';
        $currentStatus = $_POST['current_status'] ?? '';
        if ($contactId) {
            if ($currentStatus === 'unblock') {
                // Hapus dari tabel blocked_peserta (unblock)
                $stmt = $conn->prepare("DELETE FROM blocked_peserta WHERE nowa = ?");
                $stmt->bind_param("s", $contactId);
                if ($stmt->execute()) {
                    $_SESSION['notification'] = "Kontak berhasil di-unblock";
                    $_SESSION['notificationType'] = 'success';
                } else {
                    $_SESSION['notification'] = "Gagal meng-unblock kontak";
                    $_SESSION['notificationType'] = 'error';
                }
                $stmt->close();
            } else {
                // Tambah ke tabel blocked_peserta (block)
                $stmt = $conn->prepare("INSERT IGNORE INTO blocked_peserta (nowa) VALUES (?)");
                $stmt->bind_param("s", $contactId);
                if ($stmt->execute()) {
                    $_SESSION['notification'] = "Kontak berhasil diblokir";
                    $_SESSION['notificationType'] = 'success';
                } else {
                    $_SESSION['notification'] = "Gagal memblokir kontak";
                    $_SESSION['notificationType'] = 'error';
                }
                $stmt->close();
            }
        }
        header("Location: " . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }

    // Kirim massal
    if (isset($_POST['send_reminder_multi'])) {
        $contacts = $_POST['selected_contacts'] ?? [];
        $tmplId = (int)($_POST['template_id_multi'] ?? 0);
        
        if (empty($contacts) || !$tmplId) {
            $_SESSION['notification'] = "Pilih kontak & template!";
            $_SESSION['notificationType'] = 'error';
        } else {
            // Ambil template
            $stmt = $conn->prepare("SELECT content FROM poloap_templates WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $tmplId);
                $stmt->execute();
                $result = $stmt->get_result();
                $template = $result->fetch_assoc();
                $msg = $template['content'] ?? '';
                $stmt->close();
                
                if (empty($msg)) {
                    $_SESSION['notification'] = "Template pesan kosong!";
                    $_SESSION['notificationType'] = 'error';
                } else {
                    $sukses = $gagal = $blocked = 0;
                    
                    foreach ($contacts as $contact) {
                        $clean = preg_replace('/\D/', '', $contact);
                        if (substr($clean,0,1)==='0') $clean = '62'.substr($clean,1);
                        
                        // Cek apakah kontak diblokir
                        $stmt_check = $conn->prepare("SELECT 1 FROM blocked_peserta WHERE nowa = ?");
                        $stmt_check->bind_param("s", $clean);
                        $stmt_check->execute();
                        $result_check = $stmt_check->get_result();
                        
                        if ($result_check->num_rows > 0) {
                            $blocked++;
                            $stmt_check->close();
                            continue; // Lewati kontak yang diblokir
                        }
                        $stmt_check->close();
                        
                        $res = kirimPesan($clean, $msg, $apiUrl, $apiToken);
                        
                        if ($res['status'] === 'TERKIRIM') {
                            $sukses++;
                        } else {
                            $gagal++;
                        }
                        
                        // Delay kecil
                        usleep(300000);
                    }
                    
                    // PERBAIKAN: Tentukan tipe notifikasi berdasarkan hasil
                    $totalResults = $sukses + $gagal + $blocked;
                    
                    if ($sukses === $totalResults) {
                        // Semua berhasil
                        $_SESSION['notification'] = "✅ Berhasil mengirim ke $sukses kontak";
                        $_SESSION['notificationType'] = 'success';
                    } elseif ($sukses > 0 && ($gagal > 0 || $blocked > 0)) {
                        // Sebagian berhasil
                        $_SESSION['notification'] = "⚠️ Hasil: $sukses berhasil, $gagal gagal, $blocked diblokir";
                        $_SESSION['notificationType'] = 'warning';
                    } elseif ($sukses === 0 && $gagal === 0 && $blocked > 0) {
                        // Semua diblokir
                        $_SESSION['notification'] = "🔒 Semua kontak terpilih diblokir ($blocked kontak)";
                        $_SESSION['notificationType'] = 'error';
                    } else {
                        // Semua gagal
                        $_SESSION['notification'] = "❌ Gagal mengirim ke semua kontak ($gagal gagal)";
                        $_SESSION['notificationType'] = 'error';
                    }
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Kirim single
    if (isset($_POST['send_reminder'])) {
        $contact = $_POST['contact_id'] ?? '';
        $tmplId = (int)($_POST['template_id'] ?? 0);
        
        if ($contact && $tmplId) {
            // Cek apakah kontak diblokir
            $stmt_check = $conn->prepare("SELECT 1 FROM blocked_peserta WHERE nowa = ?");
            $stmt_check->bind_param("s", $contact);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                $_SESSION['notification'] = "❌ Kontak ini diblokir dan tidak bisa menerima pesan";
                $_SESSION['notificationType'] = 'error';
                $stmt_check->close();
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
            $stmt_check->close();
            
            $stmt = $conn->prepare("SELECT content FROM poloap_templates WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $tmplId);
                $stmt->execute();
                $result = $stmt->get_result();
                $template = $result->fetch_assoc();
                $msg = $template['content'] ?? '';
                $stmt->close();
                
                $clean = preg_replace('/\D/', '', $contact);
                if (substr($clean,0,1)==='0') $clean = '62'.substr($clean,1);
                
                $res = kirimPesan($clean, $msg, $apiUrl, $apiToken);
                
                if ($res['status'] === 'TERKIRIM') {
                    $_SESSION['notification'] = "✅ Berhasil dikirim ke $clean";
                    $_SESSION['notificationType'] = 'success';
                } else {
                    $_SESSION['notification'] = "❌ Gagal: " . $res['message'];
                    $_SESSION['notificationType'] = 'error';
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ==================================================================
// AMBIL DATA UNTUK TAMPILAN
// ==================================================================
$pesanTemplates = [];
if (isset($conn) && $conn) {
    $result = $conn->query("SELECT id, name, content FROM poloap_templates ORDER BY name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pesanTemplates[] = $row;
        }
    }
}

// Get filter parameters
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$showDetails = $_GET['show_details'] ?? '';

// Parse prospects dari database
$prospek = parseProspectsFromDB($conn, $fromDate, $toDate);
$registered = getRegisteredContacts($conn); // PERBAIKAN: Sekarang mengambil dari peserta dan calon_peserta
$blocked = getBlockedContacts($conn);
$stats = getStatistics($conn, $fromDate, $toDate);

// Get recent auto-reply logs
$recentLogs = getAutoReplyLogs($conn);

// Apply search filter
$filteredProspects = [];
foreach ($prospek as $id => $data) {
    // PERBAIKAN: Filter kontak yang sudah terdaftar di tabel peserta atau calon_peserta
    $n = preg_replace('/\D/', '', $id);
    if (substr($n,0,1)==='0') $n = '62'.substr($n,1);
    
    // Jangan masukkan jika sudah terdaftar di peserta atau calon_peserta
    if (isset($registered[$n])) {
        continue;
    }
    
    // Filter blocked
    if (isset($blocked[$n])) continue;
    
    // Apply search filter
    if ($searchQuery) {
        $searchLower = strtolower($searchQuery);
        $msgLower = strtolower($data['last_message']);
        $idLower = strtolower($id);
        $namaLower = strtolower($data['nama'] ?? '');
        
        if (strpos($msgLower, $searchLower) === false && 
            strpos($idLower, $searchQuery) === false &&
            strpos($namaLower, $searchLower) === false) {
            continue;
        }
    }
    
    $filteredProspects[$id] = $data;
}

$totalProspekFiltered = count($filteredProspects);

// Hitung statistik klasifikasi
$classificationStats = [];
foreach ($filteredProspects as $prospect) {
    $classification = $prospect['classification'];
    $classificationStats[$classification] = ($classificationStats[$classification] ?? 0) + 1;
}
arsort($classificationStats);
$topClassification = key($classificationStats) ?: 'Tidak Ada Data';
$topClassificationCount = $classificationStats[$topClassification] ?? 0;

// Hitung hari terlewat
$now = time();
foreach ($filteredProspects as &$prospect) {
    if ($prospect['timestamp']) {
        $lastTime = strtotime($prospect['timestamp']);
        $daysSince = floor(($now - $lastTime) / (60 * 60 * 24));
        $prospect['days_since'] = $daysSince;
    } else {
        $prospect['days_since'] = null;
    }
}
unset($prospect);

// Ambil daftar kontak yang diblokir
$blockedList = [];
if ($conn) {
    $result = $conn->query("SELECT DISTINCT nowa FROM blocked_peserta ORDER BY nowa");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $blockedList[] = $row['nowa'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Analitik Chat Tahfidz Private</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        :root {
            --primary: #374151;
            --secondary: #4b5563;
            --accent: #9ca3af;
            --card-bg: #f3f4f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background-color: #f9fafb;
            color: #111827;
            min-height: 100vh;
            -webkit-tap-highlight-color: transparent;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 0.75rem;
        }
        
        @media (min-width: 768px) {
            .container {
                max-width: 1600px;
                padding: 1rem;
            }
        }
        
        @media (min-width: 1400px) {
            .container {
                max-width: 1800px;
                padding: 1.25rem;
            }
        }
        
        /* Header Styles - Responsif */
        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 1rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        @media (min-width: 768px) {
            .header {
                padding: 2rem;
                margin-bottom: 2rem;
            }
        }
        
        .header-content {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .header-content {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                gap: 2rem;
            }
        }
        
        .title-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        @media (min-width: 768px) {
            .title-section {
                gap: 1.5rem;
            }
        }
        
        .logo-container {
            width: 60px;
            height: 60px;
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
        }
        
        @media (min-width: 768px) {
            .logo-container {
                width: 80px;
                height: 80px;
            }
        }
        
        .logo-container i {
            font-size: 1.5rem;
            color: white;
        }
        
        @media (min-width: 768px) {
            .logo-container i {
                font-size: 2rem;
            }
        }
        
        .title-content h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        @media (min-width: 768px) {
            .title-content h1 {
                font-size: 2rem;
                margin-bottom: 0.5rem;
            }
        }
        
        .title-content p {
            color: #d1d5db;
            font-size: 0.9rem;
        }
        
        @media (min-width: 768px) {
            .title-content p {
                font-size: 1.1rem;
            }
        }
        
        .header-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-start;
        }
        
        @media (min-width: 768px) {
            .header-actions {
                justify-content: flex-end;
                gap: 1rem;
            }
        }
        
        .action-btn {
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            font-size: 0.875rem;
            flex: 1;
            min-width: 140px;
            justify-content: center;
        }
        
        @media (min-width: 768px) {
            .action-btn {
                padding: 0.75rem 1.5rem;
                flex: none;
                min-width: auto;
            }
        }
        
        .action-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .btn-logout {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        /* Touch-friendly elements */
        .touch-friendly {
            min-height: 44px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .touch-text {
            font-size: 16px;
            line-height: 1.5;
        }
        
        /* Buttons - lebih besar untuk mobile */
        .btn-mobile {
            padding: 0.75rem 1rem;
            font-size: 16px;
            border-radius: 0.75rem;
            cursor: pointer;
        }
        
        /* Inputs - lebih besar untuk mobile */
        .input-mobile {
            font-size: 16px;
            padding: 0.75rem;
            border-radius: 0.75rem;
        }
        
        /* Cards */
        .card-hover:hover {
            transform: translateY(-2px);
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        /* Table Styles */
        .compact-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .compact-table td, .compact-table th {
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
        }
        
        @media (min-width: 640px) {
            .compact-table td, .compact-table th {
                padding: 0.75rem 0.75rem;
            }
        }
        
        @media (min-width: 768px) {
            .compact-table td, .compact-table th {
                padding: 0.875rem 1rem;
            }
        }
        
        @media (min-width: 1024px) {
            .compact-table td, .compact-table th {
                padding: 1rem 1.25rem;
            }
        }
        
        .contact-number {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        /* Table container with fixed height and scroll */
        .table-container {
            max-height: 50vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        @media (min-width: 768px) {
            .table-container {
                max-height: 55vh;
            }
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 1rem;
            width: 100%;
            max-width: 900px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (min-width: 768px) {
            .modal-header {
                padding: 1.5rem;
            }
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        @media (min-width: 768px) {
            .modal-body {
                padding: 1.5rem;
            }
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-sent {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-failed {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-no_rule {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-registered {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        /* Selection */
        .select-checkbox {
            width: 20px;
            height: 20px;
        }
        
        /* Notification Styles - PERBAIKAN */
        .notification-success {
            background-color: #d1fae5;
            border-color: #a7f3d0;
            color: #065f46;
        }
        
        .notification-warning {
            background-color: #fef3c7;
            border-color: #fde68a;
            color: #92400e;
        }
        
        .notification-error {
            background-color: #fee2e2;
            border-color: #fecaca;
            color: #991b1b;
        }
        
        .notification-info {
            background-color: #dbeafe;
            border-color: #bfdbfe;
            color: #1e40af;
        }
        
        /* Column widths for better spacing */
        .col-kontak {
            width: 35%;
            min-width: 180px;
        }
        
        .col-minat {
            width: 20%;
            min-width: 120px;
        }
        
        .col-hari {
            width: 10%;
            min-width: 80px;
        }
        
        .col-aksi {
            width: 35%;
            min-width: 200px;
        }
        
        /* Untuk elemen yang perlu lebih besar di mobile */
        @media (max-width: 640px) {
            .btn-sm-mobile {
                padding: 0.5rem 0.75rem;
                font-size: 14px;
            }
            
            .text-sm-mobile {
                font-size: 14px;
            }
            
            .gap-mobile {
                gap: 0.5rem;
            }
            
            .table-container {
                max-height: 50vh;
            }
            
            .col-kontak {
                width: 40%;
            }
            
            .col-minat {
                width: 25%;
            }
            
            .col-hari {
                width: 10%;
            }
            
            .col-aksi {
                width: 25%;
            }
        }
        
        /* Spacing improvements */
        .spacing-comfortable {
            margin-bottom: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .spacing-comfortable {
                margin-bottom: 2rem;
            }
        }
        
        /* Grid improvements */
        .grid-improved {
            gap: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .grid-improved {
                gap: 2rem;
            }
        }
        
        /* Action buttons in table */
        .table-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        @media (min-width: 640px) {
            .table-actions {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }
        
        /* Form elements in table */
        .table-form {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        @media (min-width: 640px) {
            .table-form {
                flex-direction: row;
                align-items: center;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="title-section">
                    <div class="logo-container touch-friendly">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div class="title-content">
                        <h1 class="touch-text">Analitik Chat Tahfidz Private</h1>
                        <p class="touch-text">Kelola dan analisis percakapan calon peserta (Database Version)</p>
                        <p class="text-xs mt-1 text-green-200">
                            Filter: Hanya menampilkan yang belum terdaftar di peserta/calon_peserta
                        </p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <a href="grafik-chat.php" class="action-btn touch-friendly">
                        <i class="fas fa-chart-bar"></i>
                        <span class="touch-text">Grafik Chat</span>
                    </a>
                    <a href="manage_templates.php" class="action-btn touch-friendly">
                        <i class="fas fa-edit"></i>
                        <span class="touch-text">Kelola Template</span>
                    </a>
                    <a href="reminder.php" class="action-btn touch-friendly">
                        <i class="fas fa-bell"></i>
                        <span class="touch-text">Reminder</span>
                    </a>
                    <a href="new-send.php" class="action-btn touch-friendly">
                        <i class="fas fa-arrow-up-right-from-square"></i>
                        <span class="touch-text">Spreadsheet</span>
                    </a>
                    <a href="logoutwa.php" class="action-btn btn-logout touch-friendly">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="touch-text">Keluar</span>
                    </a>
                </div>
            </div>
        </header>

        <!-- Notifikasi -->
        <?php if (!empty($notification)): ?>
        <?php
        // Tentukan kelas notifikasi berdasarkan tipe
        $notificationClass = 'notification-info'; // default
        if ($notificationType === 'success') $notificationClass = 'notification-success';
        if ($notificationType === 'error') $notificationClass = 'notification-error';
        if ($notificationType === 'warning') $notificationClass = 'notification-warning';
        ?>
        <div class="mb-4 p-4 rounded-lg border <?= $notificationClass ?> touch-text spacing-comfortable">
            <div class="flex items-center gap-3">
                <i class="fas 
                    <?= $notificationType === 'success' ? 'fa-check-circle' : '' ?>
                    <?= $notificationType === 'error' ? 'fa-times-circle' : '' ?>
                    <?= $notificationType === 'warning' ? 'fa-exclamation-triangle' : '' ?>
                    <?= $notificationType === 'info' ? 'fa-info-circle' : '' ?>
                "></i>
                <span class="font-medium"><?= htmlspecialchars($notification) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6 spacing-comfortable">
            <div class="bg-white rounded-xl p-4 shadow border border-gray-200 card-hover">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-semibold touch-text">Total Prospek</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1 touch-text"><?= number_format($totalProspekFiltered) ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center touch-friendly">
                        <i class="fas fa-users text-white"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-4 shadow border border-gray-200 card-hover">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-semibold touch-text">Minat Terbanyak</p>
                        <p class="text-lg font-bold text-gray-800 mt-1 truncate touch-text"><?= htmlspecialchars($topClassification) ?></p>
                        <p class="text-gray-400 text-xs mt-1"><?= $topClassificationCount ?> orang</p>
                    </div>
                    <div class="w-12 h-12 bg-amber-500 rounded-xl flex items-center justify-center touch-friendly">
                        <i class="fas fa-star text-white"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-4 shadow border border-gray-200 card-hover">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-semibold touch-text">Auto Reply</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1 touch-text"><?= number_format($stats['auto_replies']) ?></p>
                        <p class="text-gray-400 text-xs mt-1">dari <?= number_format($stats['total_messages']) ?> pesan</p>
                    </div>
                    <div class="w-12 h-12 bg-green-500 rounded-xl flex items-center justify-center touch-friendly">
                        <i class="fas fa-robot text-white"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-4 shadow border border-gray-200 card-hover">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-semibold touch-text">Status Sistem</p>
                        <p class="text-lg font-bold text-gray-800 mt-1 touch-text"><?= $stats['auto_replies'] > 0 ? 'Aktif' : 'Tidak Aktif' ?></p>
                        <p class="text-gray-400 text-xs mt-1"><?= number_format($stats['failed_replies']) ?> gagal</p>
                    </div>
                    <div class="w-12 h-12 <?= $stats['auto_replies'] > 0 ? 'bg-green-500' : 'bg-red-500' ?> rounded-xl flex items-center justify-center touch-friendly">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-6 grid-improved">
            <!-- Filter Panel -->
            <div class="xl:col-span-1">
                <div class="bg-white rounded-xl p-4 shadow border border-gray-200 sticky top-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-blue-500"></i>
                        <span class="touch-text">Filter Data</span>
                    </h3>
                    
                    <!-- Search Form -->
                    <form method="get" class="mb-4">
                        <div class="relative">
                            <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" 
                                   placeholder="Cari nama/nomor/pesan..." 
                                   class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm input-mobile">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        </div>
                    </form>

                    <!-- Date Filter Form -->
                    <form method="get" class="space-y-3">
                        <?php if (!empty($searchQuery)): ?>
                            <input type="hidden" name="search" value="<?= htmlspecialchars($searchQuery) ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Tanggal Mulai</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm input-mobile">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Tanggal Akhir</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm input-mobile">
                        </div>
                        
                        <div class="flex gap-3 pt-2">
                            <button type="submit" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-3 rounded-lg font-medium text-sm flex items-center justify-center gap-2 btn-mobile">
                                <i class="fas fa-search text-xs"></i>
                                <span class="touch-text">Terapkan</span>
                            </button>
                            <a href="?" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-3 rounded-lg font-medium text-sm flex items-center justify-center btn-mobile">
                                <i class="fas fa-refresh text-xs"></i>
                            </a>
                        </div>
                    </form>

                    <!-- Quick Stats -->
                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <h4 class="font-semibold text-gray-700 text-sm mb-3 touch-text">Statistik Cepat</h4>
                        <div class="space-y-2">
                            <?php foreach(array_slice($classificationStats, 0, 5) as $classification => $count): ?>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600 truncate flex-1 touch-text"><?= htmlspecialchars($classification) ?></span>
                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded text-xs font-semibold ml-2"><?= $count ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Auto-Reply Stats -->
                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <h4 class="font-semibold text-gray-700 text-sm mb-3 touch-text">Statistik Auto-Reply</h4>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600">Total Pesan</span>
                                <span class="bg-gray-100 text-gray-700 px-3 py-1 rounded text-xs font-semibold"><?= $stats['total_messages'] ?></span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600">Berhasil Dikirim</span>
                                <span class="bg-green-100 text-green-700 px-3 py-1 rounded text-xs font-semibold"><?= $stats['auto_replies'] ?></span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600">Gagal</span>
                                <span class="bg-red-100 text-red-700 px-3 py-1 rounded text-xs font-semibold"><?= $stats['failed_replies'] ?></span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600">Tidak Ada Rule</span>
                                <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded text-xs font-semibold"><?= $stats['no_rule'] ?></span>
                            </div>
                        </div>
                        <button type="button" onclick="showLogsModal()" class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-sm font-medium flex items-center justify-center gap-2 btn-mobile">
                            <i class="fas fa-history text-xs"></i>
                            <span class="touch-text">Lihat Log Terbaru</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="xl:col-span-4 space-y-6">
                <!-- Mass Action Panel -->
                <div class="bg-white rounded-xl p-5 shadow border border-gray-200 card-hover">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-3">
                        <i class="fas fa-paper-plane text-green-500"></i>
                        <span class="touch-text">Kirim Reminder Massal</span>
                    </h3>
                    
                    <form method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Pilih Template</label>
                            <select name="template_id_multi" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm input-mobile" required>
                                <option value="">Pilih Template...</option>
                                <?php foreach($pesanTemplates as $tmpl): ?>
                                    <option value="<?= $tmpl['id'] ?>"><?= htmlspecialchars($tmpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">
                                Pilih Kontak 
                                <span class="text-blue-600">(<?= count($filteredProspects) ?> tersedia)</span>
                            </label>
                            <div class="border border-gray-300 rounded-lg p-3 max-h-40 overflow-y-auto bg-gray-50">
                                <div class="flex items-center gap-3 mb-3 p-2 bg-white rounded-lg border">
                                    <input type="checkbox" id="selectAllContacts" class="w-5 h-5 text-blue-600 rounded select-checkbox">
                                    <label for="selectAllContacts" class="text-sm font-semibold text-gray-700 touch-text">Pilih Semua</label>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach($filteredProspects as $contactId => $data): ?>
                                    <div class="flex items-center gap-3 p-2 hover:bg-white rounded-lg transition-colors duration-150">
                                        <input type="checkbox" name="selected_contacts[]" value="<?= htmlspecialchars($contactId) ?>" 
                                               class="contact-checkbox w-5 h-5 text-blue-600 rounded select-checkbox">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium text-gray-800 truncate contact-number"><?= htmlspecialchars($contactId) ?></div>
                                            <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($data['classification']) ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-end">
                            <button type="submit" name="send_reminder_multi" 
                                    class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-semibold text-sm transition-all flex items-center justify-center gap-3 btn-mobile">
                                <i class="fas fa-paper-plane text-sm"></i>
                                <span class="touch-text">Kirim Massal</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Grid for tables -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Contacts Table -->
                    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden card-hover">
                        <div class="bg-gray-50 px-5 py-4 border-b border-gray-200">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2">
                                <h3 class="text-base font-bold text-gray-800 flex items-center gap-3">
                                    <i class="fas fa-list text-purple-500"></i>
                                    <span class="touch-text">Daftar Calon Peserta</span>
                                    <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded text-sm font-semibold">
                                        <?= $totalProspekFiltered ?> data
                                    </span>
                                </h3>
                                <div class="text-sm text-gray-500">
                                    Sumber: Database log_wa (filter: belum terdaftar)
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <table class="w-full compact-table">
                                <thead class="bg-gray-50 sticky top-0 z-10">
                                    <tr>
                                        <th class="col-kontak px-4 py-3 text-left text-sm font-semibold text-gray-500 uppercase tracking-wider touch-text">Kontak & Nama</th>
                                        <th class="col-minat px-4 py-3 text-left text-sm font-semibold text-gray-500 uppercase tracking-wider touch-text">Minat</th>
                                        <th class="col-hari px-4 py-3 text-center text-sm font-semibold text-gray-500 uppercase tracking-wider touch-text">Hari</th>
                                        <th class="col-aksi px-4 py-3 text-center text-sm font-semibold text-gray-500 uppercase tracking-wider touch-text">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (empty($filteredProspects)): ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center">
                                            <div class="flex flex-col items-center text-gray-400">
                                                <i class="fas fa-inbox text-3xl mb-3 opacity-50"></i>
                                                <div class="text-base font-semibold touch-text">Tidak ada data calon peserta</div>
                                                <div class="text-sm text-gray-500 mt-2">
                                                    Data sudah difilter yang belum terdaftar
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach($filteredProspects as $contactId => $data): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                                        <td class="col-kontak px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center flex-shrink-0 touch-friendly">
                                                    <i class="fas fa-user text-white text-sm"></i>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-semibold text-gray-900 contact-number truncate"><?= htmlspecialchars($contactId) ?></div>
                                                    <?php if (!empty($data['nama'])): ?>
                                                    <div class="text-sm text-gray-600 truncate touch-text"><?= htmlspecialchars($data['nama']) ?></div>
                                                    <?php endif; ?>
                                                    <div class="text-xs text-gray-500 truncate max-w-[180px]" title="<?= htmlspecialchars($data['last_message']) ?>">
                                                        <?= htmlspecialchars(substr($data['last_message'], 0, 35)) ?>...
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="col-minat px-4 py-3">
                                            <span class="inline-flex items-center px-3 py-1.5 rounded text-xs font-medium bg-purple-100 text-purple-800 truncate max-w-full">
                                                <?= htmlspecialchars($data['classification']) ?>
                                            </span>
                                        </td>
                                        <td class="col-hari px-4 py-3 text-center">
                                            <?php if (isset($data['days_since']) && $data['days_since'] !== null): ?>
                                                <?php
                                                $badgeClass = 'bg-green-100 text-green-800';
                                                if ($data['days_since'] >= 7) {
                                                    $badgeClass = 'bg-red-100 text-red-800';
                                                } elseif ($data['days_since'] >= 3) {
                                                    $badgeClass = 'bg-yellow-100 text-yellow-800';
                                                }
                                                ?>
                                                <span class="inline-flex items-center px-3 py-1.5 rounded text-xs font-medium <?= $badgeClass ?>">
                                                    <?= $data['days_since'] ?> hari
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-aksi px-4 py-3">
                                            <div class="table-actions">
                                                <form method="post" class="table-form">
                                                    <input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>">
                                                    <select name="template_id" class="flex-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 input-mobile min-w-[120px]" required>
                                                        <option value="">Pilih Template</option>
                                                        <?php foreach($pesanTemplates as $tmpl): ?>
                                                            <option value="<?= $tmpl['id'] ?>"><?= htmlspecialchars($tmpl['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="send_reminder" 
                                                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center justify-center gap-2 whitespace-nowrap btn-mobile min-w-[100px]">
                                                        <i class="fas fa-paper-plane text-xs"></i>
                                                        <span class="touch-text">Kirim</span>
                                                    </button>
                                                </form>
                                                <div class="flex gap-2">
                                                    <button type="button" onclick="showContactDetails('<?= htmlspecialchars($contactId) ?>')" 
                                                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center justify-center gap-2 btn-mobile">
                                                        <i class="fas fa-eye text-xs"></i>
                                                        <span class="touch-text">Detail</span>
                                                    </button>
                                                    <form method="post" class="flex-1">
                                                        <input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>">
                                                        <input type="hidden" name="current_status" value="<?= isset($blocked[$contactId]) ? 'unblock' : 'block' ?>">
                                                        <button type="submit" name="update_block_status" 
                                                                class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center justify-center gap-2 btn-mobile">
                                                            <i class="fas fa-ban text-xs"></i>
                                                            <span class="touch-text"><?= isset($blocked[$contactId]) ? 'Unblock' : 'Block' ?></span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Blocked Contacts Table -->
                    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden card-hover">
                        <div class="bg-gray-50 px-5 py-4 border-b border-gray-200">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2">
                                <h3 class="text-base font-bold text-gray-800 flex items-center gap-3">
                                    <i class="fas fa-ban text-red-500"></i>
                                    <span class="touch-text">Daftar Kontak Diblokir</span>
                                    <span class="bg-red-100 text-red-700 px-3 py-1 rounded text-sm font-semibold">
                                        <?= count($blockedList) ?> data
                                    </span>
                                </h3>
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <table class="w-full compact-table">
                                <thead class="bg-gray-50 sticky top-0 z-10">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-500 uppercase tracking-wider touch-text">Kontak</th>
                                        <th class="px-4 py-3 text-center text-sm font-semibold text-gray-500 uppercase tracking-wider w-32 touch-text">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (empty($blockedList)): ?>
                                    <tr>
                                        <td colspan="2" class="px-6 py-8 text-center">
                                            <div class="flex flex-col items-center text-gray-400">
                                                <i class="fas fa-inbox text-3xl mb-3 opacity-50"></i>
                                                <div class="text-base font-semibold touch-text">Tidak ada kontak yang diblokir</div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach($blockedList as $contactId): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 bg-red-500 rounded-full flex items-center justify-center flex-shrink-0 touch-friendly">
                                                    <i class="fas fa-phone text-white text-sm"></i>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-semibold text-gray-900 contact-number truncate"><?= htmlspecialchars($contactId) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <form method="post" class="mt-2">
                                                <input type="hidden" name="contact_id" value="<?= htmlspecialchars($contactId) ?>">
                                                <input type="hidden" name="current_status" value="unblock">
                                                <button type="submit" name="update_block_status" 
                                                        class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2.5 rounded-lg text-sm font-medium flex items-center justify-center gap-2 whitespace-nowrap btn-mobile">
                                                    <i class="fas fa-check text-sm"></i>
                                                    <span class="touch-text">Unblock</span>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Recent Logs -->
    <div id="logsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-3">
                    <i class="fas fa-history text-blue-500"></i>
                    <span class="touch-text">Log Auto-Reply Terbaru</span>
                </h3>
                <button type="button" onclick="hideLogsModal()" class="text-gray-400 hover:text-gray-600 touch-friendly" style="width: 44px; height: 44px;">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 touch-text">Waktu</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 touch-text">Kontak</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 touch-text">Pesan Masuk</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 touch-text">Balasan</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 touch-text">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach(array_slice($recentLogs, 0, 10) as $log): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600"><?= date('H:i', strtotime($log['created_at'])) ?></td>
                                <td class="px-4 py-3">
                                    <div class="contact-number text-sm"><?= htmlspecialchars($log['contact_id']) ?></div>
                                </td>
                                <td class="px-4 py-3 text-gray-700 max-w-xs truncate" title="<?= htmlspecialchars($log['incoming_message']) ?>">
                                    <?= htmlspecialchars(substr($log['incoming_message'] ?? '', 0, 35)) ?>
                                </td>
                                <td class="px-4 py-3 text-gray-700 max-w-xs truncate" title="<?= htmlspecialchars($log['reply_message']) ?>">
                                    <?= htmlspecialchars(substr($log['reply_message'] ?? '', 0, 35)) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php
                                    $statusClass = 'status-no_rule';
                                    if ($log['status'] === 'sent') $statusClass = 'status-sent';
                                    if ($log['status'] === 'failed') $statusClass = 'status-failed';
                                    if ($log['status'] === 'registered') $statusClass = 'status-registered';
                                    ?>
                                    <span class="status-badge <?= $statusClass ?>">
                                        <?= htmlspecialchars($log['status'] ?? 'no_rule') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-6 text-center">
                    <a href="view_logs.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium touch-text inline-flex items-center gap-2">
                        <i class="fas fa-external-link-alt"></i> Lihat semua log
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Contact Details -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-3">
                    <i class="fas fa-user-circle text-blue-500"></i>
                    <span class="touch-text">Detail Kontak</span>
                </h3>
                <button type="button" onclick="hideContactModal()" class="text-gray-400 hover:text-gray-600 touch-friendly" style="width: 44px; height: 44px;">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="contactDetails" class="space-y-4">
                    <!-- Details will be loaded here via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Select all functionality
            const selectAll = document.getElementById('selectAllContacts');
            const checkboxes = document.querySelectorAll('.contact-checkbox');
            
            if (selectAll && checkboxes.length > 0) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }

            // Form validation untuk mass action
            const massForm = document.querySelector('form[method="POST"]');
            if (massForm) {
                massForm.addEventListener('submit', function(e) {
                    const selected = document.querySelectorAll('.contact-checkbox:checked').length;
                    const template = this.querySelector('select[name="template_id_multi"]').value;
                    
                    if (selected === 0) {
                        e.preventDefault();
                        alert('Pilih minimal satu kontak!');
                        return false;
                    }
                    
                    if (!template) {
                        e.preventDefault();
                        alert('Pilih template pesan!');
                        return false;
                    }
                    
                    if (!confirm('Kirim reminder ke ' + selected + ' kontak?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            // Optimalkan touch experience
            const touchElements = document.querySelectorAll('button, a, input[type="checkbox"], select');
            touchElements.forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                });
                el.addEventListener('touchend', function() {
                    this.style.transform = '';
                });
            });
        });

        function showLogsModal() {
            document.getElementById('logsModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function hideLogsModal() {
            document.getElementById('logsModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function showContactDetails(contactId) {
            // Fetch contact details via AJAX
            fetch(`get_contact_details.php?contact_id=${encodeURIComponent(contactId)}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('contactDetails').innerHTML = data;
                    document.getElementById('contactModal').style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                })
                .catch(error => {
                    document.getElementById('contactDetails').innerHTML = 
                        '<div class="text-red-600 p-4 text-center">Gagal memuat data kontak.</div>';
                    document.getElementById('contactModal').style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                });
        }

        function hideContactModal() {
            document.getElementById('contactModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const logsModal = document.getElementById('logsModal');
            const contactModal = document.getElementById('contactModal');
            
            if (event.target == logsModal) {
                hideLogsModal();
            }
            if (event.target == contactModal) {
                hideContactModal();
            }
        }

        // Touch events untuk modal
        document.addEventListener('touchstart', function(e) {
            const logsModal = document.getElementById('logsModal');
            const contactModal = document.getElementById('contactModal');
            
            if (logsModal && logsModal.style.display === 'flex' && !logsModal.contains(e.target)) {
                hideLogsModal();
            }
            if (contactModal && contactModal.style.display === 'flex' && !contactModal.contains(e.target)) {
                hideContactModal();
            }
        });
    </script>
</body>
</html>