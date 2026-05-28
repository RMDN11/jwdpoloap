<?php
session_start();
require_once 'auth_checkwa.php';
require_once 'config.php';

// Notifikasi
$notification = $_SESSION['notification'] ?? '';
$notificationType = $_SESSION['notificationType'] ?? 'success';
unset($_SESSION['notification'], $_SESSION['notificationType']);

// Fungsi kirim pesan OneSender
function kirimPesanOneSender($nomor, $pesan, $apiUrl, $apiToken) {
    // Format nomor untuk OneSender (62xxx tanpa +)
    $cleanNumber = preg_replace('/\D/', '', $nomor);
    if (strlen($cleanNumber) > 0 && $cleanNumber[0] === '0') {
        $cleanNumber = '62' . substr($cleanNumber, 1);
    }
    
    // Validasi format nomor
    if (!preg_match('/^62\d{9,12}$/', $cleanNumber)) {
        return ['status' => false, 'error' => 'Format nomor tidak valid'];
    }
    
    // Payload sesuai OneSender API
    $data = [
        "recipient_type" => "individual",
        "to" => $cleanNumber,
        "type" => "text",
        "text" => [
            "body" => $pesan
        ]
    ];

    $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['status' => false, 'error' => "Koneksi error: $curlError"];
    }
    
    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        if (isset($responseData['messages'][0]['id'])) {
            return ['status' => true, 'message_id' => $responseData['messages'][0]['id']];
        }
        return ['status' => true];
    } else {
        $errorMsg = "Error $httpCode";
        $responseData = json_decode($response, true);
        if (isset($responseData['error']['message'])) {
            $errorMsg .= " - " . $responseData['error']['message'];
        }
        return ['status' => false, 'error' => $errorMsg];
    }
}

// PROSES FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reset URL - HARUS DIPROSES TERLEBIH DAHULU
    if (isset($_POST['reset_url'])) {
        unset($_SESSION['spreadsheet_data'], $_SESSION['spreadsheet_headers'], $_SESSION['spreadsheet_url']);
        $_SESSION['notification'] = "🔗 URL berhasil direset";
        $_SESSION['notificationType'] = 'success';
        header("Location: ?");
        exit;
    }
    
    // Import spreadsheet - HANYA JIKA BUKAN RESET URL
    if (isset($_POST['spreadsheet_url']) && !isset($_POST['reset_url'])) {
        $url = trim($_POST['spreadsheet_url']);
        
        $patterns = [
            '/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/',
            '/\/d\/([a-zA-Z0-9-_]+)/',
        ];
        
        $spreadsheetId = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                $spreadsheetId = $matches[1];
                break;
            }
        }
        
        if ($spreadsheetId) {
            $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $csvUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            
            $csvData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && !empty($csvData)) {
                $lines = array_map('str_getcsv', explode("\n", $csvData));
                $headers = array_shift($lines);
                $data = [];
                
                foreach ($lines as $line) {
                    if (count($line) === count($headers)) {
                        $data[] = array_combine($headers, $line);
                    }
                }
                
                $data = array_filter($data, function($row) use ($headers) {
                    return !empty(implode('', $row));
                });
                
                $_SESSION['spreadsheet_data'] = $data;
                $_SESSION['spreadsheet_headers'] = $headers;
                $_SESSION['spreadsheet_url'] = $url;
                $_SESSION['notification'] = "✅ Berhasil import " . count($data) . " data dari spreadsheet!";
                $_SESSION['notificationType'] = 'success';
            } else {
                $_SESSION['notification'] = "❌ Gagal mengakses spreadsheet. Pastikan sudah di-share dengan akses 'Anyone with the link can view'";
                $_SESSION['notificationType'] = 'error';
            }
        } else {
            $_SESSION['notification'] = "❌ URL spreadsheet tidak valid";
            $_SESSION['notificationType'] = 'error';
        }
        
        header("Location: ?");
        exit;
    }
    
    if (isset($_POST['send_bulk'])) {
        $message = trim($_POST['message']);
        $namaColumn = $_POST['nama_column'];
        $nomorColumn = $_POST['nomor_column'];
        $data = $_SESSION['spreadsheet_data'] ?? [];
        
        if (empty($message)) {
            $_SESSION['notification'] = "❌ Pesan tidak boleh kosong!";
            $_SESSION['notificationType'] = 'error';
            header("Location: ?");
            exit;
        }
        
        if (empty($data)) {
            $_SESSION['notification'] = "❌ Tidak ada data untuk dikirim!";
            $_SESSION['notificationType'] = 'error';
            header("Location: ?");
            exit;
        }
        
        $success = 0;
        $failed = 0;
        $results = [];
        
        foreach ($data as $index => $row) {
            if (!empty($row[$nomorColumn])) {
                $nama = $row[$namaColumn] ?? '';
                $nomor = trim($row[$nomorColumn]);
                $pesanPersonal = str_replace('{nama}', $nama, $message);
                
                $result = kirimPesanOneSender($nomor, $pesanPersonal, $apiUrl, $apiToken);
                
                if ($result['status']) {
                    $success++;
                    $results[] = [
                        'status' => 'success',
                        'nomor' => $nomor,
                        'nama' => $nama,
                        'message' => 'Berhasil dikirim' . (isset($result['message_id']) ? " (ID: {$result['message_id']})" : "")
                    ];
                } else {
                    $failed++;
                    $results[] = [
                        'status' => 'error',
                        'nomor' => $nomor,
                        'nama' => $nama,
                        'message' => $result['error'] ?? 'Unknown error'
                    ];
                }
                
                usleep(500000);
            } else {
                $failed++;
                $results[] = [
                    'status' => 'error',
                    'nomor' => '',
                    'nama' => '',
                    'message' => 'Nomor kosong'
                ];
            }
        }
        
        $_SESSION['notification'] = "📊 Pengiriman selesai: $success berhasil, $failed gagal";
        $_SESSION['notificationType'] = $failed > 0 ? 'warning' : 'success';
        $_SESSION['send_results'] = $results;
        header("Location: ?");
        exit;
    }
    
    if (isset($_POST['clear_data'])) {
        unset($_SESSION['spreadsheet_data'], $_SESSION['spreadsheet_headers'], $_SESSION['spreadsheet_url']);
        $_SESSION['notification'] = "🗑️ Data berhasil dihapus";
        $_SESSION['notificationType'] = 'success';
        header("Location: ?");
        exit;
    }
    
    if (isset($_POST['reset_data'])) {
        unset($_SESSION['spreadsheet_data'], $_SESSION['spreadsheet_headers'], $_SESSION['spreadsheet_url'], $_SESSION['send_results']);
        $_SESSION['notification'] = "🔄 Semua data berhasil direset";
        $_SESSION['notificationType'] = 'success';
        header("Location: ?");
        exit;
    }
}

$data = $_SESSION['spreadsheet_data'] ?? [];
$headers = $_SESSION['spreadsheet_headers'] ?? [];
$spreadsheetUrl = $_SESSION['spreadsheet_url'] ?? '';
$results = $_SESSION['send_results'] ?? [];
unset($_SESSION['send_results']);

$cache_buster = time();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kirim Pesan Bulk - Tahfidz Private</title>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        :root {
            --primary: #374151;
            --secondary: #4b5563;
            --accent: #9ca3af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 2rem;
        }
        
        .title-section {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .logo-container {
            width: 80px;
            height: 80px;
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .logo-container i {
            font-size: 2rem;
            color: white;
        }
        
        .title-content h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .title-content p {
            color: #d1d5db;
            font-size: 1.1rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
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
        
        .card-hover:hover {
            transform: translateY(-2px);
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }
        
        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: all 0.2s;
        }
        
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-primary {
            background-color: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2563eb;
        }
        
        .btn-success {
            background-color: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #059669;
        }
        
        .btn-warning {
            background-color: #f59e0b;
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #d97706;
        }
        
        .result-item {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .result-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .result-error {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .info-box {
            background-color: #dbeafe;
            border: 1px solid #93c5fd;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .compact-table td {
            padding: 0.75rem 1rem;
        }
        
        .table-container {
            max-height: 50vh;
            overflow-y: auto;
        }
        
        .scrollable-content {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .preview-box {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-top: 1rem;
            white-space: pre-wrap;
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
        }
        
        .template-item {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .template-item:hover {
            background-color: #f3f4f6;
            border-color: #3b82f6;
        }
        
        .template-name {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .template-content {
            color: #6b7280;
            font-size: 0.875rem;
            line-height: 1.4;
            max-height: 60px;
            overflow: hidden;
        }
        
        .template-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .template-btn {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
        }
        
        .template-btn.use {
            background-color: #3b82f6;
            color: white;
        }
        
        .template-btn.delete {
            background-color: #ef4444;
            color: white;
        }
        
        .url-history-item {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.875rem;
        }
        
        .url-history-item:hover {
            background-color: #f3f4f6;
            border-color: #3b82f6;
        }
        
        .url-history-name {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.25rem;
        }
        
        .url-history-url {
            color: #6b7280;
            font-size: 0.75rem;
            word-break: break-all;
        }
        
        .url-history-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .url-history-btn {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
        }
        
        .url-history-btn.use {
            background-color: #3b82f6;
            color: white;
        }
        
        .url-history-btn.delete {
            background-color: #ef4444;
            color: white;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="title-section">
                    <div class="logo-container">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div class="title-content">
                        <h1>Kirim Pesan Bulk</h1>
                        <p>Import dari Google Spreadsheet & Kirim WhatsApp Massal</p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <a href="grafik-chat.php" class="action-btn">
                        <i class="fas fa-chart-bar"></i>
                        Grafik Chat
                    </a>
                    <a href="analitikjwd.php" class="action-btn">
                        <i class="fas fa-users"></i>
                        Analitik Chat
                    </a>
                    <a href="manage_templates.php" class="action-btn">
                        <i class="fas fa-edit"></i>
                        Kelola Template
                    </a>
                    <a href="logoutwa.php" class="action-btn btn-logout">
                        <i class="fas fa-sign-out-alt"></i>
                        Keluar
                    </a>
                </div>
            </div>
        </header>

        <!-- Notifikasi -->
        <?php if (!empty($notification)): ?>
        <div class="mb-6 p-4 rounded-lg <?= $notificationType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : ($notificationType === 'warning' ? 'bg-yellow-50 border border-yellow-200 text-yellow-700' : 'bg-red-50 border border-red-200 text-red-700') ?>">
            <div class="flex items-center gap-3">
                <i class="fas <?= $notificationType === 'success' ? 'fa-check-circle' : ($notificationType === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle') ?>"></i>
                <span class="font-medium"><?= htmlspecialchars($notification) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- Form Input -->
            <div class="xl:col-span-2 space-y-6">
                <!-- Import Section -->
                <div class="bg-white rounded-xl p-6 shadow border border-gray-200 card-hover">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-upload text-blue-500"></i>
                        Import Data
                    </h2>
                    
                    <form method="POST" id="importForm" class="mb-4">
                        <div class="form-group">
                            <label class="form-label" for="spreadsheet_url">
                                <i class="fas fa-link text-blue-500"></i>
                                URL Google Spreadsheet
                            </label>
                            <div class="flex gap-2 mb-2">
                                <input 
                                    type="url" 
                                    id="spreadsheet_url" 
                                    name="spreadsheet_url" 
                                    value="<?= htmlspecialchars($spreadsheetUrl) ?>"
                                    class="form-input flex-1" 
                                    placeholder="https://docs.google.com/spreadsheets/d/..."
                                >
                                <button type="submit" name="reset_url" class="btn bg-gray-500 hover:bg-gray-600 text-white whitespace-nowrap">
                                    <i class="fas fa-times"></i>
                                    Reset URL
                                </button>
                            </div>
                            <p class="text-sm text-gray-500">
                                Pastikan spreadsheet sudah di-set <strong>"Anyone with the link can view"</strong>
                            </p>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="submit" name="import_data" class="btn btn-primary flex-1">
                                <i class="fas fa-download"></i>
                                Import Data
                            </button>
                            
                            <?php if (!empty($data)): ?>
                            <button type="submit" name="clear_data" class="btn bg-gray-500 hover:bg-gray-600 text-white">
                                <i class="fas fa-trash"></i>
                                Hapus Data
                            </button>
                            <button type="submit" name="reset_data" class="btn bg-red-500 hover:bg-red-600 text-white">
                                <i class="fas fa-refresh"></i>
                                Reset All
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <?php if (!empty($data)): ?>
                <!-- Kirim Section -->
                <div class="bg-white rounded-xl p-6 shadow border border-gray-200 card-hover">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-paper-plane text-green-500"></i>
                        Kirim Pesan
                    </h2>

                    <form method="POST" id="sendForm">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="form-label">
                                    <i class="fas fa-user text-blue-500"></i>
                                    Kolom Nama
                                </label>
                                <select name="nama_column" class="form-select" id="namaColumn">
                                    <?php foreach($headers as $header): ?>
                                    <option value="<?= htmlspecialchars($header) ?>"><?= htmlspecialchars($header) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">
                                    <i class="fas fa-phone text-blue-500"></i>
                                    Kolom Nomor WhatsApp
                                </label>
                                <select name="nomor_column" class="form-select" id="nomorColumn">
                                    <?php foreach($headers as $header): ?>
                                    <option value="<?= htmlspecialchars($header) ?>"><?= htmlspecialchars($header) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="message">
                                <i class="fas fa-envelope text-blue-500"></i>
                                Template Pesan
                            </label>
                            
                            <textarea 
                                id="message" 
                                name="message" 
                                class="form-textarea" 
                                placeholder="Halo {nama}, ..."
                                oninput="updatePreview()"
                                required
                            ></textarea>
                            <p class="text-sm text-gray-500 mt-1">
                                Gunakan <code class="bg-gray-100 px-1 py-0.5 rounded">{nama}</code> untuk placeholder nama kontak
                            </p>
                        </div>

                        <!-- Preview Pesan -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-eye text-purple-500"></i>
                                Preview Pesan
                            </label>
                            <div id="previewBox" class="preview-box hidden">
                                <div id="previewContent"></div>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <button type="submit" name="send_bulk" 
                                    class="btn btn-success flex-1"
                                    onclick="return confirm('Kirim pesan ke <?= count($data) ?> kontak?')">
                                <i class="fas fa-paper-plane"></i>
                                KIRIM KE <?= count($data) ?> KONTAK
                            </button>
                            
                            <button type="button" onclick="showSaveTemplateModal()" class="btn bg-purple-500 hover:bg-purple-600 text-white">
                                <i class="fas fa-save"></i>
                                Simpan Template
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="xl:col-span-1 space-y-6">
                <!-- Info Box -->
                <div class="bg-white rounded-xl p-6 shadow border border-gray-200 card-hover">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-info-circle text-blue-500"></i>
                        Informasi
                    </h2>
                    
                    <div class="space-y-3">
                        <div class="flex items-center gap-3 p-3 bg-blue-50 rounded-lg">
                            <i class="fas fa-table text-blue-500"></i>
                            <div>
                                <div class="font-semibold">Data Tersedia</div>
                                <div class="text-2xl font-bold text-blue-600"><?= count($data) ?></div>
                                <div class="text-sm text-gray-500">records</div>
                            </div>
                        </div>
                        
                        <div class="space-y-2">
                            <h3 class="font-semibold text-gray-700">Petunjuk:</h3>
                            <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                                <li>Spreadsheet harus public (Anyone can view)</li>
                                <li>Gunakan <code>{nama}</code> dalam pesan</li>
                                <li>Nomor otomatis diformat ke 62xxx</li>
                                <li>Delay otomatis antar pengiriman</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Riwayat URL -->
                <div class="bg-white rounded-xl p-6 shadow border border-gray-200 card-hover">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-history text-green-500"></i>
                        Riwayat URL
                    </h2>
                    
                    <div class="table-container">
                        <div id="urlHistoryList" class="space-y-2">
                            <!-- URL History will be loaded here by JavaScript -->
                        </div>
                        <div id="noUrlHistory" class="text-center py-4 text-gray-500">
                            <i class="fas fa-inbox text-2xl mb-2 opacity-50"></i>
                            <p>Belum ada URL tersimpan</p>
                        </div>
                    </div>
                </div>

                <!-- Riwayat Template -->
                <div class="bg-white rounded-xl p-6 shadow border border-gray-200 card-hover">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-history text-orange-500"></i>
                        Riwayat Template
                    </h2>
                    
                    <div class="table-container">
                        <div id="templatesList" class="space-y-2">
                            <!-- Templates will be loaded here by JavaScript -->
                        </div>
                        <div id="noTemplates" class="text-center py-4 text-gray-500">
                            <i class="fas fa-inbox text-2xl mb-2 opacity-50"></i>
                            <p>Belum ada template tersimpan</p>
                        </div>
                    </div>
                </div>

                <?php if (!empty($data)): ?>
                <!-- Preview Data -->
                <div class="bg-white rounded-xl p-6 shadow border border-gray-200 card-hover">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-eye text-purple-500"></i>
                        Preview Data
                    </h2>
                    
                    <div class="table-container">
                        <table class="w-full compact-table">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <?php foreach($headers as $header): ?>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase"><?= htmlspecialchars($header) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach($data as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <?php foreach($headers as $header): ?>
                                    <td class="px-3 py-2 text-sm text-gray-900 truncate max-w-[120px]"><?= htmlspecialchars($row[$header] ?? '') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($results)): ?>
                <!-- Hasil Pengiriman -->
                <div class="bg-white rounded-xl p-6 shadow border border-gray-200 card-hover">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-list-alt text-green-500"></i>
                        Hasil Pengiriman
                    </h2>
                    
                    <div class="table-container">
                        <div class="space-y-2">
                            <?php 
                            $successCount = count(array_filter($results, function($r) { return $r['status'] === 'success'; }));
                            $errorCount = count(array_filter($results, function($r) { return $r['status'] === 'error'; }));
                            ?>
                            <div class="grid grid-cols-2 gap-2 mb-3">
                                <div class="bg-green-100 p-2 rounded text-center">
                                    <div class="text-lg font-bold text-green-700"><?= $successCount ?></div>
                                    <div class="text-xs text-green-600">Berhasil</div>
                                </div>
                                <div class="bg-red-100 p-2 rounded text-center">
                                    <div class="text-lg font-bold text-red-700"><?= $errorCount ?></div>
                                    <div class="text-xs text-red-600">Gagal</div>
                                </div>
                            </div>
                            
                            <?php foreach($results as $result): ?>
                            <div class="result-item <?= $result['status'] === 'success' ? 'result-success' : 'result-error' ?>">
                                <i class="fas <?= $result['status'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium truncate">
                                        <?= !empty($result['nomor']) ? htmlspecialchars($result['nomor']) : 'N/A' ?>
                                    </div>
                                    <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($result['message']) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Save Template -->
    <div id="saveTemplateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Simpan Template</h3>
                <form id="saveTemplateForm">
                    <div class="form-group">
                        <label class="form-label">Nama Template</label>
                        <input type="text" id="templateName" class="form-input" placeholder="Masukkan nama template" required>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button type="button" onclick="saveTemplate()" class="btn btn-success flex-1">
                            <i class="fas fa-save"></i>
                            Simpan
                        </button>
                        <button type="button" onclick="hideSaveTemplateModal()" class="btn bg-gray-500 hover:bg-gray-600 text-white">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Storage Keys
        const TEMPLATES_KEY = 'bulk_message_templates';
        const URL_HISTORY_KEY = 'bulk_message_url_history';

        // Load templates from localStorage
        function loadTemplates() {
            const templates = JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]');
            const templatesList = document.getElementById('templatesList');
            const noTemplates = document.getElementById('noTemplates');
            
            if (templates.length === 0) {
                templatesList.innerHTML = '';
                noTemplates.classList.remove('hidden');
                return;
            }
            
            noTemplates.classList.add('hidden');
            templatesList.innerHTML = templates.map((template, index) => `
                <div class="template-item">
                    <div class="template-name">${template.name}</div>
                    <div class="template-content">${template.content.substring(0, 100)}${template.content.length > 100 ? '...' : ''}</div>
                    <div class="template-actions">
                        <button type="button" onclick="useTemplate(${index})" class="template-btn use">
                            <i class="fas fa-play mr-1"></i>Pakai
                        </button>
                        <button type="button" onclick="deleteTemplate(${index})" class="template-btn delete">
                            <i class="fas fa-trash mr-1"></i>Hapus
                        </button>
                    </div>
                </div>
            `).join('');
        }

        // Load URL history from localStorage
        function loadUrlHistory() {
            const urlHistory = JSON.parse(localStorage.getItem(URL_HISTORY_KEY) || '[]');
            const urlHistoryList = document.getElementById('urlHistoryList');
            const noUrlHistory = document.getElementById('noUrlHistory');
            
            if (urlHistory.length === 0) {
                urlHistoryList.innerHTML = '';
                noUrlHistory.classList.remove('hidden');
                return;
            }
            
            noUrlHistory.classList.add('hidden');
            urlHistoryList.innerHTML = urlHistory.map((urlItem, index) => `
                <div class="url-history-item">
                    <div class="url-history-name">${urlItem.name}</div>
                    <div class="url-history-url">${urlItem.url}</div>
                    <div class="url-history-actions">
                        <button type="button" onclick="useUrlHistory(${index})" class="url-history-btn use">
                            <i class="fas fa-play mr-1"></i>Pakai
                        </button>
                        <button type="button" onclick="deleteUrlHistory(${index})" class="url-history-btn delete">
                            <i class="fas fa-trash mr-1"></i>Hapus
                        </button>
                    </div>
                </div>
            `).join('');
        }

        // Save URL to history
        function saveUrlToHistory(url) {
            if (!url.trim()) return;
            
            const urlHistory = JSON.parse(localStorage.getItem(URL_HISTORY_KEY) || '[]');
            const urlName = `Spreadsheet ${new Date().toLocaleDateString('id-ID')} ${new Date().toLocaleTimeString('id-ID')}`;
            
            // Remove if already exists
            const filteredHistory = urlHistory.filter(item => item.url !== url);
            
            // Add to beginning
            filteredHistory.unshift({
                name: urlName,
                url: url,
                createdAt: new Date().toISOString()
            });
            
            // Keep only last 10 URLs
            const limitedHistory = filteredHistory.slice(0, 10);
            
            localStorage.setItem(URL_HISTORY_KEY, JSON.stringify(limitedHistory));
            loadUrlHistory();
        }

        // Use URL from history
        function useUrlHistory(index) {
            const urlHistory = JSON.parse(localStorage.getItem(URL_HISTORY_KEY) || '[]');
            if (urlHistory[index]) {
                document.getElementById('spreadsheet_url').value = urlHistory[index].url;
                
                // Scroll to URL input
                document.getElementById('spreadsheet_url').scrollIntoView({ behavior: 'smooth' });
                document.getElementById('spreadsheet_url').focus();
            }
        }

        // Delete URL from history
        function deleteUrlHistory(index) {
            if (confirm('Hapus URL ini dari riwayat?')) {
                const urlHistory = JSON.parse(localStorage.getItem(URL_HISTORY_KEY) || '[]');
                urlHistory.splice(index, 1);
                localStorage.setItem(URL_HISTORY_KEY, JSON.stringify(urlHistory));
                loadUrlHistory();
            }
        }

        // Save template to localStorage
        function saveTemplate() {
            const name = document.getElementById('templateName').value.trim();
            const content = document.getElementById('message').value.trim();
            
            if (!name || !content) {
                alert('Nama template dan konten tidak boleh kosong!');
                return;
            }
            
            const templates = JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]');
            
            // Check if template name already exists
            if (templates.some(t => t.name === name)) {
                alert('Template dengan nama tersebut sudah ada!');
                return;
            }
            
            templates.unshift({
                name: name,
                content: content,
                createdAt: new Date().toISOString()
            });
            
            // Keep only last 10 templates
            const limitedTemplates = templates.slice(0, 10);
            
            localStorage.setItem(TEMPLATES_KEY, JSON.stringify(limitedTemplates));
            hideSaveTemplateModal();
            loadTemplates();
            
            // Show success message
            alert('Template berhasil disimpan!');
        }

        // Use template
        function useTemplate(index) {
            const templates = JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]');
            if (templates[index]) {
                document.getElementById('message').value = templates[index].content;
                updatePreview();
                
                // Scroll to message textarea
                document.getElementById('message').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Delete template
        function deleteTemplate(index) {
            if (confirm('Hapus template ini?')) {
                const templates = JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]');
                templates.splice(index, 1);
                localStorage.setItem(TEMPLATES_KEY, JSON.stringify(templates));
                loadTemplates();
            }
        }

        // Modal functions
        function showSaveTemplateModal() {
            const message = document.getElementById('message').value;
            if (!message.trim()) {
                alert('Pesan tidak boleh kosong!');
                return;
            }
            document.getElementById('templateName').value = '';
            document.getElementById('saveTemplateModal').classList.remove('hidden');
        }

        function hideSaveTemplateModal() {
            document.getElementById('saveTemplateModal').classList.add('hidden');
        }

        // Auto select column names
        document.addEventListener('DOMContentLoaded', function() {
            const namaSelect = document.getElementById('namaColumn');
            const nomorSelect = document.getElementById('nomorColumn');
            
            if (namaSelect && nomorSelect) {
                const options = Array.from(namaSelect.options);
                
                // Auto detect nama column
                const namaKeywords = ['nama', 'name', 'contact', 'peserta', 'student'];
                for (let keyword of namaKeywords) {
                    const found = options.find(opt => 
                        opt.text.toLowerCase().includes(keyword.toLowerCase())
                    );
                    if (found) {
                        namaSelect.value = found.value;
                        break;
                    }
                }
                
                // Auto detect nomor column
                const nomorKeywords = ['nomor', 'number', 'phone', 'wa', 'whatsapp', 'hp', 'nohp', 'telepon'];
                for (let keyword of nomorKeywords) {
                    const found = options.find(opt => 
                        opt.text.toLowerCase().includes(keyword.toLowerCase())
                    );
                    if (found) {
                        nomorSelect.value = found.value;
                        break;
                    }
                }
            }
            
            // Load templates and URL history on page load
            loadTemplates();
            loadUrlHistory();
            updatePreview();
            
            // Save current URL to history if exists
            const currentUrl = document.getElementById('spreadsheet_url').value;
            if (currentUrl.trim()) {
                saveUrlToHistory(currentUrl);
            }
        });

        // Save URL when import form is submitted (only for import, not reset)
        document.getElementById('importForm').addEventListener('submit', function(e) {
            // Only save URL if it's not a reset action
            if (!e.submitter || e.submitter.name !== 'reset_url') {
                const url = document.getElementById('spreadsheet_url').value.trim();
                if (url) {
                    saveUrlToHistory(url);
                }
            }
        });

        // Preview functions
        function updatePreview() {
            const message = document.getElementById('message').value;
            const previewBox = document.getElementById('previewBox');
            const previewContent = document.getElementById('previewContent');
            
            if (message.trim()) {
                // Replace {nama} with sample name
                const sampleMessage = message.replace(/{nama}/g, 'Ahmad');
                previewContent.innerHTML = sampleMessage.replace(/\n/g, '<br>');
                previewBox.classList.remove('hidden');
            } else {
                previewBox.classList.add('hidden');
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('saveTemplateModal');
            if (event.target === modal) {
                hideSaveTemplateModal();
            }
        }

        // Handle Enter key in template name input
        document.getElementById('templateName')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveTemplate();
            }
        });
    </script>
</body>
</html>