<?php
session_start();
require_once 'auth_checkwa.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try { require_once 'config.php'; } 
catch (Exception $e) { die("Configuration error"); }

if (isset($conn) && $conn) mysqli_set_charset($conn, "utf8mb4");

// ==================================================================
// FUNGSI KLASIFIKASI & FILTER
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
    $disq = []; if (!$conn) return $disq;
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
    $blk = []; if(!$conn) return $blk;
    $q = $conn->query("SELECT nowa FROM blocked_peserta");
    if ($q) while($r = $q->fetch_assoc()) {
        $n = preg_replace('/\D/', '', $r['nowa']); 
        if(strpos($n,'0')===0) $n = '62'.substr($n,1); 
        if($n) $blk[$n] = true;
    }
    return $blk;
}

function getRegisteredContacts($conn) {
    $registered = []; if (!$conn) return $registered;
    try {
        $stmt = $conn->prepare("SELECT DISTINCT nowa FROM calon_peserta");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $dbPhone = $row['nowa'];
                $normalizedPhone = $dbPhone;
                if (substr($dbPhone, 0, 1) === '0') $normalizedPhone = '62' . substr($dbPhone, 1);
                elseif (substr($dbPhone, 0, 1) === '+') $normalizedPhone = substr($dbPhone, 1);
                $normalizedPhone = preg_replace('/\D/', '', $normalizedPhone);
                $registered[$normalizedPhone] = true;
            }
            $stmt->close();
        }
    } catch (Exception $e) {}
    return $registered;
}

// PARAMETER FILTER
$f_start = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$f_end = $_GET['to'] ?? date('Y-m-d');
$f_group = $_GET['group'] ?? 'day';

$disqualified = getDisqualifiedNumbers($conn);
$blocked = getBlockedNumbers($conn);
$registeredContacts = getRegisteredContacts($conn);
$processed = []; $rawData = [];

$categories = ['Ziyadah Pemula', 'Ziyadah Lanjutan', "Muroja'ah", 'Tahfidz Cilik', 'Mode Intensif', 'Mode Normal', 'Ekspresi Minat'];
$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', ' Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$sql = "SELECT nowa, message, created_at FROM log_wa WHERE created_at >= '$f_start 00:00:00' AND created_at <= '$f_end 23:59:59' ORDER BY created_at ASC";
$res = $conn->query($sql);

$totalValidLeads = 0; $kategoriPopuler = []; $totalNewUnregisteredLeads = 0;

while ($row = $res->fetch_assoc()) {
    $n_log = preg_replace('/\D/', '', $row['nowa']);
    if(strpos($n_log,'0')===0) $n_log = '62'.substr($n_log,1);
    if(empty($n_log)) continue;
    if(isset($processed[$n_log])) continue;
    $processed[$n_log] = true;
    if (isset($disqualified[$n_log]) || isset($blocked[$n_log])) continue;

    $klas = classifyMessage($row['message']);
    if ($klas === 'Lainnya' || $klas === 'Data CSV/Manual') continue;

    if (!isset($registeredContacts[$n_log])) $totalNewUnregisteredLeads++;

    $timestamp = strtotime($row['created_at']);
    $m_index = (int)date('m', $timestamp); $thn = date('Y', $timestamp); $bln_nama = $namaBulan[$m_index];

    if ($f_group === 'month') $label = "$bln_nama $thn";
    elseif ($f_group === 'week') { $pekan_ke = ceil((int)date('j', $timestamp) / 7); $label = "Pekan ke-$pekan_ke $bln_nama $thn"; } 
    else { $tgl = date('d', $timestamp); $label = "$tgl $bln_nama $thn"; }

    if (!isset($rawData[$label])) foreach ($categories as $cat) $rawData[$label][$cat] = 0;
    
    $rawData[$label][$klas]++;
    $totalValidLeads++;
    if(!isset($kategoriPopuler[$klas])) $kategoriPopuler[$klas] = 0;
    $kategoriPopuler[$klas]++;
}

$chartLabels = array_keys($rawData); $chartDatasets = []; $totalDataPoints = [];
foreach ($chartLabels as $label) {
    $sum = 0; foreach ($categories as $cat) { $sum += $rawData[$label][$cat] ?? 0; }
    $totalDataPoints[] = $sum;
}

$gradientPalette = [['#6366f1', '#a5b4fc'], ['#3b82f6', '#93c5fd'], ['#10b981', '#6ee7b7'], ['#eab308', '#fde047'], ['#f59e0b', '#fcd34d'], ['#ef4444', '#fca5a5'], ['#8b5cf6', '#c4b5fd']];
$colorIdx = 0;
foreach ($categories as $cat) {
    $dataPoints = []; foreach ($chartLabels as $label) { $dataPoints[] = $rawData[$label][$cat] ?? 0; }
    if (array_sum($dataPoints) > 0) {
        $chartDatasets[] = ['type' => 'bar', 'label' => $cat, 'data' => $dataPoints, 'backgroundColor' => $cat, 'borderColor' => '#ffffff', 'borderWidth' => 1, 'borderRadius' => 4, 'order' => 1, 'gradientPair' => $gradientPalette[$colorIdx % count($gradientPalette)]];
        $colorIdx++;
    }
}

if (array_sum($totalDataPoints) > 0) {
    $chartDatasets[] = ['type' => 'line', 'label' => '🌟 TOTAL', 'data' => $totalDataPoints, 'borderColor' => '#0f172a', 'backgroundColor' => '#0f172a', 'borderWidth' => 3, 'borderDash' => [5, 5], 'pointRadius' => 4, 'fill' => false, 'tension' => 0.3, 'order' => 0];
}

$topKategori = !empty($kategoriPopuler) ? (arsort($kategoriPopuler) ? array_key_first($kategoriPopuler) : '-') : '-';

if (isset($_GET['export_csv_action'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Laporan_Analitik.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_merge(['Waktu'], $categories, ['Total']));
    foreach($rawData as $label => $cats) {
        $row = [$label]; $sum = 0; foreach($categories as $c) { $row[] = $cats[$c]; $sum += $cats[$c]; }
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
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #334155; }
        
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
        
        .crm-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05); transition: all 0.2s ease-in-out; }
        .crm-input { background: #f8fafc; border: 1px solid #e2e8f0; color: #334155; border-radius: 8px; transition: all 0.2s; }
        .crm-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); background: #ffffff; }
        
        .row-animate { transition: background-color 0.2s ease; }
        .row-animate:hover { background-color: #f1f5f9; }
        @keyframes fadeInScale { from { opacity: 0; transform: scale(0.97) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .animate-fade-in { animation: fadeInScale 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards; }
    </style>
</head>
<body class="flex flex-col h-screen overflow-hidden bg-slate-50 relative">

    <div id="toast-container" class="fixed top-5 right-5 z-[9999] flex flex-col gap-3 pointer-events-none"></div>

    <header class="h-[70px] shrink-0 bg-white border-b border-slate-200 px-6 flex items-center justify-between z-10 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="bg-indigo-600 text-white w-8 h-8 flex justify-center items-center rounded-lg shadow-sm"><i class="fas fa-chart-line"></i></div>
            <h2 class="text-base font-bold text-slate-800 hidden sm:block">Dashboard Analitik Prospek</h2>
        </div>
        <div class="flex gap-2 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0 custom-scroll">
            <a href="pesan.php" class="shrink-0 bg-white border border-slate-200 text-slate-600 hover:text-indigo-600 hover:border-indigo-300 hover:bg-indigo-50 px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm">
                <i class="fas fa-arrow-left mr-1"></i> Kembali ke CRM
            </a>
            <a href="?export_csv_action=1&from=<?=urlencode($f_start)?>&to=<?=urlencode($f_end)?>&group=<?=urlencode($f_group)?>" class="shrink-0 bg-slate-800 hover:bg-slate-900 text-white px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm">
                <i class="fas fa-download mr-1"></i> Export Data
            </a>
        </div>
    </header>

    <div class="flex-1 overflow-y-auto p-5 lg:p-6 space-y-6 custom-scroll">

        <div class="bg-gradient-to-r from-blue-700 to-indigo-800 rounded-xl p-5 text-white shadow-md animate-fade-in flex flex-col md:flex-row justify-between items-center gap-4 border border-blue-900/50">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm shrink-0 border border-white/20"><i class="fas fa-bullseye text-xl text-emerald-300"></i></div>
                <div>
                    <h3 class="font-bold text-sm md:text-base">Objective: Peningkatan Konversi</h3>
                    <p class="text-[10px] md:text-xs text-blue-200 mt-0.5">Analisa statistik pendaftaran organik real-time.</p>
                </div>
            </div>
            
            <div class="flex gap-4">
                <div class="bg-black/20 border border-white/10 rounded-lg px-4 py-2 text-center">
                    <div class="text-[9px] font-bold uppercase tracking-wider text-blue-200 mb-0.5">Total Organik</div>
                    <div class="text-xl font-black text-white"><?= number_format($totalValidLeads) ?></div>
                </div>
                <div class="bg-emerald-500/30 border border-emerald-400/30 rounded-lg px-4 py-2 text-center">
                    <div class="text-[9px] font-bold uppercase tracking-wider text-emerald-100 mb-0.5">Leads Murni (Baru)</div>
                    <div class="text-xl font-black text-white"><?= number_format($totalNewUnregisteredLeads) ?></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 animate-fade-in" style="animation-delay: 0.1s;">
            <div class="xl:col-span-3 space-y-6">
                <div class="crm-card p-5">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4 flex items-center"><i class="fas fa-filter mr-2"></i> Filter Grafik</h3>
                    <form method="GET" class="space-y-3">
                        <div class="space-y-1">
                            <label class="text-[10px] font-semibold text-slate-500 block">Mulai Tanggal</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($f_start) ?>" class="crm-input w-full px-3 py-2 text-xs font-medium cursor-pointer">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-semibold text-slate-500 block">Sampai Tanggal</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($f_end) ?>" class="crm-input w-full px-3 py-2 text-xs font-medium cursor-pointer">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-semibold text-slate-500 block">Grup Rentang Waktu</label>
                            <select name="group" class="crm-input w-full px-3 py-2 text-xs font-medium cursor-pointer">
                                <option value="day" <?= $f_group === 'day' ? 'selected' : '' ?>>Harian</option>
                                <option value="week" <?= $f_group === 'week' ? 'selected' : '' ?>>Pekanan</option>
                                <option value="month" <?= $f_group === 'month' ? 'selected' : '' ?>>Bulanan</option>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 mt-2 rounded-lg font-bold text-xs transition-colors shadow-sm"><i class="fas fa-sync-alt mr-1.5"></i> Perbarui Grafik</button>
                    </form>
                </div>

                <div class="crm-card p-5">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center"><i class="fas fa-trophy mr-2 text-amber-500"></i> Top Minat Pendaftar</h3>
                    <div class="space-y-2">
                        <?php if (empty($kategoriPopuler)): ?>
                            <div class="text-[11px] text-slate-400 font-medium italic">Data kosong di periode ini.</div>
                        <?php else: 
                            $rank = 1;
                            foreach (array_slice($kategoriPopuler, 0, 5, true) as $label => $count): 
                                $bg = $rank === 1 ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-slate-50 border-slate-100 text-slate-600';
                                $icon = $rank === 1 ? '<i class="fas fa-crown text-amber-500 mr-1.5"></i>' : "<span class='text-slate-400 mr-2 text-[10px]'>#$rank</span>";
                        ?>
                            <div class="flex justify-between items-center p-2 rounded-lg border <?= $bg ?> text-xs font-bold transition-all hover-lift cursor-default">
                                <div class="flex items-center"><?= $icon ?><?= $label ?></div>
                                <span class="bg-white px-1.5 py-0.5 rounded shadow-sm"><?= number_format($count) ?></span>
                            </div>
                        <?php $rank++; endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-9">
                <div class="crm-card overflow-hidden h-full flex flex-col">
                    <div class="p-4 border-b border-slate-200 bg-white flex justify-between items-center">
                        <h3 class="font-bold text-sm text-slate-800 flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-indigo-500"></div> Matriks Data Tabular
                        </h3>
                    </div>
                    <div class="flex-1 overflow-x-auto custom-scroll max-h-[500px]">
                        <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">
                            <thead class="text-[10px] text-slate-500 uppercase bg-slate-50/80 border-b border-slate-200 sticky top-0 z-10 backdrop-blur-sm">
                                <tr>
                                    <th class="p-4 font-bold">Waktu</th>
                                    <th class="p-4 text-center bg-indigo-50/50 text-indigo-800 font-black border-x border-slate-200">Total</th>
                                    <?php foreach ($categories as $cat): ?><th class="p-4 text-center font-bold"><?= $cat ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if(empty($chartLabels)): ?>
                                    <tr><td colspan="<?= count($categories) + 2 ?>" class="p-8 text-center text-slate-400 text-xs font-medium">Tidak ada data untuk dirender.</td></tr>
                                <?php else: ?>
                                    <?php 
                                    $reversedLabels = array_reverse($chartLabels);
                                    foreach ($reversedLabels as $label): 
                                        $idx = array_search($label, $chartLabels);
                                    ?>
                                    <tr class="row-animate">
                                        <td class="p-4 text-xs font-bold text-slate-700"><?= $label ?></td>
                                        <td class="p-4 text-center border-x border-slate-50 bg-indigo-50/20 font-black text-indigo-600 text-xs shadow-sm"><?= $totalDataPoints[$idx] ?></td>
                                        <?php foreach ($categories as $cat): 
                                            $val = $rawData[$label][$cat] ?? 0;
                                        ?>
                                            <td class="p-4 text-center text-xs <?= $val > 0 ? 'text-slate-800 font-bold' : 'text-slate-300' ?>"><?= $val ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="crm-card p-5 md:p-6 animate-fade-in" style="animation-delay: 0.2s;">
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100">
                <h3 class="font-bold text-sm text-slate-800 flex items-center gap-2">
                    <i class="fas fa-chart-area text-indigo-500"></i> Visualisasi Stacked Bar (Dinamis)
                </h3>
                <div class="text-[9px] font-bold text-slate-400 border border-slate-200 px-2.5 py-1 rounded-md bg-slate-50 uppercase tracking-wider flex items-center gap-1.5"><i class="fas fa-minus text-slate-800 border-b-2 border-slate-800 border-dashed w-3 h-0"></i> Trend Total</div>
            </div>

            <div class="relative w-full h-[500px]">
                <?php if (empty($chartLabels)): ?>
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-slate-400">
                        <i class="fas fa-chart-bar text-6xl mb-4 opacity-20"></i>
                        <p class="font-medium text-xs">Pilih rentang waktu yang valid untuk melihat grafik.</p>
                    </div>
                <?php else: ?>
                    <canvas id="leadsChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <div class="h-10"></div>
    </div>

<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
let chartDatasets = <?= json_encode($chartDatasets) ?>;

function showToast(message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `flex items-center gap-2.5 px-4 py-3 rounded-xl shadow-lg text-[13px] font-bold transform transition-all duration-300 translate-x-full opacity-0 pointer-events-auto bg-blue-600 text-white shadow-blue-500/30`;
    toast.innerHTML = `<i class="fas fa-info-circle text-lg"></i> <span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
    setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 4000);
}

document.addEventListener("DOMContentLoaded", () => {
    // Notify on load
    showToast("Berhasil memuat data analitik prospek.");

    const ctx = document.getElementById('leadsChart');
    if(!ctx) return;

    const chartContext = ctx.getContext('2d');
    chartDatasets = chartDatasets.map(dataset => {
        if (dataset.type === 'bar' && dataset.gradientPair) {
            const gradient = chartContext.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, dataset.gradientPair[0]);
            gradient.addColorStop(1, dataset.gradientPair[1]);
            return { ...dataset, backgroundColor: gradient };
        }
        return dataset;
    });

    new Chart(ctx, {
        data: { labels: chartLabels, datasets: chartDatasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 25, font: { family: 'Inter', weight: '600', size: 10 }, color: '#475569' } },
                tooltip: {  
                    backgroundColor: 'rgba(15, 23, 42, 0.95)', padding: 12, cornerRadius: 8, titleFont: { family: 'Inter', size: 11 }, bodyFont: { family: 'Inter', size: 11 },
                    itemSort: (a, b) => b.raw - a.raw
                },
                datalabels: {
                    anchor: 'end', align: 'top', color: '#64748b', font: { family: 'Inter', weight: 'bold', size: 9 },
                    formatter: (value, context) => { return (context.dataset.type === 'bar' && value > 0) ? value : null; }
                }
            },
            animation: { duration: 1000, easing: 'easeOutQuart' },
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { font: { family: 'Inter', weight: '600', size: 9 }, color: '#64748b' } },
                y: { stacked: true, beginAtZero: true, border: { dash: [5, 5], display: false }, grid: { color: '#f1f5f9' }, ticks: { font: { family: 'Inter', size: 9 }, color: '#94a3b8' } }
            }
        },
        plugins: [ChartDataLabels]
    });
});

if (window.self !== window.top) {
    const headerElement = document.querySelector('header');
    if (headerElement) headerElement.style.display = 'none';
    document.body.style.backgroundColor = "transparent";
}
</script>
</body>
</html>