<?php
require_once 'config.php';
$notification = '';
$notificationType = '';

// Ambil notifikasi dari session jika ada
if (isset($_SESSION)) {
    if (isset($_SESSION['notification'])) {
        $notification = $_SESSION['notification'];
        $notificationType = $_SESSION['notificationType'];
        unset($_SESSION['notification']);
        unset($_SESSION['notificationType']);
    }
} else {
    session_start();
    if (isset($_SESSION['notification'])) {
        $notification = $_SESSION['notification'];
        $notificationType = $_SESSION['notificationType'];
        unset($_SESSION['notification']);
        unset($_SESSION['notificationType']);
    }
}

// Ambil daftar Halaqoh
$halaqohList = [];
$halaqohResult = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh != '' ORDER BY halaqoh");
while ($row = $halaqohResult->fetch_assoc()) {
    $halaqohList[] = $row['halaqoh'];
}

// Proses pengiriman akhir (saat tombol "Kirim Semua Permintaan" diklik)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_all_requests'])) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $halaqoh = $_POST['halaqoh'] ?? '';
    $pesanPengajar = $_POST['pesan_pengajar'] ?? '';
    $selectedPeserta = json_decode($_POST['selected_peserta_json'], true);

    if (empty($halaqoh) || empty($selectedPeserta) || !is_array($selectedPeserta) || count($selectedPeserta) === 0) {
        $_SESSION['notification'] = "Gagal! Halaqoh dan setidaknya satu peserta harus dipilih.";
        $_SESSION['notificationType'] = 'error';
    } else {
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        foreach ($selectedPeserta as $pesertaData) {
            $pesertaId = $pesertaData['id'];
            $pesertaNama = $pesertaData['nama'];
            $pesertaNowa = $pesertaData['nowa'];

            // Validasi data peserta (opsional, tapi disarankan)
            $stmt = $conn->prepare("SELECT id, nama_lengkap, nowa FROM peserta WHERE id = ? AND halaqoh = ?");
            $stmt->bind_param("is", $pesertaId, $halaqoh);
            $stmt->execute();
            $result = $stmt->get_result();
            $validPeserta = $result->fetch_assoc();
            $stmt->close();

            if ($validPeserta) {
                $stmt_insert = $conn->prepare("INSERT INTO reminder_requests (halaqoh, peserta_id, peserta_nama, peserta_nowa, pesan_pengajar) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->bind_param("sisss", $halaqoh, $validPeserta['id'], $validPeserta['nama_lengkap'], $validPeserta['nowa'], $pesanPengajar);
                if ($stmt_insert->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                    $errors[] = "Gagal menyimpan permintaan untuk " . htmlspecialchars($validPeserta['nama_lengkap']) . ": " . $stmt_insert->error;
                }
                $stmt_insert->close();
            } else {
                $error_count++;
                $errors[] = "Peserta tidak valid atau tidak termasuk dalam halaqoh yang dipilih: " . htmlspecialchars($pesertaNama);
            }
        }

        if ($success_count > 0) {
            $msg = "Berhasil! $success_count permintaan reminder telah dikirim ke admin.";
            if ($error_count > 0) {
                $msg .= " Namun, $error_count permintaan gagal.";
            }
            $_SESSION['notification'] = $msg;
            $_SESSION['notificationType'] = 'success';
        } else {
            $_SESSION['notification'] = "Gagal! Tidak ada permintaan yang berhasil dikirim.";
            $_SESSION['notificationType'] = 'error';
        }
        if (!empty($errors)) {
            // Tambahkan detail error ke session jika perlu, atau log
            error_log("Errors in form_reminder_modern: " . print_r($errors, true));
        }

        // Redirect untuk mencegah re-submit saat refresh
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="antialiased selection:bg-[#E6FF00] selection:text-black">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
  <title>Form Reminder - JWD</title>
  <?php $cache_buster = time(); ?>
  <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
  
  <link href="https://api.fontshare.com/v2/css?f[]=clash-display@500,600,700&f[]=cabinet-grotesk@400,500,700&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/phosphor-icons/1.4.2/css/phosphor.css" rel="stylesheet">
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            clash: ['Clash Display', 'sans-serif'],
            cabinet: ['Cabinet Grotesk', 'sans-serif'],
          },
          colors: {
            matcha: '#E6FF00',
            onyx: '#0a0a0a',
            surface: '#171717',
            surfaceborder: '#27272a'
          },
          transitionTimingFunction: {
            // The Emil Kowalski snappy curve
            'emil': 'cubic-bezier(0.32, 0.72, 0, 1)',
          }
        }
      }
    }
  </script>

  <style>
    body {
      background-color: theme('colors.onyx');
      color: theme('colors.zinc.300');
      font-family: 'Cabinet Grotesk', sans-serif;
    }
    
    /* Hide scrollbar for a cleaner look */
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 10px; }
    
    #selected-participants-list { max-height: 250px; overflow-y: auto; }
    button, select, input, textarea { touch-action: manipulation; }
    input, select, textarea { font-size: 16px; }
    
    /* Custom glow effect */
    .glow-bg {
        position: absolute;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(230,255,0,0.05) 0%, rgba(10,10,10,0) 70%);
        top: -100px;
        right: -100px;
        z-index: 0;
        pointer-events: none;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 sm:p-6 relative overflow-hidden">
  
  <div class="w-full max-w-lg md:max-w-2xl relative z-10">
    <div class="bg-surface border border-surfaceborder rounded-3xl p-6 sm:p-10 relative overflow-hidden shadow-2xl">
        <div class="glow-bg"></div>
        
        <div class="flex items-center space-x-4 mb-8 pb-6 border-b border-zinc-800/50 relative z-10">
            <div class="w-12 h-12 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center p-2 shadow-inner">
                <img src="LOGOJWD.png?v=<?= $cache_buster ?>" alt="Logo JWD" class="w-full h-full object-contain filter grayscale contrast-125">
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-clash font-semibold text-white tracking-tight">Pengingat Peserta</h1>
                <p class="text-sm font-cabinet text-zinc-500 font-medium tracking-wide">Saling mengingatkan untuk kebaikan.</p>
            </div>
        </div>

        <?php if (!empty($notification)): ?>
        <div class="mb-6 p-4 rounded-xl text-sm font-medium border <?php echo $notificationType === 'success' ? 'bg-[#E6FF00]/10 text-[#E6FF00] border-[#E6FF00]/20' : 'bg-red-500/10 text-red-400 border-red-500/20'; ?> relative z-10">
            <?php echo htmlspecialchars($notification); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-6 relative z-10" id="request-form">
            
            <div class="group">
                <label for="halaqoh" class="block text-xs font-cabinet font-bold uppercase tracking-wider text-zinc-400 mb-2 group-focus-within:text-matcha transition-colors">Pilih Halaqoh</label>
                <select name="halaqoh" id="halaqoh" class="w-full bg-onyx border border-surfaceborder text-white rounded-xl p-3.5 focus:outline-none focus:border-matcha focus:ring-1 focus:ring-matcha transition-all duration-[250ms] ease-emil appearance-none" required>
                    <option value="" disabled selected>-- Tentukan halaqoh Anda --</option>
                    <?php foreach ($halaqohList as $halaqoh): ?>
                        <option value="<?= htmlspecialchars($halaqoh) ?>"><?= htmlspecialchars($halaqoh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="peserta-section" class="hidden space-y-6">
                
                <div class="relative group">
                    <label for="search-peserta" class="block text-xs font-cabinet font-bold uppercase tracking-wider text-zinc-400 mb-2 group-focus-within:text-matcha transition-colors">Cari Peserta</label>
                    <div class="relative">
                        <i class="ph-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-zinc-500 text-lg"></i>
                        <input type="text" id="search-peserta" placeholder="Ketik minimal 2 huruf..." class="w-full bg-onyx border border-surfaceborder text-white rounded-xl py-3.5 pl-11 pr-4 focus:outline-none focus:border-matcha focus:ring-1 focus:ring-matcha transition-all duration-[250ms] ease-emil" autocomplete="off" disabled>
                    </div>
                    
                    <div id="suggestions-list" class="absolute z-20 w-full mt-2 bg-zinc-900 border border-zinc-800 rounded-xl shadow-2xl hidden max-h-52 overflow-y-auto backdrop-blur-md"></div>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-xs font-cabinet font-bold uppercase tracking-wider text-zinc-400">Terpilih (<span id="selected-count" class="text-matcha">0</span>)</label>
                        <button type="button" id="remove-all-btn" class="text-xs font-bold font-cabinet text-red-500 hover:text-red-400 uppercase tracking-wider transition-colors disabled:opacity-0 hidden">
                            Bersihkan
                        </button>
                    </div>
                    <div id="selected-participants-list" class="border border-surfaceborder rounded-xl p-4 bg-onyx space-y-2 min-h-[80px] flex flex-col justify-center">
                        <p class="text-zinc-600 text-sm text-center font-medium">Belum ada target yang dipilih.</p>
                    </div>
                </div>
            </div>

            <div id="pesan-section" class="hidden space-y-6 pt-2">
                <div class="group">
                    <label for="pesan-pengajar" class="block text-xs font-cabinet font-bold uppercase tracking-wider text-zinc-400 mb-2 group-focus-within:text-matcha transition-colors">Catatan Ekstra (Opsional)</label>
                    <textarea id="pesan-pengajar" name="pesan_pengajar" rows="3" placeholder="Misal: Sudah 4 hari absen setoran hafalan..." class="w-full bg-onyx border border-surfaceborder text-white rounded-xl p-4 focus:outline-none focus:border-matcha focus:ring-1 focus:ring-matcha transition-all duration-[250ms] ease-emil resize-none"></textarea>
                </div>

                <input type="hidden" name="selected_peserta_json" id="selected-peserta-json" value="">
                
                <div class="flex flex-col sm:flex-row gap-3 pt-2">
                    <button type="button" id="add-another-btn" class="flex-1 bg-zinc-800 hover:bg-zinc-700 text-white p-4 rounded-xl font-clash font-semibold text-base transition-all duration-[250ms] ease-emil active:scale-[0.98] flex items-center justify-center disabled:opacity-40 disabled:cursor-not-allowed border border-zinc-700" disabled>
                        <i class="ph-plus-bold text-lg mr-2"></i> Tambah Target
                    </button>
                    
                    <button type="submit" name="submit_all_requests" id="submit-all-btn" class="flex-[2] bg-matcha hover:bg-[#d4eb00] text-black p-4 rounded-xl font-clash font-semibold text-lg transition-all duration-[250ms] ease-emil active:scale-[0.98] flex items-center justify-center disabled:opacity-40 disabled:cursor-not-allowed shadow-[0_0_20px_rgba(230,255,0,0.15)]" disabled>
                        <i class="ph-paper-plane-tilt-bold text-xl mr-2"></i> Kirim Notifikasi
                    </button>
                </div>
            </div>
        </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        const halaqohSelect = document.getElementById('halaqoh');
        const pesertaSection = document.getElementById('peserta-section');
        const searchInput = document.getElementById('search-peserta');
        const suggestionsList = document.getElementById('suggestions-list');
        const selectedParticipantsList = document.getElementById('selected-participants-list');
        const addAnotherBtn = document.getElementById('add-another-btn');
        const removeAllBtn = document.getElementById('remove-all-btn');
        const submitAllBtn = document.getElementById('submit-all-btn');
        const pesanSection = document.getElementById('pesan-section');
        const selectedPesertaJsonInput = document.getElementById('selected-peserta-json');
        const selectedCountSpan = document.getElementById('selected-count');

        let selectedParticipants = [];
        let searchTimeout;

        function updateSelectedList() {
            selectedParticipantsList.innerHTML = '';
            if (selectedParticipants.length === 0) {
                selectedParticipantsList.innerHTML = '<p class="text-zinc-600 text-sm text-center font-medium">Belum ada target yang dipilih.</p>';
                selectedParticipantsList.classList.add('flex', 'flex-col', 'justify-center');
                submitAllBtn.disabled = true;
                pesanSection.classList.add('hidden');
                removeAllBtn.classList.add('hidden');
                selectedCountSpan.innerText = '0';
            } else {
                selectedParticipantsList.classList.remove('flex', 'flex-col', 'justify-center');
                removeAllBtn.classList.remove('hidden');
                
                selectedParticipants.forEach((peserta, index) => {
                    const div = document.createElement('div');
                    div.className = 'flex justify-between items-center bg-zinc-900/80 p-3 rounded-lg border border-zinc-800/50 hover:border-zinc-700 transition-all duration-[250ms] ease-emil group';
                    
                    div.innerHTML = `
                        <span class="text-zinc-200 text-sm font-medium tracking-wide break-all pr-3">${escapeHtml(peserta.nama)}</span>
                        <button type="button" class="btn-remove text-zinc-500 hover:text-red-400 p-1.5 rounded-md hover:bg-red-500/10 transition-all duration-[250ms] ease-emil active:scale-[0.9]" data-index="${index}" title="Hapus">
                            <i class="ph-x-bold pointer-events-none"></i>
                        </button>
                    `;
                    selectedParticipantsList.appendChild(div);
                });
                submitAllBtn.disabled = false;
                pesanSection.classList.remove('hidden');
                selectedCountSpan.innerText = selectedParticipants.length;
            }
            selectedPesertaJsonInput.value = JSON.stringify(selectedParticipants);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        removeAllBtn.addEventListener('click', function() {
            selectedParticipants = [];
            updateSelectedList();
        });

        selectedParticipantsList.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-remove')) {
                const index = parseInt(e.target.getAttribute('data-index'));
                if (!isNaN(index) && index >= 0 && index < selectedParticipants.length) {
                    selectedParticipants.splice(index, 1);
                    updateSelectedList();
                }
            }
        });

        halaqohSelect.addEventListener('change', function() {
            const selectedHalaqoh = this.value;
            if (selectedHalaqoh) {
                pesertaSection.classList.remove('hidden');
                searchInput.disabled = false;
                searchInput.value = '';
                suggestionsList.innerHTML = '';
                suggestionsList.classList.add('hidden');
                selectedParticipants = [];
                updateSelectedList();
            } else {
                pesertaSection.classList.add('hidden');
                searchInput.disabled = true;
                selectedParticipants = [];
                updateSelectedList();
                pesanSection.classList.add('hidden');
            }
        });

        function performSearch(query, halaqoh) {
            suggestionsList.innerHTML = '<p class="text-sm text-zinc-500 p-4 text-center">Mencari target...</p>';
            suggestionsList.classList.remove('hidden');
            
            fetch(`search_peserta.php?term=${encodeURIComponent(query)}&halaqoh=${encodeURIComponent(halaqoh)}`)
                .then(response => response.ok ? response.json() : Promise.reject())
                .then(data => {
                    suggestionsList.innerHTML = '';
                    if (data.length > 0) {
                        const ul = document.createElement('ul');
                        ul.className = 'p-2 space-y-1';
                        let hasSelectable = false;
                        
                        data.forEach(peserta => {
                            if (!selectedParticipants.some(p => p.id === peserta.id)) {
                                const li = document.createElement('li');
                                li.className = 'p-3 rounded-lg cursor-pointer text-zinc-300 hover:bg-zinc-800 hover:text-white transition-all duration-[200ms] ease-out';
                                li.innerHTML = `<div class="font-medium text-sm">${escapeHtml(peserta.nama_lengkap)}</div>`;
                                li.dataset.id = peserta.id;
                                li.dataset.nama = peserta.nama_lengkap;
                                li.dataset.nowa = peserta.nowa || '';
                                ul.appendChild(li);
                                hasSelectable = true;
                            }
                        });
                        
                        if (hasSelectable) {
                            suggestionsList.appendChild(ul);
                            addAnotherBtn.disabled = false;
                        } else {
                            suggestionsList.innerHTML = '<p class="text-sm text-zinc-500 p-4 text-center">Semua peserta dari halaqoh ini sudah dipilih.</p>';
                            addAnotherBtn.disabled = true;
                        }
                    } else {
                        suggestionsList.innerHTML = '<p class="text-sm text-zinc-500 p-4 text-center">Target tidak ditemukan.</p>';
                        addAnotherBtn.disabled = true;
                    }
                })
                .catch(() => {
                    suggestionsList.innerHTML = '<p class="text-sm text-red-400 p-4 text-center">Gagal memuat intel data.</p>';
                    addAnotherBtn.disabled = true;
                });
        }

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            const selectedHalaqoh = halaqohSelect.value;
            clearTimeout(searchTimeout);
            
            if (query.length < 2 || !selectedHalaqoh) {
                suggestionsList.classList.add('hidden');
                addAnotherBtn.disabled = (selectedParticipants.length === 0);
                return;
            }
            searchTimeout = setTimeout(() => performSearch(query, selectedHalaqoh), 300);
        });

        suggestionsList.addEventListener('click', function(e) {
            let target = e.target.closest('li');
            if (target && target.dataset.id) {
                const id = target.dataset.id;
                const nama = target.dataset.nama;
                const nowa = target.dataset.nowa;
                
                if (!selectedParticipants.some(p => p.id === id)) {
                    selectedParticipants.push({ id: id, nama: nama, nowa: nowa });
                    updateSelectedList();
                }
                
                searchInput.value = '';
                suggestionsList.innerHTML = '';
                suggestionsList.classList.add('hidden');
                addAnotherBtn.disabled = true;
            }
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !suggestionsList.contains(e.target)) {
                suggestionsList.classList.add('hidden');
            }
        });

        addAnotherBtn.addEventListener('click', function() {
            searchInput.focus();
            addAnotherBtn.disabled = true;
        });

        addAnotherBtn.disabled = true;
    });
  </script>
</body>
</html>