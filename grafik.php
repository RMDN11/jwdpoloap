<?php
session_start();
require_once 'auth_checkwa.php';

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once 'config.php';
} catch (Exception $e) {
    die("Configuration error");
}

if (isset($conn) && $conn) {
    mysqli_set_charset($conn, "utf8mb4");
}

// ==================================================================
// FUNGSI KLASIFIKASI & FILTER (KONSISTEN)
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

// ==================================================================
// PARAMETER FILTER WAKTU
// ==================================================================
$f_start = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$f_end = $_GET['to'] ?? date('Y-m-d');
$f_group = $_GET['group'] ?? 'day'; 

$disqualified = getDisqualifiedNumbers($conn);
$blocked = getBlockedNumbers($conn);
$processed = []; 
$rawData = [];

$categories = [
    'Ziyadah Pemula', 'Ziyadah Lanjutan', "Muroja'ah", 
    'Tahfidz Cilik', 'Mode Intensif', 'Mode Normal', 
    'Ekspresi Minat'
];

$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$sql = "SELECT nowa, message, created_at FROM log_wa 
        WHERE created_at >= '$f_start 00:00:00' AND created_at <= '$f_end 23:59:59' 
        ORDER BY created_at ASC";
$res = $conn->query($sql);

// Variabel Metrik CRM
$totalValidLeads = 0;
$kategoriPopuler = [];
$hotLeads = 0;
$leadsHariIni = 0;
$todayDate = date('Y-m-d');

while ($row = $res->fetch_assoc()) {
    $n_log = preg_replace('/\D/', '', $row['nowa']);
    if(strpos($n_log,'0')===0) $n_log = '62'.substr($n_log,1);
    if(empty($n_log)) continue;

    if(isset($processed[$n_log])) continue;
    $processed[$n_log] = true;
    if (isset($disqualified[$n_log]) || isset($blocked[$n_log])) continue;

    $klas = classifyMessage($row['message']);
    if ($klas === 'Lainnya' || $klas === 'Data CSV/Manual') continue;

    $timestamp = strtotime($row['created_at']);
    $m_index = (int)date('m', $timestamp);
    $thn = date('Y', $timestamp);
    $bln_nama = $namaBulan[$m_index];

    // Hitung Metrik CRM
    if (strpos($row['created_at'], $todayDate) !== false) {
        $leadsHariIni++;
    }
    if ($klas === 'Ekspresi Minat') {
        $hotLeads++;
    }

    if ($f_group === 'month') {
        $label = "$bln_nama $thn";
    } elseif ($f_group === 'week') {
        $hari_ke = (int)date('j', $timestamp);
        $pekan_ke = ceil($hari_ke / 7);
        $label = "Pekan ke-$pekan_ke $bln_nama $thn";
    } else {
        $tgl = date('d', $timestamp);
        $label = "$tgl $bln_nama $thn";
    }

    if (!isset($rawData[$label])) {
        foreach ($categories as $cat) $rawData[$label][$cat] = 0;
    }

    $rawData[$label][$klas]++;
    $totalValidLeads++;
    if(!isset($kategoriPopuler[$klas])) $kategoriPopuler[$klas] = 0;
    $kategoriPopuler[$klas]++;
}

// Persiapan Dataset Chart
$chartLabels = array_keys($rawData);
$chartDatasets = [];
$colorPalette = [
    'Ziyadah Pemula' => '#3b82f6', 'Ziyadah Lanjutan' => '#10b981', "Muroja'ah" => '#f59e0b',
    'Tahfidz Cilik' => '#8b5cf6', 'Mode Intensif' => '#ef4444', 'Mode Normal' => '#06b6d4',
    'Ekspresi Minat' => '#f472b6'
];

$totalDataPoints = [];
foreach ($chartLabels as $label) {
    $sum = 0;
    foreach ($categories as $cat) { $sum += $rawData[$label][$cat] ?? 0; }
    $totalDataPoints[] = $sum;
}

foreach ($categories as $cat) {
    $dataPoints = [];
    foreach ($chartLabels as $label) { $dataPoints[] = $rawData[$label][$cat] ?? 0; }
    if (array_sum($dataPoints) > 0) {
        $chartDatasets[] = [
            'type' => 'bar', 'label' => $cat, 'data' => $dataPoints,
            'backgroundColor' => $colorPalette[$cat], 'borderColor' => '#ffffff',
            'borderWidth' => 1, 'borderRadius' => 4, 'order' => 1
        ];
    }
}

if (array_sum($totalDataPoints) > 0) {
    $chartDatasets[] = [
        'type' => 'line', 'label' => 'Total Interaksi', 'data' => $totalDataPoints,
        'borderColor' => '#0f172a', 'backgroundColor' => '#0f172a',
        'borderWidth' => 3, 'borderDash' => [5, 5], 'pointRadius' => 4, 'fill' => false, 'tension' => 0.3, 'order' => 0
    ];
}

$topKategori = !empty($kategoriPopuler) ? (arsort($kategoriPopuler) ? array_key_first($kategoriPopuler) : '-') : '-';

// Handle Export
if (isset($_GET['export_csv_action'])) {
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename=Laporan_CRM_Leads.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_merge(['Periode Waktu'], $categories, ['Total Leads']));
    foreach($rawData as $label => $cats) {
        $row = [$label]; $sum = 0;
        foreach($categories as $c) { $row[] = $cats[$c]; $sum += $cats[$c]; }
        $row[] = $sum; fputcsv($output, $row);
    }
    fclose($output); exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Dashboard | Analitik Prospek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; color: #1f2937; }
        .crm-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.2s ease-in-out; }
        .crm-card:hover { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); transform: translateY(-2px); }
        .custom-scroll { max-height: 400px; overflow-y: auto; scrollbar-width: thin; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .btn-crm { background: #ffffff; border: 1px solid #d1d5db; border-radius: 8px; transition: all 0.2s; font-weight: 500; }
        .btn-crm:hover { background: #f9fafb; border-color: #9ca3af; }
    </style>
</head>
<body class="p-4 md:p-6 lg:p-8">

<div class="max-w-[1500px] mx-auto space-y-6">
    
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                <div class="bg-blue-600 text-white p-2 rounded-lg">
                    <i class="fas fa-chart-pie text-lg"></i>
                </div>
                CRM Leads Analytics
            </h1>
            <p class="text-sm text-gray-500 mt-1 ml-11">Pantau performa konversi dan pipeline prospek Anda</p>
        </div>
        <div class="flex gap-3">
            <a href="pesan.php" class="btn-crm px-4 py-2 text-sm text-gray-700 flex items-center shadow-sm">
                <i class="fas fa-arrow-left mr-2 text-gray-400"></i> Back to Follow-up
            </a>
            <a href="?export_csv_action=1&from=<?=$f_start?>&to=<?=$f_end?>&group=<?=$f_group?>" class="bg-blue-600 hover:bg-blue-700 text-white border border-transparent rounded-lg px-4 py-2 text-sm font-medium flex items-center shadow-sm transition-colors">
                <i class="fas fa-cloud-download-alt mr-2"></i> Export Data
            </a>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="crm-card p-5 border-l-4 border-blue-500 relative overflow-hidden">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Total Leads Valid</p>
                    <h3 class="text-3xl font-bold text-gray-900"><?= number_format($totalValidLeads) ?></h3>
                </div>
                <div class="p-3 bg-blue-50 rounded-lg">
                    <i class="fas fa-users text-blue-500 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 text-xs text-gray-500 flex items-center gap-1">
                <i class="fas fa-filter text-gray-400"></i> Bersih dari bot & duplikat
            </div>
        </div>

        <div class="crm-card p-5 border-l-4 border-pink-500 relative overflow-hidden">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Hot Leads (Minat)</p>
                    <h3 class="text-3xl font-bold text-gray-900"><?= number_format($hotLeads) ?></h3>
                </div>
                <div class="p-3 bg-pink-50 rounded-lg">
                    <i class="fas fa-fire text-pink-500 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 text-xs text-pink-600 font-medium flex items-center gap-1 bg-pink-50 w-max px-2 py-1 rounded">
                Prioritas Follow Up Pertama
            </div>
        </div>

        <div class="crm-card p-5 border-l-4 border-emerald-500 relative overflow-hidden">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Masuk Hari Ini</p>
                    <h3 class="text-3xl font-bold text-gray-900"><?= number_format($leadsHariIni) ?></h3>
                </div>
                <div class="p-3 bg-emerald-50 rounded-lg">
                    <i class="fas fa-calendar-day text-emerald-500 text-xl"></i>
                </div>
            </div>
            <div class="mt-4 text-xs text-gray-500 flex items-center gap-1">
                <i class="fas fa-clock text-gray-400"></i> Update terakhir otomatis
            </div>
        </div>

        <div class="crm-card p-5 border-l-4 border-amber-500 relative overflow-hidden">
            <div class="flex justify-between items-start">
                <div class="w-full">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Program Terfavorit</p>
                    <h3 class="text-lg font-bold text-gray-900 truncate mt-2" title="<?= htmlspecialchars($topKategori) ?>">
                        <?= htmlspecialchars($topKategori) ?>
                    </h3>
                </div>
                <div class="p-3 bg-amber-50 rounded-lg shrink-0">
                    <i class="fas fa-trophy text-amber-500 text-xl"></i>
                </div>
            </div>
            <div class="mt-3 text-xs text-gray-500 flex items-center gap-1">
                Berdasarkan volume chat masuk
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        
        <div class="xl:col-span-3 space-y-6">
            <div class="crm-card p-5">
                <h3 class="font-semibold text-gray-800 mb-4 pb-3 border-b border-gray-100 flex items-center gap-2">
                    <i class="fas fa-sliders-h text-blue-500"></i> Filter Parameter
                </h3>
                <form method="GET" class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-gray-600">Periode Mulai</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-lg outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm transition-all">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-gray-600">Periode Akhir</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-lg outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm transition-all">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-gray-600">Tampilan Grafik (Group By)</label>
                        <select name="group" class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-lg outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm transition-all cursor-pointer">
                            <option value="day" <?= $f_group === 'day' ? 'selected' : '' ?>>Harian (Daily)</option>
                            <option value="week" <?= $f_group === 'week' ? 'selected' : '' ?>>Pekanan (Weekly)</option>
                            <option value="month" <?= $f_group === 'month' ? 'selected' : '' ?>>Bulanan (Monthly)</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white py-2.5 rounded-lg font-medium text-sm transition-colors mt-2">
                        Terapkan Filter
                    </button>
                </form>
            </div>
        </div>

        <div class="xl:col-span-9">
            <div class="crm-card p-5 h-full flex flex-col">
                <div class="flex justify-between items-center mb-6 pb-3 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-chart-line text-blue-500"></i> Pipeline Pertumbuhan Leads
                    </h3>
                    <div class="text-[10px] font-semibold text-gray-500 bg-gray-100 px-3 py-1 rounded-full border border-gray-200">
                        Garis Hitam = Tren Keseluruhan
                    </div>
                </div>

                <div class="relative w-full flex-1 min-h-[350px]">
                    <?php if (empty($chartLabels)): ?>
                        <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200">
                            <i class="fas fa-inbox text-5xl mb-3 opacity-30"></i>
                            <p class="font-medium text-sm">Tidak ada pergerakan leads pada periode ini.</p>
                        </div>
                    <?php else: ?>
                        <canvas id="leadsChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="crm-card overflow-hidden">
        <div class="p-5 border-b border-gray-100 bg-white flex items-center justify-between">
            <h3 class="font-semibold text-gray-800 flex items-center gap-2">
                <i class="fas fa-list text-blue-500"></i> Rincian Database Pertumbuhan
            </h3>
        </div>
        <div class="custom-scroll">
            <table class="w-full text-left text-sm whitespace-nowrap">
                <thead class="text-gray-500 font-semibold text-xs tracking-wider bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                    <tr>
                        <th class="p-4 pl-6">Periode Laporan</th>
                        <th class="p-4 text-center border-x border-gray-200 bg-blue-50/50 text-blue-800">Total Pipeline</th>
                        <?php foreach ($categories as $cat): ?>
                            <th class="p-4 text-center"><?= $cat ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php 
                    $reversedLabels = array_reverse($chartLabels);
                    foreach ($reversedLabels as $label): 
                        $idx = array_search($label, $chartLabels);
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors bg-white">
                        <td class="p-4 pl-6 font-medium text-gray-700"><?= $label ?></td>
                        <td class="p-4 text-center border-x border-gray-50 bg-blue-50/20 font-bold text-blue-700">
                            <?= $totalDataPoints[$idx] ?>
                        </td>
                        <?php foreach ($categories as $cat): 
                            $val = $rawData[$label][$cat] ?? 0;
                        ?>
                            <td class="p-4 text-center">
                                <?php if($val > 0): ?>
                                    <span class="inline-flex items-center justify-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                        <?= $val ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartDatasets = <?= json_encode($chartDatasets) ?>;

document.addEventListener("DOMContentLoaded", () => {
    const ctx = document.getElementById('leadsChart');
    if(!ctx) return;

    // Default Font Family untuk Chart agar menyatu dengan body
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#6b7280';

    new Chart(ctx, {
        data: { labels: chartLabels, datasets: chartDatasets },
        options: {
            responsive: true, 
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { 
                    position: 'bottom', 
                    labels: { usePointStyle: true, padding: 20, font: { size: 12, weight: '500' } } 
                },
                tooltip: { 
                    backgroundColor: 'rgba(17, 24, 39, 0.95)', 
                    titleFont: { size: 13, weight: '600' },
                    bodyFont: { size: 12 },
                    padding: 12, 
                    cornerRadius: 8,
                    itemSort: (a, b) => b.raw - a.raw 
                }
            },
            scales: {
                x: { 
                    stacked: true, 
                    grid: { display: false }, 
                    ticks: { font: { size: 11 } } 
                },
                y: { 
                    stacked: true, 
                    beginAtZero: true, 
                    border: { dash: [4, 4], display: false }, 
                    grid: { color: '#f3f4f6' },
                    ticks: { font: { size: 11 }, padding: 10 }
                }
            }
        }
    });
});
</script> 

<script>
    if (window.self !== window.top) {
        // Jika dibuka di dalam iframe dashboard JWD utama, Header utama bisa disembunyikan 
        // agar tidak terjadi double-header.
        const headerElement = document.querySelector('header');
        if (headerElement) {
            headerElement.style.display = 'none';
        }
        document.body.style.backgroundColor = "transparent";
    }
</script>
</body>
</html>