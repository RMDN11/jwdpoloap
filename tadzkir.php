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
<html lang="id" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
  <title>Form Reminder - JWD</title>
  <?php $cache_buster = time(); ?>
  <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
  
  <!-- Font Geist by Vercel -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/geist@1.0.3/dist/fonts/geist-sans/style.css">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/phosphor-icons/1.4.2/css/phosphor.css" rel="stylesheet">
  
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {
            sans: ['Geist', 'sans-serif'],
          },
          colors: {
            brand: {
              lime: '#E6FF00', // Akses warna bold ala neo-brutalism/modern tech
              dark: '#0A0A0A',
              card: '#121212',
              border: '#27272A'
            }
          },
          animation: {
            'reveal': 'reveal 250ms cubic-bezier(0.32, 0.72, 0, 1) forwards',
          },
          keyframes: {
            reveal: {
              '0%': { opacity: '0', transform: 'translateY(8px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' },
            }
          }
        }
      }
    }
  </script>

  <style>
    body {
      background-color: theme('colors.brand.dark');
      color: #EDEDED;
    }

    /* Emil Kowalski's smooth interactions */
    .smooth-trans {
      transition: all 250ms cubic-bezier(0.32, 0.72, 0, 1);
    }

    /* Accessibility: Instant focus for keyboard, no lag */
    *:focus-visible {
      transition: none !important;
      outline: 2px solid theme('colors.brand.lime');
      outline-offset: 2px;
    }

    /* Custom Scrollbar for list */
    ::-webkit-scrollbar {
      width: 6px;
    }
    ::-webkit-scrollbar-track {
      background: transparent;
    }
    ::-webkit-scrollbar-thumb {
      background: theme('colors.zinc.800');
      border-radius: 4px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: theme('colors.zinc.700');
    }

    input, select, textarea, button {
      touch-action: manipulation;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 sm:p-6 selection:bg-brand-lime selection:text-black">
  
  <div class="w-full max-w-lg md:max-w-xl">
    <div class="bg-brand-card border border-brand-border rounded-2xl shadow-2xl p-6 sm:p-8 smooth-trans">
        
        <!-- Header -->
        <div class="flex items-center space-x-4 mb-8 pb-4 border-b border-brand-border">
            <div class="w-12 h-12 rounded-xl bg-zinc-900 border border-zinc-800 flex items-center justify-center p-2">
                <img src="LOGOJWD.png?v=<?= $cache_buster ?>" alt="Logo JWD" class="w-full h-full object-contain">
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-white tracking-tight">Pengingat Peserta</h1>
                <p class="text-sm text-zinc-400 mt-0.5">Saling mengingatkan dalam kebaikan.</p>
            </div>
        </div>

        <!-- Notification -->
        <?php if (!empty($notification)): ?>
        <div class="mb-6 p-4 rounded-xl text-sm font-medium animate-reveal <?php echo $notificationType === 'success' ? 'bg-lime-500/10 text-lime-400 border border-lime-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'; ?>">
            <?php echo htmlspecialchars($notification); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-6" id="request-form">
            
            <!-- Select Halaqoh -->
            <div class="group">
                <label for="halaqoh" class="block text-sm font-medium text-zinc-400 mb-2">Halaqoh</label>
                <select name="halaqoh" id="halaqoh" class="w-full bg-zinc-900 border border-brand-border rounded-xl p-3.5 text-zinc-100 text-sm smooth-trans focus:border-brand-lime hover:border-zinc-700 appearance-none cursor-pointer" required>
                    <option value="" disabled selected>-- Pilih Halaqoh Anda --</option>
                    <?php foreach ($halaqohList as $halaqoh): ?>
                        <option value="<?= htmlspecialchars($halaqoh) ?>"><?= htmlspecialchars($halaqoh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Peserta Section -->
            <div id="peserta-section" class="hidden">
                <div class="relative mb-6">
                    <label for="search-peserta" class="block text-sm font-medium text-zinc-400 mb-2">Cari Nama Peserta</label>
                    <input type="text" id="search-peserta" placeholder="Ketik minimal 2 huruf..." class="w-full bg-zinc-900 border border-brand-border rounded-xl p-3.5 text-zinc-100 text-sm smooth-trans focus:border-brand-lime placeholder-zinc-600" autocomplete="off" disabled>
                    
                    <div id="suggestions-list" class="absolute z-20 w-full mt-2 bg-zinc-900 border border-zinc-800 rounded-xl shadow-xl hidden max-h-52 overflow-y-auto smooth-trans"></div>
                </div>

                <div class="mb-6">
                    <div class="flex justify-between items-end mb-2">
                        <label class="block text-sm font-medium text-zinc-400">Peserta Terpilih</label>
                        <span class="text-xs font-bold bg-zinc-800 text-zinc-300 px-2 py-1 rounded-md" id="selected-count">0</span>
                    </div>
                    <div id="selected-participants-list" class="border border-brand-border rounded-xl p-4 bg-zinc-900/50 text-sm space-y-2 min-h-[60px] max-h-48 overflow-y-auto">
                        <p class="text-zinc-500 text-sm italic">Belum ada peserta dipilih.</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 mb-2">
                    <button type="button" id="add-another-btn" class="smooth-trans px-4 py-2.5 rounded-xl text-sm font-medium flex items-center bg-zinc-800 text-brand-lime hover:bg-zinc-700 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-zinc-800" disabled>
                        <i class="ph-plus-bold mr-2 text-lg"></i> Tambah Peserta
                    </button>
                    <button type="button" id="remove-all-btn" class="smooth-trans px-4 py-2.5 rounded-xl text-sm font-medium flex items-center bg-rose-500/10 text-rose-500 hover:bg-rose-500/20">
                        <i class="ph-trash mr-2 text-lg"></i> Hapus Semua
                    </button>
                </div>
            </div>

            <!-- Pesan Section -->
            <div id="pesan-section" class="hidden">
                <div class="pt-4 border-t border-brand-border">
                    <label for="pesan-pengajar" class="block text-sm font-medium text-zinc-400 mb-2">Pesan Tambahan <span class="text-zinc-600">(Opsional)</span></label>
                    <textarea id="pesan-pengajar" name="pesan_pengajar" rows="3" placeholder="Contoh: Sudah 4 hari tidak setoran..." class="w-full bg-zinc-900 border border-brand-border rounded-xl p-3.5 text-zinc-100 text-sm smooth-trans focus:border-brand-lime placeholder-zinc-600 resize-none"></textarea>
                </div>

                <input type="hidden" name="selected_peserta_json" id="selected-peserta-json" value="">
                
                <div class="pt-6">
                    <button type="submit" name="submit_all_requests" id="submit-all-btn" class="w-full bg-brand-lime text-black px-4 py-3.5 rounded-xl shadow-lg font-bold text-base smooth-trans flex items-center justify-center hover:bg-[#cce600] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100" disabled>
                        <i class="ph-paper-plane-tilt-bold text-xl mr-2"></i> Kirim Permintaan
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

        // Emil Kowalski style reveal logic
        function smoothReveal(element) {
            if (element.classList.contains('hidden')) {
                element.classList.remove('hidden');
                // Force reflow
                void element.offsetWidth;
                element.classList.add('animate-reveal');
            }
        }

        function updateSelectedList() {
            selectedParticipantsList.innerHTML = '';
            if (selectedParticipants.length === 0) {
                selectedParticipantsList.innerHTML = '<p class="text-zinc-500 text-sm italic">Belum ada peserta dipilih.</p>';
                submitAllBtn.disabled = true;
                pesanSection.classList.add('hidden');
                pesanSection.classList.remove('animate-reveal');
                selectedCountSpan.innerText = '0';
            } else {
                selectedParticipants.forEach((peserta, index) => {
                    const p = document.createElement('div');
                    p.className = 'flex justify-between items-center bg-zinc-800/50 p-2.5 rounded-lg border border-zinc-700/50 animate-reveal';
                    p.innerHTML = `
                        <span class="text-zinc-200 text-sm break-all font-medium pr-2">${escapeHtml(peserta.nama)}</span>
                        <button type="button" class="btn-remove smooth-trans text-zinc-400 hover:text-rose-400 p-1 rounded-md hover:bg-rose-500/10 focus-visible:ring-2 focus-visible:ring-rose-400" data-index="${index}">
                            <i class="ph-x-bold pointer-events-none"></i>
                        </button>
                    `;
                    selectedParticipantsList.appendChild(p);
                });
                submitAllBtn.disabled = false;
                smoothReveal(pesanSection);
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
            // Cek jika yang diklik adalah button atau icon di dalamnya
            const btn = e.target.closest('.btn-remove');
            if (btn) {
                const index = parseInt(btn.getAttribute('data-index'));
                if (!isNaN(index) && index >= 0 && index < selectedParticipants.length) {
                    selectedParticipants.splice(index, 1);
                    updateSelectedList();
                }
            }
        });

        halaqohSelect.addEventListener('change', function() {
            const selectedHalaqoh = this.value;
            if (selectedHalaqoh) {
                smoothReveal(pesertaSection);
                searchInput.disabled = false;
                searchInput.value = '';
                suggestionsList.innerHTML = '';
                suggestionsList.classList.add('hidden');
                selectedParticipants = [];
                updateSelectedList();
                
                addAnotherBtn.disabled = false; // 👈 TAMBAHKAN INI: Tombol langsung aktif
            } else {
                pesertaSection.classList.add('hidden');
                pesertaSection.classList.remove('animate-reveal');
                searchInput.disabled = true;
                selectedParticipants = [];
                updateSelectedList();
                pesanSection.classList.add('hidden');
                
                addAnotherBtn.disabled = true; // 👈 TAMBAHKAN INI: Tombol mati kalau belum milih
            }
        });

        function performSearch(query, halaqoh) {
            suggestionsList.innerHTML = '<p class="text-sm text-zinc-500 p-4 text-center animate-pulse">Mencari data...</p>';
            smoothReveal(suggestionsList);
            
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
                                li.className = 'p-3 rounded-lg cursor-pointer smooth-trans hover:bg-zinc-800 active:bg-zinc-700 text-zinc-300 hover:text-white';
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
                        suggestionsList.innerHTML = '<p class="text-sm text-zinc-500 p-4 text-center">Tidak ada peserta yang cocok.</p>';
                        addAnotherBtn.disabled = true;
                    }
                })
                .catch(() => {
                    suggestionsList.innerHTML = '<p class="text-sm text-rose-500 p-4 text-center">Gagal memuat data.</p>';
                    addAnotherBtn.disabled = true;
                });
        }

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            const selectedHalaqoh = halaqohSelect.value;
            clearTimeout(searchTimeout);
            
            if (query.length < 2 || !selectedHalaqoh) {
                suggestionsList.classList.add('hidden');
                suggestionsList.classList.remove('animate-reveal');
                // Baris "addAnotherBtn.disabled = ..." DIHAPUS DARI SINI
                return;
            }
            searchTimeout = setTimeout(() => performSearch(query, selectedHalaqoh), 250);
        });

        addAnotherBtn.addEventListener('click', function() {
            searchInput.focus();
            // Baris "addAnotherBtn.disabled = true;" DIHAPUS DARI SINI
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
                
                addAnotherBtn.disabled = false; // 👈 UBAH JADI FALSE: Biar siap nambah lagi
            }
        }); 

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !suggestionsList.contains(e.target)) {
                suggestionsList.classList.add('hidden');
                suggestionsList.classList.remove('animate-reveal');
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