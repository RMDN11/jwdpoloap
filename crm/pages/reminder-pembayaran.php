<?php
declare(strict_types=1);

$crmTitle = 'Reminder Pembayaran';
$search = trim((string)($_GET['q'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$bulan = trim((string)($_GET['bulan'] ?? ''));
$statusBayar = (string)($_GET['status_bayar'] ?? 'belum_lunas');
$statusPeserta = (string)($_GET['status_peserta'] ?? 'proses');
$hasFilter = isset($_GET['q']) || isset($_GET['halaqoh']) || isset($_GET['bulan']) || isset($_GET['status_bayar']) || isset($_GET['status_peserta']);

$halaqohList = [];
$r = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($r) while ($row = $r->fetch_assoc()) $halaqohList[] = (string)$row['halaqoh'];

$bulanList = [];
$r = $conn->query("SELECT DISTINCT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran <> '' ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $bulanList[] = (string)$row['bulan_pembayaran'];

$templates = [];
$r = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY category, title");
if ($r) $templates = $r->fetch_all(MYSQLI_ASSOC);

$participants = [];
$totalPeserta = 0;
$belumBayar = 0;
$todaySent = 0;

$where = ["p.nowa IS NOT NULL", "p.nowa <> ''"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(p.nama_lengkap LIKE ? OR p.nowa LIKE ?)";
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

if ($statusPeserta !== '' && $statusPeserta !== 'semua') {
    $where[] = "p.status = ?";
    $params[] = $statusPeserta;
    $types .= 's';
}

$paymentJoin = '';
if ($bulan !== '') {
    $paymentJoin = " LEFT JOIN (SELECT DISTINCT peserta_id FROM pembayaran WHERE bulan_pembayaran = ?) bp ON bp.peserta_id = p.id ";
    array_unshift($params, $bulan);
    $types = 's' . $types;

    if ($statusBayar === 'lunas') {
        $where[] = "bp.peserta_id IS NOT NULL";
    } elseif ($statusBayar === 'belum_lunas') {
        $where[] = "bp.peserta_id IS NULL";
    }
} elseif ($statusBayar === 'belum_lunas') {
    $where[] = "NOT EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id = p.id)";
} elseif ($statusBayar === 'lunas') {
    $where[] = "EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id = p.id)";
}

$paymentStatusSql = $bulan !== ''
    ? "CASE WHEN bp.peserta_id IS NOT NULL THEN 1 ELSE 0 END"
    : "CASE WHEN EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id = p.id) THEN 1 ELSE 0 END";

if ($hasFilter) {
    // Summary tidak memakai LIMIT. Jadi angka Belum Bayar benar-benar mewakili seluruh
    // peserta yang memenuhi filter, bukan hanya 100 baris yang ditampilkan.
    $summarySql = "SELECT COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN {$paymentStatusSql} = 0 THEN 1 ELSE 0 END), 0) AS belum_bayar
        FROM peserta p {$paymentJoin}
        WHERE " . implode(' AND ', $where);

    $summaryStmt = $conn->prepare($summarySql);
    if ($summaryStmt) {
        $summaryParams = $params;
        $summaryRefs = [];
        foreach ($summaryParams as $k => $v) $summaryRefs[$k] = &$summaryParams[$k];
        if ($summaryParams) {
            call_user_func_array([$summaryStmt, 'bind_param'], array_merge([$types], $summaryRefs));
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
        $summaryStmt->close();

        $totalPeserta = (int)($summary['total'] ?? 0);
        $belumBayar = (int)($summary['belum_bayar'] ?? 0);
    }

    // Hitung reminder hari ini berdasarkan peserta yang lolos filter.
    // Hanya log yang benar-benar berstatus [TERKIRIM] yang dihitung.
    // Pencocokan mendukung nomor 08xxx <-> 62xxx tanpa REGEXP_REPLACE,
    // sehingga tidak menambah risiko incompatibility MySQL pada server produksi.
    $todayMatch = "(l.nowa = p.nowa
        OR l.nowa = CONCAT('+', p.nowa)
        OR (LEFT(p.nowa, 1) = '0' AND l.nowa = CONCAT('62', SUBSTRING(p.nowa, 2)))
        OR (LEFT(p.nowa, 1) = '0' AND l.nowa = CONCAT('+62', SUBSTRING(p.nowa, 2)))
        OR (LEFT(p.nowa, 2) = '62' AND l.nowa = CONCAT('0', SUBSTRING(p.nowa, 3)))
        OR (LEFT(p.nowa, 2) = '62' AND l.nowa = CONCAT('+0', SUBSTRING(p.nowa, 3))))";

    $todaySql = "SELECT COUNT(*) AS total
        FROM peserta p {$paymentJoin}
        WHERE " . implode(' AND ', $where) . "
        AND EXISTS (
            SELECT 1
            FROM log_wa l
            WHERE DATE(l.created_at) = CURDATE()
              AND l.message LIKE '[REMINDER] [TERKIRIM]%'
              AND {$todayMatch}
        )";

    $todayStmt = $conn->prepare($todaySql);
    if ($todayStmt) {
        $todayParams = $params;
        $todayRefs = [];
        foreach ($todayParams as $k => $v) $todayRefs[$k] = &$todayParams[$k];
        if ($todayParams) {
            call_user_func_array([$todayStmt, 'bind_param'], array_merge([$types], $todayRefs));
        }
        $todayStmt->execute();
        $todayRow = $todayStmt->get_result()->fetch_assoc() ?: [];
        $todayStmt->close();
        $todaySent = (int)($todayRow['total'] ?? 0);
    }

    // Daftar peserta tetap dibatasi 100 agar filter tidak memicu query/render raksasa.
    $sql = "SELECT p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status,
            {$paymentStatusSql} AS is_lunas
            FROM peserta p {$paymentJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.halaqoh, p.nama_lengkap
            LIMIT 100";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $displayParams = $params;
        $displayRefs = [];
        foreach ($displayParams as $k => $v) $displayRefs[$k] = &$displayParams[$k];
        if ($displayParams) {
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $displayRefs));
        }
        $stmt->execute();
        $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // Ambil riwayat hanya untuk nomor peserta yang benar-benar ditampilkan.
    // Log gagal sengaja tidak masuk karena action menyimpan [TERKIRIM] / [GAGAL].
    $historyMap = [];

    $normalizeWa = static function (string $value): string {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits !== '' && str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }
        return $digits;
    };

    $logNumbers = [];
    foreach ($participants as $participant) {
        $raw = (string)$participant['nowa'];
        $normalized = $normalizeWa($raw);
        if ($raw !== '') $logNumbers[$raw] = true;
        if ($normalized !== '') {
            $logNumbers[$normalized] = true;
            if (str_starts_with($normalized, '62')) {
                $local = '0' . substr($normalized, 2);
                $logNumbers[$local] = true;
                $logNumbers['+' . $normalized] = true;
                $logNumbers['+' . $local] = true;
            }
        }
    }

    if ($logNumbers) {
        $logNumbers = array_keys($logNumbers);
        $placeholders = implode(',', array_fill(0, count($logNumbers), '?'));
        $logTypes = str_repeat('s', count($logNumbers));
        $logSql = "SELECT nowa, created_at
                   FROM log_wa
                   WHERE message LIKE '[REMINDER] [TERKIRIM]%'
                     AND nowa IN ({$placeholders})
                   ORDER BY created_at DESC";

        $logStmt = $conn->prepare($logSql);
        if ($logStmt) {
            $logParams = $logNumbers;
            $logRefs = [];
            foreach ($logParams as $k => $v) $logRefs[$k] = &$logParams[$k];
            call_user_func_array([$logStmt, 'bind_param'], array_merge([$logTypes], $logRefs));
            $logStmt->execute();
            $logResult = $logStmt->get_result();

            while ($log = $logResult->fetch_assoc()) {
                $normalized = $normalizeWa((string)$log['nowa']);
                if ($normalized === '') continue;
                if (!isset($historyMap[$normalized])) {
                    $historyMap[$normalized] = ['count' => 0, 'last_at' => null];
                }
                $historyMap[$normalized]['count']++;
                if ($historyMap[$normalized]['last_at'] === null) {
                    $historyMap[$normalized]['last_at'] = $log['created_at'];
                }
            }
            $logStmt->close();
        }
    }

    foreach ($participants as &$participant) {
        $normalized = $normalizeWa((string)$participant['nowa']);
        $participant['reminder_count'] = (int)($historyMap[$normalized]['count'] ?? 0);
        $participant['reminder_last_at'] = $historyMap[$normalized]['last_at'] ?? null;
    }
    unset($participant);
}

$pendingRequests = [];
$r = $conn->query("SELECT id, halaqoh, peserta_nama, peserta_nowa, pesan_pengajar, status, created_at FROM reminder_requests ORDER BY created_at DESC LIMIT 12");
if ($r) $pendingRequests = $r->fetch_all(MYSQLI_ASSOC);

$formatReminderHistory = static function (int $count, ?string $lastAt): string {
    if ($count < 1 || !$lastAt) return 'Belum pernah dihubungi';

    $timestamp = strtotime($lastAt);
    if (!$timestamp) return $count . 'x';

    $date = date('Y-m-d', $timestamp) === date('Y-m-d')
        ? 'Hari ini ' . date('H:i', $timestamp)
        : date('d/m/Y H:i', $timestamp);

    return $count . 'x · ' . $date;
};
?>

<div class="reminder-payment-page">
    <div class="reminder-payment-topbar">
        <a class="reminder-back-btn" href="?page=reminder&tab=pembayaran" title="Kembali ke Reminder" aria-label="Kembali ke Reminder">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        </a>
    </div>

    <section class="reminder-stats reminder-stats-compact">
        <div class="reminder-stat">
            <span class="reminder-stat-icon warning"><i class="fa-solid fa-wallet"></i></span>
            <div><strong><?= $belumBayar ?></strong><small>Belum bayar</small></div>
        </div>
        <div class="reminder-stat">
            <span class="reminder-stat-icon green"><i class="fa-solid fa-paper-plane"></i></span>
            <div><strong><?= $todaySent ?></strong><small>Reminder hari ini</small></div>
        </div>
    </section>

    <section class="reminder-card reminder-target-card">
        <div class="reminder-card-head">
            <div>
                <span class="reminder-kicker">Target peserta</span>
                <h2>Peserta reminder</h2>
            </div>
            <span class="reminder-count" id="reminderSelectedCount">0 dipilih</span>
        </div>

        <form class="reminder-filters reminder-filter-box" method="get">
            <input type="hidden" name="page" value="reminder-pembayaran">

            <label>
                <span>Cari peserta</span>
                <div class="reminder-input-icon">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / nomor WA">
                </div>
            </label>

            <label>
                <span>Bulan pembayaran</span>
                <select name="bulan">
                    <option value="">Semua bulan</option>
                    <?php foreach ($bulanList as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $bulan === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Halaqoh</span>
                <select name="halaqoh">
                    <option value="">Semua halaqoh</option>
                    <?php foreach ($halaqohList as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $halaqoh === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Status peserta</span>
                <select name="status_peserta">
                    <option value="proses" <?= $statusPeserta === 'proses' ? 'selected' : '' ?>>Proses</option>
                    <option value="selesai" <?= $statusPeserta === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                    <option value="semua" <?= $statusPeserta === 'semua' ? 'selected' : '' ?>>Semua status</option>
                </select>
            </label>

            <label>
                <span>Status pembayaran</span>
                <select name="status_bayar">
                    <option value="belum_lunas" <?= $statusBayar === 'belum_lunas' ? 'selected' : '' ?>>Belum bayar</option>
                    <option value="lunas" <?= $statusBayar === 'lunas' ? 'selected' : '' ?>>Sudah bayar</option>
                    <option value="semua" <?= $statusBayar === 'semua' ? 'selected' : '' ?>>Semua</option>
                </select>
            </label>

            <button class="reminder-filter-btn" type="submit">
                <i class="fa-solid fa-filter"></i> Terapkan
            </button>
        </form>

        <?php if (!$hasFilter): ?>
            <div class="reminder-empty">
                <i class="fa-solid fa-filter"></i>
                <strong>Gunakan filter untuk menampilkan peserta</strong>
                <span>Daftar peserta dan ringkasan akan dimuat setelah filter dijalankan.</span>
            </div>
        <?php else: ?>
            <div class="reminder-selectbar">
                <label>
                    <input type="checkbox" id="reminderSelectAll">
                    <span>Pilih semua yang tampil</span>
                </label>
                <span><?= $totalPeserta > 100 ? '100 dari ' . $totalPeserta : $totalPeserta ?> peserta</span>
            </div>

            <div class="reminder-list">
                <?php if (!$participants): ?>
                    <div class="reminder-empty">
                        <i class="fa-regular fa-face-frown"></i>
                        <strong>Target tidak ditemukan</strong>
                        <span>Coba ubah filter status peserta, pembayaran, bulan, halaqoh, atau pencarian.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($participants as $p): ?>
                        <label class="reminder-person">
                            <input
                                type="checkbox"
                                class="reminder-target"
                                value="<?= (int)$p['id'] ?>"
                                data-name="<?= htmlspecialchars($p['nama_lengkap'], ENT_QUOTES) ?>"
                                data-wa="<?= htmlspecialchars($p['nowa'], ENT_QUOTES) ?>"
                            >
                            <span class="reminder-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$p['nama_lengkap'], 0, 1))) ?></span>
                            <span class="reminder-person-body">
                                <strong><?= htmlspecialchars($p['nama_lengkap']) ?></strong>
                                <small><?= htmlspecialchars($p['nowa']) ?> · <?= htmlspecialchars($p['halaqoh'] ?: '-') ?></small>
                                <em class="reminder-history">
                                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                    <?= htmlspecialchars($formatReminderHistory((int)$p['reminder_count'], $p['reminder_last_at'])) ?>
                                </em>
                            </span>
                            <span class="reminder-person-status <?= (int)$p['is_lunas'] ? 'paid' : 'unpaid' ?>">
                                <?= (int)$p['is_lunas'] ? 'Lunas' : 'Belum bayar' ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <aside class="reminder-side">
        <form class="reminder-card reminder-compose-card" id="reminderSendForm" method="post" action="actions/reminder-send.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="mode" value="participants">
            <input type="hidden" name="selected" id="reminderSelectedInput" value="[]">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
            <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan, ENT_QUOTES) ?>">
            <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
            <input type="hidden" name="status_peserta" value="<?= htmlspecialchars($statusPeserta, ENT_QUOTES) ?>">
            <input type="hidden" name="status_bayar" value="<?= htmlspecialchars($statusBayar, ENT_QUOTES) ?>">

            <div class="reminder-card-head">
                <div><span class="reminder-kicker">Pesan</span><h2>Kirim reminder</h2></div>
                <span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span>
            </div>

            <label class="reminder-field">
                <span>Template</span>
                <select name="template_id" id="reminderTemplate" <?= !$templates ? 'disabled' : '' ?>>
                    <option value="">Pilih template...</option>
                    <?php foreach ($templates as $tpl): ?>
                        <option value="<?= (int)$tpl['id'] ?>" data-content="<?= htmlspecialchars($tpl['content'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($tpl['title']) ?><?= $tpl['category'] ? ' · ' . htmlspecialchars($tpl['category']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="reminder-field">
                <span>Preview pesan</span>
                <textarea id="reminderPreview" rows="9" readonly placeholder="Pilih template untuk melihat preview."></textarea>
            </label>

            <div class="reminder-helper">
                <i class="fa-solid fa-circle-info"></i>
                <span><code>{nama}</code> akan otomatis diganti dengan nama peserta.</span>
            </div>

            <button class="reminder-send-btn" type="submit" <?= !$templates ? 'disabled' : '' ?>>
                <i class="fa-solid fa-paper-plane"></i>
                <span>Kirim ke <b id="reminderSendCount">0</b> peserta</span>
            </button>
        </form>

        <div class="reminder-card">
            <div class="reminder-card-head compact">
                <div><span class="reminder-kicker">Masuk dari pengajar</span><h2>Permintaan reminder</h2></div>
                <span class="reminder-count"><?= count($pendingRequests) ?></span>
            </div>

            <div class="reminder-request-list">
                <?php if (!$pendingRequests): ?>
                    <div class="reminder-empty compact"><i class="fa-regular fa-inbox"></i><span>Belum ada permintaan.</span></div>
                <?php else: ?>
                    <?php foreach ($pendingRequests as $req): ?>
                        <div class="reminder-request">
                            <div>
                                <strong><?= htmlspecialchars($req['peserta_nama']) ?></strong>
                                <small><?= htmlspecialchars($req['halaqoh'] ?: '-') ?> · <?= htmlspecialchars(mb_strimwidth($req['pesan_pengajar'], 0, 80, '…')) ?></small>
                            </div>
                            <span class="reminder-request-status <?= ($req['status'] ?? '') === 'terkirim' ? 'sent' : 'pending' ?>">
                                <?= ($req['status'] ?? '') === 'terkirim' ? 'Terkirim' : 'Menunggu' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <a class="reminder-secondary-link" href="../kelola_reminder.php">
                Buka semua permintaan <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </aside>
</div>

<script>
(() => {
    const checks = [...document.querySelectorAll('.reminder-target')];
    const all = document.getElementById('reminderSelectAll');
    const count = document.getElementById('reminderSelectedCount');
    const sendCount = document.getElementById('reminderSendCount');
    const input = document.getElementById('reminderSelectedInput');
    const form = document.getElementById('reminderSendForm');
    const template = document.getElementById('reminderTemplate');
    const preview = document.getElementById('reminderPreview');

    function selected() {
        return checks.filter(x => x.checked).map(x => ({
            id: x.value,
            name: x.dataset.name || '',
            nowa: x.dataset.wa || ''
        }));
    }

    function refresh() {
        const items = selected();
        count.textContent = items.length + ' dipilih';
        sendCount.textContent = items.length;
        input.value = JSON.stringify(items);
        if (all) all.checked = checks.length > 0 && items.length === checks.length;
    }

    checks.forEach(x => x.addEventListener('change', refresh));
    all?.addEventListener('change', () => {
        checks.forEach(x => x.checked = all.checked);
        refresh();
    });

    template?.addEventListener('change', () => {
        const option = template.options[template.selectedIndex];
        preview.value = option?.dataset.content || '';
    });

    form?.addEventListener('submit', event => {
        const items = selected();

        if (!items.length) {
            event.preventDefault();
            alert('Pilih minimal satu peserta terlebih dahulu.');
            return;
        }

        if (!template?.value) {
            event.preventDefault();
            alert('Pilih template reminder terlebih dahulu.');
            return;
        }

        if (!confirm('Kirim reminder ke ' + items.length + ' peserta sekarang?')) {
            event.preventDefault();
        }
    });

    refresh();
})();
</script>
