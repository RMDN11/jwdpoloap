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

// Hapus 'Data CSV/Manual' dari kategori agar tidak muncul di grafik & tabel
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

$totalValidLeads = 0;
$kategoriPopuler = [];

while ($row = $res->fetch_assoc()) {
    $n_log = preg_replace('/\D/', '', $row['nowa']);
    if(strpos($n_log,'0')===0) $n_log = '62'.substr($n_log,1);
    if(empty($n_log)) continue;

    if(isset($processed[$n_log])) continue;
    $processed[$n_log] = true;
    if (isset($disqualified[$n_log]) || isset($blocked[$n_log])) continue;

    $klas = classifyMessage($row['message']);
    // EXCLUDE: Jangan masukkan Data CSV/Manual ke statistik grafik
    if ($klas === 'Lainnya' || $klas === 'Data CSV/Manual') continue;

    $timestamp = strtotime($row['created_at']);
    $m_index = (int)date('m', $timestamp);
    $thn = date('Y', $timestamp);
    $bln_nama = $namaBulan[$m_index];

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

// Persiapan Dataset Chart (STACKED BAR)
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
        'type' => 'line', 'label' => '🌟 TOTAL', 'data' => $totalDataPoints,
        'borderColor' => '#0f172a', 'backgroundColor' => '#0f172a',
        'borderWidth' => 3, 'borderDash' => [5, 5], 'pointRadius' => 4, 'fill' => false, 'tension' => 0.3, 'order' => 0
    ];
}

$topKategori = !empty($kategoriPopuler) ? (arsort($kategoriPopuler) ? array_key_first($kategoriPopuler) : '-') : '-';

// Handle Export (CSV juga tanpa Data CSV/Manual)
if (isset($_GET['export_csv_action'])) {
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename=Laporan_Analitik.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_merge(['Waktu'], $categories, ['Total']));
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
    <title>Analitik Prospek | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; color: #1e293b; }
        .vibrant-card { border-radius: 28px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        .custom-scroll { max-height: 450px; overflow-y: auto; scrollbar-width: thin; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .btn-glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: 16px; transition: all 0.3s ease; }
        .btn-glass:hover { background: rgba(255, 255, 255, 0.9); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-[1500px] mx-auto space-y-6">
    
    <header class="bg-transparent text-slate-800 py-6 px-4 mb-6 flex flex-wrap justify-between items-center gap-4 relative overflow-hidden">
        <div class="flex items-center gap-4 relative z-10">
            <div class="bg-white/70 backdrop-blur-md p-4 rounded-3xl border border-slate-200 shadow-lg text-blue-600">
                <i class="fas fa-chart-line text-2xl"></i>
            </div>
            <div>
                <h1 class="text-3xl font-black tracking-tighter text-slate-900">Grafik Chat Masuk</h1>
                <p class="text-[11px] text-slate-500 uppercase tracking-widest font-semibold mt-1">Analisa Chatmu!</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2.5 relative z-10">
            <a href="pesan.php" class="btn-glass px-5 py-3 text-sm font-bold flex items-center text-blue-600">
                <i class="fas fa-arrow-left mr-2"></i>Kembali Ke Follow up!
            </a>
            <a href="?export_csv_action=1&from=<?=$f_start?>&to=<?=$f_end?>&group=<?=$f_group?>" class="btn-glass bg-indigo-600/90 text-white border-transparent px-5 py-3 text-sm font-bold flex items-center hover:bg-indigo-600">
                <i class="fas fa-download mr-2"></i>Export Laporan
            </a>
        </div>
    </header>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <div class="xl:col-span-3 space-y-6">
            <div class="vibrant-card p-6">
                <h3 class="font-extrabold text-slate-800 mb-5 flex items-center gap-2.5 text-sm">
                    <i class="fas fa-filter text-blue-500 bg-blue-50 p-2.5 rounded-xl border border-blue-100"></i> Filter Grafik
                </h3>
                <form method="GET" class="space-y-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block pl-1">Mulai Tanggal</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-100 text-sm font-medium text-slate-700">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block pl-1">Sampai</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-100 text-sm font-medium text-slate-700">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block pl-1">Grup Waktu</label>
                        <select name="group" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-100 text-sm font-medium text-slate-700 cursor-pointer">
                            <option value="day" <?= $f_group === 'day' ? 'selected' : '' ?>>Harian</option>
                            <option value="week" <?= $f_group === 'week' ? 'selected' : '' ?>>Pekanan</option>
                            <option value="month" <?= $f_group === 'month' ? 'selected' : '' ?>>Bulanan</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white py-3.5 rounded-xl font-bold text-sm transition-all">Perbarui Data</button>
                </form>
            </div>

            <div class="vibrant-card p-7 border-emerald-100 bg-emerald-50/30 relative overflow-hidden shadow-sm">
                <div class="absolute -right-4 -bottom-4 opacity-10"><i class="fas fa-users text-7xl text-emerald-500"></i></div>
                <p class="text-[11px] font-black text-emerald-600 uppercase tracking-widest mb-1">Total Leads Bersih</p>
                <h2 class="text-5xl font-black tracking-tight text-slate-900 mb-5"><?= number_format($totalValidLeads) ?></h2>
                <div class="bg-white px-4 py-2.5 rounded-xl border border-emerald-100 shadow-sm inline-flex items-center gap-2.5 w-full">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse border-2 border-white ring-2 ring-emerald-100"></span>
                    <span class="font-bold text-slate-800 text-sm flex-1 truncate"><?= htmlspecialchars($topKategori) ?></span>
                </div>
            </div>
        </div>

        <div class="xl:col-span-9">
            <div class="vibrant-card overflow-hidden h-full">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-white sticky top-0 z-20">
                    <h3 class="font-extrabold text-sm text-slate-800 tracking-tight flex items-center gap-2.5">
                        <i class="fas fa-table text-blue-500 bg-blue-50 p-2 rounded-lg text-xs"></i> Rincian Angka Berdasarkan Filter
                    </h3>
                </div>
                <div class="custom-scroll">
                    <table class="w-full text-left text-xs whitespace-nowrap">
                        <thead class="text-slate-500 uppercase font-extrabold text-[10px] tracking-wider bg-slate-50 sticky top-0 z-10 border-b border-slate-200">
                            <tr>
                                <th class="p-4 pl-8">Waktu / Tanggal</th>
                                <th class="p-4 text-center border-x border-slate-200 bg-blue-50/50 text-blue-800 font-black">Total</th>
                                <?php foreach ($categories as $cat): ?>
                                    <th class="p-4 text-center"><?= $cat ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php 
                            $reversedLabels = array_reverse($chartLabels);
                            foreach ($reversedLabels as $label): 
                                $idx = array_search($label, $chartLabels);
                            ?>
                            <tr class="hover:bg-slate-50 transition-colors group bg-white">
                                <td class="p-4 pl-8 font-bold text-slate-700"><?= $label ?></td>
                                <td class="p-4 text-center border-x border-slate-50 bg-blue-50/20 font-black text-blue-700 text-sm"><?= $totalDataPoints[$idx] ?></td>
                                <?php foreach ($categories as $cat): 
                                    $val = $rawData[$label][$cat] ?? 0;
                                ?>
                                    <td class="p-4 text-center <?= $val > 0 ? 'text-slate-800 font-bold' : 'text-slate-300' ?>"><?= $val ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6">
        <div class="vibrant-card p-6 md:p-8 border border-slate-100 shadow-lg bg-white">
            <div class="flex justify-between items-center mb-8">
                <h3 class="font-extrabold text-lg text-slate-800 tracking-tight flex items-center gap-2.5">
                    <i class="fas fa-chart-bar text-indigo-500 bg-indigo-50 p-2.5 rounded-xl border border-indigo-100"></i> Stacked Bar
                </h3>
                <div class="text-[10px] font-bold text-slate-400 border border-slate-100 px-4 py-1.5 rounded-full bg-slate-50 shadow-inner uppercase tracking-wider">🌟 Garis Putus = Tren Total</div>
            </div>

            <div class="relative w-full h-[550px]">
                <?php if (empty($chartLabels)): ?>
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-slate-400">
                        <i class="fas fa-folder-open text-6xl mb-4 opacity-20"></i>
                        <p class="font-medium text-sm">Tidak ada data untuk rentang waktu ini.</p>
                    </div>
                <?php else: ?>
                    <canvas id="leadsChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartDatasets = <?= json_encode($chartDatasets) ?>;

document.addEventListener("DOMContentLoaded", () => {
    const ctx = document.getElementById('leadsChart');
    if(!ctx) return;

    new Chart(ctx, {
        data: { labels: chartLabels, datasets: chartDatasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 25, font: { weight: '600', size: 11 } } },
                tooltip: { 
                    backgroundColor: 'rgba(15, 23, 42, 0.95)', padding: 15, cornerRadius: 10,
                    itemSort: (a, b) => b.raw - a.raw 
                }
            },
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { font: { weight: '600', size: 10 } } },
                y: { stacked: true, beginAtZero: true, border: { dash: [5, 5] }, grid: { color: '#e2e8f0', drawBorder: false } }
            }
        }
    });
});
</script>
</body>
</html>