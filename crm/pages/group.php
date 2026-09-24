<?php
$crmTitle = 'Grup';

$groupsByCategory = [];
$groupResult = $conn->query("SELECT id, nama_grup, id_grup, kategori FROM wa_grup ORDER BY kategori, nama_grup");
if ($groupResult) {
    while ($row = $groupResult->fetch_assoc()) {
        $category = trim((string)$row['kategori']) ?: 'Tanpa Kategori';
        $groupsByCategory[$category][] = $row;
    }
}

$groupHistory = [];
$historyResult = $conn->query("SELECT id, nama, message, created_at FROM log_wa WHERE message LIKE '%[GRUP]%' ORDER BY created_at DESC LIMIT 12");
if ($historyResult) $groupHistory = $historyResult->fetch_all(MYSQLI_ASSOC);

$jadwalBerjalan = [];
$jadwalQuery = "
SELECT j.pesan, j.tipe_jadwal, j.jadwal_kirim, j.jam_harian, j.hari_rutin, j.media_path,
GROUP_CONCAT(DISTINCT COALESCE(w.nama_grup, j.id_grup) ORDER BY COALESCE(w.nama_grup, j.id_grup) SEPARATOR ', ') AS daftar_grup,
COUNT(DISTINCT j.id_grup) AS total_grup
FROM jadwal_pesan_grup j
LEFT JOIN wa_grup w ON j.id_grup = w.id_grup
WHERE j.status = 'pending'
GROUP BY j.pesan, COALESCE(j.jadwal_kirim, ''), COALESCE(j.jam_harian, ''), COALESCE(j.hari_rutin, ''), j.tipe_jadwal, COALESCE(j.media_path, '')
ORDER BY MAX(j.id) DESC
LIMIT 12";
$jadwalResult = $conn->query($jadwalQuery);
if ($jadwalResult) $jadwalBerjalan = $jadwalResult->fetch_all(MYSQLI_ASSOC);

function crmGroupDays(string $value): string {
    if ($value === '') return '';
    $map = [1=>'Sen',2=>'Sel',3=>'Rab',4=>'Kam',5=>'Jum',6=>'Sab',7=>'Min'];
    return implode(', ', array_map(fn($day) => $map[(int)$day] ?? $day, explode(',', $value)));
}
?>

<section class="page-head group-page-head">
    <div>
        <span class="eyebrow">Broadcast</span>
        <h1>Kirim ke Grup</h1>
        <p>Tulis sekali, pilih grup, lalu kirim atau jadwalkan.</p>
    </div>
    <a class="group-manage-link" href="../kelola_grup.php"><i class="fa-solid fa-users-gear"></i> Kelola grup</a>
</section>

<div id="crmGroupLoader" class="group-loader" hidden>
    <div class="group-loader-icon"><i class="fa-solid fa-paper-plane"></i></div>
    <div class="group-loader-body">
        <div class="group-loader-top"><strong id="groupLoadingTitle">Memproses...</strong><b id="groupProgressText">0 / 0</b></div>
        <span id="groupProgressStatus">Mempersiapkan pengiriman...</span>
        <div class="group-progress"><i id="groupProgressBar"></i></div>
    </div>
</div>

<section class="group-compose-grid">
    <form id="crmGroupForm" class="group-card group-composer" enctype="multipart/form-data">
        <div class="group-card-head">
            <div>
                <span class="group-kicker">Pesan</span>
                <h2>Buat broadcast</h2>
            </div>
            <span class="group-compose-badge"><i class="fa-brands fa-whatsapp"></i></span>
        </div>

        <label class="group-field">
            <span>Isi pesan</span>
            <textarea id="crmGroupMessage" rows="7" placeholder="Tulis pesan untuk grup..."></textarea>
        </label>

        <label class="group-upload" for="crmGroupImage">
            <input id="crmGroupImage" type="file" accept="image/png,image/jpeg,image/gif">
            <span class="group-upload-icon"><i class="fa-solid fa-image"></i></span>
            <span><strong id="groupFileName">Tambah gambar</strong><small>PNG, JPG, GIF · maksimal 5 MB</small></span>
            <i class="fa-solid fa-chevron-right"></i>
        </label>

        <div class="group-section">
            <div class="group-section-title"><span>Waktu kirim</span><small>Pilih salah satu</small></div>
            <div class="group-modes">
                <label class="group-mode active" data-mode="sekarang">
                    <input type="radio" name="groupSchedule" value="sekarang" checked>
                    <i class="fa-solid fa-bolt"></i><strong>Sekarang</strong><small>Langsung</small>
                </label>
                <label class="group-mode" data-mode="sekali">
                    <input type="radio" name="groupSchedule" value="sekali">
                    <i class="fa-regular fa-calendar"></i><strong>Sekali</strong><small>Tanggal & jam</small>
                </label>
                <label class="group-mode" data-mode="harian">
                    <input type="radio" name="groupSchedule" value="harian">
                    <i class="fa-solid fa-repeat"></i><strong>Rutin</strong><small>Hari & jam</small>
                </label>
            </div>
            <div id="groupOnceFields" class="group-extra-field" hidden>
                <label>Tanggal & waktu<input id="groupOnceTime" type="datetime-local"></label>
            </div>
            <div id="groupDailyFields" class="group-extra-field" hidden>
                <label>Jam kirim<input id="groupDailyTime" type="time"></label>
                <div class="group-day-picker">
                    <span>Hari</span>
                    <div>
                        <?php foreach ([1=>'Sen',2=>'Sel',3=>'Rab',4=>'Kam',5=>'Jum',6=>'Sab',7=>'Min'] as $value => $label): ?>
                            <label><input type="checkbox" name="groupDays[]" value="<?= $value ?>"><b><?= $label ?></b></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="group-card-divider"></div>

        <div class="group-section">
            <div class="group-section-title"><span>Grup tujuan</span><small id="groupSelectedCount">0 dipilih</small></div>
            <div class="group-search"><i class="fa-solid fa-magnifying-glass"></i><input id="groupSearch" type="search" placeholder="Cari nama grup..."></div>
            <div class="group-list">
                <?php foreach ($groupsByCategory as $category => $groups): ?>
                    <div class="group-category" data-category="<?= htmlspecialchars(strtolower($category)) ?>">
                        <label class="group-category-head">
                            <input type="checkbox" class="group-category-check">
                            <span><?= htmlspecialchars($category) ?></span>
                            <small><?= count($groups) ?></small>
                        </label>
                        <div class="group-category-items">
                            <?php foreach ($groups as $group): ?>
                                <label class="group-target" data-name="<?= htmlspecialchars(strtolower($group['nama_grup'])) ?>">
                                    <input type="checkbox" class="crm-group-target" value="<?= htmlspecialchars($group['id_grup']) ?>" data-name="<?= htmlspecialchars($group['nama_grup']) ?>">
                                    <span class="group-target-dot"><i class="fa-solid fa-users"></i></span>
                                    <span><?= htmlspecialchars($group['nama_grup']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$groupsByCategory): ?>
                    <div class="group-empty"><i class="fa-solid fa-users-slash"></i><strong>Belum ada grup</strong><span>Tambahkan grup terlebih dahulu.</span><a href="../kelola_grup.php">Kelola grup</a></div>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" class="group-send-btn"><i class="fa-solid fa-paper-plane"></i><span>Proses Pengiriman</span></button>
    </form>

    <aside class="group-side">
        <div class="group-card group-preview-card">
            <div class="group-card-head compact">
                <div><span class="group-kicker">Preview</span><h2>WhatsApp</h2></div>
                <span class="group-preview-dot"></span>
            </div>
            <div class="group-phone">
                <div class="group-phone-top"><i class="fa-solid fa-arrow-left"></i><span>Grup WhatsApp</span><i class="fa-solid fa-video"></i><i class="fa-solid fa-phone"></i></div>
                <div class="group-phone-body" id="crmGroupPreview"><span class="group-preview-empty">Pesanmu akan tampil di sini.</span></div>
            </div>
        </div>

        <div class="group-card group-summary-card">
            <span class="group-kicker">Ringkas</span>
            <div class="group-summary-row"><span>Grup terpilih</span><strong id="groupSummaryCount">0</strong></div>
            <div class="group-summary-row"><span>Mode</span><strong id="groupSummaryMode">Sekarang</strong></div>
            <div class="group-summary-row"><span>Media</span><strong id="groupSummaryMedia">Tidak ada</strong></div>
        </div>
    </aside>
</section>

<section class="group-lower-grid">
    <div class="group-card">
        <div class="group-card-head compact">
            <div><span class="group-kicker">Terjadwal</span><h2>Jadwal aktif</h2></div>
            <span class="group-count-pill"><?= count($jadwalBerjalan) ?></span>
        </div>
        <div class="group-schedule-list">
            <?php if (!$jadwalBerjalan): ?>
                <div class="group-empty compact"><i class="fa-regular fa-calendar-xmark"></i><span>Belum ada jadwal aktif.</span></div>
            <?php else: foreach ($jadwalBerjalan as $schedule): ?>
                <div class="group-schedule-item">
                    <span class="group-schedule-icon"><i class="fa-regular fa-calendar-check"></i></span>
                    <div><strong><?= (int)$schedule['total_grup'] ?> grup</strong><p><?= htmlspecialchars(mb_strimwidth($schedule['pesan'],0,70,'…')) ?></p></div>
                    <small><?= $schedule['tipe_jadwal'] === 'sekali' ? htmlspecialchars(date('d M · H:i', strtotime($schedule['jadwal_kirim']))) : htmlspecialchars($schedule['jam_harian'].' · '.crmGroupDays($schedule['hari_rutin'])) ?></small>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php if ($jadwalBerjalan): ?><a class="group-old-link" href="../kirimgrup.php">Kelola jadwal lengkap <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
    </div>

    <div class="group-card">
        <div class="group-card-head compact">
            <div><span class="group-kicker">Riwayat</span><h2>Pengiriman</h2></div>
            <span class="group-count-pill"><?= count($groupHistory) ?></span>
        </div>
        <div class="group-history-list">
            <?php if (!$groupHistory): ?>
                <div class="group-empty compact"><i class="fa-regular fa-clock"></i><span>Belum ada riwayat.</span></div>
            <?php else: foreach ($groupHistory as $log):
                $statusMessage = explode(' :: ', $log['message'], 2)[0];
                $success = str_contains($statusMessage, '[TERKIRIM]');
            ?>
                <div class="group-history-item">
                    <span class="<?= $success ? 'success' : 'failed' ?>"><i class="fa-solid <?= $success ? 'fa-check' : 'fa-xmark' ?>"></i></span>
                    <div><strong><?= htmlspecialchars($log['nama']) ?></strong><small><?= htmlspecialchars(mb_strimwidth($statusMessage,0,85,'…')) ?></small></div>
                    <time><?= htmlspecialchars(date('H:i', strtotime($log['created_at']))) ?></time>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</section>

<script>
(() => {
    const endpoint = '../kirimgrup.php';
    const form = document.getElementById('crmGroupForm');
    const message = document.getElementById('crmGroupMessage');
    const image = document.getElementById('crmGroupImage');
    const preview = document.getElementById('crmGroupPreview');
    const selectedCount = document.getElementById('groupSelectedCount');
    const summaryCount = document.getElementById('groupSummaryCount');
    const summaryMode = document.getElementById('groupSummaryMode');
    const summaryMedia = document.getElementById('groupSummaryMedia');
    const fileName = document.getElementById('groupFileName');
    const loader = document.getElementById('crmGroupLoader');
    const progressBar = document.getElementById('groupProgressBar');
    const progressText = document.getElementById('groupProgressText');
    const progressStatus = document.getElementById('groupProgressStatus');
    const loadingTitle = document.getElementById('groupLoadingTitle');

    const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const selectedGroups = () => [...document.querySelectorAll('.crm-group-target:checked')];

    function updateSummary() {
        const count = selectedGroups().length;
        selectedCount.textContent = count + ' dipilih';
        summaryCount.textContent = count;
        const mode = document.querySelector('input[name="groupSchedule"]:checked')?.value || 'sekarang';
        summaryMode.textContent = mode === 'sekarang' ? 'Sekarang' : mode === 'sekali' ? 'Sekali' : 'Rutin';
        summaryMedia.textContent = image.files[0]?.name || 'Tidak ada';
    }

    function updatePreview() {
        const text = message.value.trim();
        const file = image.files[0];
        if (!text && !file) {
            preview.innerHTML = '<span class="group-preview-empty">Pesanmu akan tampil di sini.</span>';
            return;
        }
        const bubble = document.createElement('div');
        bubble.className = 'group-wa-bubble';
        let html = '';
        if (file) html += '<img id="groupPreviewImage" alt="Preview gambar">';
        if (text) html += '<p>' + escapeHtml(text).replace(/\n/g,'<br>') + '</p>';
        html += '<time>18:15 ✓✓</time>';
        bubble.innerHTML = html;
        preview.innerHTML = '';
        preview.appendChild(bubble);
        if (file) {
            const reader = new FileReader();
            reader.onload = e => document.getElementById('groupPreviewImage').src = e.target.result;
            reader.readAsDataURL(file);
        }
    }

    function updateMode() {
        const mode = document.querySelector('input[name="groupSchedule"]:checked')?.value;
        document.querySelectorAll('.group-mode').forEach(card => card.classList.toggle('active', card.dataset.mode === mode));
        document.getElementById('groupOnceFields').hidden = mode !== 'sekali';
        document.getElementById('groupDailyFields').hidden = mode !== 'harian';
        updateSummary();
    }

    document.querySelectorAll('.group-mode').forEach(card => card.addEventListener('click', () => {
        card.querySelector('input').checked = true;
        updateMode();
    }));
    document.querySelectorAll('input[name="groupSchedule"]').forEach(input => input.addEventListener('change', updateMode));

    document.querySelectorAll('.group-category-check').forEach(categoryCheck => categoryCheck.addEventListener('change', () => {
        categoryCheck.closest('.group-category').querySelectorAll('.crm-group-target').forEach(input => input.checked = categoryCheck.checked);
        updateSummary();
    }));
    document.querySelectorAll('.crm-group-target').forEach(input => input.addEventListener('change', updateSummary));

    document.getElementById('groupSearch')?.addEventListener('input', e => {
        const query = e.target.value.trim().toLowerCase();
        document.querySelectorAll('.group-category').forEach(category => {
            let visible = 0;
            category.querySelectorAll('.group-target').forEach(item => {
                const match = !query || item.dataset.name.includes(query);
                item.hidden = !match;
                if (match) visible++;
            });
            category.hidden = visible === 0;
        });
    });

    image.addEventListener('change', () => {
        fileName.textContent = image.files[0]?.name || 'Tambah gambar';
        updateSummary();
        updatePreview();
    });
    message.addEventListener('input', updatePreview);

    form.addEventListener('submit', async e => {
        e.preventDefault();
        const groups = selectedGroups();
        const text = message.value.trim();
        const mode = document.querySelector('input[name="groupSchedule"]:checked')?.value || 'sekarang';
        const file = image.files[0];

        if (!groups.length) return alert('Pilih minimal satu grup.');
        if (!text && !file) return alert('Isi pesan atau tambahkan gambar.');
        if (file && file.size > 5 * 1024 * 1024) return alert('Ukuran gambar maksimal 5 MB.');
        if (mode === 'sekali' && !document.getElementById('groupOnceTime').value) return alert('Pilih tanggal dan waktu.');
        if (mode === 'harian') {
            if (!document.getElementById('groupDailyTime').value) return alert('Pilih jam rutin.');
            if (!document.querySelector('input[name="groupDays[]"]:checked')) return alert('Pilih minimal satu hari.');
        }

        loader.hidden = false;
        loadingTitle.textContent = mode === 'sekarang' ? 'Mengirim ke grup...' : 'Menyimpan jadwal...';
        let savedImagePath = null;
        progressBar.style.width = '5%';
        progressText.textContent = '0 / ' + groups.length;

        try {
            if (file) {
                progressStatus.textContent = 'Mengunggah gambar satu kali...';
                const uploadData = new FormData();
                uploadData.append('ajax_upload_image', '1');
                uploadData.append('promo_image', file);
                const uploadResponse = await fetch(endpoint, {method:'POST', body:uploadData});
                const uploadJson = await uploadResponse.json();
                if (uploadJson.status !== 'success') throw new Error(uploadJson.msg || 'Gagal upload gambar.');
                savedImagePath = uploadJson.path;
            }

            const days = [...document.querySelectorAll('input[name="groupDays[]"]:checked')].map(x => x.value).join(',');
            let ok = 0;
            for (let index = 0; index < groups.length; index++) {
                const group = groups[index];
                progressStatus.innerHTML = 'Memproses <b>' + escapeHtml(group.dataset.name) + '</b>...';
                const data = new FormData();
                data.append('ajax_kirim_grup', '1');
                data.append('group_id', group.value);
                data.append('pesan', text);
                data.append('tipe_jadwal', mode);
                data.append('waktu_jadwal', mode === 'sekali' ? document.getElementById('groupOnceTime').value : '');
                data.append('jam_harian', mode === 'harian' ? document.getElementById('groupDailyTime').value : '');
                data.append('hari_rutin', days);
                if (savedImagePath) data.append('saved_image_path', savedImagePath);

                try {
                    const response = await fetch(endpoint, {method:'POST', body:data});
                    const raw = await response.text();
                    let json;
                    try {
                        json = JSON.parse(raw);
                    } catch (parseError) {
                        throw new Error(raw.trim().slice(0, 160) || 'Respons server bukan JSON yang valid.');
                    }
                    if (!response.ok) {
                        throw new Error(json.msg || ('Server error HTTP ' + response.status));
                    }
                    if (json.status === 'success') {
                        ok++;
                    } else {
                        throw new Error(json.msg || 'Pengiriman gagal.');
                    }
                    progressStatus.textContent = 'Berhasil diproses.';
                } catch (groupError) {
                    progressStatus.textContent = 'Gagal: ' + (groupError.message || 'respons server tidak valid.');
                }

                const percent = Math.round(((index + 1) / groups.length) * 100);
                progressBar.style.width = percent + '%';
                progressText.textContent = (index + 1) + ' / ' + groups.length;
            }
            loadingTitle.textContent = mode === 'sekarang' ? 'Selesai' : 'Jadwal tersimpan';
            progressStatus.textContent = ok + ' berhasil · ' + (groups.length - ok) + ' gagal';
            setTimeout(() => window.location.reload(), 1200);
        } catch (error) {
            loadingTitle.textContent = 'Terjadi kendala';
            progressStatus.textContent = error.message || 'Koneksi gagal.';
            progressBar.style.width = '100%';
        }
    });

    updateMode();
    updateSummary();
    updatePreview();
})();
</script>
