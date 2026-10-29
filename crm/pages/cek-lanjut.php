<?php
declare(strict_types=1);

$crmTitle = 'Konfirmasi Lanjut';

// Auto migration
if (isset($conn) && $conn instanceof mysqli) {
    $checkCol = $conn->query("SHOW COLUMNS FROM peserta LIKE 'status_lanjut'");
    if ($checkCol && $checkCol->num_rows === 0) {
        $conn->query("ALTER TABLE peserta ADD COLUMN status_lanjut VARCHAR(10) DEFAULT 'ya'");
    }
    $checkColAlasan = $conn->query("SHOW COLUMNS FROM peserta LIKE 'alasan_tidak_lanjut'");
    if ($checkColAlasan && $checkColAlasan->num_rows === 0) {
        $conn->query("ALTER TABLE peserta ADD COLUMN alasan_tidak_lanjut TEXT");
    }
}

// Ambil list halaqoh unik untuk opsi filter
$halaqohList = [];
if (isset($conn) && $conn instanceof mysqli) {
    $resHalaqoh = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh != '' ORDER BY halaqoh ASC");
    if ($resHalaqoh) {
        while ($hRow = $resHalaqoh->fetch_assoc()) {
            $hVal = trim((string)$hRow['halaqoh']);
            if ($hVal !== '') {
                $halaqohList[] = $hVal;
            }
        }
    }
}
?>

<section class="page-head">
    <span class="eyebrow">Peserta</span>
    <h1>Konfirmasi Lanjut</h1>
    <p>Tandai peserta yang tidak melanjutkan program dan kelola catatannya.</p>
</section>

<div class="cek-lanjut-grid">
    <!-- KOLOM KIRI: Pencarian & Daftar Semua Peserta -->
    <div class="cek-lanjut-card">
        <div class="cek-lanjut-card-head">
            <h2>
                <i class="fa-solid fa-users-viewfinder text-emerald-700"></i>
                Pencarian Peserta
            </h2>
            <span id="searchCount" class="cek-lanjut-badge">0 Peserta</span>
        </div>

        <div class="cek-lanjut-search-bar">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="searchInput" placeholder="Cari nama atau nomor WhatsApp..." autocomplete="off">
        </div>

        <?php if (!empty($halaqohList)): ?>
        <div class="cek-lanjut-filters">
            <select id="halaqohFilter" class="cek-lanjut-select">
                <option value="">Semua Halaqoh</option>
                <?php foreach ($halaqohList as $h): ?>
                    <option value="<?= htmlspecialchars($h) ?>"><?= htmlspecialchars($h) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div id="searchResultContainer" class="cek-lanjut-list-container">
            <div class="cek-lanjut-empty">
                <i class="fa-solid fa-magnifying-glass"></i>
                Ketik nama peserta atau pilih halaqoh untuk mencari.
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: Daftar Peserta Tidak Lanjut -->
    <div class="cek-lanjut-card">
        <div class="cek-lanjut-card-head">
            <h2>
                <i class="fa-solid fa-user-xmark" style="color: #dc2626;"></i>
                List Tidak Lanjut
            </h2>
            <span id="listCount" class="cek-lanjut-badge danger">0 Data</span>
        </div>

        <div id="tidakLanjutContainer" class="cek-lanjut-list-container" style="flex: 1;">
            <div class="cek-lanjut-empty">
                <i class="fa-solid fa-spinner fa-spin"></i>
                Memuat data...
            </div>
        </div>

        <button id="copyListBtn" class="cek-lanjut-copy-btn">
            <i class="fa-regular fa-copy"></i>
            <span>Salin List Nama & Alasan</span>
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const halaqohFilter = document.getElementById('halaqohFilter');
    const searchResultContainer = document.getElementById('searchResultContainer');
    const tidakLanjutContainer = document.getElementById('tidakLanjutContainer');
    const searchCount = document.getElementById('searchCount');
    const listCount = document.getElementById('listCount');
    const copyListBtn = document.getElementById('copyListBtn');

    let currentDataTidakLanjut = [];
    let debounceTimer = null;

    // 1. Fetch Search Results
    function fetchPeserta() {
        const keyword = searchInput ? searchInput.value.trim() : '';
        const halaqoh = halaqohFilter ? halaqohFilter.value.trim() : '';

        const params = new URLSearchParams({
            page: 'cek-lanjut',
            ajax_action: 'search',
            keyword: keyword,
            halaqoh: halaqoh
        });

        fetch('index.php?' + params.toString())
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
                <div class="cek-lanjut-empty">
                    <i class="fa-regular fa-folder-open"></i>
                    ${keyword ? 'Peserta tidak ditemukan.' : 'Tidak ada data peserta.'}
                </div>`;
            return;
        }

        peserta.forEach(p => {
            const isTidakLanjut = p.status_lanjut === 'tidak';
            const card = document.createElement('div');
            card.className = `cek-lanjut-item ${isTidakLanjut ? 'is-tidak-lanjut' : ''}`;

            const phoneFormatted = (p.nowa || '').replace(/[^0-9]/g, '');
            const waLink = phoneFormatted ? `https://wa.me/${phoneFormatted}` : '#';

            card.innerHTML = `
                <div class="cek-lanjut-item-info">
                    <div class="cek-lanjut-name">${escapeHtml(p.nama_lengkap || '-')}</div>
                    <div class="cek-lanjut-meta">
                        <span>
                            <i class="fa-brands fa-whatsapp text-emerald-600"></i>
                            ${phoneFormatted ? `<a href="${waLink}" target="_blank">${escapeHtml(p.nowa)}</a>` : '-'}
                        </span>
                        <span>•</span>
                        <span>
                            <i class="fa-solid fa-book-open"></i>
                            ${escapeHtml(p.halaqoh || 'Belum ada')}
                        </span>
                    </div>
                </div>
                <div class="cek-lanjut-toggle-box">
                    <span class="cek-lanjut-status-label ${isTidakLanjut ? 'tidak' : ''}">
                        ${isTidakLanjut ? 'Tidak Lanjut' : 'Lanjut'}
                    </span>
                    <label class="cek-lanjut-toggle">
                        <input type="checkbox" ${isTidakLanjut ? 'checked' : ''} data-id="${p.id}" class="toggle-status-checkbox">
                        <span class="cek-lanjut-slider"></span>
                    </label>
                </div>
            `;

            const chk = card.querySelector('.toggle-status-checkbox');
            chk.addEventListener('change', function() {
                toggleStatus(p.id, this.checked);
            });

            searchResultContainer.appendChild(card);
        });
    }

    // 2. Debounced search input
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(fetchPeserta, 300);
        });
    }

    if (halaqohFilter) {
        halaqohFilter.addEventListener('change', fetchPeserta);
    }

    // 3. Toggle Status (Lanjut / Tidak Lanjut)
    window.toggleStatus = function(id, isChecked) {
        const statusLanjut = isChecked ? 'tidak' : 'ya';

        const formData = new FormData();
        formData.append('id', String(id));
        formData.append('status_lanjut', statusLanjut);
        if (!isChecked) {
            formData.append('alasan', '');
        }

        fetch('index.php?page=cek-lanjut&ajax_action=toggle', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                fetchPeserta();
                fetchListTidakLanjut();
            }
        })
        .catch(err => console.error("Error toggle status:", err));
    };

    // 4. Update Alasan
    window.updateAlasan = function(id, alasan) {
        const formData = new FormData();
        formData.append('id', String(id));
        formData.append('alasan', alasan);

        fetch('index.php?page=cek-lanjut&ajax_action=update_alasan', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                // update local cache
                const item = currentDataTidakLanjut.find(x => String(x.id) === String(id));
                if (item) item.alasan_tidak_lanjut = alasan;
            }
        })
        .catch(err => console.error("Error updating alasan:", err));
    };

    // 5. Fetch List Tidak Lanjut
    function fetchListTidakLanjut() {
        fetch('index.php?page=cek-lanjut&ajax_action=get_list')
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
            tidakLanjutContainer.innerHTML = `
                <div class="cek-lanjut-empty">
                    <i class="fa-regular fa-circle-check"></i>
                    Belum ada peserta yang ditandai tidak lanjut.
                </div>`;
            return;
        }

        peserta.forEach((p, index) => {
            const item = document.createElement('div');
            item.className = 'cek-lanjut-discontinued-item';

            const phoneFormatted = (p.nowa || '').replace(/[^0-9]/g, '');
            const waLink = phoneFormatted ? `https://wa.me/${phoneFormatted}` : '#';

            item.innerHTML = `
                <div class="cek-lanjut-discontinued-top">
                    <div style="display:flex; align-items:center; gap:0.625rem; flex:1; min-width:0;">
                        <span class="cek-lanjut-index-badge">${index + 1}</span>
                        <div style="min-width:0; flex:1;">
                            <div class="cek-lanjut-name" style="font-size:0.8125rem;">${escapeHtml(p.nama_lengkap || '-')}</div>
                            <div class="cek-lanjut-meta">
                                <span><a href="${waLink}" target="_blank">${escapeHtml(p.nowa || '-')}</a></span>
                                <span>•</span>
                                <span>${escapeHtml(p.halaqoh || 'Belum ada')}</span>
                            </div>
                        </div>
                    </div>
                    <button class="cek-lanjut-undo-btn" title="Batalkan (Ubah ke Lanjut)" data-id="${p.id}">
                        <i class="fa-solid fa-arrow-rotate-left"></i>
                    </button>
                </div>
                <div class="cek-lanjut-reason-box">
                    <input type="text" 
                           class="cek-lanjut-reason-input" 
                           value="${escapeHtml(p.alasan_tidak_lanjut || '')}" 
                           placeholder="Ketik alasan tidak lanjut..." 
                           data-id="${p.id}">
                </div>
            `;

            // Undo button
            item.querySelector('.cek-lanjut-undo-btn').addEventListener('click', function() {
                toggleStatus(p.id, false);
            });

            // Reason input change
            const reasonInput = item.querySelector('.cek-lanjut-reason-input');
            reasonInput.addEventListener('change', function() {
                updateAlasan(p.id, this.value.trim());
            });

            tidakLanjutContainer.appendChild(item);
        });
    }

    // 6. Copy to Clipboard
    if (copyListBtn) {
        copyListBtn.addEventListener('click', function() {
            if (currentDataTidakLanjut.length === 0) {
                alert('Tidak ada data untuk disalin.');
                return;
            }

            let textToCopy = "Daftar Peserta Konfirmasi Tidak Lanjut:\n\n";
            currentDataTidakLanjut.forEach((p, index) => {
                const halaqohText = p.halaqoh ? ` (${p.halaqoh})` : '';
                const alasanText = p.alasan_tidak_lanjut ? ` (Alasan: ${p.alasan_tidak_lanjut})` : ' (Alasan: -)';
                textToCopy += `${index + 1}. ${p.nama_lengkap}${halaqohText}${alasanText}\n`;
            });

            navigator.clipboard.writeText(textToCopy).then(() => {
                const originalHTML = copyListBtn.innerHTML;
                copyListBtn.innerHTML = '<i class="fa-solid fa-check"></i><span>Berhasil Disalin!</span>';
                copyListBtn.classList.add('copied');

                setTimeout(() => {
                    copyListBtn.innerHTML = originalHTML;
                    copyListBtn.classList.remove('copied');
                }, 2000);
            }).catch(err => {
                console.error('Gagal menyalin teks: ', err);
                alert('Gagal menyalin data.');
            });
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    // Initial load
    fetchPeserta();
    fetchListTidakLanjut();
});
</script>
