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
// FUNGSI UPLOAD MEDIA KE WHATSAPP API - DIPERBAIKI
// ==================================================================
function uploadMediaToWhatsApp($filePath, $apiUrl, $apiToken) {
    // Validasi file
    if (!file_exists($filePath)) {
        return ['status' => 'GAGAL', 'message' => 'File tidak ditemukan'];
    }
    
    $mimeType = mime_content_type($filePath);
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['status' => 'GAGAL', 'message' => 'Format gambar tidak didukung (jpg, png, webp)'];
    }
    
    // Cek ukuran file (max 5MB)
    $fileSize = filesize($filePath);
    if ($fileSize > 5 * 1024 * 1024) {
        return ['status' => 'GAGAL', 'message' => 'Ukuran file terlalu besar (max 5MB)'];
    }
    
    // Baca file
    $fileContent = file_get_contents($filePath);
    $fileName = basename($filePath);
    
    // Upload ke WhatsApp API
    $uploadUrl = rtrim($apiUrl, '/') . '/media';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $uploadUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => curl_file_create($filePath, $mimeType, $fileName),
            'messaging_product' => 'whatsapp'
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken
        ],
        CURLOPT_TIMEOUT => 60, // Timeout lebih lama untuk upload
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['status' => 'GAGAL', 'message' => "Upload error: $error"];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode === 200 && isset($result['id'])) {
        return [
            'status' => 'BERHASIL',
            'media_id' => $result['id'],
            'message' => 'Gambar berhasil diupload'
        ];
    }
    
    $errorMsg = isset($result['error']['message']) ? $result['error']['message'] : 'Upload gagal';
    return [
        'status' => 'GAGAL',
        'message' => $errorMsg
    ];
}
// ==================================================================
// FUNGSI KIRIM PESAN GAMBAR
// ==================================================================
function kirimPesanGambar($recipient, $mediaId, $caption, $apiUrl, $apiToken) {
    // Validasi dasar
    if (empty($recipient) || empty($mediaId)) {
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
    
    // Payload untuk gambar
    $data = [
        "recipient_type" => "individual",
        "to" => $cleanNumber,
        "type" => "image",
        "image" => [
            "id" => $mediaId,
            "caption" => !empty($caption) ? $caption : ""
        ]
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
        CURLOPT_TIMEOUT => 15,
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
        return ['status' => 'TERKIRIM', 'message' => 'Gambar berhasil dikirim'];
    }
    
    return ['status' => 'GAGAL', 'message' => "HTTP $httpCode: Gagal mengirim"];
}
// ==================================================================
// FUNGSI CLASSIFY MESSAGE - SESUAI ANALITIK CHAT
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
// ==================================================================
// FUNGSI GET PROSPECTS - SESUAI ANALITIK CHAT
// ==================================================================
function parseProspectsFromDB($conn, $fromDate = null, $toDate = null) {
    $prospek = [];
    if (!$conn) return $prospek;
    
    // Query untuk mengambil data dari log_wa - SESUAI ANALITIK CHAT
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
            
            // Skip jika bukan pesan yang relevan untuk prospek - SESUAI ANALITIK CHAT
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
// ==================================================================
// FUNGSI GET REGISTERED CONTACTS
// ==================================================================
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
// ==================================================================
// FUNGSI GET BLOCKED CONTACTS
// ==================================================================
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
// FUNGSI GET MEDIA GALLERY
// ==================================================================
function getMediaGallery($conn) {
    $mediaList = [];
    if (!$conn) return $mediaList;
    
    $result = $conn->query("SELECT id, media_id, filename, filepath, caption, uploaded_at FROM media_gallery ORDER BY uploaded_at DESC");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $mediaList[] = $row;
        }
    }
    
    return $mediaList;
}
// ==================================================================
// PROSES POST
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Upload gambar baru
    if (isset($_POST['upload_image'])) {
        if (!isset($_FILES['image_file']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['notification'] = "❌ Pilih gambar terlebih dahulu";
            $_SESSION['notificationType'] = 'error';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        $uploadDir = __DIR__ . '/uploads/media/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileTmp = $_FILES['image_file']['tmp_name'];
        $fileName = $_FILES['image_file']['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (!in_array($fileExt, $allowedExt)) {
            $_SESSION['notification'] = "❌ Format gambar tidak didukung (jpg, jpeg, png, webp)";
            $_SESSION['notificationType'] = 'error';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        $caption = trim($_POST['caption_text'] ?? '');
        
        $newFileName = uniqid() . '_' . time() . '.' . $fileExt;
        $filePath = $uploadDir . $newFileName;
        
        if (move_uploaded_file($fileTmp, $filePath)) {
            // Upload ke WhatsApp API
            $uploadResult = uploadMediaToWhatsApp($filePath, $apiUrl, $apiToken);
            
            if ($uploadResult['status'] === 'BERHASIL') {
                // Simpan ke database dengan caption
                $stmt = $conn->prepare("INSERT INTO media_gallery (media_id, filename, filepath, caption, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
                $mediaId = $uploadResult['media_id'];
                $stmt->bind_param("ssss", $mediaId, $newFileName, $filePath, $caption);
                
                if ($stmt->execute()) {
                    $_SESSION['notification'] = "✅ Gambar berhasil diupload ke WhatsApp dengan caption";
                    $_SESSION['notificationType'] = 'success';
                } else {
                    $_SESSION['notification'] = "⚠️ Gambar diupload ke WhatsApp tapi gagal disimpan ke database";
                    $_SESSION['notificationType'] = 'warning';
                }
                $stmt->close();
            } else {
                // Hapus file lokal jika upload gagal
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $_SESSION['notification'] = "❌ " . $uploadResult['message'];
                $_SESSION['notificationType'] = 'error';
            }
        } else {
            $_SESSION['notification'] = "❌ Gagal mengupload gambar ke server";
            $_SESSION['notificationType'] = 'error';
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Kirim gambar massal
    if (isset($_POST['send_image_massal'])) {
        $contacts = $_POST['selected_contacts'] ?? [];
        $mediaId = $_POST['media_id'] ?? '';
        $customCaption = trim($_POST['custom_caption'] ?? '');
        
        if (empty($contacts) || empty($mediaId)) {
            $_SESSION['notification'] = "❌ Pilih kontak dan gambar!";
            $_SESSION['notificationType'] = 'error';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Ambil caption dari database jika tidak ada custom caption
        if (empty($customCaption)) {
            $stmt = $conn->prepare("SELECT caption FROM media_gallery WHERE media_id = ? LIMIT 1");
            $stmt->bind_param("s", $mediaId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $customCaption = $row['caption'] ?? '';
            }
            $stmt->close();
        }
        
        $sukses = $gagal = 0;
        
        foreach ($contacts as $contact) {
            $res = kirimPesanGambar($contact, $mediaId, $customCaption, $apiUrl, $apiToken);
            
            if ($res['status'] === 'TERKIRIM') {
                $sukses++;
            } else {
                $gagal++;
            }
            
            // Delay untuk menghindari rate limit
            usleep(400000);
        }
        
        if ($sukses > 0 && $gagal === 0) {
            $_SESSION['notification'] = "✅ Berhasil mengirim gambar ke $sukses kontak";
            $_SESSION['notificationType'] = 'success';
        } elseif ($sukses > 0 && $gagal > 0) {
            $_SESSION['notification'] = "⚠️ Sebagian berhasil: $sukses sukses, $gagal gagal";
            $_SESSION['notificationType'] = 'warning';
        } else {
            $_SESSION['notification'] = "❌ Gagal mengirim ke semua kontak";
            $_SESSION['notificationType'] = 'error';
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Kirim gambar single
    if (isset($_POST['send_image_single'])) {
        $contact = $_POST['contact_id'] ?? '';
        $mediaId = $_POST['media_id_single'] ?? '';
        $customCaption = trim($_POST['custom_caption_single'] ?? '');
        
        if (empty($contact) || empty($mediaId)) {
            $_SESSION['notification'] = "❌ Pilih kontak dan gambar!";
            $_SESSION['notificationType'] = 'error';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Ambil caption dari database jika tidak ada custom caption
        if (empty($customCaption)) {
            $stmt = $conn->prepare("SELECT caption FROM media_gallery WHERE media_id = ? LIMIT 1");
            $stmt->bind_param("s", $mediaId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $customCaption = $row['caption'] ?? '';
            }
            $stmt->close();
        }
        
        $res = kirimPesanGambar($contact, $mediaId, $customCaption, $apiUrl, $apiToken);
        
        if ($res['status'] === 'TERKIRIM') {
            $_SESSION['notification'] = "✅ Gambar berhasil dikirim ke $contact";
            $_SESSION['notificationType'] = 'success';
        } else {
            $_SESSION['notification'] = "❌ Gagal: " . $res['message'];
            $_SESSION['notificationType'] = 'error';
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Hapus media
    if (isset($_POST['delete_media'])) {
        $mediaId = $_POST['media_id'] ?? '';
        
        if ($mediaId) {
            $stmt = $conn->prepare("SELECT filepath FROM media_gallery WHERE id = ?");
            $stmt->bind_param("i", $mediaId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $filePath = $row['filepath'];
                
                // Hapus file fisik
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                
                // Hapus dari database
                $stmt2 = $conn->prepare("DELETE FROM media_gallery WHERE id = ?");
                $stmt2->bind_param("i", $mediaId);
                
                if ($stmt2->execute()) {
                    $_SESSION['notification'] = "✅ Media berhasil dihapus";
                    $_SESSION['notificationType'] = 'success';
                } else {
                    $_SESSION['notification'] = "❌ Gagal menghapus media";
                    $_SESSION['notificationType'] = 'error';
                }
                
                $stmt2->close();
            }
            
            $stmt->close();
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
// ==================================================================
// AMBIL DATA
// ==================================================================
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';
$searchQuery = $_GET['search'] ?? '';

// Parse prospects dari database - SESUAI ANALITIK CHAT
$prospek = parseProspectsFromDB($conn, $fromDate, $toDate);
$registered = getRegisteredContacts($conn);
$blocked = getBlockedContacts($conn);

// Apply filter: kontak yang belum terdaftar dan tidak diblokir
$filteredProspects = [];
foreach ($prospek as $id => $data) {
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

$totalProspek = count($filteredProspects);
$mediaGallery = getMediaGallery($conn);
$totalMedia = count($mediaGallery);

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
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Kirim Promosi Gambar - WhatsApp</title>
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
    --purple: #8b5cf6;
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
        max-width: 1400px;
        padding: 1.5rem;
    }
}
/* Header Styles */
.header {
    background: linear-gradient(135deg, var(--purple), #7c3aed);
    color: white;
    border-radius: 1.25rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 25px rgba(139, 92, 246, 0.3);
}
@media (min-width: 768px) {
    .header {
        padding: 2rem;
        margin-bottom: 2.5rem;
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
    gap: 1.25rem;
}
.logo-container {
    width: 65px;
    height: 65px;
    border-radius: 1.25rem;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid rgba(255, 255, 255, 0.2);
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
@media (min-width: 768px) {
    .logo-container {
        width: 85px;
        height: 85px;
    }
}
.logo-container i {
    font-size: 1.75rem;
    color: white;
}
@media (min-width: 768px) {
    .logo-container i {
        font-size: 2.25rem;
    }
}
.title-content h1 {
    font-size: 1.625rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
    letter-spacing: -0.5px;
}
@media (min-width: 768px) {
    .title-content h1 {
        font-size: 2.25rem;
        margin-bottom: 0.5rem;
    }
}
.title-content p {
    color: #d1d5db;
    font-size: 0.95rem;
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
    padding: 0.75rem 1.5rem;
    border-radius: 0.875rem;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    font-size: 0.9rem;
    flex: 1;
    min-width: 140px;
    justify-content: center;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
.action-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.btn-back {
    background: rgba(59, 130, 246, 0.2);
    border: 1px solid rgba(59, 130, 246, 0.3);
}
.btn-back:hover {
    background: rgba(59, 130, 246, 0.3);
}
.btn-logout {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.3);
}
.btn-logout:hover {
    background: rgba(239, 68, 68, 0.3);
}
/* Touch-friendly */
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
.btn-mobile {
    padding: 0.75rem 1rem;
    font-size: 16px;
    border-radius: 0.875rem;
    cursor: pointer;
}
.input-mobile {
    font-size: 16px;
    padding: 0.75rem;
    border-radius: 0.875rem;
}
/* Cards */
.card-hover:hover {
    transform: translateY(-3px);
    transition: all 0.3s ease;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
}
/* Notification */
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
/* Grid spacing */
.grid-improved {
    gap: 1.5rem;
}
@media (min-width: 768px) {
    .grid-improved {
        gap: 2rem;
    }
}
.spacing-comfortable {
    margin-bottom: 1.5rem;
}
@media (min-width: 768px) {
    .spacing-comfortable {
        margin-bottom: 2rem;
    }
}
/* Upload area */
.upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 1rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background: #f9fafb;
}
.upload-area:hover {
    border-color: #8b5cf6;
    background: #f3f4f6;
}
.upload-area i {
    font-size: 3rem;
    color: #8b5cf6;
    margin-bottom: 1rem;
}
/* Media gallery */
.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 1rem;
}
.media-item {
    border-radius: 0.75rem;
    overflow: hidden;
    position: relative;
    aspect-ratio: 1/1;
    border: 2px solid #e5e7eb;
    transition: all 0.3s;
}
.media-item:hover {
    border-color: #8b5cf6;
    transform: scale(1.02);
}
.media-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.media-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 0.5rem;
    font-size: 0.75rem;
    text-align: center;
    opacity: 0;
    transition: opacity 0.3s;
}
.media-item:hover .media-overlay {
    opacity: 1;
}
/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    z-index: 1000;
    justify-content: center;
    align-items: center;
    padding: 1rem;
}
.modal-content {
    background-color: white;
    border-radius: 1.25rem;
    width: 100%;
    max-width: 600px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35);
}
.modal-header {
    padding: 1.25rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-body {
    padding: 1.25rem;
}
/* Buttons */
.btn-primary {
    background-color: #8b5cf6;
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 0.75rem;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-primary:hover {
    background-color: #7c3aed;
    transform: translateY(-2px);
}
.btn-danger {
    background-color: #ef4444;
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 0.625rem;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-danger:hover {
    background-color: #dc2626;
    transform: scale(1.05);
}
/* Table */
.compact-table {
    width: 100%;
    border-collapse: collapse;
}
.compact-table td, .compact-table th {
    padding: 0.75rem;
    vertical-align: middle;
}
.table-container {
    max-height: 50vh;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
/* Caption preview */
.caption-preview {
    background: #f3f4f6;
    border-left: 4px solid #8b5cf6;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-top: 0.5rem;
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
                <i class="fas fa-image"></i>
            </div>
            <div class="title-content">
                <h1 class="touch-text">Kirim Promosi Gambar</h1>
                <p class="touch-text">Broadcast pesan promosi dengan gambar ke calon peserta</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="analitik-chat.php" class="action-btn btn-back touch-friendly">
                <i class="fas fa-arrow-left"></i>
                <span class="touch-text">Kembali</span>
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
$notificationClass = 'notification-info';
if ($notificationType === 'success') $notificationClass = 'notification-success';
if ($notificationType === 'error') $notificationClass = 'notification-error';
if ($notificationType === 'warning') $notificationClass = 'notification-warning';
?>
<div class="mb-4 p-4 rounded-xl border <?= $notificationClass ?> touch-text spacing-comfortable">
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
<div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6 spacing-comfortable">
    <div class="bg-white rounded-xl p-4 shadow-lg border border-gray-200 card-hover">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm font-semibold touch-text">Total Calon Peserta</p>
                <p class="text-2xl font-bold text-gray-800 mt-1 touch-text"><?= number_format($totalProspek) ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center touch-friendly">
                <i class="fas fa-users text-white"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-lg border border-gray-200 card-hover">
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
    <div class="bg-white rounded-xl p-4 shadow-lg border border-gray-200 card-hover">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm font-semibold touch-text">Gambar Tersedia</p>
                <p class="text-2xl font-bold text-gray-800 mt-1 touch-text"><?= number_format($totalMedia) ?></p>
            </div>
            <div class="w-12 h-12 bg-purple-500 rounded-xl flex items-center justify-center touch-friendly">
                <i class="fas fa-images text-white"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-lg border border-gray-200 card-hover">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm font-semibold touch-text">Format Gambar</p>
                <p class="text-lg font-bold text-gray-800 mt-1 touch-text">JPG, PNG, WEBP</p>
            </div>
            <div class="w-12 h-12 bg-green-500 rounded-xl flex items-center justify-center touch-friendly">
                <i class="fas fa-file-image text-white"></i>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 grid-improved">
    <!-- Upload & Gallery Panel -->
    <div class="lg:col-span-1">
        <!-- Upload Form -->
        <div class="bg-white rounded-xl p-5 shadow-lg border border-gray-200 mb-6 card-hover">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2.5">
                <i class="fas fa-cloud-upload-alt text-purple-500"></i>
                <span class="touch-text">Upload Gambar Baru</span>
            </h3>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <div class="upload-area" onclick="document.getElementById('image_file').click()">
                    <i class="fas fa-image text-purple-500"></i>
                    <p class="text-gray-600 font-medium mb-2 touch-text">Klik untuk upload gambar</p>
                    <p class="text-xs text-gray-400">JPG, PNG, WEBP (Max 5MB)</p>
                    <input type="file" id="image_file" name="image_file" accept="image/*" class="hidden" onchange="previewImage(this)">
                </div>
                
                <!-- Preview Image -->
                <div id="imagePreview" class="hidden">
                    <img id="previewImg" src="" alt="Preview" class="w-full rounded-lg mb-3">
                </div>
                
                <!-- Caption -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Caption Gambar</label>
                    <textarea name="caption_text" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm input-mobile" placeholder="Tulis caption untuk gambar ini..."></textarea>
                    <p class="text-xs text-gray-400 mt-1">Caption akan disimpan bersama gambar</p>
                </div>
                
                <button type="submit" name="upload_image" class="w-full btn-primary btn-mobile">
                    <i class="fas fa-upload mr-2"></i>
                    Upload ke WhatsApp
                </button>
            </form>
        </div>
        
        <!-- Media Gallery -->
        <div class="bg-white rounded-xl p-5 shadow-lg border border-gray-200 card-hover">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2.5">
                    <i class="fas fa-images text-purple-500"></i>
                    <span class="touch-text">Gallery Gambar</span>
                </h3>
                <span class="bg-purple-100 text-purple-700 px-2.5 py-1 rounded text-xs font-semibold">
                    <?= $totalMedia ?> gambar
                </span>
            </div>
            
            <?php if (empty($mediaGallery)): ?>
            <div class="text-center py-8 text-gray-400">
                <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                <p class="text-sm">Belum ada gambar</p>
                <p class="text-xs mt-1">Upload gambar terlebih dahulu</p>
            </div>
            <?php else: ?>
            <div class="media-grid max-h-96 overflow-y-auto pr-2">
                <?php foreach($mediaGallery as $media): ?>
                <div class="media-item">
                    <img src="<?= htmlspecialchars($media['filepath']) ?>" alt="Media">
                    <div class="media-overlay">
                        <p class="truncate"><?= htmlspecialchars(pathinfo($media['filename'], PATHINFO_FILENAME)) ?></p>
                        <?php if (!empty($media['caption'])): ?>
                        <p class="text-xs mt-1 truncate" title="<?= htmlspecialchars($media['caption']) ?>">
                            <?= htmlspecialchars(substr($media['caption'], 0, 15)) ?>...
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Kirim Massal -->
        <div class="bg-white rounded-xl p-5 shadow-lg border border-gray-200 card-hover">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-3">
                <i class="fas fa-paper-plane text-green-500"></i>
                <span class="touch-text">Kirim Gambar Massal ke Calon Peserta</span>
            </h3>
            
            <form method="POST" class="space-y-4">
                <!-- Pilih Gambar -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Pilih Gambar</label>
                    <select name="media_id" id="mediaSelect" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm input-mobile" required onchange="showCaptionPreview()">
                        <option value="">Pilih gambar...</option>
                        <?php foreach($mediaGallery as $media): ?>
                        <option value="<?= htmlspecialchars($media['media_id']) ?>" data-caption="<?= htmlspecialchars($media['caption']) ?>">
                            <?= htmlspecialchars(pathinfo($media['filename'], PATHINFO_FILENAME)) ?> 
                            (<?= date('d/m/Y', strtotime($media['uploaded_at'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Caption Preview & Custom -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Caption</label>
                    <div class="space-y-2">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="radio" name="caption_type" value="default" checked class="form-radio text-purple-600" onclick="toggleCaptionInput(false)">
                                <span class="ml-2 text-sm">Gunakan caption default</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="radio" name="caption_type" value="custom" class="form-radio text-purple-600" onclick="toggleCaptionInput(true)">
                                <span class="ml-2 text-sm">Tulis caption custom</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Default Caption Preview -->
                    <div id="defaultCaptionPreview" class="caption-preview mt-2 hidden">
                        <p class="text-sm font-medium text-gray-700 mb-1">Caption Default:</p>
                        <p id="defaultCaptionText" class="text-sm text-gray-600"></p>
                    </div>
                    
                    <!-- Custom Caption Input -->
                    <div id="customCaptionDiv" class="mt-2 hidden">
                        <textarea name="custom_caption" id="customCaptionInput" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm input-mobile" placeholder="Tulis caption custom..."></textarea>
                    </div>
                </div>
                
                <!-- Pilih Kontak -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">
                        Pilih Calon Peserta
                        <span class="text-purple-600">(<?= $totalProspek ?>)</span>
                    </label>
                    <div class="border border-gray-300 rounded-lg p-3 max-h-48 overflow-y-auto bg-gray-50">
                        <div class="flex items-center gap-3 mb-3 p-2 bg-white rounded-lg border">
                            <input type="checkbox" id="selectAll" class="w-5 h-5 text-purple-600 rounded">
                            <label for="selectAll" class="text-sm font-semibold text-gray-700 touch-text">Pilih Semua</label>
                        </div>
                        <div class="space-y-2">
                            <?php foreach($filteredProspects as $contactId => $data): ?>
                            <div class="flex items-center gap-3 p-2 hover:bg-white rounded-lg transition-colors">
                                <input type="checkbox" name="selected_contacts[]" value="<?= htmlspecialchars($contactId) ?>" class="contact-checkbox w-5 h-5 text-purple-600 rounded">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($contactId) ?></div>
                                    <?php if (!empty($data['nama'])): ?>
                                    <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($data['nama']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-xs text-purple-600 truncate"><?= htmlspecialchars($data['classification']) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Submit -->
                <button type="submit" name="send_image_massal" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg font-semibold text-sm transition-all flex items-center justify-center gap-3 btn-mobile">
                    <i class="fas fa-paper-plane"></i>
                    <span class="touch-text">Kirim ke Calon Peserta Terpilih</span>
                </button>
            </form>
        </div>
        
        <!-- Kirim Single -->
        <div class="bg-white rounded-xl p-5 shadow-lg border border-gray-200 card-hover">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-3">
                <i class="fas fa-envelope text-blue-500"></i>
                <span class="touch-text">Kirim Gambar ke Satu Kontak</span>
            </h3>
            
            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Kontak -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Nomor Kontak</label>
                    <input type="text" name="contact_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm input-mobile" placeholder="628xxxxxxxxx" required>
                </div>
                
                <!-- Gambar -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Pilih Gambar</label>
                    <select name="media_id_single" id="mediaSelectSingle" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm input-mobile" required onchange="showCaptionPreviewSingle()">
                        <option value="">Pilih gambar...</option>
                        <?php foreach($mediaGallery as $media): ?>
                        <option value="<?= htmlspecialchars($media['media_id']) ?>" data-caption="<?= htmlspecialchars($media['caption']) ?>">
                            <?= htmlspecialchars(pathinfo($media['filename'], PATHINFO_FILENAME)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Kirim Button -->
                <div class="flex items-end">
                    <button type="submit" name="send_image_single" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-semibold text-sm transition-all flex items-center justify-center gap-2 btn-mobile">
                        <i class="fas fa-paper-plane"></i>
                        <span class="touch-text">Kirim</span>
                    </button>
                </div>
                
                <!-- Caption (full width) -->
                <div class="md:col-span-3">
                    <label class="block text-sm font-semibold text-gray-700 mb-2 touch-text">Caption (Opsional)</label>
                    <textarea name="custom_caption_single" rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm input-mobile" placeholder="Tulis caption untuk gambar..."></textarea>
                    <p class="text-xs text-gray-400 mt-1">Kosongkan untuk menggunakan caption default gambar</p>
                </div>
            </form>
        </div>
        
        <!-- Daftar Calon Peserta -->
        <div class="bg-white rounded-xl p-5 shadow-lg border border-gray-200 card-hover">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-bold text-gray-800 flex items-center gap-2.5">
                    <i class="fas fa-list text-gray-500"></i>
                    <span class="touch-text">Daftar Calon Peserta</span>
                </h3>
                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded text-sm font-semibold">
                    <?= $totalProspek ?> kontak
                </span>
            </div>
            
            <div class="table-container">
                <table class="compact-table">
                    <thead class="bg-gray-50 sticky top-0 z-10">
                        <tr>
                            <th class="text-left text-xs font-semibold text-gray-500 uppercase">Kontak</th>
                            <th class="text-left text-xs font-semibold text-gray-500 uppercase">Nama</th>
                            <th class="text-left text-xs font-semibold text-gray-500 uppercase">Minat</th>
                            <th class="text-left text-xs font-semibold text-gray-500 uppercase">Hari</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($filteredProspects)): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-400">
                                Tidak ada calon peserta tersedia
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach(array_slice($filteredProspects, 0, 20) as $contactId => $data): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="font-medium text-gray-900"><?= htmlspecialchars($contactId) ?></td>
                            <td class="text-gray-600"><?= htmlspecialchars($data['nama'] ?? '-') ?></td>
                            <td class="text-purple-600 text-sm"><?= htmlspecialchars($data['classification']) ?></td>
                            <td class="text-gray-500 text-sm">
                                <?php if (isset($data['days_since']) && $data['days_since'] !== null): ?>
                                    <?= $data['days_since'] ?> hari
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalProspek > 20): ?>
            <div class="mt-3 text-center text-sm text-gray-500">
                Menampilkan 20 dari <?= $totalProspek ?> calon peserta
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<script>
// Preview image before upload
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            preview.classList.remove('hidden');
        }
        
        reader.readAsDataURL(input.files[0]);
    }
}

// Show caption preview for massal
function showCaptionPreview() {
    const select = document.getElementById('mediaSelect');
    const option = select.options[select.selectedIndex];
    const caption = option.getAttribute('data-caption') || '';
    const preview = document.getElementById('defaultCaptionPreview');
    const previewText = document.getElementById('defaultCaptionText');
    
    if (caption) {
        previewText.textContent = caption;
        preview.classList.remove('hidden');
    } else {
        preview.classList.add('hidden');
    }
}

// Show caption preview for single
function showCaptionPreviewSingle() {
    const select = document.getElementById('mediaSelectSingle');
    const option = select.options[select.selectedIndex];
    const caption = option.getAttribute('data-caption') || '';
    console.log('Caption:', caption);
}

// Toggle custom caption input
function toggleCaptionInput(show) {
    const customDiv = document.getElementById('customCaptionDiv');
    const previewDiv = document.getElementById('defaultCaptionPreview');
    
    if (show) {
        customDiv.classList.remove('hidden');
        previewDiv.classList.add('hidden');
    } else {
        customDiv.classList.add('hidden');
        previewDiv.classList.remove('hidden');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Select all functionality
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.contact-checkbox');
    
    if (selectAll && checkboxes.length > 0) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    }
    
    // Form validation untuk massal
    const forms = document.querySelectorAll('form[method="POST"]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (this.querySelector('[name="send_image_massal"]')) {
                const selected = this.querySelectorAll('.contact-checkbox:checked').length;
                const mediaId = this.querySelector('[name="media_id"]').value;
                
                if (selected === 0) {
                    e.preventDefault();
                    alert('Pilih minimal satu calon peserta!');
                    return false;
                }
                
                if (!mediaId) {
                    e.preventDefault();
                    alert('Pilih gambar terlebih dahulu!');
                    return false;
                }
                
                if (!confirm(`Kirim gambar promosi ke ${selected} calon peserta?`)) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    });
    
    // Touch optimization
    const touchElements = document.querySelectorAll('button, a, input[type="checkbox"], select');
    touchElements.forEach(el => {
        el.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.98)';
        });
        el.addEventListener('touchend', function() {
            this.style.transform = '';
        });
    });
    
    // Initialize caption preview on page load
    showCaptionPreview();
});
</script>
</body>
</html>