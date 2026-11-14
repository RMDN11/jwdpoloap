<?php
declare(strict_types=1);

$crmTitle = 'Reminder Pengajar';

$retentionContext = (string)($_GET['retention_context'] ?? '') === '1';
$retentionPreviousSource = trim((string)($_GET['previous_source'] ?? ''));
$retentionCurrentSource = trim((string)($_GET['current_source'] ?? ''));
$retentionAk = trim((string)($_GET['retention_ak'] ?? ''));
$retentionNames = [];
$retentionPrefillMessage = '';
$retentionContextError = '';

$search = trim((string)($_GET['q'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$jenis = trim((string)($_GET['jenis'] ?? ''));

if ($retentionContext && $retentionAk !== '' && $retentionPreviousSource !== '' && $retentionCurrentSource !== '') {
    try {
        require_once __DIR__ . '/../lib/reminder-retention.php';

        $retentionPrevious = crmRetentionLoadSource($conn, $retentionPreviousSource);
        $retentionCurrent = crmRetentionLoadSource($conn, $retentionCurrentSource);
        $retentionAkKey = crmRetentionNormalizeGroup($retentionAk);

        $retentionPrevious = array_values(array_filter(
            $retentionPrevious,
            static fn(array $record): bool => (string)($record['group_key'] ?? '') === $retentionAkKey
        ));

        $retentionComparison = crmRetentionCompare($retentionPrevious, $retentionCurrent);
        $retentionNames = array_values(array_map(
            static fn(array $record): string => trim((string)($record['name'] ?? '')),
            $retentionComparison['not_continued'] ?? []
        ));
        $retentionNames = array_values(array_filter($retentionNames, static fn(string $name): bool => $name !== ''));

        if ($retentionNames) {
            $nameLines = array_map(static fn(string $name): string => '• ' . $name, $retentionNames);
            $retentionPrefillMessage = "Assalamu'alaikum Kak, izin menginformasikan peserta yang tidak melanjutkan pada periode berikutnya dari {$retentionAk}:\n\n"
                . implode("\n", $nameLines)
                . "\n\nMohon dibantu follow up bila diperlukan. Jazakallahu khairan.";
        } else {
            $retentionContextError = 'Tidak ada peserta yang tidak lanjut pada AK ini.';
        }
    } catch (Throwable $e) {
        error_log('CRM reminder pengajar retention context failed: ' . $e->getMessage());
        $retentionContextError = 'Data retention tidak dapat dimuat. Silakan buka Reminder Pengajar secara normal.';
    }

    // Retention selalu membuka tutor berdasarkan AK batch sebelumnya.
    if ($halaqoh === '') {
        $halaqoh = $retentionAk;
    }
}

$halaqohList = [];
$result = $conn->query("SELECT DISTINCT halaqoh FROM pengampu WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $halaqohList[] = (string)$row['halaqoh'];
    }
}

$where = ["p.nowa IS NOT NULL", "p.nowa <> ''"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(p.nama LIKE ? OR p.nowa LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($halaqoh !== '') {
    $where[] = "p.halaqoh = ?";
    $params[] = $halaqoh;
    $types .= 's';
}

if ($jenis === 'AK' || $jenis === 'IK') {
    $where[] = "UPPER(TRIM(p.halaqoh)) LIKE ?";
    $params[] = $jenis . '%';
    $types .= 's';
}

$pengajar = [];
$sql = "SELECT p.id, p.nama, p.nowa, p.halaqoh
        FROM pengampu p
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE
                WHEN UPPER(TRIM(p.halaqoh)) LIKE 'AK%' THEN 1
                WHEN UPPER(TRIM(p.halaqoh)) LIKE 'IK%' THEN 2
                ELSE 3
            END ASC,
            CAST(NULLIF(REGEXP_REPLACE(UPPER(TRIM(p.halaqoh)), '[^0-9]', ''), '') AS UNSIGNED) ASC,
            p.halaqoh ASC,
            p.nama ASC
        LIMIT 100";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($params) {
        $refs = [];
        foreach ($params as $key => $value) $refs[$key] = &$params[$key];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    }
    $stmt->execute();
    $pengajar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$historyByNumber = [];
$historyResult = $conn->query("
    SELECT nowa, sent_at, message
    FROM crm_message_history
    WHERE template_name = 'Reminder Pengajar'
      AND status = 'sent'
    ORDER BY sent_at DESC
    LIMIT 1000
");
if ($historyResult) {
    while ($row = $historyResult->fetch_assoc()) {
        $key = crmNormalizeNumber((string)$row['nowa']);
        if ($key === '' || isset($historyByNumber[$key])) continue;
        $historyByNumber[$key] = [
            'sent_at' => (string)$row['sent_at'],
            'message' => (string)$row['message'],
        ];
    }
}

$previousMessages = [];
$historyResult = $conn->query("
    SELECT message, COUNT(*) AS usage_count, MAX(sent_at) AS last_used_at
    FROM crm_message_history
    WHERE template_name = 'Reminder Pengajar'
      AND status = 'sent'
      AND message <> ''
    GROUP BY message
    ORDER BY last_used_at DESC
    LIMIT 30
");
if ($historyResult) {
    while ($row = $historyResult->fetch_assoc()) {
        $previousMessages[] = [
            'message' => (string)$row['message'],
            'usage_count' => (int)$row['usage_count'],
            'last_used_at' => (string)$row['last_used_at'],
        ];
    }
}

function reminderPengajarInitials(string $name): string {
    $name = trim($name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name) ?: [];
    $first = strtoupper(substr($parts[0] ?? '?', 0, 1));
    $last = count($parts) > 1 ? strtoupper(substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}

function reminderPengajarTime(string $datetime): string {
    $ts = strtotime($datetime);
    if (!$ts) return '-';
    return date('d M Y, H:i', $ts);
}
?>

<section class="reminder-pengajar-page">
    <div class="reminder-pengajar-back">
        <a href="?page=reminder" aria-label="Kembali ke Reminder">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
    </div>

    <div class="reminder-pengajar-grid">
        <div class="reminder-pengajar-main">
            <section class="reminder-card reminder-target-card">
                <div class="reminder-card-head">
                    <div>
                        <span class="reminder-kicker">Target</span>
                        <h2><?= $retentionContext && $retentionAk !== '' ? 'Tutor ' . htmlspecialchars($retentionAk) : 'Pilih Pengajar' ?></h2>
                    </div>
                    <span class="reminder-count"><?= count($pengajar) ?> pengajar</span>
                </div>
                <?php if ($retentionContext && $retentionNames): ?>
                    <div class="reminder-retention-context">
                        <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                        <div>
                            <strong>Dari Retention · <?= htmlspecialchars($retentionAk) ?></strong>
                            <span><?= count($retentionNames) ?> peserta tidak lanjut sudah dimasukkan ke draft pesan.</span>
                        </div>
                    </div>
                <?php elseif ($retentionContextError !== ''): ?>
                    <div class="reminder-retention-context error">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <div><span><?= htmlspecialchars($retentionContextError) ?></span></div>
                    </div>
                <?php endif; ?>

                <form method="GET" action="" class="reminder-pengajar-filter">
                    <input type="hidden" name="page" value="reminder-pengajar">
                    <?php if ($retentionContext): ?>
                        <input type="hidden" name="retention_context" value="1">
                        <input type="hidden" name="previous_source" value="<?= htmlspecialchars($retentionPreviousSource, ENT_QUOTES) ?>">
                        <input type="hidden" name="current_source" value="<?= htmlspecialchars($retentionCurrentSource, ENT_QUOTES) ?>">
                        <input type="hidden" name="retention_ak" value="<?= htmlspecialchars($retentionAk, ENT_QUOTES) ?>">
                    <?php endif; ?>
                    <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama atau nomor WhatsApp...">
                    <select name="jenis" aria-label="Filter jenis pengajar">
                        <option value="">Semua Pengajar</option>
                        <option value="AK" <?= $jenis === 'AK' ? 'selected' : '' ?>>AK</option>
                        <option value="IK" <?= $jenis === 'IK' ? 'selected' : '' ?>>IK</option>
                    </select>
                    <select name="halaqoh">
                        <option value="">Semua Halaqoh</option>
                        <?php foreach ($halaqohList as $item): ?>
                            <option value="<?= htmlspecialchars($item) ?>" <?= $halaqoh === $item ? 'selected' : '' ?>>
                                <?= htmlspecialchars($item) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                </form>

                <div class="reminder-pengajar-selectbar">
                    <label>
                        <input type="checkbox" id="selectAllPengajar">
                        <span>Pilih semua</span>
                    </label>
                    <span id="selectedPengajarCount">0 dipilih</span>
                </div>

                <?php if (!$pengajar): ?>
                    <div class="reminder-empty">Tidak ada pengajar yang sesuai dengan filter.</div>
                <?php else: ?>
                    <div class="reminder-list reminder-pengajar-list">
                        <?php foreach ($pengajar as $item):
                            $number = crmNormalizeNumber((string)$item['nowa']);
                            $history = $historyByNumber[$number] ?? null;
                        ?>
                            <label class="reminder-person reminder-pengajar-person">
                                <span class="reminder-target">
                                    <input
                                        type="checkbox"
                                        class="pengajar-checkbox"
                                        value="<?= (int)$item['id'] ?>"
                                        data-name="<?= htmlspecialchars((string)$item['nama'], ENT_QUOTES) ?>"
                                        data-nowa="<?= htmlspecialchars((string)$item['nowa'], ENT_QUOTES) ?>"
                                        <?= $retentionContext && $retentionAk !== '' && crmRetentionNormalizeGroup((string)$item['halaqoh']) === crmRetentionNormalizeGroup($retentionAk) ? 'checked' : '' ?>
                                    >
                                    <span class="reminder-avatar"><?= htmlspecialchars(reminderPengajarInitials((string)$item['nama'])) ?></span>
                                </span>

                                <span class="reminder-person-body">
                                    <strong><?= htmlspecialchars((string)$item['nama']) ?></strong>
                                    <small><?= htmlspecialchars((string)$item['nowa']) ?> · <?= htmlspecialchars((string)$item['halaqoh']) ?></small>
                                    <?php if ($history): ?>
                                        <span class="reminder-history">
                                            Terakhir: <?= htmlspecialchars(reminderPengajarTime($history['sent_at'])) ?>
                                            · <?= htmlspecialchars(mb_strimwidth($history['message'], 0, 90, '…')) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="reminder-history">Belum ada reminder dari workspace ini</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="reminder-pengajar-side">
            <section class="reminder-card reminder-compose-card">
                <div class="reminder-card-head">
                    <div>
                        <span class="reminder-kicker">Pesan</span>
                        <h2>Tulis Pesan</h2>
                    </div>
                    <span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span>
                </div>

                <form method="POST" action="actions/reminder-pengajar-send.php" id="reminderPengajarForm">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                    <input type="hidden" name="selected" id="selectedPengajarPayload" value="[]">
                    <input type="hidden" name="scroll_y" id="pengajarScrollY" value="<?= (int)($_GET['scroll_y'] ?? 0) ?>">

                    <div class="reminder-field">
                        <label for="pengajarMessage">Pesan</label>
                        <textarea
                            id="pengajarMessage"
                            name="message"
                            maxlength="2000"
                            required
                            placeholder="Tulis pesan untuk pengajar..."
                        ><?= htmlspecialchars($retentionPrefillMessage) ?></textarea>
                        <div class="reminder-message-meta">
                            <span>Pesan bisa diedit sebelum dikirim.</span>
                            <span id="pengajarCharCount">0 / 2000</span>
                        </div>
                    </div>

                    <div id="selectedPengajarPreview" class="reminder-selected-preview">
                        Belum ada pengajar yang dipilih.
                    </div>

                    <button type="submit" class="reminder-send-btn" id="sendPengajarBtn" disabled>
                        <i class="fa-solid fa-paper-plane"></i>
                        Kirim WhatsApp
                    </button>
                </form>

                <section class="reminder-card reminder-pengajar-progress" id="pengajarProgressCard" hidden style="margin-top:16px;">
                    <div class="reminder-card-head compact">
                        <div>
                            <span class="reminder-kicker">Progress</span>
                            <h2 id="pengajarProgressTitle">Menyiapkan...</h2>
                        </div>
                        <span id="pengajarProgressCount" class="reminder-count">0 / 0</span>
                    </div>
                    <div class="reminder-progress-track"><span id="pengajarProgressBar"></span></div>
                    <p id="pengajarProgressStatus" style="margin:9px 0 0;color:#65756d;font-size:10px;">Menyiapkan pengiriman.</p>
                    <div class="reminder-progress-stats" style="display:flex;gap:12px;margin-top:8px;color:#829089;font-size:9px;">
                        <span>✓ <b id="pengajarSuccessCount" style="color:#166534">0</b> berhasil</span>
                        <span>× <b id="pengajarFailedCount" style="color:#991b1b">0</b> gagal</span>
                        <span>◷ <b id="pengajarRemainingCount">0</b> tersisa</span>
                    </div>
                </section>
            </section>

            <section class="reminder-card reminder-history-card">
                <div class="reminder-card-head compact">
                    <div>
                        <span class="reminder-kicker">Riwayat</span>
                        <h2>Pesan Sebelumnya</h2>
                    </div>
                    <span class="reminder-count"><?= count($previousMessages) ?> pesan</span>
                </div>

                <?php if (!$previousMessages): ?>
                    <div class="reminder-empty compact">
                        Belum ada pesan yang pernah dikirim dari workspace ini.
                    </div>
                <?php else: ?>
                    <div class="reminder-previous-list">
                        <?php foreach ($previousMessages as $item): ?>
                            <article class="reminder-previous-item">
                                <p><?= nl2br(htmlspecialchars($item['message'])) ?></p>
                                <div class="reminder-previous-meta">
                                    <span><?= $item['usage_count'] ?>x dipakai · <?= htmlspecialchars(reminderPengajarTime($item['last_used_at'])) ?></span>
                                    <button
                                        type="button"
                                        class="use-previous-message"
                                        data-message="<?= htmlspecialchars($item['message'], ENT_QUOTES) ?>"
                                    >
                                        Gunakan Lagi
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</section>

<script>
(() => {
    const checkboxes = [...document.querySelectorAll('.pengajar-checkbox')];
    const selectAll = document.getElementById('selectAllPengajar');
    const countEl = document.getElementById('selectedPengajarCount');
    const payloadEl = document.getElementById('selectedPengajarPayload');
    const previewEl = document.getElementById('selectedPengajarPreview');
    const sendBtn = document.getElementById('sendPengajarBtn');
    const messageEl = document.getElementById('pengajarMessage');
    const charEl = document.getElementById('pengajarCharCount');

    function selectedItems() {
        return checkboxes
            .filter(cb => cb.checked)
            .map(cb => ({
                id: Number(cb.value),
                name: cb.dataset.name || '',
                nowa: cb.dataset.nowa || ''
            }));
    }

    function syncSelection() {
        const selected = selectedItems();
        countEl.textContent = selected.length + ' dipilih';
        payloadEl.value = JSON.stringify(selected);
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checkboxes.every(item => item.checked);
        }
        sendBtn.disabled = selected.length === 0 || !messageEl.value.trim();

        if (!selected.length) {
            previewEl.textContent = 'Belum ada pengajar yang dipilih.';
            return;
        }

        const names = selected.slice(0, 3).map(item => item.name);
        const extra = selected.length > 3 ? ' +' + (selected.length - 3) + ' lainnya' : '';
        previewEl.innerHTML = '<strong>' + names.map(escapeHtml).join(', ') + '</strong>' + escapeHtml(extra);
    }

    function syncMessage() {
        charEl.textContent = messageEl.value.length + ' / 2000';
        sendBtn.disabled = selectedItems().length === 0 || !messageEl.value.trim();
    }

    function escapeHtml(value) {
        return value.replace(/[&<>"']/g, char => ({
            '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
        }[char]));
    }

    checkboxes.forEach(cb => cb.addEventListener('change', () => {
        selectAll.checked = checkboxes.length > 0 && checkboxes.every(item => item.checked);
        syncSelection();
    }));

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach(cb => { cb.checked = selectAll.checked; });
            syncSelection();
        });
    }

    if (messageEl) messageEl.addEventListener('input', syncMessage);

    document.querySelectorAll('.use-previous-message').forEach(button => {
        button.addEventListener('click', () => {
            messageEl.value = button.dataset.message || '';
            messageEl.focus();
            syncMessage();
            messageEl.scrollIntoView({behavior: 'smooth', block: 'center'});
        });
    });

    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const form = document.getElementById('reminderPengajarForm');
    const progressCard = document.getElementById('pengajarProgressCard');
    const progressTitle = document.getElementById('pengajarProgressTitle');
    const progressCount = document.getElementById('pengajarProgressCount');
    const progressBar = document.getElementById('pengajarProgressBar');
    const progressStatus = document.getElementById('pengajarProgressStatus');
    const successEl = document.getElementById('pengajarSuccessCount');
    const failedEl = document.getElementById('pengajarFailedCount');
    const remainingEl = document.getElementById('pengajarRemainingCount');

    async function sendOnePengajar(item, msg, csrf) {
        const data = new FormData();
        data.append('csrf', csrf);
        data.append('message', msg);
        data.append('target_id', String(item.id));
        data.append('ajax', '1');

        const res = await fetch('actions/reminder-pengajar-send.php', {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json().catch(() => null);
        if (!res.ok || !json) {
            throw new Error(json?.message || ('Gagal mengirim (HTTP ' + res.status + ')'));
        }
        return json;
    }

    form?.addEventListener('submit', async event => {
        event.preventDefault();

        const scrollYInput = document.getElementById('pengajarScrollY');
        if (scrollYInput) {
            scrollYInput.value = String(Math.max(0, Math.round(window.scrollY || window.pageYOffset || 0)));
        }

        const selected = selectedItems();
        const msg = messageEl ? messageEl.value.trim() : '';
        const csrf = form.querySelector('[name=csrf]')?.value || '';

        if (!selected.length || !msg) {
            return;
        }

        if (selected.length > 20) {
            alert('⚠️ PROTEKSI ANTI-BANNED WHATSAPP:\n\nAnda memilih ' + selected.length + ' pengajar. Pengiriman instan dibatasi maksimal 20 pengajar per batch demi mencegah pemblokiran nomor WhatsApp.');
            return;
        }

        if (!window.confirm('Kirim pesan ini ke ' + selected.length + ' pengajar? Pengiriman akan diproses bertahap dengan jeda aman anti-banned.')) {
            return;
        }

        // Kunci input dan tombol selama pengiriman berlangsung
        sendBtn.disabled = true;
        messageEl.disabled = true;
        checkboxes.forEach(cb => { cb.disabled = true; });
        if (selectAll) selectAll.disabled = true;

        if (progressCard) {
            progressCard.hidden = false;
            progressCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        let success = 0;
        let failed = 0;
        const total = selected.length;

        for (let i = 0; i < total; i++) {
            const item = selected[i];
            if (progressTitle) progressTitle.textContent = 'Mengirim ke ' + item.name;
            if (progressCount) progressCount.textContent = (i + 1) + ' / ' + total;
            if (progressStatus) progressStatus.textContent = 'Menghubungi OneSender untuk ' + item.name + '...';
            if (remainingEl) remainingEl.textContent = String(total - i);

            try {
                const res = await sendOnePengajar(item, msg, csrf);
                if (res.status === 'success') {
                    success++;
                    if (progressStatus) progressStatus.textContent = '✓ ' + (res.message || 'Terkirim ke ' + item.name);
                } else {
                    failed++;
                    if (progressStatus) progressStatus.textContent = '× ' + (res.message || 'Gagal mengirim ke ' + item.name);
                }
            } catch (err) {
                failed++;
                if (progressStatus) progressStatus.textContent = '× ' + (err.message || 'Gagal mengirim ke ' + item.name);
            }

            const pct = Math.round(((i + 1) / total) * 100);
            if (progressBar) progressBar.style.width = pct + '%';
            if (successEl) successEl.textContent = String(success);
            if (failedEl) failedEl.textContent = String(failed);
            if (remainingEl) remainingEl.textContent = String(total - (i + 1));

            // Jeda acak manusia 2.5 - 4.5 detik antar pesan (kecuali pesan terakhir)
            if (i < total - 1) {
                const delayMs = 2500 + Math.floor(Math.random() * 2000);
                const delaySec = (delayMs / 1000).toFixed(1);
                if (progressStatus) progressStatus.textContent += ' · Jeda anti-banned ' + delaySec + ' detik...';
                await sleep(delayMs);
            }
        }

        if (progressTitle) progressTitle.textContent = 'Pengiriman Selesai';
        if (progressStatus) {
            progressStatus.textContent = success + ' pengajar berhasil dikirimi pesan' + (failed ? ', ' + failed + ' gagal.' : '. Memuat ulang...');
        }

        setTimeout(() => {
            const scrollY = Math.max(0, Math.round(window.scrollY || window.pageYOffset || 0));
            const url = new URL(window.location.href);
            url.searchParams.set('scroll_y', String(scrollY));
            window.location.href = url.toString();
        }, 1800);
    });

    syncSelection();
    syncMessage();

    <?php if ($retentionContext && $retentionNames): ?>
    messageEl?.focus();
    messageEl?.setSelectionRange(messageEl.value.length, messageEl.value.length);
    <?php endif; ?>

    const restoreScrollY = <?= (int)($_GET['scroll_y'] ?? 0) ?>;
    if (restoreScrollY > 0) {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                window.scrollTo(0, restoreScrollY);
            });
        });
    }
})();
</script>
