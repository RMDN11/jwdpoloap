<?php
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(E_ALL); ini_set('display_errors', 0);

// Proteksi Auth & Database
if (!file_exists('auth_checkwa.php')) die("File otentikasi tidak ditemukan.");
require_once 'auth_checkwa.php';
if (!file_exists('config.php')) die("File config tidak ditemukan.");
require_once 'config.php';

// Fitur AJAX Fetching untuk Auto-Refresh (Sangat Ringan)
if (isset($_GET['ajax_fetch'])) {
    $search = $_GET['search'] ?? '';
    if ($search) {
        $stmt = $conn->prepare("SELECT id, nowa, nama, message, created_at, last_template_name FROM log_wa WHERE nowa LIKE ? OR nama LIKE ? OR message LIKE ? ORDER BY id DESC LIMIT 300");
        $s = "%$search%"; $stmt->bind_param("sss", $s, $s, $s);
    } else {
        $stmt = $conn->prepare("SELECT id, nowa, nama, message, created_at, last_template_name FROM log_wa ORDER BY id DESC LIMIT 300");
    }
    $stmt->execute(); $res = $stmt->get_result();
    
    $html = '';
    if ($res->num_rows === 0) {
        $html = '<tr><td colspan="6" class="p-8 text-center text-slate-400 font-bold">Tidak ada data ditemukan.</td></tr>';
    } else {
        while ($r = $res->fetch_assoc()) {
            $msg = htmlspecialchars($r['message'] ?? '');
            $nama = htmlspecialchars($r['nama'] ?? 'Tanpa Nama');
            $waktu = date('d/m/Y H:i:s', strtotime($r['created_at']));
            $status = $r['last_template_name'] ? "<span class='bg-indigo-50 text-indigo-600 px-2 py-1 rounded text-[10px] font-bold border border-indigo-200'>Dikirim: {$r['last_template_name']}</span>" : "<span class='bg-amber-50 text-amber-600 px-2 py-1 rounded text-[10px] font-bold border border-amber-200'>Belum diproses</span>";
            
            // Format ulang jika pesan adalah "Data CSV/Manual"
            if ($msg === 'Data CSV/Manual') {
                $msg_html = "<span class='italic text-slate-400 font-medium'>[Data dari input Manual/CSV]</span>";
            } else {
                $msg_html = "<div class='bg-slate-50 border border-slate-100 p-2 rounded-lg max-h-24 overflow-y-auto custom-scroll text-slate-700 font-medium'>" . nl2br($msg) . "</div>";
            }

            $html .= "<tr class='hover:bg-slate-50/50 transition-colors border-b border-slate-100'>
                <td class='p-3 text-center text-xs font-black text-slate-400'>{$r['id']}</td>
                <td class='p-3 text-xs font-semibold text-slate-600 whitespace-nowrap'>{$waktu}</td>
                <td class='p-3 whitespace-nowrap'>
                    <div class='font-black text-slate-800 text-sm'>{$nama}</div>
                    <div class='text-[11px] text-slate-500 font-medium mt-0.5'>{$r['nowa']}</div>
                </td>
                <td class='p-3 min-w-[300px]'>{$msg_html}</td>
                <td class='p-3 whitespace-nowrap text-center'>{$status}</td>
            </tr>";
        }
    }
    echo $html; exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pantau Log WA - Realtime</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .glass-panel { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.5); }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-8 min-h-screen flex flex-col">

    <!-- Header Glassmorphism -->
    <div class="glass-panel rounded-3xl p-5 mb-6 shadow-xl shadow-slate-200/50 flex flex-col md:flex-row justify-between items-center gap-4">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-indigo-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-200 text-xl"><i class="fas fa-database"></i></div>
            <div>
                <h1 class="text-xl font-black tracking-tight text-slate-800">Raw Data Log WA</h1>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-0.5">Menampilkan 300 pesan terakhir (Tanpa Filter)</p>
            </div>
        </div>
        
        <div class="flex items-center gap-3 w-full md:w-auto">
            <div class="relative flex-1 md:w-64">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" id="searchInput" placeholder="Cari Nama/Pesan..." class="w-full pl-9 pr-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 transition-all shadow-sm">
            </div>
            <button onclick="toggleAutoRefresh()" id="btnRefresh" class="bg-emerald-50 text-emerald-600 border border-emerald-200 hover:bg-emerald-600 hover:text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-sm transition-colors flex items-center gap-2 whitespace-nowrap">
                <i class="fas fa-sync-alt" id="iconRefresh"></i> <span id="textRefresh">Auto-Refresh: ON</span>
            </button>
            <a href="pesan-2.php" class="bg-slate-800 text-white hover:bg-slate-900 px-4 py-2.5 rounded-xl text-xs font-bold shadow-md shadow-slate-200 transition-colors"><i class="fas fa-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <!-- Tabel Data -->
    <div class="glass-panel rounded-3xl shadow-xl shadow-slate-200/50 flex-1 overflow-hidden flex flex-col border border-slate-200">
        <div class="overflow-x-auto flex-1 custom-scroll bg-white/50">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="text-[10px] text-slate-400 font-bold bg-white border-b border-slate-200 uppercase tracking-wider sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="p-4 w-16 text-center">ID DB</th>
                        <th class="p-4 w-36">Waktu Masuk</th>
                        <th class="p-4 w-48">Kontak</th>
                        <th class="p-4">Pesan Masuk (Raw)</th>
                        <th class="p-4 w-32 text-center">Status Kirim</th>
                    </tr>
                </thead>
                <tbody id="tableBody" class="divide-y divide-slate-100">
                    <tr><td colspan="5" class="p-10 text-center"><i class="fas fa-spinner fa-spin text-indigo-500 text-2xl"></i><p class="mt-2 text-slate-400 font-bold text-xs uppercase tracking-wider">Memuat Data...</p></td></tr>
                </tbody>
            </table>
        </div>
    </div>

<script>
    let autoRefresh = true;
    let refreshInterval;

    function fetchData() {
        const search = document.getElementById('searchInput').value;
        fetch(`?ajax_fetch=1&search=${encodeURIComponent(search)}`)
            .then(res => res.text())
            .then(html => {
                document.getElementById('tableBody').innerHTML = html;
            })
            .catch(err => console.error("Gagal memuat data", err));
    }

    function toggleAutoRefresh() {
        autoRefresh = !autoRefresh;
        const btn = document.getElementById('btnRefresh');
        const icon = document.getElementById('iconRefresh');
        const text = document.getElementById('textRefresh');

        if (autoRefresh) {
            btn.className = "bg-emerald-50 text-emerald-600 border border-emerald-200 hover:bg-emerald-600 hover:text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-sm transition-colors flex items-center gap-2 whitespace-nowrap";
            icon.classList.add('fa-spin');
            text.innerText = "Auto-Refresh: ON";
            fetchData();
            refreshInterval = setInterval(fetchData, 10000);
        } else {
            btn.className = "bg-rose-50 text-rose-600 border border-rose-200 hover:bg-rose-600 hover:text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-sm transition-colors flex items-center gap-2 whitespace-nowrap";
            icon.classList.remove('fa-spin');
            text.innerText = "Auto-Refresh: OFF";
            clearInterval(refreshInterval);
        }
    }

    // Auto trigger pencarian saat mengetik (dengan delay 500ms agar tidak berat)
    let typingTimer;
    document.getElementById('searchInput').addEventListener('keyup', () => {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(fetchData, 500);
    });

    // Inisialisasi Pertama
    document.getElementById('iconRefresh').classList.add('fa-spin');
    fetchData();
    refreshInterval = setInterval(fetchData, 10000); // Otomatis refresh tiap 10 detik
</script>
</body>
</html>