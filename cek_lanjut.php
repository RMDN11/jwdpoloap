<?php
require_once 'auth_checkwa.php'; // Sesuaikan jika ada file auth
require_once 'config.php';

// ============================================
// AUTO MIGRATION: Tambah kolom jika belum ada
// ============================================
$checkCol = $conn->query("SHOW COLUMNS FROM peserta LIKE 'status_lanjut'");
if ($checkCol && $checkCol->num_rows == 0) {
    $conn->query("ALTER TABLE peserta ADD COLUMN status_lanjut VARCHAR(10) DEFAULT 'ya'");
}

$checkColAlasan = $conn->query("SHOW COLUMNS FROM peserta LIKE 'alasan_tidak_lanjut'");
if ($checkColAlasan && $checkColAlasan->num_rows == 0) {
    $conn->query("ALTER TABLE peserta ADD COLUMN alasan_tidak_lanjut TEXT");
}

// ============================================
// ENDPOINT AJAX UNTUK FUNGSI REAL-TIME
// ============================================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];

    // 1. Live Search Peserta
    if ($action === 'search') {
        $search = $_GET['keyword'] ?? '';
        $sql = "SELECT id, nama_lengkap, nowa, halaqoh, status_lanjut, alasan_tidak_lanjut FROM peserta WHERE nama_lengkap LIKE ? LIMIT 30";
        $stmt = $conn->prepare($sql);
        $searchParam = "%$search%";
        $stmt->bind_param("s", $searchParam);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    // 2. Toggle Status (Ubah status 'ya' / 'tidak')
    if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        $status_lanjut = $_POST['status_lanjut'] ?? 'ya'; // 'ya' atau 'tidak'
        $alasan = $_POST['alasan'] ?? '';
        
        // Jika dikembalikan ke 'ya', kosongkan juga alasannya
        if ($status_lanjut === 'ya') {
            $sql = "UPDATE peserta SET status_lanjut = ?, alasan_tidak_lanjut = '' WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $status_lanjut, $id);
        } else {
            $sql = "UPDATE peserta SET status_lanjut = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $status_lanjut, $id);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'msg' => 'Status berhasil diperbarui']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Gagal memperbarui status']);
        }
        exit;
    }

    // 3. Ambil Daftar Peserta yang "Tidak Lanjut"
    if ($action === 'get_list') {
        $sql = "SELECT id, nama_lengkap, nowa, halaqoh, alasan_tidak_lanjut FROM peserta WHERE status_lanjut = 'tidak' ORDER BY nama_lengkap ASC";
        $result = $conn->query($sql);
        
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    // 4. Update Alasan Tidak Lanjut
    if ($action === 'update_alasan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        $alasan = $_POST['alasan'] ?? '';
        
        $sql = "UPDATE peserta SET alasan_tidak_lanjut = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $alasan, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'msg' => 'Alasan berhasil diperbarui']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Gagal memperbarui alasan']);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Peserta - JWD</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        
        .bento-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 1.25rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }

        /* Custom Toggle Switch styling */
        .toggle-checkbox:checked {
            right: 0;
            border-color: #ef4444; /* red-500 */
        }
        .toggle-checkbox:checked + .toggle-label {
            background-color: #ef4444; /* red-500 */
        }
        .toggle-checkbox {
            right: 0;
            z-index: 1;
            border-color: #e2e8f0;
            transition: all 0.3s;
        }
        .toggle-label {
            width: 3rem;
            height: 1.5rem;
            background-color: #cbd5e1; /* slate-300 */
            border-radius: 9999px;
            transition: all 0.3s;
        }
        .toggle-dot {
            top: 0.125rem;
            left: 0.125rem;
            width: 1.25rem;
            height: 1.25rem;
            background-color: white;
            border-radius: 50%;
            transition: all 0.3s;
        }
        .toggle-checkbox:checked ~ .toggle-dot {
            transform: translateX(100%);
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800">

    <main class="flex-grow p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto">
        <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-800 flex items-center">
                    <i class="fas fa-user-times text-red-500 mr-3"></i> Konfirmasi Tidak Lanjut
                </h1>
                <p class="text-sm text-slate-500 mt-1">Cari nama peserta, tandai, dan sertakan alasan jika mereka tidak melanjutkan program.</p>
            </div>
            <a href="reminder.php" class="bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl font-medium text-sm transition shadow-sm">
                <i class="fas fa-arrow-left mr-2"></i> Kembali
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- KOLOM KIRI: Pencarian & Daftar Semua Peserta -->
            <div class="lg:col-span-7 space-y-4">
                <div class="bento-card p-6">
                    <div class="relative mb-6">
                        <i class="fas fa-search absolute left-4 top-3.5 text-slate-400"></i>
                        <input type="text" id="searchInput" placeholder="Ketik nama peserta untuk mencari..." 
                               class="w-full border-slate-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-red-500 font-medium text-sm transition shadow-sm bg-slate-50 focus:bg-white" autocomplete="off">
                    </div>

                    <div class="flex justify-between items-center mb-3 px-2">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Hasil Pencarian</span>
                        <span id="searchCount" class="text-xs bg-slate-100 text-slate-600 px-2 py-1 rounded-md font-bold">0 Peserta</span>
                    </div>

                    <div id="searchResultContainer" class="space-y-2 max-h-[500px] overflow-y-auto pr-2">
                        <!-- Hasil pencarian akan dirender di sini oleh JS -->
                        <div class="text-center py-10 text-slate-400 text-sm">
                            <i class="fas fa-search mb-2 text-2xl opacity-50"></i><br>
                            Ketik nama peserta di kotak pencarian.
                        </div>
                    </div>
                </div>
            </div>

            <!-- KOLOM KANAN: Daftar Peserta Tidak Lanjut -->
            <div class="lg:col-span-5 space-y-4">
                <div class="bento-card p-6 h-full flex flex-col">
                    <div class="flex justify-between items-center mb-5">
                        <h2 class="text-lg font-bold text-slate-800 flex items-center">
                            <i class="fas fa-clipboard-list text-red-500 mr-2"></i> List Tidak Lanjut
                        </h2>
                        <span id="listCount" class="bg-red-100 text-red-700 text-xs font-bold px-3 py-1 rounded-full border border-red-200">
                            0 Data
                        </span>
                    </div>

                    <div id="tidakLanjutContainer" class="flex-1 space-y-3 max-h-[500px] overflow-y-auto pr-2 mb-4">
                        <!-- List yang tidak lanjut dirender di sini -->
                        <div class="text-center py-10 text-slate-400 text-sm">Memuat data...</div>
                    </div>

                    <div class="mt-auto pt-4 border-t border-slate-100">
                        <button id="copyListBtn" class="w-full bg-slate-800 hover:bg-slate-900 text-white px-4 py-3 rounded-xl shadow-md font-bold text-sm transition flex items-center justify-center">
                            <i class="fas fa-copy mr-2"></i> Salin List Nama & Alasan
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const searchResultContainer = document.getElementById('searchResultContainer');
            const tidakLanjutContainer = document.getElementById('tidakLanjutContainer');
            const searchCount = document.getElementById('searchCount');
            const listCount = document.getElementById('listCount');
            const copyListBtn = document.getElementById('copyListBtn');

            let currentDataTidakLanjut = [];

            // 1. Fungsi Pencarian Peserta
            function fetchPeserta(keyword = '') {
                fetch(`?ajax_action=search&keyword=${encodeURIComponent(keyword)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            renderSearchResults(data.data, keyword);
                        }
                    })
                    .catch(err => console.error("Error fetching peserta:", err));
            }

            function renderSearchResults(peserta, keyword) {
                searchResultContainer.innerHTML = '';
                searchCount.textContent = `${peserta.length} Peserta`;

                if (peserta.length === 0) {
                    searchResultContainer.innerHTML = `
                        <div class="text-center py-10 text-slate-400 text-sm">
                            <i class="fas fa-box-open mb-2 text-2xl opacity-50"></i><br>
                            ${keyword ? 'Peserta tidak ditemukan.' : 'Ketik nama peserta di kotak pencarian.'}
                        </div>`;
                    return;
                }

                peserta.forEach(p => {
                    const isTidakLanjut = p.status_lanjut === 'tidak';
                    const card = document.createElement('div');
                    card.className = `flex justify-between items-center p-4 rounded-xl border transition-all duration-200 ${isTidakLanjut ? 'bg-red-50 border-red-200' : 'bg-white border-slate-100 hover:border-slate-300 shadow-sm'}`;
                    
                    card.innerHTML = `
                        <div>
                            <div class="font-bold text-sm ${isTidakLanjut ? 'text-red-700 line-through opacity-70' : 'text-slate-800'}">${p.nama_lengkap}</div>
                            <div class="text-[11px] font-medium mt-1 ${isTidakLanjut ? 'text-red-500' : 'text-slate-500'}">
                                <i class="fab fa-whatsapp mr-1"></i> ${p.nowa || '-'} &nbsp;|&nbsp; <i class="fas fa-book-open mr-1"></i> ${p.halaqoh || '-'}
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider ${isTidakLanjut ? 'text-red-600' : 'text-slate-400'}">
                                ${isTidakLanjut ? 'Tidak Lanjut' : 'Lanjut'}
                            </span>
                            <div class="relative inline-block w-12 h-6 align-middle select-none">
                                <input type="checkbox" id="toggle-${p.id}" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 appearance-none cursor-pointer" ${isTidakLanjut ? 'checked' : ''} onchange="toggleStatus(${p.id}, this.checked)">
                                <label for="toggle-${p.id}" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-300 cursor-pointer"></label>
                                <div class="toggle-dot absolute block bg-white"></div>
                            </div>
                        </div>
                    `;
                    searchResultContainer.appendChild(card);
                });
            }

            // 2. Event Listener untuk Live Search (dengan Debounce)
            let debounceTimer;
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const keyword = this.value.trim();
                debounceTimer = setTimeout(() => {
                    fetchPeserta(keyword);
                }, 300);
            });

            // 3. Fungsi Toggle (Ubah Status ke Database)
            window.toggleStatus = function(id, isChecked) {
                const statusLanjut = isChecked ? 'tidak' : 'ya'; // Jika dicentang = tidak lanjut
                
                const formData = new FormData();
                formData.append('id', id);
                formData.append('status_lanjut', statusLanjut);
                
                // Jika dikembalikan ke "ya", kosongkan alasan
                if (!isChecked) {
                    formData.append('alasan', '');
                }

                fetch('?ajax_action=toggle', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        fetchPeserta(searchInput.value.trim());
                        fetchListTidakLanjut();
                    }
                })
                .catch(err => console.error("Error toggle status:", err));
            }

            // 4. Fungsi Update Alasan (Real-time)
            window.updateAlasan = function(id, alasan) {
                const formData = new FormData();
                formData.append('id', id);
                formData.append('alasan', alasan);

                fetch('?ajax_action=update_alasan', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status !== 'success') {
                        console.error("Gagal memperbarui alasan");
                    }
                })
                .catch(err => console.error("Error updating alasan:", err));
            }

            // 5. Ambil dan Render List "Tidak Lanjut"
            function fetchListTidakLanjut() {
                fetch('?ajax_action=get_list')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            currentDataTidakLanjut = data.data;
                            renderListTidakLanjut(data.data);
                        }
                    })
                    .catch(err => console.error("Error fetching list:", err));
            }

            function renderListTidakLanjut(peserta) {
                tidakLanjutContainer.innerHTML = '';
                listCount.textContent = `${peserta.length} Data`;

                if (peserta.length === 0) {
                    tidakLanjutContainer.innerHTML = `<div class="text-center py-10 text-slate-400 text-sm">Belum ada peserta yang ditandai tidak lanjut.</div>`;
                    return;
                }

                peserta.forEach((p, index) => {
                    const item = document.createElement('div');
                    item.className = 'flex flex-col p-3 bg-white border border-slate-100 rounded-xl shadow-sm transition-all duration-200 hover:border-red-200';
                    item.innerHTML = `
                        <div class="flex items-start justify-between">
                            <div class="flex items-start flex-1">
                                <div class="bg-red-50 text-red-600 font-bold w-6 h-6 rounded-md flex items-center justify-center text-xs mr-3 mt-0.5 shrink-0">
                                    ${index + 1}
                                </div>
                                <div class="flex-1">
                                    <div class="font-bold text-sm text-slate-800">${p.nama_lengkap}</div>
                                    <div class="text-[11px] text-slate-500 font-medium mt-0.5">${p.nowa} &bull; ${p.halaqoh}</div>
                                </div>
                            </div>
                            <button onclick="toggleStatus(${p.id}, false)" class="text-slate-400 hover:text-red-500 text-xs p-2 transition" title="Batalkan (Ubah ke Lanjut)">
                                <i class="fas fa-undo"></i>
                            </button>
                        </div>
                        <!-- KOLOM INPUT ALASAN -->
                        <div class="mt-2 ml-9">
                            <input type="text" 
                                   value="${p.alasan_tidak_lanjut || ''}" 
                                   placeholder="Ketik alasan tidak lanjut..." 
                                   class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-red-500 focus:border-red-500 outline-none transition bg-slate-50 focus:bg-white"
                                   onchange="updateAlasan(${p.id}, this.value)"
                            >
                        </div>
                    `;
                    tidakLanjutContainer.appendChild(item);
                });
            }

            // 6. Fitur Copy to Clipboard (Dengan Format Alasan)
            copyListBtn.addEventListener('click', function() {
                if (currentDataTidakLanjut.length === 0) {
                    alert('Tidak ada data untuk disalin.');
                    return;
                }

                let textToCopy = "Daftar Peserta Konfirmasi Tidak Lanjut:\n\n";
                currentDataTidakLanjut.forEach((p, index) => {
                    // Format: 1. Nama (Halaqoh) (Alasan: ...)
                    const alasanText = p.alasan_tidak_lanjut ? ` (Alasan: ${p.alasan_tidak_lanjut})` : ' (Alasan: -)';
                    textToCopy += `${index + 1}. ${p.nama_lengkap} (${p.halaqoh})${alasanText}\n`;
                });

                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalHTML = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check mr-2"></i> Berhasil Disalin!';
                    this.classList.replace('bg-slate-800', 'bg-emerald-600');
                    this.classList.replace('hover:bg-slate-900', 'hover:bg-emerald-700');
                    
                    setTimeout(() => {
                        this.innerHTML = originalHTML;
                        this.classList.replace('bg-emerald-600', 'bg-slate-800');
                        this.classList.replace('hover:bg-emerald-700', 'hover:bg-slate-900');
                    }, 2000);
                }).catch(err => {
                    console.error('Gagal menyalin teks: ', err);
                    alert('Gagal menyalin data.');
                });
            });

            // Initialize Pertama Kali
            fetchPeserta('');
            fetchListTidakLanjut();
        });
    </script>
</body>
</html>