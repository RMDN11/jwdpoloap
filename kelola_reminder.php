<?php
session_start();
require_once 'auth_checkwa.php';

require_once 'config.php';

// FIX: Set karakter set koneksi ke utf8mb4 untuk mendukung emoji dan karakter khusus
if ($conn) {
    mysqli_set_charset($conn, "utf8mb4");
}

$notification = '';
$notificationType = '';

// Mengambil notifikasi dari session jika ada
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notificationType'];
    // Hapus notifikasi dari session agar tidak muncul lagi setelah refresh
    unset($_SESSION['notification']);
    unset($_SESSION['notificationType']);
}

// === LOGIKA RESET HARIAN ===
$resetStmt = $conn->prepare("SELECT value FROM reminder_configs WHERE `key` = 'last_reset_date'");
$resetStmt->execute();
$resetResult = $resetStmt->get_result();
$lastResetDate = $resetResult->fetch_assoc()['value'] ?? null;
$resetStmt->close();

if ($lastResetDate !== date('Y-m-d')) {
    // Reset status permintaan menjadi 'menunggu'
    $conn->query("UPDATE reminder_requests SET status = 'menunggu'");
    
    $updateResetStmt = $conn->prepare("
        INSERT INTO reminder_configs (`key`, `value`) VALUES ('last_reset_date', ?)
        ON DUPLICATE KEY UPDATE `value` = ?
    ");
    $currentDate = date('Y-m-d');
    $updateResetStmt->bind_param("ss", $currentDate, $currentDate);
    $updateResetStmt->execute();
    $updateResetStmt->close();
}
// === AKHIR LOGIKA RESET HARIAN ===


// Fungsi untuk mengirim pesan teks
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
    if ($curlError) {
        return ['status' => 'GAGAL', 'message' => "cURL Error: " . $curlError];
    }
    $responseData = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['status' => 'TERKIRIM', 'message' => "Berhasil dikirim."];
    } else {
        $errorMessage = isset($responseData['message']) ? $responseData['message'] : "Unknown error." . " HTTP Code: " . $httpCode . " Response: " . $response;
        return ['status' => 'GAGAL', 'message' => "Pengiriman tidak berhasil: " . $errorMessage];
    }
}

// === Proses Hapus Semua Permintaan (BARU DITAMBAH) ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_all_requests'])) {
    if ($conn->query("DELETE FROM reminder_requests")) {
        $_SESSION['notification'] = "Berhasil! Semua permintaan reminder telah dihapus.";
        $_SESSION['notificationType'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus semua permintaan: " . $conn->error;
        $_SESSION['notificationType'] = 'error';
    }
    header("Location: kelola_reminder.php");
    exit();
}

// === Proses Hapus Permintaan Satuan ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['hapus_request'])) {
    $requestId = (int)$_POST['request_id'];
    $stmt = $conn->prepare("DELETE FROM reminder_requests WHERE id = ?");
    $stmt->bind_param("i", $requestId);
    if ($stmt->execute()) {
        $_SESSION['notification'] = "Berhasil! Permintaan telah dihapus.";
        $_SESSION['notificationType'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus permintaan: " . $stmt->error;
        $_SESSION['notificationType'] = 'error';
    }
    $stmt->close();
    header("Location: kelola_reminder.php");
    exit();
}

// === Proses Tambah/Edit/Hapus Template ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submit_template'])) {
    $templateName = htmlspecialchars(trim($_POST['template_name']));
    $templateContent = trim($_POST['template_content']); 
    $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;

    if ($templateId > 0) {
        // Update Template
        $stmt = $conn->prepare("UPDATE reminder_templates SET name = ?, content = ? WHERE id = ?");
        $stmt->bind_param("ssi", $templateName, $templateContent, $templateId);
        if ($stmt->execute()) {
            $_SESSION['notification'] = "Template berhasil diupdate.";
            $_SESSION['notificationType'] = 'success';
        } else {
            $_SESSION['notification'] = "Gagal mengupdate template: " . $stmt->error;
            $_SESSION['notificationType'] = 'error';
        }
    } else {
        // Tambah Template
        $stmt = $conn->prepare("INSERT INTO reminder_templates (name, content) VALUES (?, ?)");
        $stmt->bind_param("ss", $templateName, $templateContent);
        if ($stmt->execute()) {
            $_SESSION['notification'] = "Template baru berhasil ditambahkan.";
            $_SESSION['notificationType'] = 'success';
        } else {
            $_SESSION['notification'] = "Gagal menambahkan template: " . $stmt->error;
            $_SESSION['notificationType'] = 'error';
        }
    }
    $stmt->close();
    header("Location: kelola_reminder.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_template'])) {
    $templateId = (int)$_POST['template_id'];
    $stmt = $conn->prepare("DELETE FROM reminder_templates WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    if ($stmt->execute()) {
        $_SESSION['notification'] = "Template berhasil dihapus.";
        $_SESSION['notificationType'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus template: " . $stmt->error;
        $_SESSION['notificationType'] = 'error';
    }
    $stmt->close();
    header("Location: kelola_reminder.php");
    exit();
}

// === Proses Kirim Satu Pesan ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['kirim_reminder'])) {
    $requestId = (int)$_POST['request_id'];
    $templateId = (int)$_POST['template_id'];

    $stmt = $conn->prepare("SELECT peserta_nowa, peserta_nama FROM reminder_requests WHERE id = ?");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $requestData = $result->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT content FROM reminder_templates WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $templateData = $result->fetch_assoc();
    $stmt->close();

    if ($requestData && $templateData) {
        $message = str_replace(
            ['{peserta_nama}', '{peserta_nowa}'],
            [$requestData['peserta_nama'], $requestData['peserta_nowa']],
            $templateData['content']
        );
        $apiResponse = kirimPesan($requestData['peserta_nowa'], $message, $apiUrl, $apiToken);
        if ($apiResponse['status'] === 'TERKIRIM') {
            $stmt = $conn->prepare("UPDATE reminder_requests SET status = 'terkirim', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $requestId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['notification'] = "🎉 Berhasil! Pesan telah terkirim.";
            $_SESSION['notificationType'] = 'success';
        } else {
            $_SESSION['notification'] = "⚠️ Gagal mengirim pesan: " . $apiResponse['message'];
            $_SESSION['notificationType'] = 'error';
        }
    } else {
        $_SESSION['notification'] = "Data permintaan atau template tidak ditemukan.";
        $_SESSION['notificationType'] = 'error';
    }
    header("Location: kelola_reminder.php");
    exit();
}

// === Proses Kirim Semua Pesan (Status 'menunggu') ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['kirim_semua'])) {
    $templateId = (int)$_POST['template_id'];

    // Ambil data template satu kali sebelum loop
    $stmt = $conn->prepare("SELECT content FROM reminder_templates WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $templateData = $result->fetch_assoc();
    $stmt->close();

    if ($templateData) {
        $countSuccess = 0;
        $countFailed = 0;

        // Ambil semua permintaan yang berstatus 'menunggu' dalam satu query
        $stmt = $conn->prepare("SELECT id, peserta_nowa, peserta_nama FROM reminder_requests WHERE status = 'menunggu'");
        $stmt->execute();
        $result = $stmt->get_result();
        $requests = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Siapkan statement UPDATE di luar loop untuk efisiensi
        $stmt_update = $conn->prepare("UPDATE reminder_requests SET status = 'terkirim', updated_at = NOW() WHERE id = ?");
        $stmt_update->bind_param("i", $requestIdToUpdate);

        foreach ($requests as $req) {
            $message = str_replace(
                ['{peserta_nama}', '{peserta_nowa}'],
                [$req['peserta_nama'], $req['peserta_nowa']],
                $templateData['content']
            );

            $apiResponse = kirimPesan($req['peserta_nowa'], $message, $apiUrl, $apiToken);
            
            if ($apiResponse['status'] === 'TERKIRIM') {
                $countSuccess++;
                // Lakukan UPDATE menggunakan statement yang sudah disiapkan
                $requestIdToUpdate = $req['id'];
                $stmt_update->execute();
            } else {
                $countFailed++;
            }
        }
        
        // Tutup statement UPDATE setelah loop selesai
        $stmt_update->close();

        $_SESSION['notification'] = "🎉 Berhasil mengirim **{$countSuccess}** pesan. Gagal mengirim **{$countFailed}** pesan.";
        $_SESSION['notificationType'] = $countFailed > 0 ? 'error' : 'success';
    } else {
        $_SESSION['notification'] = "Template pesan tidak ditemukan.";
        $_SESSION['notificationType'] = 'error';
    }
    header("Location: kelola_reminder.php");
    exit();
}
// === Ambil Data Template dan Permintaan ===
$stmt = $conn->prepare("SELECT id, name, content FROM reminder_templates ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
$templates = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT id, halaqoh, peserta_nama, peserta_nowa, pesan_pengajar, status, created_at 
    FROM reminder_requests 
    ORDER BY created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kelola Reminder - JWD</title>
  <?php $cache_buster = time(); ?>
  <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }
    
    /* Warna Abu-abu Modern */
    .bg-primary { background-color: #374151; }
    .bg-secondary { background-color: #4b5563; }
    .bg-card { background-color: #f3f4f6; }
    .text-primary { color: #111827; }
    .text-secondary { color: #4b5563; }
    .border-card { border-color: #e5e7eb; }
    .bg-accent { background-color: #9ca3af; }
    .text-accent { color: #9ca3af; }
    .bg-accent-light { background-color: #e5e7eb; }
    .text-accent-light { color: #9ca3af; }
    .bg-success { background-color: #d1fae5; }
    .text-success { color: #065f46; }
    .bg-error { background-color: #fee2e2; }
    .text-error { color: #991b1b; }
    .border-success { border-color: #a7f3d0; }
    .border-error { border-color: #fecaca; }
    .btn-primary { background: linear-gradient(to right, #6b7280, #4b5563); }
    .btn-primary:hover { background: linear-gradient(to right, #4b5563, #374151); }
    .btn-accent { background: linear-gradient(to right, #9ca3af, #6b7280); }
    .btn-accent:hover { background: linear-gradient(to right, #6b7280, #4b5563); }
    
    /* Layout dan Spacing */
    .section-card { 
        background-color: #f3f4f6; 
        border-radius: 0.75rem; 
        border: 1px solid #e5e7eb;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    }
    
    /* Form Input */
    .form-input {
        width: 100%;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        padding: 0.75rem;
        background-color: white;
        transition: all 0.2s;
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    .form-input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    /* Tombol */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        font-weight: 500;
        transition: all 0.2s;
        cursor: pointer;
        font-size: 16px; /* Better touch targets */
        min-height: 44px; /* Minimum touch target size */
    }
    
    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 14px;
        min-height: 36px;
    }
    
    .btn-primary {
        background: linear-gradient(to right, #6b7280, #4b5563);
        color: white;
    }
    
    .btn-primary:hover {
        background: linear-gradient(to right, #4b5563, #374151);
        transform: translateY(-1px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    
    .btn-success {
        background: linear-gradient(to right, #10b981, #059669);
        color: white;
    }
    
    .btn-success:hover {
        background: linear-gradient(to right, #059669, #047857);
        transform: translateY(-1px);
    }
    
    .btn-danger {
        background: linear-gradient(to right, #ef4444, #dc2626);
        color: white;
    }
    
    .btn-danger:hover {
        background: linear-gradient(to right, #dc2626, #b91c1c);
        transform: translateY(-1px);
    }
    
    /* Notifikasi */
    .notification {
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1.5rem;
        border-left-width: 4px;
    }
    
    .notification-success {
        background-color: #d1fae5;
        color: #065f46;
        border-color: #10b981;
    }
    
    .notification-error {
        background-color: #fee2e2;
        color: #991b1b;
        border-color: #ef4444;
    }
    
    /* Status Badge */
    .status-badge {
        padding: 0.5rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 32px;
    }
    
    .badge-menunggu {
        background-color: #fef3c7;
        color: #92400e;
        border: 1px solid #fcd34d;
    }
    
    .badge-terkirim {
        background-color: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    /* Tabel Responsive */
    .table-container { 
        overflow-x: auto;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
    }
    
    .table-container table { 
        width: 100%; 
        min-width: 768px; /* Minimum width for table */
    }
    
    .table-container thead { 
        background-color: #f9fafb;
    }
    
    .table-container th { 
        background-color: #f9fafb;
        padding: 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        text-align: left;
    }
    
    .table-container td {
        padding: 1rem;
        font-size: 0.875rem;
    }
    
    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .mobile-stack {
            flex-direction: column;
        }
        
        .mobile-w-full {
            width: 100%;
        }
        
        .mobile-text-center {
            text-align: center;
        }
        
        .mobile-p-3 {
            padding: 0.75rem;
        }
        
        .mobile-space-y-3 > * + * {
            margin-top: 0.75rem;
        }
        
        /* Compact table for mobile */
        .table-container {
            font-size: 0.75rem;
        }
        
        .table-container th,
        .table-container td {
            padding: 0.5rem;
        }
        
        /* Better button sizing on mobile */
        .btn {
            width: 100%;
            justify-content: center;
        }
        
        .btn-sm {
            width: auto;
        }
    }
    
    /* Improved responsive grid for templates */
    .template-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1rem;
    }
    
    @media (max-width: 640px) {
        .template-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* Line clamp for text truncation */
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    /* Touch-friendly interactions */
    @media (hover: none) {
        .btn:hover {
            transform: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(to right, #6b7280, #4b5563);
        }
        
        .btn-success:hover {
            background: linear-gradient(to right, #10b981, #059669);
        }
        
        .btn-danger:hover {
            background: linear-gradient(to right, #ef4444, #dc2626);
        }
    }
  </style>
</head>
<body class="bg-gray-50 text-gray-800">
  <div id="app" class="flex flex-col min-h-screen">
    <!-- Header Mobile Friendly -->
    <header class="bg-gray-800 text-white shadow-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-3">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                <div class="flex items-center space-x-3">
                    <div class="bg-gradient-to-br from-gray-600 to-gray-700 p-2 sm:p-3 rounded-xl shadow-lg">
                        <i class="fas fa-message text-white text-xl sm:text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-bold text-white">Kelola Reminder</h1>
                        <p class="text-xs sm:text-sm text-gray-300">Atur permintaan pengiriman dan template pesan</p>
                    </div>
                </div>
                <!-- Tombol Navigasi - Stack on mobile -->
                <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                    <a href="reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-bell mr-1"></i> Pembayaran
                    </a>
                    <a href="promosi.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-bullhorn mr-1"></i> Promosi
                    </a>
                    <a href="kirimgrup.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-users mr-1"></i> Grup
                    </a>
                    <a href="logoutwa.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-right-from-bracket"></i> Keluar
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow p-3 sm:p-6 lg:p-8">
        <div class="max-w-7xl mx-auto">
            <?php if (!empty($notification)): ?>
            <div class="notification <?php echo $notificationType === 'success' ? 'notification-success' : 'notification-error'; ?> mb-4 sm:mb-6">
                <?php echo htmlspecialchars($notification); ?>
            </div>
            <?php endif; ?>

            <!-- Daftar Permintaan Reminder -->
            <div class="section-card p-4 sm:p-6 mb-6 sm:mb-8">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-4 sm:mb-6 mobile-space-y-3">
                    <h2 class="text-lg sm:text-xl font-bold text-primary flex items-center mobile-text-center sm:text-left">
                        <i class="fas fa-list text-lg sm:text-xl mr-2 text-accent"></i> Daftar Permintaan Reminder
                    </h2>
                    <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded-full inline-block sm:inline">
                        <?= count($requests ?? []) ?> Permintaan
                    </span>
                </div>
                
                <!-- Form Kirim Semua dan Hapus Semua - Stack on mobile -->
                <div class="flex flex-col md:flex-row md:items-center gap-3 mb-4 sm:mb-6 p-3 sm:p-4 bg-white rounded-lg border border-card">
                    <!-- Form Kirim Semua Pesan -->
                    <form method="POST" class="flex flex-col sm:flex-row gap-2 flex-1">
                        <input type="hidden" name="kirim_semua" value="1">
                        <select name="template_id" class="form-input mobile-w-full" <?= empty($templates) ? 'disabled' : '' ?>>
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?= $tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="kirim_semua" class="btn btn-success mobile-w-full sm:w-auto" <?= empty($templates) ? 'disabled' : '' ?>>
                            <i class="fas fa-paper-plane mr-2"></i> Kirim Semua
                        </button>
                    </form>
                    
                    <!-- Form Hapus Semua Permintaan -->
                    <?php if (!empty($requests)): ?>
                    <form method="POST" onsubmit="return confirm('ANDA YAKIN INGIN MENGHAPUS SEMUA PERMINTAAN REMINDER? Tindakan ini tidak bisa dibatalkan.');" class="mobile-w-full">
                        <button type="submit" name="delete_all_requests" class="btn btn-danger mobile-w-full">
                            <i class="fas fa-trash mr-2"></i> Hapus Semua
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (empty($requests)): ?>
                    <div class="text-center py-8 sm:py-12 text-gray-500 bg-white rounded-lg border border-card">
                        <i class="fas fa-inbox text-3xl sm:text-4xl mb-3"></i>
                        <p class="text-sm sm:text-base">Belum ada permintaan reminder.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="w-full">
                            <thead class="bg-gray-100 text-gray-600 text-xs sm:text-sm font-semibold">
                                <tr>
                                    <th class="p-3 sm:p-4">Halaqoh</th>
                                    <th class="p-3 sm:p-4">Peserta</th>
                                    <th class="p-3 sm:p-4 hidden sm:table-cell">No. WA</th>
                                    <th class="p-3 sm:p-4">Pesan</th>
                                    <th class="p-3 sm:p-4">Status</th>
                                    <th class="p-3 sm:p-4">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($requests as $req): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="p-3 sm:p-4 font-medium text-gray-800 text-xs sm:text-sm">
                                            <?= htmlspecialchars($req['halaqoh']) ?>
                                        </td>
                                        <td class="p-3 sm:p-4 text-xs sm:text-sm">
                                            <?= htmlspecialchars($req['peserta_nama']) ?>
                                        </td>
                                        <td class="p-3 sm:p-4 text-xs sm:text-sm text-gray-600 hidden sm:table-cell">
                                            <?= htmlspecialchars($req['peserta_nowa'] ?: '-') ?>
                                        </td>
                                        <td class="p-3 sm:p-4 text-xs sm:text-sm text-gray-600 max-w-[120px] sm:max-w-xs">
                                            <div class="truncate" title="<?= htmlspecialchars($req['pesan_pengajar']) ?>">
                                                <?= nl2br(htmlspecialchars($req['pesan_pengajar'])) ?>
                                            </div>
                                        </td>
                                        <td class="p-3 sm:p-4">
                                            <?php
                                            $status = $req['status'] ?? 'menunggu';
                                            $badgeClass = $status === 'terkirim' ? 'badge-terkirim' : 'badge-menunggu';
                                            $badgeLabel = $status === 'terkirim' ? 'Terkirim' : 'Menunggu';
                                            ?>
                                            <span class="status-badge <?= $badgeClass ?> text-xs"><?= $badgeLabel ?></span>
                                        </td>
                                        <td class="p-3 sm:p-4 whitespace-nowrap">
                                            <div class="flex flex-col sm:flex-row gap-1 sm:gap-2">
                                                <form method="POST" class="w-full sm:w-auto">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <input type="hidden" name="template_id" value="<?= $templates ? $templates[0]['id'] : 1 ?>">
                                                    <button type="submit" name="kirim_reminder" 
                                                            class="btn btn-success btn-sm mobile-w-full" 
                                                            <?= empty($templates) || $req['status'] === 'terkirim' ? 'disabled' : '' ?>>
                                                        <i class="fab fa-whatsapp mr-1"></i> Kirim
                                                    </button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Hapus permintaan ini? Tindakan tidak bisa dibatalkan.');" class="w-full sm:w-auto">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <button type="submit" name="hapus_request" class="btn btn-danger btn-sm mobile-w-full">
                                                        <i class="fas fa-trash mr-1"></i> Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Kelola Template Pesan -->
            <div class="section-card p-4 sm:p-6">
                <h2 class="text-lg sm:text-xl font-bold text-primary mb-4 sm:mb-6 flex items-center mobile-text-center sm:text-left">
                    <i class="fas fa-edit text-lg sm:text-xl mr-2 text-accent"></i> Kelola Template Pesan
                </h2>
                
                <form method="POST" class="space-y-4 bg-white p-4 sm:p-6 rounded-lg border border-card">
                    <input type="hidden" name="template_id" id="template_id">
                    <div>
                        <label for="template_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Nama Template
                        </label>
                        <input type="text" name="template_name" id="template_name" required 
                               class="form-input" placeholder="Contoh: Template Reminder Pembayaran">
                    </div>
                    <div>
                        <label for="template_content" class="block text-sm font-medium text-gray-700 mb-2">
                            Isi Pesan Template
                        </label>
                        <p class="text-xs text-gray-500 mb-2">
                            Gunakan <code class="bg-gray-100 px-1 py-0.5 rounded">{peserta_nama}</code> dan 
                            <code class="bg-gray-100 px-1 py-0.5 rounded">{peserta_nowa}</code> sebagai placeholder yang akan diganti secara otomatis.
                        </p>
                        <textarea name="template_content" id="template_content" rows="6" required 
                                  class="form-input" placeholder="Tulis isi pesan template di sini..."></textarea>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                        <button type="submit" name="submit_template" class="btn btn-primary mobile-w-full sm:w-auto">
                            <i class="fas fa-save mr-2"></i> Simpan Template
                        </button>
                        <button type="button" onclick="clearTemplateForm()" class="btn bg-gray-300 text-gray-700 hover:bg-gray-400 mobile-w-full sm:w-auto">
                            <i class="fas fa-times mr-2"></i> Batal
                        </button>
                    </div>
                </form>
                
                <div class="mt-6 border-t pt-4 sm:pt-6">
                    <h3 class="text-base sm:text-lg font-semibold text-primary mb-3 sm:mb-4 flex items-center mobile-text-center sm:text-left">
                        <i class="fas fa-list-alt text-base sm:text-lg mr-2 text-accent"></i> Daftar Template Tersimpan
                    </h3>
                    <?php if (empty($templates)): ?>
                        <div class="text-center py-6 sm:py-8 text-gray-500 bg-white rounded-lg border border-card">
                            <i class="fas fa-file-alt text-2xl sm:text-3xl mb-2"></i>
                            <p class="text-sm sm:text-base">Belum ada template.</p>
                        </div>
                    <?php else: ?>
                        <div class="template-grid">
                            <?php foreach ($templates as $tpl): ?>
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center p-3 sm:p-4 bg-white rounded-lg border border-card hover:shadow-md transition">
                                    <div class="flex-1 mb-2 sm:mb-0">
                                        <h4 class="font-medium text-gray-800 text-sm sm:text-base"><?= htmlspecialchars($tpl['name']) ?></h4>
                                        <p class="text-xs sm:text-sm text-gray-600 mt-1 line-clamp-2"><?= htmlspecialchars($tpl['content']) ?></p>
                                    </div>
                                    <div class="flex gap-2">
                                        <button 
                                            type="button" 
                                            class="btn bg-blue-100 text-blue-700 hover:bg-blue-200 btn-sm edit-template-btn"
                                            data-id="<?= $tpl['id'] ?>"
                                            data-name="<?= htmlspecialchars($tpl['name'], ENT_QUOTES) ?>"
                                            data-content="<?= htmlspecialchars($tpl['content'], ENT_QUOTES) ?>">
                                            <i class="fas fa-edit mr-1"></i> Edit
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Hapus template ini?');" class="inline">
                                            <input type="hidden" name="template_id" value="<?= $tpl['id'] ?>">
                                            <button type="submit" name="delete_template" class="btn bg-red-100 text-red-700 hover:bg-red-200 btn-sm">
                                                <i class="fas fa-trash mr-1"></i> Hapus
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
  </div>

<script>
    // Menangani klik pada tombol edit menggunakan event listener
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.edit-template-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const content = this.getAttribute('data-content');
                
                document.getElementById('template_id').value = id;
                document.getElementById('template_name').value = name;
                document.getElementById('template_content').value = content;
                
                // Scroll ke form template
                document.getElementById('template_name').focus();
            });
        });
    });

    function clearTemplateForm() {
        document.getElementById('template_id').value = '';
        document.getElementById('template_name').value = '';
        document.getElementById('template_content').value = '';
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