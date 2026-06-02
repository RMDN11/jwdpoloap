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
  <title>Reminder Form | Professional</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Raleway', sans-serif; background: #FDFDFD; }
    .minimal-input { border: 1px solid #E2E8F0; border-radius: 0; padding: 0.75rem; font-size: 0.875rem; width: 100%; transition: all 0.2s; }
    .minimal-input:focus { border-color: #000; outline: none; }
    .btn-dark { background: #111; color: #FFF; font-weight: 700; text-transform: uppercase; letter-spacing: 0.2em; font-size: 0.75rem; padding: 1rem; transition: background 0.2s; }
    .btn-dark:hover { background: #333; }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen p-6">
  <div class="w-full max-w-xl">
    <div class="bg-white border border-slate-200 p-10 shadow-sm">
        <header class="mb-10 text-center">
            <h1 class="text-2xl font-light tracking-[0.2em] text-slate-900 uppercase">Reminder Request</h1>
            <div class="w-8 h-px bg-slate-300 mx-auto mt-4"></div>
        </header>

        <?php if (!empty($notification)): ?>
        <div class="mb-8 p-4 bg-slate-50 text-slate-600 text-xs font-bold uppercase tracking-widest border border-slate-100">
            <?php echo htmlspecialchars($notification); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-8" id="request-form">
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Pilih Halaqoh</label>
                <select name="halaqoh" id="halaqoh" class="minimal-input bg-white" required>
                    <option value="" disabled selected>-- Select Group --</option>
                    <?php foreach ($halaqohList as $halaqoh): ?>
                        <option value="<?= htmlspecialchars($halaqoh) ?>"><?= htmlspecialchars($halaqoh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- (Logic JS tetap sama, hanya styling input-input di dalamnya perlu class 'minimal-input' dan 'btn-dark') -->
            <!-- ... Sisa form menggunakan class minimalis di atas ... -->
            <button type="submit" name="submit_all_requests" class="btn-dark w-full">Send Request</button>
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