<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/reminder-csv.php';
require_once __DIR__ . '/../lib/reminder-schedule.php';

crmEnsureReminderScheduleTables($conn);

$cronApiUrl = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : (!empty($apiUrl) ? $apiUrl : '');
$cronApiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : (!empty($apiToken) ? $apiToken : '');
$activeCampaigns = crmGetActiveCampaigns($conn, 3);

// Pemrosesan antrean yang jatuh tempo secara otomatis saat membuka halaman (dengan throttling aman)
$lastQueueRun = (int)($_SESSION['crm_last_queue_check'] ?? 0);
if ((time() - $lastQueueRun) >= 60 && !empty($activeCampaigns) && !empty($cronApiUrl) && !empty($cronApiToken)) {
    $_SESSION['crm_last_queue_check'] = time();
    crmProcessPendingReminderQueue($conn, (string)$cronApiUrl, (string)$cronApiToken, 2);
    $activeCampaigns = crmGetActiveCampaigns($conn, 3);
}

$crmTitle = 'Reminder Pembayaran';

// Filter Persistence across CRM page navigation
$filterKeys = ['q', 'halaqoh', 'bulan', 'csv_file', 'status_bayar', 'status_peserta', 'p'];
$hasAnyFilterParam = false;
foreach ($filterKeys as $k) {
    if (isset($_GET[$k])) {
        $hasAnyFilterParam = true;
        break;
    }
}

if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    unset($_SESSION['crm_reminder_pembayaran_filters']);
    $hasAnyFilterParam = false;
}

if ($hasAnyFilterParam) {
    $_SESSION['crm_reminder_pembayaran_filters'] = [
        'q' => (string)($_GET['q'] ?? ''),
        'halaqoh' => is_array($_GET['halaqoh'] ?? null) ? implode(',', $_GET['halaqoh']) : (string)($_GET['halaqoh'] ?? ''),
        'bulan' => (string)($_GET['bulan'] ?? ''),
        'csv_file' => (string)($_GET['csv_file'] ?? ''),
        'status_bayar' => (string)($_GET['status_bayar'] ?? 'belum_lunas'),
        'status_peserta' => (string)($_GET['status_peserta'] ?? 'proses'),
        'p' => (int)($_GET['p'] ?? 1),
    ];
} elseif (!empty($_SESSION['crm_reminder_pembayaran_filters'])) {
    $savedFilters = $_SESSION['crm_reminder_pembayaran_filters'];
    $_GET['q'] = $savedFilters['q'] ?? '';
    $_GET['halaqoh'] = $savedFilters['halaqoh'] ?? '';
    $_GET['bulan'] = $savedFilters['bulan'] ?? '';
    $_GET['csv_file'] = $savedFilters['csv_file'] ?? '';
    $_GET['status_bayar'] = $savedFilters['status_bayar'] ?? 'belum_lunas';
    $_GET['status_peserta'] = $savedFilters['status_peserta'] ?? 'proses';
    $_GET['p'] = $savedFilters['p'] ?? 1;
}

$search = trim((string)($_GET['q'] ?? ''));
$halaqohRaw = $_GET['halaqoh'] ?? '';
$halaqohSelected = [];
if (is_array($halaqohRaw)) {
    foreach ($halaqohRaw as $h) {
        $ht = trim((string)$h);
        if ($ht !== '') $halaqohSelected[] = $ht;
    }
} elseif (is_string($halaqohRaw) && trim($halaqohRaw) !== '') {
    foreach (explode(',', $halaqohRaw) as $h) {
        $ht = trim($h);
        if ($ht !== '') $halaqohSelected[] = $ht;
    }
}
$halaqohSelected = array_values(array_unique($halaqohSelected));
$halaqoh = implode(',', $halaqohSelected);
$bulan = trim((string)($_GET['bulan'] ?? ''));
$csvFile = basename(trim((string)($_GET['csv_file'] ?? '')));
$statusBayar = (string)($_GET['status_bayar'] ?? 'belum_lunas');
$statusPeserta = (string)($_GET['status_peserta'] ?? 'proses');
$hasFilter = isset($_GET['q']) || isset($_GET['halaqoh']) || isset($_GET['bulan']) || isset($_GET['status_bayar']) || isset($_GET['status_peserta']) || isset($_GET['csv_file']);

$halaqohList = [];
$r = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($r) while ($row = $r->fetch_assoc()) $halaqohList[] = (string)$row['halaqoh'];

$halaqohAk = [];
$halaqohIk = [];
foreach ($halaqohList as $h) {
    if (preg_match('/^AK(\s|\b|$)/i', $h)) {
        $halaqohAk[] = $h;
    } elseif (preg_match('/^IK(\s|\b|$)/i', $h)) {
        $halaqohIk[] = $h;
    }
}
natsort($halaqohAk);
natsort($halaqohIk);
$halaqohAk = array_values($halaqohAk);
$halaqohIk = array_values($halaqohIk);
$halaqohList = array_merge($halaqohAk, $halaqohIk);

// Active items & exclusion logic for AK and IK
$isAkAllExplicit = in_array('AK', $halaqohSelected, true);
$activeAkList = $isAkAllExplicit ? $halaqohAk : array_values(array_intersect($halaqohAk, $halaqohSelected));
$isAllAkSelected = ($isAkAllExplicit || (!empty($halaqohAk) && count($activeAkList) === count($halaqohAk)));

$isIkAllExplicit = in_array('IK', $halaqohSelected, true);
$activeIkList = $isIkAllExplicit ? $halaqohIk : array_values(array_intersect($halaqohIk, $halaqohSelected));
$isAllIkSelected = ($isIkAllExplicit || (!empty($halaqohIk) && count($activeIkList) === count($halaqohIk)));

$otherNonAk = array_values(array_diff($halaqohSelected, array_merge(['AK'], $halaqohAk)));
$otherNonIk = array_values(array_diff($halaqohSelected, array_merge(['IK'], $halaqohIk)));

$defaultCatTab = 'all';
if ($isAllAkSelected || !empty($activeAkList)) {
    $defaultCatTab = 'ak';
} elseif ($isAllIkSelected || !empty($activeIkList)) {
    $defaultCatTab = 'ik';
}

$bulanList = [];
$r = $conn->query("SELECT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran <> '' GROUP BY bulan_pembayaran ORDER BY MAX(id) DESC");
if ($r) while ($row = $r->fetch_assoc()) $bulanList[] = (string)$row['bulan_pembayaran'];

$csvImports = crmReminderCsvList();

$templates = [];
$r = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY id ASC");
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

if ($halaqohSelected) {
    $hasAkCategory = in_array('AK', $halaqohSelected, true);
    $hasIkCategory = in_array('IK', $halaqohSelected, true);
    $specificHalaqoh = array_values(array_filter($halaqohSelected, static fn($h) => $h !== 'AK' && $h !== 'IK'));

    $halConditions = [];
    if ($hasAkCategory) {
        $halConditions[] = "(p.halaqoh LIKE 'AK %' OR p.halaqoh = 'AK')";
    }
    if ($hasIkCategory) {
        $halConditions[] = "(p.halaqoh LIKE 'IK %' OR p.halaqoh = 'IK')";
    }
    if ($specificHalaqoh) {
        $placeholders = implode(',', array_fill(0, count($specificHalaqoh), '?'));
        $halConditions[] = "p.halaqoh IN ({$placeholders})";
        foreach ($specificHalaqoh as $sh) {
            $params[] = $sh;
            $types .= 's';
        }
    }
    if ($halConditions) {
        $where[] = '(' . implode(' OR ', $halConditions) . ')';
    }
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

$buildPaginationUrl = static function (int $targetPage, ?string $customHalaqoh = null) use ($search, $halaqoh, $bulan, $csvFile, $statusBayar, $statusPeserta): string {
    $queryArgs = [
        'page' => 'reminder-pembayaran',
        'p' => $targetPage,
    ];
    if ($search !== '') $queryArgs['q'] = $search;
    $targetHal = $customHalaqoh !== null ? $customHalaqoh : $halaqoh;
    if ($targetHal !== '') $queryArgs['halaqoh'] = $targetHal;
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
                        <a href="?page=reminder-pembayaran&reset=1" class="reminder-quick-chip reset">
                            <i class="fa-solid fa-rotate-left"></i> Reset filter
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($halaqohList): ?>
                    <div class="reminder-halaqoh-filter-box reminder-halaqoh-bar">
                        <div class="reminder-halaqoh-top">
                            <div class="reminder-halaqoh-title">
                                <span class="halaqoh-title-badge"><i class="fa-solid fa-graduation-cap"></i></span>
                                <strong>Pilih Halaqoh:</strong>
                                <?php
                                $selectedAkCount = count($activeAkList);
                                $selectedIkCount = count($activeIkList);
                                $totalSelectedCount = $selectedAkCount + $selectedIkCount;
                                ?>
                                <?php if ($totalSelectedCount > 0): ?>
                                    <span class="halaqoh-selection-summary">
                                        <?php if ($isAllAkSelected && $selectedIkCount === 0): ?>
                                            Semua AK (<?= count($halaqohAk) ?>) dipilih
                                        <?php elseif ($selectedAkCount > 0 && $selectedIkCount === 0): ?>
                                            <?= $selectedAkCount ?> dari <?= count($halaqohAk) ?> AK dipilih
                                        <?php elseif ($isAllIkSelected && $selectedAkCount === 0): ?>
                                            Semua IK (<?= count($halaqohIk) ?>) dipilih
                                        <?php elseif ($selectedIkCount > 0 && $selectedAkCount === 0): ?>
                                            <?= $selectedIkCount ?> dari <?= count($halaqohIk) ?> IK dipilih
                                        <?php else: ?>
                                            <?= $totalSelectedCount ?> halaqoh dipilih
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="reminder-halaqoh-tabs" role="tablist">
                                <button type="button" class="reminder-halaqoh-tab <?= $defaultCatTab === 'all' ? 'active' : '' ?>" data-tab="all">
                                    Semua (<?= count($halaqohList) ?>)
                                </button>
                                <button type="button" class="reminder-halaqoh-tab tab-ak <?= $defaultCatTab === 'ak' ? 'active' : '' ?>" data-tab="ak">
                                    <i class="fa-solid fa-venus" style="color:#db2777"></i> AK · Akhwat (<?= count($halaqohAk) ?>)
                                </button>
                                <button type="button" class="reminder-halaqoh-tab tab-ik <?= $defaultCatTab === 'ik' ? 'active' : '' ?>" data-tab="ik">
                                    <i class="fa-solid fa-mars" style="color:#2563eb"></i> IK · Ikhwan (<?= count($halaqohIk) ?>)
                                </button>
                            </div>
                        </div>

                        <!-- PANEL 1: KATEGORI AK (AKHWAT) -->
                        <div class="reminder-halaqoh-panel" id="panel-halaqoh-ak" style="<?= $defaultCatTab === 'ak' ? '' : 'display:none' ?>">
                            <div class="reminder-halaqoh-panel-chips">
                                <?php
                                if ($isAllAkSelected) {
                                    $allAkUrl = $buildPaginationUrl(1, implode(',', $otherNonAk));
                                } else {
                                    $allAkUrl = $buildPaginationUrl(1, implode(',', array_merge($otherNonAk, ['AK'])));
                                }
                                ?>
                                <a href="<?= htmlspecialchars($allAkUrl) ?>" class="reminder-quick-chip chip-category-all chip-ak <?= $isAllAkSelected ? 'active' : '' ?>" title="Pilih seluruh 28 halaqoh Akhwat sekaligus">
                                    <i class="fa-solid fa-layer-group"></i> <?= $isAllAkSelected ? '✓ ' : '' ?>Pilih Semua AK (<?= count($halaqohAk) ?>)
                                </a>

                                <span class="chip-separator"></span>

                                <?php foreach ($halaqohAk as $hItem): ?>
                                    <?php
                                    $isChipActive = $isAllAkSelected || in_array($hItem, $activeAkList, true);
                                    if ($isChipActive) {
                                        // User clicks active chip -> EXCLUDE this chip!
                                        $newAk = array_values(array_diff($activeAkList, [$hItem]));
                                    } else {
                                        // User clicks inactive chip -> INCLUDE this chip!
                                        $newAk = array_merge($activeAkList, [$hItem]);
                                    }
                                    if (count($newAk) === count($halaqohAk)) {
                                        $targetHal = array_merge($otherNonAk, ['AK']);
                                    } else {
                                        $targetHal = array_merge($otherNonAk, $newAk);
                                    }
                                    $chipUrl = $buildPaginationUrl(1, implode(',', $targetHal));
                                    ?>
                                    <a href="<?= htmlspecialchars($chipUrl) ?>" class="reminder-quick-chip chip-item-ak <?= $isChipActive ? 'active' : '' ?>" title="<?= $isChipActive ? 'Klik untuk kecualikan ' . htmlspecialchars($hItem) : 'Klik untuk pilih ' . htmlspecialchars($hItem) ?>">
                                        <?= $isChipActive ? '✓ ' : '' ?><?= htmlspecialchars($hItem) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- PANEL 2: KATEGORI IK (IKHWAN) -->
                        <div class="reminder-halaqoh-panel" id="panel-halaqoh-ik" style="<?= $defaultCatTab === 'ik' ? '' : 'display:none' ?>">
                            <div class="reminder-halaqoh-panel-chips">
                                <?php
                                if ($isAllIkSelected) {
                                    $allIkUrl = $buildPaginationUrl(1, implode(',', $otherNonIk));
                                } else {
                                    $allIkUrl = $buildPaginationUrl(1, implode(',', array_merge($otherNonIk, ['IK'])));
                                }
                                ?>
                                <a href="<?= htmlspecialchars($allIkUrl) ?>" class="reminder-quick-chip chip-category-all chip-ik <?= $isAllIkSelected ? 'active' : '' ?>" title="Pilih seluruh 12 halaqoh Ikhwan sekaligus">
                                    <i class="fa-solid fa-layer-group"></i> <?= $isAllIkSelected ? '✓ ' : '' ?>Pilih Semua IK (<?= count($halaqohIk) ?>)
                                </a>

                                <span class="chip-separator"></span>

                                <?php foreach ($halaqohIk as $hItem): ?>
                                    <?php
                                    $isChipActive = $isAllIkSelected || in_array($hItem, $activeIkList, true);
                                    if ($isChipActive) {
                                        // User clicks active chip -> EXCLUDE this chip!
                                        $newIk = array_values(array_diff($activeIkList, [$hItem]));
                                    } else {
                                        // User clicks inactive chip -> INCLUDE this chip!
                                        $newIk = array_merge($activeIkList, [$hItem]);
                                    }
                                    if (count($newIk) === count($halaqohIk)) {
                                        $targetHal = array_merge($otherNonIk, ['IK']);
                                    } else {
                                        $targetHal = array_merge($otherNonIk, $newIk);
                                    }
                                    $chipUrl = $buildPaginationUrl(1, implode(',', $targetHal));
                                    ?>
                                    <a href="<?= htmlspecialchars($chipUrl) ?>" class="reminder-quick-chip chip-item-ik <?= $isChipActive ? 'active' : '' ?>" title="<?= $isChipActive ? 'Klik untuk kecualikan ' . htmlspecialchars($hItem) : 'Klik untuk pilih ' . htmlspecialchars($hItem) ?>">
                                        <?= $isChipActive ? '✓ ' : '' ?><?= htmlspecialchars($hItem) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- PANEL 3: SEMUA / GABUNGAN -->
                        <div class="reminder-halaqoh-panel" id="panel-halaqoh-all" style="<?= $defaultCatTab === 'all' ? '' : 'display:none' ?>">
                            <div class="reminder-halaqoh-panel-chips">
                                <a href="<?= htmlspecialchars($buildPaginationUrl(1, '')) ?>" class="reminder-quick-chip <?= empty($halaqohSelected) ? 'active' : '' ?>">
                                    <i class="fa-solid fa-border-all"></i> Semua Halaqoh
                                </a>
                                <a href="<?= htmlspecialchars($buildPaginationUrl(1, $isAllAkSelected ? '' : 'AK')) ?>" class="reminder-quick-chip chip-category-all chip-ak <?= $isAllAkSelected ? 'active' : '' ?>">
                                    <i class="fa-solid fa-venus"></i> <?= $isAllAkSelected ? '✓ ' : '' ?>Semua AK (Akhwat)
                                </a>
                                <a href="<?= htmlspecialchars($buildPaginationUrl(1, $isAllIkSelected ? '' : 'IK')) ?>" class="reminder-quick-chip chip-category-all chip-ik <?= $isAllIkSelected ? 'active' : '' ?>">
                                    <i class="fa-solid fa-mars"></i> <?= $isAllIkSelected ? '✓ ' : '' ?>Semua IK (Ikhwan)
                                </a>
                                <?php if (!empty($halaqohSelected)): ?>
                                    <a href="<?= htmlspecialchars($buildPaginationUrl(1, '')) ?>&reset=1" class="reminder-quick-chip reset">
                                        <i class="fa-solid fa-rotate-left"></i> Reset halaqoh
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

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
                        <span>Halaqoh <?= count($halaqohSelected) > 1 ? '(' . count($halaqohSelected) . ' terpilih)' : '' ?></span>
                        <select name="halaqoh">
                            <option value="">Semua halaqoh</option>
                            <option value="AK" <?= in_array('AK', $halaqohSelected, true) ? 'selected' : '' ?>>★ SEMUA AK (Akhwat - <?= count($halaqohAk) ?> Halaqoh)</option>
                            <option value="IK" <?= in_array('IK', $halaqohSelected, true) ? 'selected' : '' ?>>★ SEMUA IK (Ikhwan - <?= count($halaqohIk) ?> Halaqoh)</option>
                            <?php if (count($halaqohSelected) > 1 && !in_array('AK', $halaqohSelected, true) && !in_array('IK', $halaqohSelected, true)): ?>
                                <option value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>" selected>
                                    Multi-Halaqoh (<?= count($halaqohSelected) ?> dipilih: <?= htmlspecialchars(implode(', ', array_slice($halaqohSelected, 0, 2))) ?>...)
                                </option>
                            <?php endif; ?>
                            <?php if ($halaqohAk): ?>
                                <optgroup label="── Halaqoh AK (Akhwat) ──">
                                    <?php foreach ($halaqohAk as $item): ?>
                                        <option value="<?= htmlspecialchars($item) ?>" <?= (count($halaqohSelected) === 1 && $halaqohSelected[0] === $item) ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if ($halaqohIk): ?>
                                <optgroup label="── Halaqoh IK (Ikhwan) ──">
                                    <?php foreach ($halaqohIk as $item): ?>
                                        <option value="<?= htmlspecialchars($item) ?>" <?= (count($halaqohSelected) === 1 && $halaqohSelected[0] === $item) ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if ($halaqohOther): ?>
                                <optgroup label="── Halaqoh Lainnya ──">
                                    <?php foreach ($halaqohOther as $item): ?>
                                        <option value="<?= htmlspecialchars($item) ?>" <?= (count($halaqohSelected) === 1 && $halaqohSelected[0] === $item) ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
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

                    <div id="reminderSelectAllMatchingBanner" class="reminder-select-matching-banner" style="display:none">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:9px 14px;background:#fefce8;border:1px solid #fde047;border-radius:10px;margin:8px 0 12px;font-size:12px;color:#854d0e">
                            <div style="display:flex;align-items:center;gap:8px">
                                <i class="fa-solid fa-circle-info" style="color:#ca8a04"></i>
                                <span id="selectAllMatchingText"><?= count($participants) ?> peserta di halaman ini telah dipilih.</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px">
                                <button type="button" id="selectAllMatchingBtn" style="background:#ca8a04;color:#fff;border:0;border-radius:7px;padding:6px 12px;font-weight:700;font-size:11px;cursor:pointer">
                                    Pilih semua <?= (int)$totalPeserta ?> peserta yang cocok (semua halaman)
                                </button>
                                <button type="button" id="clearSelectAllMatchingBtn" style="display:none;background:#fff;color:#854d0e;border:1px solid #ca8a04;border-radius:7px;padding:6px 12px;font-weight:700;font-size:11px;cursor:pointer">
                                    Batalkan seleksi semua
                                </button>
                            </div>
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
                                            <?php
                                            $hVal = (string)($p['halaqoh'] ?: '-');
                                            $badgeCatClass = '';
                                            if (stripos($hVal, 'AK') === 0) $badgeCatClass = 'badge-ak';
                                            elseif (stripos($hVal, 'IK') === 0) $badgeCatClass = 'badge-ik';
                                            ?>
                                            <span class="reminder-badge-halaqoh <?= $badgeCatClass ?>" title="Halaqoh: <?= htmlspecialchars($hVal) ?>">
                                                <i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($hVal) ?>
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
                <input type="hidden" name="select_all_matching" id="reminderSelectAllMatchingInput" value="0">
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
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                        <span>Preview pesan</span>
                        <button type="button" id="btnRerollSpintax" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px" title="Uji acak variasi kata Spintax">
                            <i class="fa-solid fa-dice"></i> Uji Acak Kata (Spintax)
                        </button>
                    </div>
                    <textarea id="reminderPreview" rows="9" readonly placeholder="Pilih template untuk melihat preview."></textarea>
                </label>

                <div class="reminder-helper">
                    <i class="fa-solid fa-shield-halved" style="color:#16a34a"></i>
                    <span>Dukungan Anti-Banned: Variabel <code>{nama}</code>, <code>{bulan}</code>, <code>{halaqoh}</code>, <code>{sapaan_waktu}</code>, serta Spintax <code>{opsi1|opsi2}</code> otomatis diacak per kontak.</span>
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

<div id="reminderScheduleModal" class="reminder-modal-overlay" hidden style="display:none">
    <div class="reminder-modal-box">
        <div class="reminder-modal-header">
            <div>
                <span class="reminder-kicker">Otomasi Pengiriman</span>
                <h2 style="margin:2px 0 0;font-size:18px">Jadwal Reminder Bertahap</h2>
            </div>
            <button type="button" id="closeReminderScheduleModal" class="reminder-modal-close" aria-label="Tutup">×</button>
        </div>

        <form method="post" action="actions/reminder-schedule.php" id="reminderScheduleForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="selected" id="reminderScheduleSelectedInput" value="[]">
            <input type="hidden" name="select_all_matching" id="scheduleSelectAllMatchingInput" value="0">
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
                    <span>Mode Template</span>
                    <div class="template-mode-cards-grid">
                        <div class="template-mode-card active" id="modeCardSingle" role="button" tabindex="0">
                            <input type="radio" name="template_mode" value="single" class="mode-card-radio-hidden" checked>
                            <div class="mode-radio-circle"></div>
                            <div class="mode-card-body">
                                <div class="mode-card-header">
                                    <i class="fa-solid fa-file-lines" style="color:#168044"></i>
                                    <strong>1 Template Tunggal</strong>
                                </div>
                                <small>Kirim 1 template yang sama ke semua peserta terpilih</small>
                            </div>
                        </div>
                        <div class="template-mode-card" id="modeCardRolling" role="button" tabindex="0">
                            <input type="radio" name="template_mode" value="rolling" class="mode-card-radio-hidden">
                            <div class="mode-radio-circle"></div>
                            <div class="mode-card-body">
                                <div class="mode-card-header">
                                    <i class="fa-solid fa-shuffle" style="color:#2563eb"></i>
                                    <strong>Rotasi Rolling</strong>
                                </div>
                                <small>Rotasi dinamis bergantian template antar peserta</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="reminder-field" id="scheduleSingleTemplateBox">
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

                <div class="reminder-field" id="scheduleRollingTemplateBox" style="display:none;background:#f8fbf9;border:1px solid #dcebe1;border-radius:12px;padding:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <div>
                            <span style="font-size:11px;font-weight:800;color:#166534">
                                <i class="fa-solid fa-shuffle"></i> Pilih Template yang Dirotasi
                            </span>
                            <span id="scheduleRollingCountBadge" style="margin-left:6px;font-size:10px;font-weight:700;color:#0369a1;background:#e0f2fe;padding:1px 6px;border-radius:4px"></span>
                        </div>
                        <div style="display:flex;gap:6px">
                            <button type="button" id="scheduleRollingSelectAll" style="background:#fff;border:1px solid #cbd5e1;padding:3px 8px;border-radius:6px;font-size:10px;cursor:pointer">Pilih Semua</button>
                            <button type="button" id="scheduleRollingReset" style="background:#fff;border:1px solid #cbd5e1;padding:3px 8px;border-radius:6px;font-size:10px;cursor:pointer">Reset</button>
                        </div>
                    </div>
                    <div id="scheduleRollingListContainer" style="display:grid;gap:6px;max-height:220px;overflow-y:auto;overflow-x:hidden;padding-right:4px;box-sizing:border-box">
                        <?php
                        $tplLimit = count($templates);
                        for ($tIdx = 0; $tIdx < $tplLimit; $tIdx++):
                            $tItem = $templates[$tIdx];
                            $snippet = mb_substr(preg_replace('/\s+/', ' ', (string)$tItem['content']), 0, 80);
                        ?>
                            <label class="schedule-rolling-card checked">
                                <input type="checkbox" name="rolling_templates[]" value="<?= (int)$tItem['id'] ?>" class="schedule-rolling-check" checked>
                                <div class="schedule-rolling-card-info">
                                    <div class="schedule-rolling-card-head">
                                        <div class="schedule-rolling-card-title"><?= htmlspecialchars((string)$tItem['title']) ?></div>
                                        <span class="schedule-rolling-card-badge"><?= htmlspecialchars((string)($tItem['category'] ?: 'Pembayaran')) ?></span>
                                    </div>
                                    <div class="schedule-rolling-card-snippet"><?= htmlspecialchars($snippet) ?></div>
                                </div>
                            </label>
                        <?php endfor; ?>
                        <?php if ($tplLimit === 0): ?>
                            <div style="color:#64748b;font-size:11px;text-align:center;padding:10px">Belum ada template pada wa_templates.</div>
                        <?php endif; ?>
                    </div>
                    <small style="color:#64748b;font-size:10px;display:block;margin-top:6px">
                        Pesan akan otomatis dirotasi bergantian antar peserta dalam tiap halaqoh menggunakan template yang Anda centang di atas.
                    </small>
                </div>

                <div style="background:#f8fbf9;border:1px solid #dcebe1;border-radius:14px;padding:14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:6px">
                        <span style="font-size:11px;font-weight:800;color:#166534">
                            <i class="fa-solid fa-sliders"></i> Pengaturan Jeda & Waktu Otomasi
                        </span>
                        <span style="background:#ecfdf5;color:#166534;border:1px solid #bbf7d0;font-size:10px;padding:2px 8px;border-radius:6px;font-weight:700">
                            <i class="fa-solid fa-shield-halved"></i> Spintax & Jitter Aktif
                        </span>
                    </div>

                    <div class="reminder-modal-grid-2" style="margin-bottom:12px">
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
                        <div style="display:flex;gap:16px;align-items:center;margin:6px 0;flex-wrap:wrap">
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

            <div class="reminder-modal-btn-group">
                <button type="button" id="cancelScheduleModal" class="reminder-clear-btn" style="padding:10px 16px;font-size:11px;border-radius:10px">Tutup</button>
                <button type="submit" class="reminder-send-btn" style="width:auto;padding:10px 18px;margin-top:0">
                    <i class="fa-solid fa-clock"></i> Simpan & Jadwalkan Otomasi
                </button>
            </div>
        </form>
    </div>
</div>

<div id="reminderTemplateModal" class="reminder-modal-overlay" hidden style="display:none">
    <div id="reminderTemplateModalContent" class="reminder-modal-box template-manager-box">
        <div class="reminder-modal-header">
            <div>
                <span class="reminder-kicker" style="color:#16a34a;font-weight:700">Manajemen Template</span>
                <h2 id="templateModalTitle" style="margin:2px 0 0;font-size:18px;color:#0f172a">Kelola Template WhatsApp</h2>
                <small style="color:#64748b;font-size:11px">Edit judul/isi pesan, buat template baru, atau hapus template lama.</small>
            </div>
            <button type="button" id="closeReminderTemplateModal" class="reminder-modal-close" aria-label="Tutup">×</button>
        </div>

        <div id="templateModalAlert" style="display:none;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:600"></div>

        <!-- Section 1: Daftar Template Tersedia -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:12px;font-weight:800;color:#0f172a">
                        <i class="fa-solid fa-list-check" style="color:#16a34a"></i> Daftar Template Tersedia
                    </span>
                    <span id="templateCountBadge" style="background:#e2e8f0;color:#334155;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700"><?= count($templates) ?></span>
                </div>
                <button type="button" id="btnResetToNewTemplate" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer">
                    <i class="fa-solid fa-plus"></i> Buat Baru
                </button>
            </div>

            <div id="templateManagerList" style="display:grid;gap:8px;max-height:220px;overflow-y:auto;overflow-x:hidden;padding-right:4px;box-sizing:border-box">
                <!-- Template list items rendered via JS -->
            </div>
        </div>

        <!-- Hidden Delete Form (fallback jika fetch gagal) -->
        <form method="post" action="actions/reminder-template-delete.php" id="reminderTemplateDeleteForm" style="display:none">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="template_id" id="reminderTemplateDeleteId" value="">
            <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query(array_filter([
                'page'=>'reminder-pembayaran','q'=>$search,'bulan'=>$bulan,'csv_file'=>$csvFile,'halaqoh'=>$halaqoh,'status_peserta'=>$statusPeserta,'status_bayar'=>$statusBayar
            ], static fn($v) => $v !== '')), ENT_QUOTES) ?>">
        </form>

        <!-- Section 2: Form Editor (Tambah / Edit) -->
        <form method="post" action="actions/reminder-template-save.php" id="reminderTemplateForm" class="template-editor-box">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="template_id" id="reminderTemplateId" value="">
            <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query(array_filter([
                'page'=>'reminder-pembayaran','q'=>$search,'bulan'=>$bulan,'csv_file'=>$csvFile,'halaqoh'=>$halaqoh,'status_peserta'=>$statusPeserta,'status_bayar'=>$statusBayar
            ], static fn($v) => $v !== '')), ENT_QUOTES) ?>">

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px">
                <span id="formModeTitle" style="font-size:13px;font-weight:800;color:#0f172a">
                    <i class="fa-solid fa-plus-circle" style="color:#16a34a"></i> Tambah Template Baru
                </span>
                <button type="button" id="btnCancelEditMode" style="display:none;background:#f1f5f9;border:1px solid #cbd5e1;color:#475569;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer">
                    ✕ Batal Edit
                </button>
            </div>

            <div style="display:grid;gap:10px">
                <div class="reminder-modal-grid-category-title">
                    <label class="reminder-field" style="margin-top:0">
                        <span style="font-size:11px;font-weight:700;color:#475569">Kategori</span>
                        <input type="text" name="category" id="reminderTemplateCategory" maxlength="100" placeholder="Contoh: Pembayaran" value="Pembayaran" style="font-size:12px;padding:8px 10px">
                    </label>
                    <label class="reminder-field" style="margin-top:0">
                        <span style="font-size:11px;font-weight:700;color:#475569">Judul Template <b style="color:#ef4444">*</b></span>
                        <input type="text" name="title" id="reminderTemplateTitle" maxlength="150" required placeholder="Contoh: Reminder S-1 Tagihan" style="font-size:12px;padding:8px 10px">
                    </label>
                </div>

                <label class="reminder-field" style="margin-top:0">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                        <span style="font-size:11px;font-weight:700;color:#475569">Isi Pesan WhatsApp <b style="color:#ef4444">*</b></span>
                        <small style="color:#64748b;font-size:10px">Klik tombol variabel cepat untuk sisipkan</small>
                    </div>
                    <textarea name="content" id="reminderTemplateContent" rows="6" maxlength="5000" required placeholder="Assalamu'alaikum Kak {nama}... Kami ingin menginformasikan tagihan SPP bulan {bulan}..." style="font-size:12px;line-height:1.5;padding:10px;font-family:inherit"></textarea>
                </label>

                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:-4px">
                    <span style="font-size:10px;font-weight:700;color:#64748b">Variabel Cepat:</span>
                    <button type="button" class="btn-insert-variable" data-var="{nama}" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 7px;border-radius:6px;font-size:10px;cursor:pointer">{nama}</button>
                    <button type="button" class="btn-insert-variable" data-var="{bulan}" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 7px;border-radius:6px;font-size:10px;cursor:pointer">{bulan}</button>
                    <button type="button" class="btn-insert-variable" data-var="{halaqoh}" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 7px;border-radius:6px;font-size:10px;cursor:pointer">{halaqoh}</button>
                    <button type="button" class="btn-insert-variable" data-var="{sapaan_waktu}" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 7px;border-radius:6px;font-size:10px;cursor:pointer" title="Pagi / Siang / Sore / Malam">{sapaan_waktu}</button>
                    <button type="button" class="btn-insert-variable" data-var="{peserta_nowa}" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 7px;border-radius:6px;font-size:10px;cursor:pointer">{peserta_nowa}</button>
                    <button type="button" class="btn-insert-variable" data-var="{Assalamu'alaikum|Halo Kak|Selamat {sapaan_waktu} Kak}" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer" title="Acak Variasi Sapaan">{sapaan acak}</button>
                    <button type="button" class="btn-insert-variable" data-var="{opsi1|opsi2|opsi3}" style="background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer" title="Format Spintax Pengacak Kata">{spintax}</button>
                </div>
            </div>

            <div class="reminder-modal-btn-group" style="margin-top:14px;border-top:1px solid #f1f5f9;padding-top:12px">
                <button type="button" id="btnCancelEditModeFooter" class="reminder-clear-btn" style="display:none;padding:10px 16px;font-size:11px;border-radius:10px">Batal Edit</button>
                <button type="submit" id="reminderTemplateSubmitBtn" class="reminder-send-btn" style="width:auto;padding:10px 20px;font-size:12px;display:inline-flex;align-items:center;gap:6px;margin-top:0">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Template Baru
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Floating Quick Actions Bar on Mobile -->
<div id="reminderMobileQuickBar" class="reminder-mobile-quick-actions" style="display:none">
    <div style="display:flex;align-items:center;gap:8px">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:rgba(34,197,94,0.2);color:#4ade80;font-size:12px">
            <i class="fa-solid fa-check"></i>
        </span>
        <span style="font-size:13px;font-weight:700;letter-spacing:-0.01em"><span id="mobileQuickCount">0</span> dipilih</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
        <button type="button" class="btn-mobile-schedule" id="mobileQuickScheduleBtn">
            <i class="fa-solid fa-clock"></i> Otomasi
        </button>
        <button type="button" class="btn-mobile-send" id="mobileQuickSendBtn">
            <i class="fa-solid fa-paper-plane"></i> Kirim
        </button>
    </div>
</div>

<script id="crmTemplatesData" type="application/json">
<?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
</script>

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

    const totalPesertaCount = <?= (int)$totalPeserta ?>;
    let selectAllMatching = false;
    const matchingBanner = document.getElementById('reminderSelectAllMatchingBanner');
    const matchingText = document.getElementById('selectAllMatchingText');
    const selectAllMatchingBtn = document.getElementById('selectAllMatchingBtn');
    const clearSelectAllMatchingBtn = document.getElementById('clearSelectAllMatchingBtn');
    const reminderSelectAllMatchingInput = document.getElementById('reminderSelectAllMatchingInput');
    const scheduleSelectAllMatchingInput = document.getElementById('scheduleSelectAllMatchingInput');
    const reminderMobileQuickBar = document.getElementById('reminderMobileQuickBar');
    const mobileQuickCount = document.getElementById('mobileQuickCount');

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

    const templateModeRadios = [...document.querySelectorAll('input[name="template_mode"]')];
    const singleTplBox = document.getElementById('scheduleSingleTemplateBox');
    const rollingTplBox = document.getElementById('scheduleRollingTemplateBox');
    const rollingSelectAllBtn = document.getElementById('scheduleRollingSelectAll');
    const rollingResetBtn = document.getElementById('scheduleRollingReset');
    const rollingChecks = [...document.querySelectorAll('.schedule-rolling-check')];

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
        const countVal = selectAllMatching ? totalPesertaCount : items.length;
        scheduleCounts.forEach(el => el.textContent = countVal);

        const groups = {};
        if (selectAllMatching) {
            groups['Multi-Halaqoh'] = countVal;
        } else {
            items.forEach(it => {
                const h = it.halaqoh || 'Umum';
                groups[h] = (groups[h] || 0) + 1;
            });
        }
        const numGroups = Math.max(1, Object.keys(groups).length);

        const elTargets = document.getElementById('scheduleEstimateTargets');
        const elHalaqoh = document.getElementById('scheduleEstimateHalaqoh');
        const elDuration = document.getElementById('scheduleEstimateDuration');
        const elEndTime = document.getElementById('scheduleEstimateEndTime');

        if (elTargets) elTargets.textContent = countVal + ' orang' + (selectAllMatching ? ' (semua)' : '');
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

    function resolveSpintaxClient(text) {
        let maxIterations = 10;
        while (maxIterations-- > 0 && /\{([^{}]+\|[^{}]+)\}/u.test(text)) {
            text = text.replace(/\{([^{}]+\|[^{}]+)\}/gu, function(match, group) {
                const parts = group.split('|');
                return parts[Math.floor(Math.random() * parts.length)];
            });
        }
        return text;
    }

    function getTimeGreetingClient() {
        const h = new Date().getHours();
        if (h >= 4 && h < 11) return 'Pagi';
        if (h >= 11 && h < 15) return 'Siang';
        if (h >= 15 && h < 18) return 'Sore';
        return 'Malam';
    }

    function updatePersonalizedPreview() {
        const option = template?.options[template.selectedIndex];
        let content = option?.dataset.content || '';
        if (!content) {
            if (preview) preview.value = '';
            return;
        }

        const target = activePreviewTarget || checks.find(x => x.checked);
        const name = target ? (target.dataset.name || 'Kak') : 'Kak Fulan';
        const hal = target ? (target.dataset.halaqoh || '-') : 'IK 1';
        const bul = <?= json_encode($bulan !== '' ? $bulan : 'periode ini', JSON_UNESCAPED_UNICODE) ?>;
        const sapaanWaktu = getTimeGreetingClient();
        const sapaanDefault = `{Assalamu'alaikum|Halo Kak|Selamat ${sapaanWaktu} Kak}`;

        content = content
            .replace(/{nama}|{NAMA}|\[nama\]|\[NAMA\]/g, name)
            .replace(/{bulan}|{BULAN}|\[bulan\]|\[BULAN\]/g, bul)
            .replace(/{halaqoh}|{HALAQOH}|\[halaqoh\]|\[HALAQOH\]/g, hal)
            .replace(/{sapaan_waktu}|{SAPAAN_WAKTU}|\[sapaan_waktu\]|\[SAPAAN_WAKTU\]/g, sapaanWaktu)
            .replace(/{sapaan}|{SAPAAN}|\[sapaan\]|\[SAPAAN\]/g, sapaanDefault);

        content = resolveSpintaxClient(content);

        if (preview) preview.value = content;
    }

    function refresh() {
        const items = selected();
        if (selectAllMatching) {
            count.textContent = totalPesertaCount + ' dipilih (semua)';
            sendCount.textContent = totalPesertaCount;
            scheduleCounts.forEach(el => el.textContent = totalPesertaCount);
            if (reminderSelectAllMatchingInput) reminderSelectAllMatchingInput.value = '1';
            if (scheduleSelectAllMatchingInput) scheduleSelectAllMatchingInput.value = '1';
        } else {
            count.textContent = items.length + ' dipilih';
            sendCount.textContent = items.length;
            scheduleCounts.forEach(el => el.textContent = items.length);
            if (reminderSelectAllMatchingInput) reminderSelectAllMatchingInput.value = '0';
            if (scheduleSelectAllMatchingInput) scheduleSelectAllMatchingInput.value = '0';
        }

        input.value = JSON.stringify(items);
        if (all) all.checked = checks.length > 0 && items.length === checks.length;
        if (clearBtn) clearBtn.style.display = (items.length > 0 || selectAllMatching) ? 'inline-flex' : 'none';

        if (matchingBanner) {
            if (all && all.checked && totalPesertaCount > checks.length) {
                matchingBanner.style.display = 'block';
                if (selectAllMatching) {
                    if (matchingText) matchingText.textContent = 'Seluruh ' + totalPesertaCount + ' peserta (semua halaman) telah dipilih.';
                    if (selectAllMatchingBtn) selectAllMatchingBtn.style.display = 'none';
                    if (clearSelectAllMatchingBtn) clearSelectAllMatchingBtn.style.display = 'inline-block';
                } else {
                    if (matchingText) matchingText.textContent = items.length + ' peserta di halaman ini telah dipilih.';
                    if (selectAllMatchingBtn) selectAllMatchingBtn.style.display = 'inline-block';
                    if (clearSelectAllMatchingBtn) clearSelectAllMatchingBtn.style.display = 'none';
                }
            } else if (!selectAllMatching) {
                matchingBanner.style.display = 'none';
            }
        }

        updatePersonalizedPreview();
        updateScheduleEstimation();

        const effectiveCount = selectAllMatching ? totalPesertaCount : items.length;
        if (mobileQuickCount) mobileQuickCount.textContent = effectiveCount;
        if (reminderMobileQuickBar) {
            reminderMobileQuickBar.style.display = (effectiveCount > 0 && window.innerWidth <= 768) ? 'flex' : 'none';
        }
    }

    selectAllMatchingBtn?.addEventListener('click', () => {
        selectAllMatching = true;
        refresh();
    });

    clearSelectAllMatchingBtn?.addEventListener('click', () => {
        selectAllMatching = false;
        refresh();
    });

    checks.forEach(x => x.addEventListener('change', () => {
        if (x.checked) activePreviewTarget = x;
        selectAllMatching = false;
        refresh();
    }));

    all?.addEventListener('change', () => {
        checks.forEach(x => x.checked = all.checked);
        if (all.checked && checks.length > 0) activePreviewTarget = checks[0];
        selectAllMatching = false;
        refresh();
    });

    clearBtn?.addEventListener('click', () => {
        checks.forEach(x => x.checked = false);
        if (all) all.checked = false;
        activePreviewTarget = null;
        selectAllMatching = false;
        if (matchingBanner) matchingBanner.style.display = 'none';
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

    const btnRerollSpintax = document.getElementById('btnRerollSpintax');
    if (btnRerollSpintax) {
        btnRerollSpintax.addEventListener('click', function(e) {
            e.preventDefault();
            updatePersonalizedPreview();
            this.style.transform = 'scale(0.95)';
            setTimeout(() => this.style.transform = 'none', 120);
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    let templatesData = [];
    try {
        const rawTplScript = document.getElementById('crmTemplatesData');
        if (rawTplScript) templatesData = JSON.parse(rawTplScript.textContent || '[]');
    } catch (e) {
        templatesData = [];
    }

    const templateModal = document.getElementById('reminderTemplateModal');
    const templateModalContent = document.getElementById('reminderTemplateModalContent');
    const manageTemplates = document.getElementById('manageReminderTemplates');
    const closeTemplateModal = document.getElementById('closeReminderTemplateModal');
    const templateModalAlert = document.getElementById('templateModalAlert');
    const templateManagerList = document.getElementById('templateManagerList');
    const templateCountBadge = document.getElementById('templateCountBadge');
    const btnResetToNewTemplate = document.getElementById('btnResetToNewTemplate');
    const btnCancelEditMode = document.getElementById('btnCancelEditMode');
    const btnCancelEditModeFooter = document.getElementById('btnCancelEditModeFooter');
    const formModeTitle = document.getElementById('formModeTitle');
    const reminderTemplateSubmitBtn = document.getElementById('reminderTemplateSubmitBtn');
    const templateForm = document.getElementById('reminderTemplateForm');
    const templateId = document.getElementById('reminderTemplateId');
    const templateCategory = document.getElementById('reminderTemplateCategory');
    const templateTitle = document.getElementById('reminderTemplateTitle');
    const templateContent = document.getElementById('reminderTemplateContent');
    const scheduleRollingListContainer = document.getElementById('scheduleRollingListContainer');
    const scheduleRollingCountBadge = document.getElementById('scheduleRollingCountBadge');

    let activeEditingId = null;

    function showModalAlert(message, type = 'success') {
        if (!templateModalAlert) return;
        templateModalAlert.style.display = 'block';
        if (type === 'success') {
            templateModalAlert.style.background = '#dcfce7';
            templateModalAlert.style.color = '#15803d';
            templateModalAlert.style.border = '1px solid #86efac';
            templateModalAlert.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + message;
        } else {
            templateModalAlert.style.background = '#fee2e2';
            templateModalAlert.style.color = '#b91c1c';
            templateModalAlert.style.border = '1px solid #fca5a5';
            templateModalAlert.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + message;
        }
        setTimeout(() => {
            if (templateModalAlert) templateModalAlert.style.display = 'none';
        }, 5000);
    }

    function renderTemplateManagerList() {
        if (!templateManagerList) return;
        if (templateCountBadge) templateCountBadge.textContent = templatesData.length;

        if (templatesData.length === 0) {
            templateManagerList.innerHTML = '<div style="color:#64748b;font-size:12px;text-align:center;padding:14px">Belum ada template. Silakan buat template baru di form bawah.</div>';
            return;
        }

        templateManagerList.innerHTML = templatesData.map(tpl => {
            const isEditing = activeEditingId && Number(activeEditingId) === Number(tpl.id);
            const cardBg = isEditing ? '#f0fdf4' : '#ffffff';
            const cardBorder = isEditing ? '2px solid #22c55e' : '1px solid #e2e8f0';
            const snippet = (tpl.content || '').replace(/\s+/g, ' ').slice(0, 70);

            return `
                <div class="template-item-card ${isEditing ? 'is-editing' : ''}" data-id="${tpl.id}" style="width:100%;box-sizing:border-box;min-width:0;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;background:${cardBg};border:${cardBorder};border-radius:10px;transition:all .15s ease">
                    <div style="flex:1;min-width:0;overflow:hidden">
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;min-width:0">
                            <strong style="font-size:12px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0">${escapeHtml(tpl.title)}</strong>
                            <span style="background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:4px;font-size:9px;font-weight:700;flex-shrink:0;white-space:nowrap">${escapeHtml(tpl.category || 'Pembayaran')}</span>
                            ${isEditing ? '<span style="background:#22c55e;color:#fff;padding:1px 6px;border-radius:4px;font-size:9px;font-weight:700;flex-shrink:0;white-space:nowrap">Sedang Diedit</span>' : ''}
                        </div>
                        <div style="color:#64748b;font-size:11px;line-height:1.4;word-break:break-word;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${escapeHtml(snippet)}</div>
                    </div>
                    <div class="card-actions" style="display:flex;gap:6px;flex-shrink:0">
                        <button type="button" class="btn-action-edit-tpl" data-id="${tpl.id}" style="display:inline-flex;align-items:center;gap:4px;border:1px solid #cbd5e1;background:#fff;border-radius:7px;padding:5px 9px;font-size:11px;font-weight:600;cursor:pointer;color:#334155">
                            <i class="fa-solid fa-pen-to-square" style="color:#2563eb"></i> Edit
                        </button>
                        <button type="button" class="btn-action-del-tpl" data-id="${tpl.id}" data-title="${escapeHtml(tpl.title)}" style="display:inline-flex;align-items:center;gap:4px;border:1px solid #fecaca;background:#fff;border-radius:7px;padding:5px 9px;font-size:11px;font-weight:600;cursor:pointer;color:#b91c1c">
                            <i class="fa-solid fa-trash-can"></i> Hapus
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        // Attach edit & delete handlers
        templateManagerList.querySelectorAll('.btn-action-edit-tpl').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.id, 10);
                startEditTemplate(id);
            });
        });

        templateManagerList.querySelectorAll('.btn-action-del-tpl').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.id, 10);
                const title = btn.dataset.title || '';
                deleteTemplate(id, title);
            });
        });
    }

    function updateRollingSelectedCount() {
        const totalList = document.querySelectorAll('.schedule-rolling-check');
        let count = 0;
        totalList.forEach(ch => {
            const card = ch.closest('.schedule-rolling-card');
            if (ch.checked) {
                count++;
                if (card) card.classList.add('checked');
            } else {
                if (card) card.classList.remove('checked');
            }
        });
        if (scheduleRollingCountBadge) {
            scheduleRollingCountBadge.textContent = `${count} dari ${totalList.length} dipilih`;
        }
    }

    function syncDropdowns() {
        // Sync #reminderTemplate on main page
        if (template) {
            const curVal = template.value;
            template.innerHTML = '<option value="">Pilih template...</option>' +
                templatesData.map(t => `<option value="${t.id}" data-content="${escapeHtml(t.content)}">${escapeHtml(t.title)}${t.category ? ' · ' + escapeHtml(t.category) : ''}</option>`).join('');
            if (curVal && templatesData.some(t => String(t.id) === String(curVal))) {
                template.value = curVal;
            }
            template.disabled = templatesData.length === 0;
            updatePersonalizedPreview();
        }

        // Sync #scheduleTemplateSelect in schedule modal
        if (scheduleTemplateSelect) {
            const curVal = scheduleTemplateSelect.value;
            scheduleTemplateSelect.innerHTML = '<option value="">Pilih template...</option>' +
                templatesData.map(t => `<option value="${t.id}">${escapeHtml(t.title)}${t.category ? ' · ' + escapeHtml(t.category) : ''}</option>`).join('');
            if (curVal && templatesData.some(t => String(t.id) === String(curVal))) {
                scheduleTemplateSelect.value = curVal;
            }
        }

        // Sync rolling checkboxes in schedule modal
        if (scheduleRollingListContainer) {
            const previouslyChecked = new Set(
                [...document.querySelectorAll('.schedule-rolling-check:checked')].map(c => c.value)
            );
            if (templatesData.length === 0) {
                scheduleRollingListContainer.innerHTML = '<div style="color:#64748b;font-size:11px;text-align:center;padding:10px">Belum ada template pada wa_templates.</div>';
            } else {
                scheduleRollingListContainer.innerHTML = templatesData.map(tItem => {
                    const isChecked = previouslyChecked.size > 0 ? previouslyChecked.has(String(tItem.id)) : true;
                    const snippet = (tItem.content || '').replace(/\s+/g, ' ').slice(0, 80);
                    return `
                        <label class="schedule-rolling-card ${isChecked ? 'checked' : ''}">
                            <input type="checkbox" name="rolling_templates[]" value="${tItem.id}" class="schedule-rolling-check" ${isChecked ? 'checked' : ''}>
                            <div class="schedule-rolling-card-info">
                                <div class="schedule-rolling-card-head">
                                    <div class="schedule-rolling-card-title">${escapeHtml(tItem.title)}</div>
                                    <span class="schedule-rolling-card-badge">${escapeHtml(tItem.category || 'Pembayaran')}</span>
                                </div>
                                <div class="schedule-rolling-card-snippet">${escapeHtml(snippet)}</div>
                            </div>
                        </label>
                    `;
                }).join('');
            }
            updateRollingSelectedCount();
        }
    }

    function startEditTemplate(id) {
        const tpl = templatesData.find(t => Number(t.id) === Number(id));
        if (!tpl) return;

        activeEditingId = tpl.id;
        if (templateId) templateId.value = tpl.id;
        if (templateCategory) templateCategory.value = tpl.category || 'Pembayaran';
        if (templateTitle) templateTitle.value = tpl.title || '';
        if (templateContent) templateContent.value = tpl.content || '';

        if (templateForm) {
            templateForm.classList.add('is-editing');
        }

        if (formModeTitle) {
            formModeTitle.innerHTML = `<i class="fa-solid fa-pen-to-square" style="color:#2563eb"></i> Sedang Mengedit: <span style="color:#2563eb">${escapeHtml(tpl.title)}</span>`;
        }
        if (btnCancelEditMode) btnCancelEditMode.style.display = 'inline-block';
        if (btnCancelEditModeFooter) btnCancelEditModeFooter.style.display = 'inline-block';
        if (reminderTemplateSubmitBtn) {
            reminderTemplateSubmitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Perbarui Template Ini';
        }

        renderTemplateManagerList();

        if (templateForm) {
            try {
                templateForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (e) {
                if (templateModalContent) templateModalContent.scrollTop = templateForm.offsetTop - 20;
            }
        }
        templateTitle?.focus();
    }

    function cancelEditTemplate() {
        activeEditingId = null;
        if (templateId) templateId.value = '';
        if (templateForm) {
            templateForm.reset();
            templateForm.classList.remove('is-editing');
        }
        if (templateCategory) templateCategory.value = 'Pembayaran';

        if (formModeTitle) {
            formModeTitle.innerHTML = '<i class="fa-solid fa-plus-circle" style="color:#16a34a"></i> Tambah Template Baru';
        }
        if (btnCancelEditMode) btnCancelEditMode.style.display = 'none';
        if (btnCancelEditModeFooter) btnCancelEditModeFooter.style.display = 'none';
        if (reminderTemplateSubmitBtn) {
            reminderTemplateSubmitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Template Baru';
        }

        renderTemplateManagerList();
    }

    async function deleteTemplate(id, title) {
        if (!confirm(`Hapus template "${title}"? Tindakan ini tidak bisa dibatalkan.`)) return;

        const csrfToken = templateForm?.querySelector('input[name="csrf"]')?.value || '';
        const formData = new FormData();
        formData.append('csrf', csrfToken);
        formData.append('template_id', id);
        formData.append('ajax', '1');

        try {
            const resp = await fetch('actions/reminder-template-delete.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            const data = await resp.json();
            if (data.success) {
                templatesData = templatesData.filter(t => Number(t.id) !== Number(id));
                if (activeEditingId && Number(activeEditingId) === Number(id)) {
                    cancelEditTemplate();
                } else {
                    renderTemplateManagerList();
                }
                syncDropdowns();
                showModalAlert(data.message || 'Template berhasil dihapus.', 'success');
            } else {
                showModalAlert(data.message || 'Gagal menghapus template.', 'error');
            }
        } catch (err) {
            // Fallback: standard form submit
            const delForm = document.getElementById('reminderTemplateDeleteForm');
            const delId = document.getElementById('reminderTemplateDeleteId');
            if (delForm && delId) {
                delId.value = id;
                delForm.submit();
            } else {
                showModalAlert('Terjadi kesalahan jaringan.', 'error');
            }
        }
    }

    templateForm?.addEventListener('submit', async event => {
        event.preventDefault();

        const tTitle = templateTitle?.value?.trim() || '';
        const tContent = templateContent?.value?.trim() || '';
        if (!tTitle || !tContent) {
            showModalAlert('Judul dan isi template wajib diisi.', 'error');
            return;
        }

        const submitBtn = reminderTemplateSubmitBtn;
        const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
        }

        const formData = new FormData(templateForm);
        formData.append('ajax', '1');

        try {
            const resp = await fetch('actions/reminder-template-save.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            const data = await resp.json();
            if (data.success && data.template) {
                const saved = data.template;
                const idx = templatesData.findIndex(t => Number(t.id) === Number(saved.id));
                if (idx >= 0) {
                    templatesData[idx] = saved;
                } else {
                    templatesData.push(saved);
                }
                cancelEditTemplate();
                syncDropdowns();
                showModalAlert(data.message || 'Template berhasil disimpan.', 'success');
            } else {
                showModalAlert(data.message || 'Gagal menyimpan template.', 'error');
            }
        } catch (err) {
            templateForm.submit();
            return;
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            }
        }
    });

    document.querySelectorAll('.btn-insert-variable').forEach(chip => {
        chip.addEventListener('click', () => {
            const varText = chip.dataset.var;
            if (!templateContent || !varText) return;
            const start = templateContent.selectionStart || templateContent.value.length;
            const end = templateContent.selectionEnd || templateContent.value.length;
            const val = templateContent.value;
            templateContent.value = val.substring(0, start) + varText + val.substring(end);
            templateContent.focus();
            templateContent.selectionStart = templateContent.selectionEnd = start + varText.length;
        });
    });

    function openTemplateModal() {
        if (!templateModal) return;
        if (reminderMobileQuickBar) reminderMobileQuickBar.style.display = 'none';
        templateModal.hidden = false;
        templateModal.style.display = 'flex';
        renderTemplateManagerList();
    }

    function closeTemplateManager() {
        if (!templateModal) return;
        templateModal.style.display = 'none';
        templateModal.hidden = true;
        cancelEditTemplate();
        const effectiveCount = selectAllMatching ? totalPesertaCount : selected().length;
        if (reminderMobileQuickBar && effectiveCount > 0 && window.innerWidth <= 768) {
            reminderMobileQuickBar.style.display = 'flex';
        }
    }

    manageTemplates?.addEventListener('click', openTemplateModal);
    closeTemplateModal?.addEventListener('click', closeTemplateManager);
    btnResetToNewTemplate?.addEventListener('click', () => {
        cancelEditTemplate();
        if (templateForm) {
            try {
                templateForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (e) {
                if (templateModalContent) templateModalContent.scrollTop = templateForm.offsetTop - 20;
            }
        }
        templateTitle?.focus();
    });
    btnCancelEditMode?.addEventListener('click', cancelEditTemplate);
    btnCancelEditModeFooter?.addEventListener('click', cancelEditTemplate);
    templateModal?.addEventListener('click', event => {
        if (event.target === templateModal) closeTemplateManager();
    });

    if (new URLSearchParams(window.location.search).get('manage_templates') === '1') {
        openTemplateModal();
        const newUrl = new URL(window.location.href);
        newUrl.searchParams.delete('manage_templates');
        window.history.replaceState({}, '', newUrl.toString());
    }

    form?.addEventListener('submit', event => {
        const items = selected();
        const effectiveCount = selectAllMatching ? totalPesertaCount : items.length;

        if (!effectiveCount) {
            event.preventDefault();
            alert('Pilih minimal satu peserta terlebih dahulu.');
            return;
        }

        if (selectAllMatching || effectiveCount > 20) {
            event.preventDefault();
            const proceed = confirm('⚠️ PROTEKSI ANTI-BANNED WHATSAPP:\n\nAnda memilih ' + effectiveCount + ' peserta. Mengirim pesan massal secara instan tanpa jeda sangat berisiko membuat nomor WhatsApp Anda DIBLOKIR/BANNED!\n\nDisarankan menggunakan tombol "Atur Jadwal Otomasi" agar pesan terkirim dengan jeda aman bertahap (2 menit per nomor).\n\nKlik OK untuk beralih ke "Atur Jadwal Otomasi" sekarang, atau Cancel untuk membatalkan.');
            if (proceed) {
                openScheduleManager();
            }
            return;
        }

        if (!template?.value) {
            event.preventDefault();
            alert('Pilih template reminder terlebih dahulu.');
            return;
        }

        if (!confirm('Kirim reminder ke ' + effectiveCount + ' peserta sekarang?')) {
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
        const effectiveCount = selectAllMatching ? totalPesertaCount : items.length;
        if (!effectiveCount) {
            alert('Pilih minimal satu peserta terlebih dahulu.');
            return;
        }

        if (reminderMobileQuickBar) reminderMobileQuickBar.style.display = 'none';

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
        const effectiveCount = selectAllMatching ? totalPesertaCount : selected().length;
        if (reminderMobileQuickBar && effectiveCount > 0 && window.innerWidth <= 768) {
            reminderMobileQuickBar.style.display = 'flex';
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

    const modeCardSingle = document.getElementById('modeCardSingle');
    const modeCardRolling = document.getElementById('modeCardRolling');

    function setTemplateMode(mode) {
        const isRolling = mode === 'rolling';
        const singleRadio = document.querySelector('input[name="template_mode"][value="single"]');
        const rollingRadio = document.querySelector('input[name="template_mode"][value="rolling"]');
        if (singleRadio) singleRadio.checked = !isRolling;
        if (rollingRadio) rollingRadio.checked = isRolling;
        if (singleTplBox) singleTplBox.style.display = isRolling ? 'none' : 'block';
        if (rollingTplBox) rollingTplBox.style.display = isRolling ? 'block' : 'none';
        if (scheduleTemplateSelect) scheduleTemplateSelect.required = !isRolling;
        if (modeCardSingle) modeCardSingle.classList.toggle('active', !isRolling);
        if (modeCardRolling) modeCardRolling.classList.toggle('active', isRolling);
    }

    modeCardSingle?.addEventListener('click', () => setTemplateMode('single'));
    modeCardRolling?.addEventListener('click', () => setTemplateMode('rolling'));
    modeCardSingle?.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setTemplateMode('single'); } });
    modeCardRolling?.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setTemplateMode('rolling'); } });

    templateModeRadios.forEach(radio => {
        radio.addEventListener('change', () => setTemplateMode(radio.value));
    });

    rollingSelectAllBtn?.addEventListener('click', () => {
        document.querySelectorAll('.schedule-rolling-check').forEach(ch => ch.checked = true);
        updateRollingSelectedCount();
    });
    rollingResetBtn?.addEventListener('click', () => {
        document.querySelectorAll('.schedule-rolling-check').forEach(ch => ch.checked = false);
        updateRollingSelectedCount();
    });

    document.addEventListener('change', event => {
        if (event.target && event.target.classList && event.target.classList.contains('schedule-rolling-check')) {
            updateRollingSelectedCount();
        }
    });

    document.addEventListener('click', event => {
        const card = event.target.closest('.schedule-rolling-card');
        if (!card) return;
        if (event.target.tagName !== 'INPUT') {
            const chk = card.querySelector('.schedule-rolling-check');
            if (chk) {
                chk.checked = !chk.checked;
                updateRollingSelectedCount();
            }
        }
    });

    updateRollingSelectedCount();

    scheduleDelayMessage?.addEventListener('input', updateScheduleEstimation);
    scheduleDelayHalaqoh?.addEventListener('input', updateScheduleEstimation);
    scheduleStartDatetime?.addEventListener('input', updateScheduleEstimation);

    scheduleForm?.addEventListener('submit', event => {
        const items = selected();
        const effectiveCount = selectAllMatching ? totalPesertaCount : items.length;
        if (!effectiveCount) {
            event.preventDefault();
            alert('Pilih minimal satu peserta terlebih dahulu.');
            return;
        }

        const isRollingMode = document.querySelector('input[name="template_mode"]:checked')?.value === 'rolling';
        if (isRollingMode) {
            const checkedRolling = document.querySelectorAll('.schedule-rolling-check:checked');
            if (!checkedRolling.length) {
                event.preventDefault();
                alert('Pilih minimal satu template untuk rotasi rolling.');
                return;
            }
        } else if (!scheduleTemplateSelect?.value) {
            event.preventDefault();
            alert('Pilih template reminder terlebih dahulu.');
            return;
        }

        if (scheduleSelectedInput) {
            scheduleSelectedInput.value = JSON.stringify(items);
        }

        if (!confirm('Jadwalkan pengiriman reminder bertahap untuk ' + effectiveCount + ' peserta?')) {
            event.preventDefault();
            return;
        }

        const submitBtn = scheduleForm.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan Jadwal...';
        }
    });

    document.getElementById('mobileQuickScheduleBtn')?.addEventListener('click', () => {
        openScheduleManager();
    });
    document.getElementById('mobileQuickSendBtn')?.addEventListener('click', () => {
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            template?.focus();
        }
    });
    window.addEventListener('resize', () => {
        const items = selected();
        const effectiveCount = selectAllMatching ? totalPesertaCount : items.length;
        if (reminderMobileQuickBar) {
            reminderMobileQuickBar.style.display = (effectiveCount > 0 && window.innerWidth <= 768) ? 'flex' : 'none';
        }
    });

    document.querySelectorAll('.reminder-halaqoh-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;
            document.querySelectorAll('.reminder-halaqoh-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            document.querySelectorAll('.reminder-halaqoh-panel').forEach(p => p.style.display = 'none');
            const panel = document.getElementById('panel-halaqoh-' + target);
            if (panel) panel.style.display = 'block';
        });
    });

    refresh();
})();
</script>
