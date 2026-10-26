<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/reminder-csv.php';
require_once __DIR__ . '/../lib/reminder-schedule.php';

crmEnsureReminderScheduleTables($conn);
$activeCampaigns = crmGetActiveCampaigns($conn, 3);

$crmTitle = 'Reminder Pembayaran';
$search = trim((string)($_GET['q'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$bulan = trim((string)($_GET['bulan'] ?? ''));
$csvFile = basename(trim((string)($_GET['csv_file'] ?? '')));
$statusBayar = (string)($_GET['status_bayar'] ?? 'belum_lunas');
$statusPeserta = (string)($_GET['status_peserta'] ?? 'proses');
$hasFilter = isset($_GET['q']) || isset($_GET['halaqoh']) || isset($_GET['bulan']) || isset($_GET['status_bayar']) || isset($_GET['status_peserta']) || isset($_GET['csv_file']);

$halaqohList = [];
$r = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($r) while ($row = $r->fetch_assoc()) $halaqohList[] = (string)$row['halaqoh'];

$bulanList = [];
$r = $conn->query("SELECT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran <> '' GROUP BY bulan_pembayaran ORDER BY MAX(id) DESC");
if ($r) while ($row = $r->fetch_assoc()) $bulanList[] = (string)$row['bulan_pembayaran'];

$csvImports = crmReminderCsvList();

$templates = [];
$r = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY category, title");
if ($r) $templates = $r->fetch_all(MYSQLI_ASSOC);

$participants = [];
$totalPeserta = 0;
$belumBayar = 0;
$todaySent = 0;

$csvTargetByPesertaId = [];
$csvParticipantIds = [];

if ($csvFile !== '') {
    try {
        $csvRows = crmReminderCsvRead($csvFile);
        $waMap = [];

        $result = $conn->query("SELECT id, nowa FROM peserta WHERE nowa IS NOT NULL AND nowa <> ''");
        if ($result) {
            while ($participant = $result->fetch_assoc()) {
                $normalized = crmReminderCsvNormalizeWa((string)$participant['nowa']);
                if ($normalized === '') continue;
                if (array_key_exists($normalized, $waMap)) {
                    $waMap[$normalized] = 0;
                } else {
                    $waMap[$normalized] = (int)$participant['id'];
                }
            }
        }

        foreach ($csvRows as $row) {
            if (crmReminderCsvNormalizeStatus((string)$row['status_siswa']) !== 'ON') continue;
            $normalized = (string)$row['normalized_wa'];
            if ($normalized === '' || !array_key_exists($normalized, $waMap) || $waMap[$normalized] === 0) continue;

            $pesertaId = (int)$waMap[$normalized];
            $csvParticipantIds[$pesertaId] = true;
            $csvTargetByPesertaId[$pesertaId] = crmReminderCsvNormalizeWa((string)$row['whatsapp_wali']);
        }
    } catch (Throwable $e) {
        error_log('CRM CSV source read failed: ' . $e->getMessage());
        $csvFile = '';
        $csvTargetByPesertaId = [];
        $csvParticipantIds = [];
    }
}

$where = ["p.nowa IS NOT NULL", "p.nowa <> ''"];
$params = [];
$types = '';

if ($csvFile !== '') {
    $ids = array_map('intval', array_keys($csvParticipantIds));
    if (!$ids) {
        $where[] = '1 = 0';
    } else {
        $where[] = 'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $id) {
            $params[] = $id;
            $types .= 'i';
        }
    }
}

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

$pageNumber = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($pageNumber - 1) * $perPage;
$totalPages = 1;

if ($hasFilter) {
    $summarySql = "SELECT COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN {$paymentStatusSql} = 0 THEN 1 ELSE 0 END), 0) AS belum_bayar
        FROM peserta p {$paymentJoin}
        WHERE " . implode(' AND ', $where);

    $summaryStmt = $conn->prepare($summarySql);
    if ($summaryStmt) {
        $summaryParams = $params;
        $summaryRefs = [];
        foreach ($summaryParams as $k => $v) $summaryRefs[$k] = &$summaryParams[$k];
        if ($summaryParams) call_user_func_array([$summaryStmt, 'bind_param'], array_merge([$types], $summaryRefs));
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
        $summaryStmt->close();
        $totalPeserta = (int)($summary['total'] ?? 0);
        $belumBayar = (int)($summary['belum_bayar'] ?? 0);
    }

    $totalPages = max(1, (int)ceil($totalPeserta / $perPage));
    if ($pageNumber > $totalPages && $totalPeserta > 0) {
        $pageNumber = $totalPages;
        $offset = ($pageNumber - 1) * $perPage;
    }

    $sql = "SELECT p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status,
            {$paymentStatusSql} AS is_lunas
            FROM peserta p {$paymentJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.halaqoh, p.nama_lengkap
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $displayParams = $params;
        $displayParams[] = $perPage;
        $displayParams[] = $offset;
        $displayTypes = $types . 'ii';
        $displayRefs = [];
        foreach ($displayParams as $k => $v) $displayRefs[$k] = &$displayParams[$k];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$displayTypes], $displayRefs));
        $stmt->execute();
        $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // Reminder history is optional UI metadata. Never let a history/log failure
    // abort the whole Reminder Pembayaran page after a template save/edit redirect.
    $historyMap = [];
    $targetWaByPesertaId = [];

    try {
        $logNumbers = [];

        foreach ($participants as $participant) {
            $participantId = (int)$participant['id'];
            $rawTarget = $csvFile !== ''
                ? (string)($csvTargetByPesertaId[$participantId] ?? '')
                : (string)$participant['nowa'];
            $normalized = crmReminderCsvNormalizeWa($rawTarget);
            if ($normalized === '') continue;

            $targetWaByPesertaId[$participantId] = $normalized;
            $logNumbers[$normalized] = true;
            $logNumbers['+' . $normalized] = true;

            if (str_starts_with($normalized, '62')) {
                $local = '0' . substr($normalized, 2);
                $logNumbers[$local] = true;
                $logNumbers['+' . $local] = true;
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
            if (!$logStmt) {
                throw new RuntimeException('Gagal menyiapkan query riwayat reminder.');
            }

            $logParams = $logNumbers;
            $logRefs = [];
            foreach ($logParams as $k => $v) $logRefs[$k] = &$logParams[$k];
            call_user_func_array([$logStmt, 'bind_param'], array_merge([$logTypes], $logRefs));
            $logStmt->execute();
            $logResult = $logStmt->get_result();

            while ($log = $logResult->fetch_assoc()) {
                $normalized = crmReminderCsvNormalizeWa((string)$log['nowa']);
                if ($normalized === '') continue;
                if (!isset($historyMap[$normalized])) {
                    $historyMap[$normalized] = ['count' => 0, 'last_at' => null, 'today' => false];
                }
                $historyMap[$normalized]['count']++;
                if ($historyMap[$normalized]['last_at'] === null) {
                    $historyMap[$normalized]['last_at'] = $log['created_at'];
                }
                if (date('Y-m-d', strtotime((string)$log['created_at'])) === date('Y-m-d')) {
                    $historyMap[$normalized]['today'] = true;
                }
            }
            $logStmt->close();
        }
    } catch (Throwable $historyError) {
        error_log('CRM reminder history read failed: ' . $historyError->getMessage());
        $historyMap = [];
    }

    $todaySent = 0;
    $todaySeen = [];
    foreach ($targetWaByPesertaId as $participantId => $normalizedTarget) {
        if (!empty($historyMap[$normalizedTarget]['today']) && !isset($todaySeen[$participantId])) {
            $todaySeen[$participantId] = true;
            $todaySent++;
        }
    }

    foreach ($participants as &$participant) {
        $normalized = $targetWaByPesertaId[(int)$participant['id']] ?? crmReminderCsvNormalizeWa((string)$participant['nowa']);
        $participant['reminder_count'] = (int)($historyMap[$normalized]['count'] ?? 0);
        $participant['reminder_last_at'] = $historyMap[$normalized]['last_at'] ?? null;
    }
    unset($participant);
}

$formatReminderHistory = static function (int $count, ?string $lastAt): string {
    if ($count < 1 || !$lastAt) return 'Belum pernah dihubungi';
    $timestamp = strtotime($lastAt);
    if (!$timestamp) return $count . 'x';
    $date = date('Y-m-d', $timestamp) === date('Y-m-d') ? 'Hari ini ' . date('H:i', $timestamp) : date('d/m/Y H:i', $timestamp);
    return $count . 'x · ' . $date;
};

$buildPaginationUrl = static function (int $targetPage) use ($search, $halaqoh, $bulan, $csvFile, $statusBayar, $statusPeserta): string {
    $queryArgs = [
        'page' => 'reminder-pembayaran',
        'p' => $targetPage,
    ];
    if ($search !== '') $queryArgs['q'] = $search;
    if ($halaqoh !== '') $queryArgs['halaqoh'] = $halaqoh;
    if ($bulan !== '') $queryArgs['bulan'] = $bulan;
    if ($csvFile !== '') $queryArgs['csv_file'] = $csvFile;
    if ($statusBayar !== '') $queryArgs['status_bayar'] = $statusBayar;
    if ($statusPeserta !== '') $queryArgs['status_peserta'] = $statusPeserta;
    return '?' . http_build_query($queryArgs);
};
?>
<div class="reminder-payment-page">
    <div class="reminder-payment-topbar">
        <a class="reminder-back-btn" href="?page=reminder&tab=pembayaran" title="Kembali ke Reminder" aria-label="Kembali ke Reminder">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        </a>
    </div>

    <div class="reminder-payment-grid">
        <div class="reminder-payment-main">
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

            <?php if (!empty($activeCampaigns)): ?>
                <div class="reminder-campaigns" style="margin-bottom:16px">
                    <?php foreach ($activeCampaigns as $camp): 
                        $cId = (int)$camp['id'];
                        $totalTgt = max(1, (int)$camp['total_targets']);
                        $sentCnt = (int)$camp['sent_count'];
                        $failCnt = (int)$camp['failed_count'];
                        $pct = min(100, (int)round(($sentCnt + $failCnt) / $totalTgt * 100));
                        $cStatus = (string)$camp['status'];
                        $nextAt = !empty($camp['next_scheduled_at']) ? date('d/m/Y H:i', strtotime($camp['next_scheduled_at'])) : '-';
                        $curHal = !empty($camp['current_halaqoh']) ? $camp['current_halaqoh'] : '-';
                    ?>
                        <div class="reminder-card reminder-campaign-card">
                            <div class="reminder-campaign-header">
                                <div>
                                    <span class="reminder-kicker">Otomasi Berjalan</span>
                                    <h2 style="font-size:16px;margin:2px 0 0"><?= htmlspecialchars($camp['title']) ?></h2>
                                </div>
                                <span class="reminder-campaign-badge <?= $cStatus === 'running' ? 'running' : 'paused' ?>">
                                    <i class="fa-solid <?= $cStatus === 'running' ? 'fa-circle-play' : 'fa-circle-pause' ?>"></i>
                                    <?= $cStatus === 'running' ? 'Berjalan' : 'Dijeda' ?>
                                </span>
                            </div>

                            <div class="reminder-campaign-progress">
                                <div class="reminder-campaign-progress-bar" style="width:<?= $pct ?>%"></div>
                            </div>

                            <div class="reminder-campaign-meta">
                                <span>Terkirim: <strong><?= $sentCnt ?> / <?= $totalTgt ?></strong> (<?= $pct ?>%)</span>
                                <span>Halaqoh aktif: <strong><?= htmlspecialchars($curHal) ?></strong></span>
                                <span>Jadwal berikutnya: <strong><?= htmlspecialchars($nextAt) ?></strong></span>
                            </div>

                            <div class="reminder-campaign-actions">
                                <?php if ($cStatus === 'running'): ?>
                                    <form method="post" action="actions/reminder-schedule.php" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="pause">
                                        <input type="hidden" name="campaign_id" value="<?= $cId ?>">
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                        <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan, ENT_QUOTES) ?>">
                                        <input type="hidden" name="csv_file" value="<?= htmlspecialchars($csvFile, ENT_QUOTES) ?>">
                                        <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
                                        <input type="hidden" name="status_peserta" value="<?= htmlspecialchars($statusPeserta, ENT_QUOTES) ?>">
                                        <input type="hidden" name="status_bayar" value="<?= htmlspecialchars($statusBayar, ENT_QUOTES) ?>">
                                        <input type="hidden" name="p" value="<?= (int)$pageNumber ?>">
                                        <button type="submit" class="reminder-campaign-btn pause">
                                            <i class="fa-solid fa-pause"></i> Jeda
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="actions/reminder-schedule.php" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="resume">
                                        <input type="hidden" name="campaign_id" value="<?= $cId ?>">
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                        <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan, ENT_QUOTES) ?>">
                                        <input type="hidden" name="csv_file" value="<?= htmlspecialchars($csvFile, ENT_QUOTES) ?>">
                                        <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
                                        <input type="hidden" name="status_peserta" value="<?= htmlspecialchars($statusPeserta, ENT_QUOTES) ?>">
                                        <input type="hidden" name="status_bayar" value="<?= htmlspecialchars($statusBayar, ENT_QUOTES) ?>">
                                        <input type="hidden" name="p" value="<?= (int)$pageNumber ?>">
                                        <button type="submit" class="reminder-campaign-btn resume">
                                            <i class="fa-solid fa-play"></i> Lanjutkan
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="actions/reminder-schedule.php" style="display:inline" onsubmit="return confirm('Yakin ingin membatalkan jadwal otomasi ini?')">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="campaign_id" value="<?= $cId ?>">
                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                    <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan, ENT_QUOTES) ?>">
                                    <input type="hidden" name="csv_file" value="<?= htmlspecialchars($csvFile, ENT_QUOTES) ?>">
                                    <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
                                    <input type="hidden" name="status_peserta" value="<?= htmlspecialchars($statusPeserta, ENT_QUOTES) ?>">
                                    <input type="hidden" name="status_bayar" value="<?= htmlspecialchars($statusBayar, ENT_QUOTES) ?>">
                                    <input type="hidden" name="p" value="<?= (int)$pageNumber ?>">
                                    <button type="submit" class="reminder-campaign-btn cancel">
                                        <i class="fa-solid fa-stop"></i> Batalkan
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="reminder-card reminder-target-card">
                <div class="reminder-card-head">
                    <div>
                        <span class="reminder-kicker">Target peserta</span>
                        <h2>Peserta reminder</h2>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px">
                        <a class="reminder-secondary-link" href="?page=reminder-csv" style="margin:0;padding:5px 10px;border:1px solid #dbe5df;border-radius:8px;background:#f8fafc;font-size:11px">
                            <i class="fa-solid fa-file-csv"></i> Kelola CSV
                        </a>
                        <span class="reminder-count" id="reminderSelectedCount">0 dipilih</span>
                    </div>
                </div>

                <div class="reminder-quick-filters">
                    <span style="font-size:11px;font-weight:700;color:#65756d;margin-right:2px">
                        <i class="fa-solid fa-bolt" style="color:#d97706"></i> Filter cepat:
                    </span>
                    <a href="?page=reminder-pembayaran&status_peserta=proses&status_bayar=belum_lunas<?= $bulanList ? '&bulan=' . urlencode($bulanList[0]) : '' ?>" 
                       class="reminder-quick-chip <?= ($statusBayar === 'belum_lunas' && $statusPeserta === 'proses' && ($bulan !== '' || empty($bulanList))) ? 'active' : '' ?>">
                        <i class="fa-solid fa-clock"></i> Belum bayar bulan ini
                    </a>
                    <a href="?page=reminder-pembayaran&status_peserta=proses&status_bayar=semua" 
                       class="reminder-quick-chip <?= ($statusPeserta === 'proses' && $statusBayar === 'semua' && $bulan === '') ? 'active' : '' ?>">
                        <i class="fa-solid fa-users"></i> Semua aktif
                    </a>
                    <?php if ($hasFilter): ?>
                        <a href="?page=reminder-pembayaran" class="reminder-quick-chip reset">
                            <i class="fa-solid fa-rotate-left"></i> Reset filter
                        </a>
                    <?php endif; ?>
                </div>

                <form class="reminder-filters reminder-filter-box" method="get">
                    <input type="hidden" name="page" value="reminder-pembayaran">

                    <label class="reminder-filter-span-2">
                        <span>Cari peserta</span>
                        <div class="reminder-input-icon">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / nomor WA">
                        </div>
                    </label>

                    <label>
                        <span>Sumber peserta</span>
                        <select name="csv_file">
                            <option value="">Master peserta CRM</option>
                            <?php foreach ($csvImports as $item): ?>
                                <option value="<?= htmlspecialchars((string)$item['file'], ENT_QUOTES) ?>" <?= $csvFile === (string)$item['file'] ? 'selected' : '' ?>>CSV · <?= htmlspecialchars((string)$item['label']) ?> · <?= (int)$item['matched_count'] ?> cocok</option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button class="reminder-filter-btn" type="submit">
                        <i class="fa-solid fa-filter"></i> Terapkan
                    </button>

                    <label>
                        <span>Periode pembayaran</span>
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
                        <div style="display:flex;align-items:center;gap:10px">
                            <button type="button" id="reminderClearSelection" class="reminder-clear-btn" style="display:none">
                                <i class="fa-solid fa-xmark"></i> Batal pilih
                            </button>
                            <span>Halaman <?= $pageNumber ?> dari <?= $totalPages ?> (<?= count($participants) ?> dari <?= $totalPeserta ?> peserta)</span>
                        </div>
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
                                        data-wa="<?= htmlspecialchars(($csvFile !== '' ? ($csvTargetByPesertaId[(int)$p['id']] ?? '') : $p['nowa']), ENT_QUOTES) ?>"
                                        data-halaqoh="<?= htmlspecialchars($p['halaqoh'] ?: '-', ENT_QUOTES) ?>"
                                    >
                                    <span class="reminder-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$p['nama_lengkap'], 0, 1))) ?></span>
                                    <span class="reminder-person-body">
                                        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
                                            <strong><?= htmlspecialchars($p['nama_lengkap']) ?></strong>
                                            <span class="reminder-badge-halaqoh" title="Halaqoh: <?= htmlspecialchars($p['halaqoh'] ?: '-') ?>">
                                                <i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($p['halaqoh'] ?: '-') ?>
                                            </span>
                                        </div>
                                        <small><?= htmlspecialchars($csvFile !== '' ? (($csvTargetByPesertaId[(int)$p['id']] ?? '') ?: $p['nowa']) : $p['nowa']) ?></small>
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

                    <?php if ($totalPages > 1): ?>
                        <div class="reminder-pagination">
                            <span>Menampilkan <?= count($participants) ?> dari <?= $totalPeserta ?> peserta</span>
                            <div class="reminder-pagination-pages">
                                <?php if ($pageNumber > 1): ?>
                                    <a class="reminder-page-link" href="<?= htmlspecialchars($buildPaginationUrl($pageNumber - 1)) ?>" title="Halaman Sebelumnya">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="reminder-page-link disabled"><i class="fa-solid fa-chevron-left"></i></span>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $pageNumber - 2);
                                $endPage = min($totalPages, $pageNumber + 2);
                                if ($startPage > 1): ?>
                                    <a class="reminder-page-link" href="<?= htmlspecialchars($buildPaginationUrl(1)) ?>">1</a>
                                    <?php if ($startPage > 2): ?><span style="padding:0 3px;color:#94a3b8">…</span><?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a class="reminder-page-link <?= $i === $pageNumber ? 'active' : '' ?>" href="<?= htmlspecialchars($buildPaginationUrl($i)) ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?><span style="padding:0 3px;color:#94a3b8">…</span><?php endif; ?>
                                    <a class="reminder-page-link" href="<?= htmlspecialchars($buildPaginationUrl($totalPages)) ?>"><?= $totalPages ?></a>
                                <?php endif; ?>

                                <?php if ($pageNumber < $totalPages): ?>
                                    <a class="reminder-page-link" href="<?= htmlspecialchars($buildPaginationUrl($pageNumber + 1)) ?>" title="Halaman Berikutnya">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="reminder-page-link disabled"><i class="fa-solid fa-chevron-right"></i></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>

        <aside class="reminder-payment-side reminder-side">
            <form class="reminder-card reminder-compose-card" id="reminderSendForm" method="post" action="actions/reminder-send.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                <input type="hidden" name="mode" value="participants">
                <input type="hidden" name="selected" id="reminderSelectedInput" value="[]">
                <input type="hidden" name="p" value="<?= (int)$pageNumber ?>">
                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan, ENT_QUOTES) ?>">
                <input type="hidden" name="csv_file" value="<?= htmlspecialchars($csvFile, ENT_QUOTES) ?>">
                <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
                <input type="hidden" name="status_peserta" value="<?= htmlspecialchars($statusPeserta, ENT_QUOTES) ?>">
                <input type="hidden" name="status_bayar" value="<?= htmlspecialchars($statusBayar, ENT_QUOTES) ?>">

                <div class="reminder-card-head">
                    <div><span class="reminder-kicker">Pesan</span><h2>Kirim reminder</h2></div>
                    <span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span>
                </div>

                <div class="reminder-field">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                        <span>Template</span>
                        <button type="button" class="reminder-secondary-link" id="manageReminderTemplates" style="border:0;background:transparent;padding:0;cursor:pointer">
                            Kelola template
                        </button>
                    </div>
                    <select name="template_id" id="reminderTemplate" <?= !$templates ? 'disabled' : '' ?>>
                        <option value="">Pilih template...</option>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= (int)$tpl['id'] ?>" data-content="<?= htmlspecialchars($tpl['content'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($tpl['title']) ?><?= $tpl['category'] ? ' · ' . htmlspecialchars($tpl['category']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <label class="reminder-field">
                    <span>Preview pesan</span>
                    <textarea id="reminderPreview" rows="9" readonly placeholder="Pilih template untuk melihat preview."></textarea>
                </label>

                <div class="reminder-helper">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>Variabel <code>{nama}</code>, <code>{bulan}</code>, dan <code>{halaqoh}</code> akan otomatis diganti.</span>
                </div>

                <button class="reminder-send-btn" type="submit" <?= !$templates ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Kirim ke <b id="reminderSendCount">0</b> peserta</span>
                </button>

                <button type="button" class="reminder-schedule-btn" id="openScheduleModalBtn" <?= !$templates ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-clock"></i>
                    <span>Atur Jadwal Otomasi (<b class="scheduleSelectedCount">0</b>)</span>
                </button>
            </form>
        </aside>
    </div>
</div>

<div id="reminderScheduleModal" hidden style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.42);padding:20px;align-items:center;justify-content:center">
    <div style="width:min(640px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:20px;padding:22px;box-shadow:0 20px 60px rgba(15,23,42,.2)">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px">
            <div>
                <span class="reminder-kicker">Otomasi Pengiriman</span>
                <h2 style="margin:3px 0 0">Jadwal Reminder Bertahap</h2>
            </div>
            <button type="button" id="closeReminderScheduleModal" aria-label="Tutup" style="border:0;background:#f1f5f9;border-radius:10px;width:36px;height:36px;cursor:pointer">×</button>
        </div>

        <form method="post" action="actions/reminder-schedule.php" id="reminderScheduleForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="selected" id="reminderScheduleSelectedInput" value="[]">
            <input type="hidden" name="p" value="<?= (int)$pageNumber ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
            <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan, ENT_QUOTES) ?>">
            <input type="hidden" name="csv_file" value="<?= htmlspecialchars($csvFile, ENT_QUOTES) ?>">
            <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
            <input type="hidden" name="status_peserta" value="<?= htmlspecialchars($statusPeserta, ENT_QUOTES) ?>">
            <input type="hidden" name="status_bayar" value="<?= htmlspecialchars($statusBayar, ENT_QUOTES) ?>">

            <div style="display:grid;gap:14px">
                <label class="reminder-field">
                    <span>Nama Jadwal / Kampanye</span>
                    <input type="text" name="title" id="scheduleCampaignTitle" maxlength="150" value="Reminder Tagihan <?= date('d M Y H:i') ?>" required>
                </label>

                <div class="reminder-field">
                    <span>Template Pesan</span>
                    <select name="template_id" id="scheduleTemplateSelect" required>
                        <option value="">Pilih template...</option>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= (int)$tpl['id'] ?>">
                                <?= htmlspecialchars($tpl['title']) ?><?= $tpl['category'] ? ' · ' . htmlspecialchars($tpl['category']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="background:#f8fbf9;border:1px solid #dcebe1;border-radius:14px;padding:14px">
                    <span style="display:block;font-size:11px;font-weight:800;color:#166534;margin-bottom:10px">
                        <i class="fa-solid fa-sliders"></i> Pengaturan Jeda & Waktu Otomasi
                    </span>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                        <label class="reminder-field" style="margin-top:0">
                            <span>Jeda Antar Pesan (menit)</span>
                            <input type="number" name="delay_message" id="scheduleDelayMessage" min="1" max="60" value="2" required>
                            <small style="color:#64748b;font-size:10px">Jarak waktu antar nomor dalam 1 halaqoh</small>
                        </label>
                        <label class="reminder-field" style="margin-top:0">
                            <span>Jeda Antar Halaqoh (menit)</span>
                            <input type="number" name="delay_halaqoh" id="scheduleDelayHalaqoh" min="1" max="300" value="15" required>
                            <small style="color:#64748b;font-size:10px">Cooldown sebelum lanjut ke halaqoh berikutnya</small>
                        </label>
                    </div>

                    <div class="reminder-field" style="margin-top:0">
                        <span>Waktu Mulai Pengiriman</span>
                        <div style="display:flex;gap:16px;align-items:center;margin:6px 0">
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:11px;font-weight:700">
                                <input type="radio" name="start_type" value="now" checked style="width:auto;height:auto;accent-color:#168044">
                                Langsung Mulai Sekarang
                            </label>
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:11px;font-weight:700">
                                <input type="radio" name="start_type" value="custom" style="width:auto;height:auto;accent-color:#168044">
                                Tentukan Waktu Tertentu
                            </label>
                        </div>
                        <input type="datetime-local" name="start_datetime" id="scheduleStartDatetime" style="display:none;margin-top:4px" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>

                <div style="background:#f1f5f9;border-radius:12px;padding:12px 14px;font-size:11px;line-height:1.55;color:#334155" id="scheduleEstimateBox">
                    <div style="display:flex;justify-content:space-between;gap:8px">
                        <span>Target Terpilih: <strong id="scheduleEstimateTargets">0 orang</strong></span>
                        <span>Jumlah Halaqoh: <strong id="scheduleEstimateHalaqoh">0 grup</strong></span>
                    </div>
                    <div style="margin-top:6px;padding-top:6px;border-top:1px dashed #cbd5e1;color:#0f172a">
                        Estimasi selesai: <strong id="scheduleEstimateDuration">-</strong> (Selesai sekitar <b id="scheduleEstimateEndTime">-</b>)
                    </div>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
                <button type="button" id="cancelScheduleModal" class="reminder-clear-btn" style="padding:10px 16px;font-size:11px;border-radius:10px">Tutup</button>
                <button type="submit" class="reminder-send-btn" style="width:auto;padding:10px 18px;margin-top:0">
                    <i class="fa-solid fa-clock"></i> Simpan & Jadwalkan Otomasi
                </button>
            </div>
        </form>
    </div>
</div>

<div id="reminderTemplateModal" hidden style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.42);padding:20px;align-items:center;justify-content:center">
    <div style="width:min(680px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:20px;padding:22px;box-shadow:0 20px 60px rgba(15,23,42,.2)">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px">
            <div>
                <span class="reminder-kicker">Kelola pesan</span>
                <h2 id="templateModalTitle" style="margin:3px 0 0">Template WhatsApp</h2>
            </div>
            <button type="button" id="closeReminderTemplateModal" aria-label="Tutup" style="border:0;background:#f1f5f9;border-radius:10px;width:36px;height:36px;cursor:pointer">×</button>
        </div>

        <div id="templateManagerList" style="display:grid;gap:8px;margin-bottom:16px">
            <?php foreach ($templates as $tpl): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid #e2e8f0;border-radius:12px">
                    <div style="flex:1;min-width:0">
                        <strong style="display:block"><?= htmlspecialchars($tpl['title']) ?></strong>
                        <small style="color:#64748b"><?= htmlspecialchars($tpl['category'] ?: 'Tanpa kategori') ?></small>
                    </div>
                    <button type="button" class="edit-reminder-template" data-id="<?= (int)$tpl['id'] ?>" data-category="<?= htmlspecialchars($tpl['category'], ENT_QUOTES) ?>" data-title="<?= htmlspecialchars($tpl['title'], ENT_QUOTES) ?>" data-content="<?= htmlspecialchars($tpl['content'], ENT_QUOTES) ?>" style="border:1px solid #dbe3ea;background:#fff;border-radius:9px;padding:7px 10px;cursor:pointer">
                        Edit
                    </button>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" action="actions/reminder-template-save.php" id="reminderTemplateForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="template_id" id="reminderTemplateId" value="">
            <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query(array_filter([
                'page'=>'reminder-pembayaran','q'=>$search,'bulan'=>$bulan,'csv_file'=>$csvFile,'halaqoh'=>$halaqoh,'status_peserta'=>$statusPeserta,'status_bayar'=>$statusBayar
            ], static fn($v) => $v !== '')), ENT_QUOTES) ?>">

            <div style="display:grid;gap:12px">
                <label class="reminder-field"><span>Kategori</span><input type="text" name="category" id="reminderTemplateCategory" maxlength="100" placeholder="Pembayaran"></label>
                <label class="reminder-field"><span>Judul template</span><input type="text" name="title" id="reminderTemplateTitle" maxlength="150" required placeholder="Reminder pembayaran"></label>
                <label class="reminder-field"><span>Isi pesan</span><textarea name="content" id="reminderTemplateContent" rows="8" maxlength="5000" required placeholder="Assalamu'alaikum {nama}..."></textarea></label>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
                <button type="button" id="newReminderTemplate" class="reminder-secondary-link" style="border:1px solid #dbe3ea;background:#fff;border-radius:9px;padding:9px 12px;cursor:pointer">+ Template baru</button>
                <button type="submit" class="reminder-send-btn" style="width:auto;padding:10px 16px">Simpan template</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const checks = [...document.querySelectorAll('.reminder-target')];
    const all = document.getElementById('reminderSelectAll');
    const clearBtn = document.getElementById('reminderClearSelection');
    const count = document.getElementById('reminderSelectedCount');
    const sendCount = document.getElementById('reminderSendCount');
    const input = document.getElementById('reminderSelectedInput');
    const form = document.getElementById('reminderSendForm');
    const template = document.getElementById('reminderTemplate');
    const preview = document.getElementById('reminderPreview');

    const scheduleBtn = document.getElementById('openScheduleModalBtn');
    const scheduleModal = document.getElementById('reminderScheduleModal');
    const closeScheduleModal = document.getElementById('closeReminderScheduleModal');
    const cancelScheduleModal = document.getElementById('cancelScheduleModal');
    const scheduleForm = document.getElementById('reminderScheduleForm');
    const scheduleSelectedInput = document.getElementById('reminderScheduleSelectedInput');
    const scheduleTemplateSelect = document.getElementById('scheduleTemplateSelect');
    const scheduleDelayMessage = document.getElementById('scheduleDelayMessage');
    const scheduleDelayHalaqoh = document.getElementById('scheduleDelayHalaqoh');
    const scheduleStartDatetime = document.getElementById('scheduleStartDatetime');
    const scheduleCounts = [...document.querySelectorAll('.scheduleSelectedCount')];
    const startTypeRadios = [...document.querySelectorAll('input[name="start_type"]')];

    let activePreviewTarget = null;

    function selected() {
        return checks.filter(x => x.checked).map(x => ({
            id: x.value,
            name: x.dataset.name || '',
            nowa: x.dataset.wa || '',
            halaqoh: x.dataset.halaqoh || ''
        }));
    }

    function updateScheduleEstimation() {
        const items = selected();
        const countVal = items.length;
        scheduleCounts.forEach(el => el.textContent = countVal);

        const groups = {};
        items.forEach(it => {
            const h = it.halaqoh || 'Umum';
            groups[h] = (groups[h] || 0) + 1;
        });
        const numGroups = Object.keys(groups).length;

        const elTargets = document.getElementById('scheduleEstimateTargets');
        const elHalaqoh = document.getElementById('scheduleEstimateHalaqoh');
        const elDuration = document.getElementById('scheduleEstimateDuration');
        const elEndTime = document.getElementById('scheduleEstimateEndTime');

        if (elTargets) elTargets.textContent = countVal + ' orang';
        if (elHalaqoh) elHalaqoh.textContent = numGroups + ' grup halaqoh';

        if (countVal === 0) {
            if (elDuration) elDuration.textContent = '0 menit';
            if (elEndTime) elEndTime.textContent = '-';
            return;
        }

        const msgDelay = Math.max(1, parseInt(scheduleDelayMessage?.value || 2, 10));
        const halDelay = Math.max(1, parseInt(scheduleDelayHalaqoh?.value || 15, 10));

        const totalMinutes = Math.max(0, (countVal - numGroups) * msgDelay + (numGroups > 0 ? (numGroups - 1) * halDelay : 0));

        let durationText = totalMinutes + ' menit';
        if (totalMinutes >= 60) {
            const hours = Math.floor(totalMinutes / 60);
            const mins = totalMinutes % 60;
            durationText = hours + ' jam ' + (mins > 0 ? mins + ' menit' : '');
        }
        if (elDuration) elDuration.textContent = '~' + durationText;

        const isCustom = document.querySelector('input[name="start_type"]:checked')?.value === 'custom';
        let baseDate = new Date();
        if (isCustom && scheduleStartDatetime?.value) {
            const customDate = new Date(scheduleStartDatetime.value);
            if (!isNaN(customDate.getTime())) baseDate = customDate;
        }
        const endDate = new Date(baseDate.getTime() + totalMinutes * 60 * 1000);
        const timeFormatter = new Intl.DateTimeFormat('id-ID', {
            day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false
        });
        if (elEndTime) elEndTime.textContent = timeFormatter.format(endDate);
    }

    function updatePersonalizedPreview() {
        const option = template?.options[template.selectedIndex];
        let content = option?.dataset.content || '';
        if (!content) {
            if (preview) preview.value = '';
            return;
        }

        const target = activePreviewTarget || checks.find(x => x.checked);
        if (target) {
            const name = target.dataset.name || 'Kak';
            const hal = target.dataset.halaqoh || '-';
            const bul = <?= json_encode($bulan !== '' ? $bulan : 'periode ini', JSON_UNESCAPED_UNICODE) ?>;
            content = content
                .replace(/{nama}|{NAMA}|\[nama\]|\[NAMA\]/g, name)
                .replace(/{bulan}|{BULAN}|\[bulan\]|\[BULAN\]/g, bul)
                .replace(/{halaqoh}|{HALAQOH}|\[halaqoh\]|\[HALAQOH\]/g, hal);
        }
        if (preview) preview.value = content;
    }

    function refresh() {
        const items = selected();
        count.textContent = items.length + ' dipilih';
        sendCount.textContent = items.length;
        input.value = JSON.stringify(items);
        if (all) all.checked = checks.length > 0 && items.length === checks.length;
        if (clearBtn) clearBtn.style.display = items.length > 0 ? 'inline-flex' : 'none';
        updatePersonalizedPreview();
        updateScheduleEstimation();
    }

    checks.forEach(x => x.addEventListener('change', () => {
        if (x.checked) activePreviewTarget = x;
        refresh();
    }));

    all?.addEventListener('change', () => {
        checks.forEach(x => x.checked = all.checked);
        if (all.checked && checks.length > 0) activePreviewTarget = checks[0];
        refresh();
    });

    clearBtn?.addEventListener('click', () => {
        checks.forEach(x => x.checked = false);
        if (all) all.checked = false;
        activePreviewTarget = null;
        refresh();
    });

    document.querySelectorAll('.reminder-person').forEach(row => {
        row.addEventListener('click', event => {
            const chk = row.querySelector('.reminder-target');
            if (chk) {
                activePreviewTarget = chk;
                updatePersonalizedPreview();
            }
        });
    });

    template?.addEventListener('change', () => {
        updatePersonalizedPreview();
    });

    const templateModal = document.getElementById('reminderTemplateModal');
    const manageTemplates = document.getElementById('manageReminderTemplates');
    const closeTemplateModal = document.getElementById('closeReminderTemplateModal');
    const newTemplate = document.getElementById('newReminderTemplate');
    const templateForm = document.getElementById('reminderTemplateForm');
    const templateId = document.getElementById('reminderTemplateId');
    const templateCategory = document.getElementById('reminderTemplateCategory');
    const templateTitle = document.getElementById('reminderTemplateTitle');
    const templateContent = document.getElementById('reminderTemplateContent');

    function openTemplateModal(edit = null) {
        templateModal.hidden = false;
        templateModal.style.display = 'flex';
        if (edit) {
            document.getElementById('templateModalTitle').textContent = 'Edit template';
            templateId.value = edit.id || '';
            templateCategory.value = edit.category || '';
            templateTitle.value = edit.title || '';
            templateContent.value = edit.content || '';
        } else {
            document.getElementById('templateModalTitle').textContent = 'Tambah template';
            templateForm.reset();
            templateId.value = '';
        }
    }

    function closeTemplateManager() {
        templateModal.style.display = 'none';
        templateModal.hidden = true;
    }

    manageTemplates?.addEventListener('click', () => openTemplateModal());
    closeTemplateModal?.addEventListener('click', closeTemplateManager);
    newTemplate?.addEventListener('click', () => openTemplateModal());
    templateModal?.addEventListener('click', event => {
        if (event.target === templateModal) closeTemplateManager();
    });

    document.querySelectorAll('.edit-reminder-template').forEach(button => {
        button.addEventListener('click', () => openTemplateModal({
            id: button.dataset.id,
            category: button.dataset.category,
            title: button.dataset.title,
            content: button.dataset.content
        }));
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
            return;
        }

        const submitBtn = form.querySelector('.reminder-send-btn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Mengirim reminder...</span>';
        }
    });

    function openScheduleManager() {
        const items = selected();
        if (!items.length) {
            alert('Pilih minimal satu peserta terlebih dahulu.');
            return;
        }

        if (scheduleTemplateSelect && template?.value) {
            scheduleTemplateSelect.value = template.value;
        }

        if (scheduleSelectedInput) {
            scheduleSelectedInput.value = JSON.stringify(items);
        }

        updateScheduleEstimation();

        if (scheduleModal) {
            scheduleModal.hidden = false;
            scheduleModal.style.display = 'flex';
        }
    }

    function closeScheduleManager() {
        if (scheduleModal) {
            scheduleModal.style.display = 'none';
            scheduleModal.hidden = true;
        }
    }

    scheduleBtn?.addEventListener('click', openScheduleManager);
    closeScheduleModal?.addEventListener('click', closeScheduleManager);
    cancelScheduleModal?.addEventListener('click', closeScheduleManager);
    scheduleModal?.addEventListener('click', event => {
        if (event.target === scheduleModal) closeScheduleManager();
    });

    startTypeRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            if (scheduleStartDatetime) {
                scheduleStartDatetime.style.display = radio.value === 'custom' && radio.checked ? 'block' : 'none';
            }
            updateScheduleEstimation();
        });
    });

    scheduleDelayMessage?.addEventListener('input', updateScheduleEstimation);
    scheduleDelayHalaqoh?.addEventListener('input', updateScheduleEstimation);
    scheduleStartDatetime?.addEventListener('input', updateScheduleEstimation);

    scheduleForm?.addEventListener('submit', event => {
        const items = selected();
        if (!items.length) {
            event.preventDefault();
            alert('Pilih minimal satu peserta terlebih dahulu.');
            return;
        }

        if (!scheduleTemplateSelect?.value) {
            event.preventDefault();
            alert('Pilih template reminder terlebih dahulu.');
            return;
        }

        if (scheduleSelectedInput) {
            scheduleSelectedInput.value = JSON.stringify(items);
        }

        if (!confirm('Jadwalkan pengiriman reminder bertahap untuk ' + items.length + ' peserta?')) {
            event.preventDefault();
            return;
        }

        const submitBtn = scheduleForm.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan Jadwal...';
        }
    });

    refresh();
})();
</script>
