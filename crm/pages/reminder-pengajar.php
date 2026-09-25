<?php
declare(strict_types=1);

$crmTitle = 'Reminder Pengajar';

$search = trim((string)($_GET['q'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$jenis = trim((string)($_GET['jenis'] ?? ''));

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
                        <h2>Pilih Pengajar</h2>
                    </div>
                    <span class="reminder-count"><?= count($pengajar) ?> pengajar</span>
                </div>

                <form method="GET" action="" class="reminder-pengajar-filter">
                    <input type="hidden" name="page" value="reminder-pengajar">
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

                    <div class="reminder-field">
                        <label for="pengajarMessage">Pesan</label>
                        <textarea
                            id="pengajarMessage"
                            name="message"
                            maxlength="2000"
                            required
                            placeholder="Tulis pesan untuk pengajar..."
                        ></textarea>
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

    document.getElementById('reminderPengajarForm')?.addEventListener('submit', event => {
        const selected = selectedItems();
        if (!selected.length || !messageEl.value.trim()) {
            event.preventDefault();
            return;
        }

        if (!window.confirm('Kirim pesan ini ke ' + selected.length + ' pengajar?')) {
            event.preventDefault();
        }
    });

    syncSelection();
    syncMessage();
})();
</script>
