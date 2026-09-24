<?php
session_start();
require_once 'auth_checkwa.php';
require_once 'config.php'; // Pastikan file config.php menyediakan koneksi $conn
// FIX: Set karakter set koneksi ke utf8mb4
if ($conn) {
    mysqli_set_charset($conn, "utf8mb4");
}

$basePath = __DIR__;
$logFile = "$basePath/webhook_log.txt";

// ========================================
// 🧠 FUNGSI UTILITAS (Sama seperti analitikjwd.php)
// ========================================
function extractMessageFromLog($line) {
    if (preg_match('/"message_text"\s*:\s*"([^"]*)"/', $line, $m)) return $m[1];
    if (preg_match('/"body"\s*:\s*"([^"]*)"/', $line, $m)) return $m[1];
    if (preg_match("/message:\s*'([^']*)'/", $line, $m)) return $m[1];
    return '';
}

function classifyMessage($message) {
    $messageLower = strtolower($message);
    $patterns = [
        'Kak, mau ikut mode Intensif' => '/kak,\s*mau\s*ikut\s*mode\s*intensif/i',
        'Kak, mau ikut mode Normal' => '/kak,\s*mau\s*ikut\s*mode\s*normal/i',
        'Kak, mau ikut mode Tahfidz Cilik' => '/kak,\s*mau\s*ikut\s*mode\s*tahfidz\s*cilik/i',
        'Kak, saya ingin bertanya program Tahfidz Private' => '/kak,\s*saya\s*ingin\s*bertanya\s*program\s*tahfidz\s*private/i',
        'Kak, mau ikut Ziyadah Pemula' => '/kak,\s*mau\s*ikut\s*ziyadah\s*pemula/i',
        'Kak, mau ikut Ziyadah Lanjutan' => '/kak,\s*mau\s*ikut\s*ziyadah\s*lanjutan/i',
        'Kak, mau ikut Muroja\'ah' => '/kak,\s*mau\s*ikut\s*muroja\'ah/i',
    ];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $messageLower)) {
            return $label;
        }
    }
    return 'Pesan Lainnya';
}

function parseProspectsFromLog($logFile, $fromDate = null, $toDate = null) {
    $prospek = [];
    $lastTimestamp = null;
    if (!file_exists($logFile)) return $prospek;
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $timestampStr = null;
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
            $timestampStr = $m[1];
            $lastTimestamp = $timestampStr;
        } elseif ($lastTimestamp) {
            $timestampStr = $lastTimestamp;
        }
        $include = true;
        if ($timestampStr) {
            $ts = strtotime($timestampStr);
            if ($fromDate && $ts < strtotime("$fromDate 00:00:00")) $include = false;
            if ($toDate && $ts > strtotime("$toDate 23:59:59")) $include = false;
        }
        if (!$include) continue;
        $msg = extractMessageFromLog($line);
        if (!$msg) continue;
        if (stripos($msg, 'kak, mau') !== 0) continue;
        $contactId = null;
        if (preg_match('/(\d+@c\.us)/', $line, $matches)) {
            $contactId = str_replace('@c.us', '', $matches[1]);
        } elseif (preg_match('/\b(\d{10,15})\b/', $line, $matches)) {
            $contactId = $matches[1];
        }
        if ($contactId) {
            if (!isset($prospek[$contactId])) {
                $prospek[$contactId] = [
                    'contact_id' => $contactId,
                    'status' => 'belum_daftar',
                    'last_message_time' => null,
                    'last_message' => '',
                    'pesan_klasifikasi' => 'Pesan Lainnya'
                ];
            }
            $prospek[$contactId]['last_message_time'] = $timestampStr;
            $prospek[$contactId]['last_message'] = $msg;
            $prospek[$contactId]['pesan_klasifikasi'] = classifyMessage($msg);
        }
    }
    return $prospek;
}

function getRegisteredContacts($conn) {
    $registered = [];
    if (!$conn) return $registered;
    try {
        $stmt = $conn->prepare("SELECT DISTINCT nowa FROM calon_peserta");
        if (!$stmt) return $registered;
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dbPhone = $row['nowa'];
            $normalizedPhone = $dbPhone;
            if (substr($dbPhone, 0, 1) === '0') {
                $normalizedPhone = '62' . substr($dbPhone, 1);
            } elseif (substr($dbPhone, 0, 1) === '+') {
                $normalizedPhone = substr($dbPhone, 1);
            }
            $normalizedPhone = preg_replace('/\D/', '', $normalizedPhone);
            $registered[$normalizedPhone] = true;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching calon_peserta: " . $e->getMessage());
    }
    return $registered;
}

function getUnregisteredProspects($conn, $logFile, $fromDate = null, $toDate = null) {
    if (!file_exists($logFile)) return 0;
    $registeredContacts = $conn ? getRegisteredContacts($conn) : [];
    $prospek = parseProspectsFromLog($logFile, $fromDate, $toDate);
    $filteredProspects = array_filter($prospek, function($data) use ($registeredContacts) {
        $contactId = preg_replace('/\D/', '', $data['contact_id']);
        if (substr($contactId, 0, 1) === '0') $contactId = '62' . substr($contactId, 1);
        return !isset($registeredContacts[$contactId]);
    });
    return count($filteredProspects);
}

function getChatClassificationCountsForUnregistered($conn, $logFile, $fromDate = null, $toDate = null) {
    $counts = [];
    if (!file_exists($logFile)) return $counts;
    $registeredContacts = $conn ? getRegisteredContacts($conn) : [];
    $prospek = parseProspectsFromLog($logFile, $fromDate, $toDate);
    $filteredProspects = array_filter($prospek, function($data) use ($registeredContacts) {
        $contactId = preg_replace('/\D/', '', $data['contact_id']);
        if (substr($contactId, 0, 1) === '0') $contactId = '62' . substr($contactId, 1);
        return !isset($registeredContacts[$contactId]);
    });
    foreach ($filteredProspects as $data) {
        $label = $data['pesan_klasifikasi'];
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
}

$fromDate = $_GET['from'] ?? null;
$toDate = $_GET['to'] ?? null;

$totalProspects = count(parseProspectsFromLog($logFile, $fromDate, $toDate));
$unregisteredProspects = getUnregisteredProspects($conn, $logFile, $fromDate, $toDate);
$classificationCounts = getChatClassificationCountsForUnregistered($conn, $logFile, $fromDate, $toDate);
$chartLabels = array_keys($classificationCounts);
$chartCounts = array_values($classificationCounts);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Grafik Chat Tahfidz Private</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
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
        
        /* Header Styles - Sama seperti analitik chat */
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
        
        /* Filter Panel */
        .filter-panel {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            margin-bottom: 2rem;
        }
        
        .filter-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-title i {
            color: var(--accent);
        }
        
        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 200px;
        }
        
        .form-label {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #374151;
        }
        
        .form-input {
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: linear-gradient(to right, #6b7280, #4b5563);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(to right, #4b5563, #374151);
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            border-color: #9ca3af;
        }
        
        /* OKR Cards */
        .okr-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .okr-card {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .okr-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .okr-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .okr-title i {
            color: var(--accent);
        }
        
        .key-result {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .key-result:last-child {
            border-bottom: none;
        }
        
        .key-result span:first-child {
            flex: 1;
            color: #374151;
        }
        
        .key-result span:last-child {
            font-weight: 600;
            color: #111827;
        }
        
        .key-result .highlight {
            color: #3b82f6;
        }
        
        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            margin-bottom: 2rem;
        }
        
        .chart-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chart-title i {
            color: var(--accent);
        }
        
        .chart-wrapper {
            height: 400px;
            position: relative;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn-success {
            background: linear-gradient(to right, #10b981, #059669);
            color: white;
        }
        
        .btn-success:hover {
            background: linear-gradient(to right, #059669, #047857);
        }
        
        .btn-gray {
            background: linear-gradient(to right, #6b7280, #4b5563);
            color: white;
        }
        
        .btn-gray:hover {
            background: linear-gradient(to right, #4b5563, #374151);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .title-section {
                flex-direction: column;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .okr-cards {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                flex-direction: column;
            }
            
            .form-group {
                min-width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header - Sama seperti analitik chat -->
        <header class="header">
            <div class="header-content">
                <div class="title-section">
                    <div class="logo-container">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="title-content">
                        <h1>Grafik Chat Tahfidz Private</h1>
                        <p>Dashboard analisis dan visualisasi data percakapan</p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <a href="analitikjwd.php" class="action-btn">
                        <i class="fas fa-chart-line"></i>
                        Analitik Chat
                    </a>
                    <a href="manage_templates.php" class="action-btn">
                        <i class="fas fa-edit"></i>
                        Kelola Template
                    </a>
                    <a href="reminder.php" class="action-btn">
                        <i class="fas fa-bell"></i>
                        Reminder Pembayaran
                    </a>
                    <a href="logoutwa.php" class="action-btn btn-logout">
                        <i class="fas fa-sign-out-alt"></i>
                        Keluar
                    </a>
                </div>
            </div>
        </header>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i>
                Filter Periode Data
            </h3>
            <form method="get" class="filter-form">
                <div class="form-group">
                    <label class="form-label">Tanggal Awal</label>
                    <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Akhir</label>
                    <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>" class="form-input">
                </div>
                <div class="form-group" style="min-width: auto;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Terapkan Filter
                    </button>
                </div>
                <div class="form-group" style="min-width: auto;">
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-refresh"></i>
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- OKR Cards -->
        <div class="okr-cards">
            <div class="okr-card">
                <h3 class="okr-title">
                    <i class="fas fa-bullseye"></i>
                    Objective: Meningkatkan Konversi Pendaftaran
                </h3>
                <div class="key-result">
                    <span>Total Prospek Tertangkap (Semua)</span>
                    <span class="font-bold"><?= $totalProspects ?></span>
                </div>
                <div class="key-result">
                    <span>Prospek Belum Terdaftar (Fokus Utama)</span>
                    <span class="font-bold highlight"><?= $unregisteredProspects ?></span>
                </div>
                <div class="key-result">
                    <span>Target Konversi</span>
                    <span class="font-bold">TBD</span>
                </div>
            </div>

            <div class="okr-card">
                <h3 class="okr-title">
                    <i class="fas fa-chart-pie"></i>
                    Key Result: Analisis Klasifikasi Pesan
                </h3>
                <div class="space-y-2">
                    <?php if (empty($classificationCounts)): ?>
                        <div class="text-gray-500 text-sm py-2">Tidak ada data untuk periode ini.</div>
                    <?php else: foreach ($classificationCounts as $label => $count): ?>
                        <div class="key-result">
                            <span class="text-sm"><?= $label ?></span>
                            <span class="font-bold"><?= $count ?></span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Chart Container -->
        <div class="chart-container">
            <h3 class="chart-title">
                <i class="fas fa-chart-bar"></i>
                Grafik Distribusi Pesan Berdasarkan Klasifikasi
                <?php if ($fromDate || $toDate): ?>
                    <span style="font-size: 1rem; color: #6b7280; margin-left: auto;">
                        (<?= $fromDate ?: 'Awal' ?> - <?= $toDate ?: 'Sekarang' ?>)
                    </span>
                <?php endif; ?>
            </h3>
            <div class="chart-wrapper">
                <canvas id="pesanChart"></canvas>
            </div>
        </div>

       
    <!-- Modern Chart -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const ctx = document.getElementById('pesanChart');
        const chartLabels = <?php echo json_encode($chartLabels); ?>;
        const chartCounts = <?php echo json_encode($chartCounts); ?>;

        if (!ctx || chartLabels.length === 0) {
            document.querySelector('.chart-wrapper').innerHTML = '<div class="text-center text-gray-500 py-8"><i class="fas fa-inbox text-4xl mb-2 opacity-50"></i><p>Tidak ada data grafik untuk ditampilkan</p></div>';
            return;
        }

        const gradientColors = [
            ['#6366f1', '#a5b4fc'],
            ['#3b82f6', '#93c5fd'],
            ['#10b981', '#6ee7b7'],
            ['#eab308', '#fde047'],
            ['#f59e0b', '#fcd34d'],
            ['#ef4444', '#fca5a5'],
            ['#8b5cf6', '#c4b5fd']
        ];

        const barColors = chartLabels.map((_, i) => {
            const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
            const pair = gradientColors[i % gradientColors.length];
            gradient.addColorStop(0, pair[0]);
            gradient.addColorStop(1, pair[1]);
            return gradient;
        });

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Jumlah Pesan',
                    data: chartCounts,
                    backgroundColor: barColors,
                    borderRadius: 8,
                    borderSkipped: false,
                    barPercentage: 0.7,
                    categoryPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        display: false 
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17,24,39,0.9)',
                        padding: 12,
                        displayColors: false,
                        titleFont: { size: 13 },
                        bodyFont: { size: 13 },
                        callbacks: { 
                            label: function(context) {
                                return `Jumlah: ${context.parsed.y} pesan`;
                            }
                        }
                    },
                    datalabels: {
                        anchor: 'end',
                        align: 'top',
                        color: '#374151',
                        font: { weight: 'bold', size: 11 },
                        formatter: (value) => value
                    }
                },
                scales: {
                    x: { 
                        grid: { 
                            display: false 
                        },
                        ticks: { 
                            color: '#4b5563',
                            font: { size: 11 },
                            maxRotation: 45
                        } 
                    },
                    y: { 
                        beginAtZero: true, 
                        grid: { 
                            color: '#f3f4f6' 
                        }, 
                        ticks: { 
                            color: '#6b7280', 
                            stepSize: 1,
                            font: { size: 11 }
                        } 
                    }
                },
                animation: {
                    duration: 1200,
                    easing: 'easeOutQuart'
                }
            },
            plugins: [ChartDataLabels]
        });
    });
    </script>
</body>
</html>
