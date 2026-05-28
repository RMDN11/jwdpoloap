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
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
  <title>Form Reminder - JWD</title>
  <?php $cache_buster = time(); ?>
  <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/phosphor-icons/1.4.2/css/phosphor.css" rel="stylesheet">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body {
      font-family: 'Inter', sans-serif;
      background-color: #f9fafb;
    }
    .card-minimal {
      box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px 0 rgba(0, 0, 0, 0.03);
      border-radius: 1rem;
    }
    .btn-primary {
      background-color: #4b5563;
      transition: background-color 0.2s;
    }
    .btn-primary:hover {
      background-color: #374151;
    }
    .btn-add {
      background-color: #3b82f6;
    }
    .btn-add:hover {
      background-color: #2563eb;
    }
    .btn-remove {
      background-color: #ef4444;
      padding: 0.25rem 0.75rem;
      border-radius: 0.375rem;
      font-size: 0.75rem;
    }
    .btn-remove:hover {
      background-color: #dc2626;
    }
    #suggestions-list li {
      transition: background-color 0.15s;
    }
    #suggestions-list li:active {
      background-color: #e5e7eb;
    }
    #selected-participants-list {
      max-height: 200px;
      overflow-y: auto;
    }
    button, .btn-remove, select, input, textarea {
      touch-action: manipulation;
    }
    input, select, textarea {
      font-size: 16px;
    }
    @media (max-width: 640px) {
      .container-padding {
        padding-left: 1rem;
        padding-right: 1rem;
      }
    }
  </style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen p-3 sm:p-4">
  <div class="w-full max-w-lg md:max-w-2xl">
    <div class="bg-white rounded-xl card-minimal p-5 sm:p-8">
        <!-- Header dengan Logo dan Judul -->
        <div class="flex items-center space-x-3 sm:space-x-4 mb-6 pb-2 border-b border-gray-100">
            <img src="LOGOJWD.png?v=<?= $cache_buster ?>" alt="Logo JWD" class="w-10 h-10 sm:w-12 sm:h-12 object-contain rounded-full bg-gray-50 p-1">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 leading-tight">Pengingat untuk Peserta</h1>
                <p class="text-xs sm:text-sm text-gray-500">Saling mengingatkan untuk kebaikan</p>
            </div>
        </div>

        <?php if (!empty($notification)): ?>
        <div class="mb-5 p-3 rounded-md text-sm <?php echo $notificationType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
            <?php echo htmlspecialchars($notification); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-5" id="request-form">
            <div>
                <label for="halaqoh" class="block text-sm font-medium text-gray-700 mb-1">Halaqoh</label>
                <select name="halaqoh" id="halaqoh" class="w-full border border-gray-300 rounded-md p-2.5 focus:outline-none focus:ring-1 focus:ring-gray-400 focus:border-gray-400 bg-white text-sm" required>
                    <option value="" disabled selected>-- Pilih Halaqoh Anda --</option>
                    <?php foreach ($halaqohList as $halaqoh): ?>
                        <option value="<?= htmlspecialchars($halaqoh) ?>"><?= htmlspecialchars($halaqoh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="peserta-section" class="hidden">
                <div class="relative mb-4">
                    <label for="search-peserta" class="block text-sm font-medium text-gray-700 mb-1">Cari Nama Peserta</label>
                    <input type="text" id="search-peserta" placeholder="Ketik minimal 2 huruf..." class="w-full border border-gray-300 rounded-md p-2.5 focus:outline-none focus:ring-1 focus:ring-gray-400 text-sm" autocomplete="off" disabled>
                    <div id="suggestions-list" class="absolute z-10 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-sm hidden max-h-52 overflow-y-auto"></div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Peserta Terpilih (<span id="selected-count">0</span>)</label>
                    <div id="selected-participants-list" class="border border-gray-200 rounded-md p-3 bg-gray-50 text-sm space-y-2">
                        <p class="text-gray-500 text-sm">Belum ada peserta dipilih.</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 mb-5">
                    <button type="button" id="add-another-btn" class="btn-add px-4 py-2 rounded-md shadow-sm font-medium text-sm text-white transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center" disabled>
                        <i class="ph-plus-bold text-sm mr-1"></i> Tambah Peserta
                    </button>
                    <button type="button" id="remove-all-btn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md shadow-sm font-medium text-sm transition flex items-center">
                        <i class="ph-trash text-sm mr-1"></i> Hapus Semua
                    </button>
                </div>
            </div>

            <div id="pesan-section" class="hidden">
                <div>
                    <label for="pesan-pengajar" class="block text-sm font-medium text-gray-700 mb-1">Pesan Tambahan (Opsional)</label>
                    <textarea id="pesan-pengajar" name="pesan_pengajar" rows="3" placeholder="Contoh: Sudah 4 hari tidak setoran" class="w-full border border-gray-300 rounded-md p-2.5 focus:outline-none focus:ring-1 focus:ring-gray-400 text-sm"></textarea>
                </div>

                <input type="hidden" name="selected_peserta_json" id="selected-peserta-json" value="">
                <div class="pt-2">
                    <button type="submit" name="submit_all_requests" id="submit-all-btn" class="w-full btn-primary text-white px-4 py-2.5 rounded-md shadow-sm font-semibold text-base transition flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                        <i class="ph-paper-plane-tilt text-lg mr-2"></i> Kirim Semua Permintaan
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
                selectedParticipantsList.innerHTML = '<p class="text-gray-500 text-sm">Belum ada peserta dipilih.</p>';
                submitAllBtn.disabled = true;
                pesanSection.classList.add('hidden');
                selectedCountSpan.innerText = '0';
            } else {
                selectedParticipants.forEach((peserta, index) => {
                    const p = document.createElement('p');
                    p.className = 'flex justify-between items-center bg-white p-2 rounded border border-gray-200';
                    // Hanya menampilkan nama, tanpa nomor WA
                    p.innerHTML = `
                        <span class="text-gray-800 text-sm break-all pr-2">${escapeHtml(peserta.nama)}</span>
                        <button type="button" class="btn-remove text-white font-medium" data-index="${index}">Hapus</button>
                    `;
                    selectedParticipantsList.appendChild(p);
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
            suggestionsList.innerHTML = '<p class="text-sm text-gray-500 p-3 text-center">Mencari...</p>';
            suggestionsList.classList.remove('hidden');
            fetch(`search_peserta.php?term=${encodeURIComponent(query)}&halaqoh=${encodeURIComponent(halaqoh)}`)
                .then(response => response.ok ? response.json() : Promise.reject())
                .then(data => {
                    suggestionsList.innerHTML = '';
                    if (data.length > 0) {
                        const ul = document.createElement('ul');
                        ul.className = 'p-1';
                        let hasSelectable = false;
                        data.forEach(peserta => {
                            if (!selectedParticipants.some(p => p.id === peserta.id)) {
                                const li = document.createElement('li');
                                li.className = 'p-2 rounded-md cursor-pointer hover:bg-gray-100 active:bg-gray-200 transition';
                                // Hanya menampilkan nama, tanpa nomor WA
                                li.innerHTML = `<div class="font-medium text-gray-800 text-sm">${escapeHtml(peserta.nama_lengkap)}</div>`;
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
                            suggestionsList.innerHTML = '<p class="text-sm text-gray-500 p-3 text-center">Semua peserta dari halaqoh ini sudah dipilih.</p>';
                            addAnotherBtn.disabled = true;
                        }
                    } else {
                        suggestionsList.innerHTML = '<p class="text-sm text-gray-500 p-3 text-center">Tidak ditemukan peserta.</p>';
                        addAnotherBtn.disabled = true;
                    }
                })
                .catch(() => {
                    suggestionsList.innerHTML = '<p class="text-sm text-red-500 p-3 text-center">Gagal memuat data.</p>';
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
            searchTimeout = setTimeout(() => performSearch(query, selectedHalaqoh), 250);
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