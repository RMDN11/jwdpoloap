<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reminder-schedule.php';
crmEnsureReminderScheduleTables($conn);

$cronApiUrl = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : (!empty($apiUrl) ? $apiUrl : '');
$cronApiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : (!empty($apiToken) ? $apiToken : '');
$activeCampaigns = crmGetActiveCampaigns($conn, 3, 'peserta');

// Pemrosesan antrean yang jatuh tempo secara otomatis saat membuka halaman
if (!empty($activeCampaigns) && !empty($cronApiUrl) && !empty($cronApiToken)) {
    crmProcessPendingReminderQueue($conn, (string)$cronApiUrl, (string)$cronApiToken, 5);
    $activeCampaigns = crmGetActiveCampaigns($conn, 3, 'peserta');
}

$crmTitle = 'Reminder Peserta';
$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$selectedRequestId = (int)($_GET['request_id'] ?? 0);

function rpInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (!$parts) return '?';
    return strtoupper(substr($parts[0], 0, 1) . (count($parts) > 1 ? substr($parts[count($parts)-1], 0, 1) : ''));
}
function rpTime(string $value): string {
    $ts = strtotime($value);
    return $ts ? date('d M Y, H:i', $ts) : '-';
}
function rpMessage(string $template, string $name, string $nowa): string {
    return str_ireplace(
        ['{peserta_nama}', '{peserta_nowa}', '{nama}', '[nama]'],
        [$name, $nowa, $name, $name],
        $template
    );
}

/* Preserve the legacy daily reset from kelola_reminder.php. */
$lastResetDate = null;
$resetStmt = $conn->prepare("SELECT value FROM reminder_configs WHERE `key` = 'last_reset_date' LIMIT 1");
if ($resetStmt) {
    $resetStmt->execute();
    $row = $resetStmt->get_result()->fetch_assoc();
    $lastResetDate = $row['value'] ?? null;
    $resetStmt->close();
}
if ($lastResetDate !== date('Y-m-d')) {
    $conn->query("UPDATE reminder_requests SET status = 'menunggu'");
    $updateResetStmt = $conn->prepare("
        INSERT INTO reminder_configs (`key`, `value`)
        VALUES ('last_reset_date', ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    if ($updateResetStmt) {
        $today = date('Y-m-d');
        $updateResetStmt->bind_param('s', $today);
        $updateResetStmt->execute();
        $updateResetStmt->close();
    }
}

$halaqohList = [];
$result = $conn->query("SELECT DISTINCT halaqoh FROM reminder_requests WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($result) while ($row = $result->fetch_assoc()) $halaqohList[] = (string)$row['halaqoh'];

$stats = ['total'=>0,'pending'=>0,'sent'=>0];
$result = $conn->query("SELECT COUNT(*) total, SUM(status='menunggu') pending, SUM(status='terkirim') sent FROM reminder_requests");
if ($result && ($row = $result->fetch_assoc())) {
    $stats['total'] = (int)($row['total'] ?? 0);
    $stats['pending'] = (int)($row['pending'] ?? 0);
    $stats['sent'] = (int)($row['sent'] ?? 0);
}

$where = [];
$params = [];
$types = '';
if ($search !== '') {
    $where[] = '(peserta_nama LIKE ? OR peserta_nowa LIKE ? OR pesan_pengajar LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($status === 'menunggu' || $status === 'terkirim') {
    $where[] = 'status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($halaqoh !== '') {
    $where[] = 'halaqoh = ?';
    $params[] = $halaqoh;
    $types .= 's';
}

$sql = "SELECT id, halaqoh, peserta_id, peserta_nama, peserta_nowa, pesan_pengajar, status, created_at, updated_at FROM reminder_requests";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY CASE WHEN status='menunggu' THEN 1 ELSE 2 END, created_at DESC LIMIT 300";

$requests = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($params) {
        $refs = [];
        foreach ($params as $key => $value) $refs[$key] = &$params[$key];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    }
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* Contact History Map: Count & last contact time per participant */
$targetNumbers = [];
foreach ($requests as $r) {
    $rawWa = (string)($r['peserta_nowa'] ?? '');
    $norm = crmNormalizeNumber($rawWa);
    if ($norm === '') continue;
    $targetNumbers[$norm] = true;
    $targetNumbers['+' . $norm] = true;
    if (str_starts_with($norm, '62')) {
        $local = '0' . substr($norm, 2);
        $targetNumbers[$local] = true;
        $targetNumbers['+' . $local] = true;
    }
}

$historyMap = [];
if (!empty($targetNumbers)) {
    try {
        $numKeys = array_keys($targetNumbers);
        $ph = implode(',', array_fill(0, count($numKeys), '?'));
        $logTypes = str_repeat('s', count($numKeys));
        $logSql = "SELECT nowa, created_at FROM log_wa
                   WHERE message LIKE '[REMINDER]%' AND message LIKE '%[TERKIRIM]%'
                     AND nowa IN ({$ph})
                   ORDER BY created_at DESC";
        $logStmt = $conn->prepare($logSql);
        if ($logStmt) {
            $refs = [];
            foreach ($numKeys as $k => $v) $refs[$k] = &$numKeys[$k];
            call_user_func_array([$logStmt, 'bind_param'], array_merge([$logTypes], $refs));
            $logStmt->execute();
            $logRes = $logStmt->get_result();
            while ($l = $logRes->fetch_assoc()) {
                $n = crmNormalizeNumber((string)$l['nowa']);
                if ($n === '') continue;
                if (!isset($historyMap[$n])) {
                    $historyMap[$n] = ['count' => 0, 'last_at' => null];
                }
                $historyMap[$n]['count']++;
                if ($historyMap[$n]['last_at'] === null) {
                    $historyMap[$n]['last_at'] = (string)$l['created_at'];
                }
            }
            $logStmt->close();
        }
    } catch (Throwable $e) {
        error_log('CRM reminder-peserta history error: ' . $e->getMessage());
    }
}

$formatPesertaHistory = static function (int $count, ?string $lastAt): string {
    if ($count < 1 || !$lastAt) return 'Belum pernah dihubungi';
    $ts = strtotime($lastAt);
    if (!$ts) return $count . 'x dihubungi';
    $isToday = date('Y-m-d', $ts) === date('Y-m-d');
    $timeStr = $isToday ? 'Hari ini ' . date('H:i', $ts) : date('d/m/Y H:i', $ts);
    return $count . 'x · ' . $timeStr;
};

$templates = [];
$result = $conn->query("SELECT id, name, content FROM reminder_templates ORDER BY name ASC");
if ($result) $templates = $result->fetch_all(MYSQLI_ASSOC);

$selectedRequest = null;
if ($selectedRequestId > 0) {
    foreach ($requests as $item) if ((int)$item['id'] === $selectedRequestId) { $selectedRequest = $item; break; }
}
if (!$selectedRequest) {
    foreach ($requests as $item) if (($item['status'] ?? '') === 'menunggu') { $selectedRequest = $item; break; }
    if (!$selectedRequest && $requests) $selectedRequest = $requests[0];
}
$defaultTemplate = $templates[0] ?? null;
$previewMessage = ($selectedRequest && $defaultTemplate)
    ? rpMessage((string)$defaultTemplate['content'], (string)$selectedRequest['peserta_nama'], (string)$selectedRequest['peserta_nowa'])
    : '';
?>

<section class="reminder-peserta-page">
    <div class="reminder-peserta-back"><a href="?page=reminder" aria-label="Kembali"><i class="fa-solid fa-arrow-left"></i></a></div>

    <div class="reminder-peserta-head">
        <div>
            <span class="reminder-kicker">Workspace</span>
            <h1>Pengingat Peserta</h1>
            <p>Kelola permintaan tadzkir peserta, kirim reminder WhatsApp, atur jadwal otomasi pesan per halaqoh dan hari aktif, serta pantau riwayat kontak peserta.</p>
        </div>
        <span class="reminder-peserta-head-badge"><i class="fa-solid fa-clock"></i><?= $stats['pending'] ?> menunggu</span>
    </div>

    <div class="reminder-peserta-stats">
        <article class="reminder-peserta-stat"><span class="reminder-peserta-stat-icon blue"><i class="fa-solid fa-inbox"></i></span><div><strong><?= $stats['total'] ?></strong><small>Total Permintaan</small></div></article>
        <article class="reminder-peserta-stat"><span class="reminder-peserta-stat-icon amber"><i class="fa-solid fa-hourglass-half"></i></span><div><strong><?= $stats['pending'] ?></strong><small>Menunggu</small></div></article>
        <article class="reminder-peserta-stat"><span class="reminder-peserta-stat-icon green"><i class="fa-solid fa-circle-check"></i></span><div><strong><?= $stats['sent'] ?></strong><small>Terkirim</small></div></article>
    </div>

    <div class="reminder-peserta-grid">
        <div class="reminder-peserta-main">
            <!-- Active Campaigns Monitor for Peserta -->
            <?php if (!empty($activeCampaigns)): ?>
                <div style="display:grid;gap:12px;margin-bottom:14px">
                    <?php foreach ($activeCampaigns as $camp):
                        $cId = (int)$camp['id'];
                        $totalTgt = max(1, (int)$camp['total_targets']);
                        $sentCnt = (int)$camp['sent_count'];
                        $failCnt = (int)$camp['failed_count'];
                        $pct = min(100, (int)round(($sentCnt + $failCnt) / $totalTgt * 100));
                        $cStatus = (string)$camp['status'];
                        $nextAt = !empty($camp['next_scheduled_at']) ? date('d/m/Y H:i', strtotime($camp['next_scheduled_at'])) : '-';
                        $curHal = !empty($camp['current_halaqoh']) ? $camp['current_halaqoh'] : '-';

                        // Format allowed days
                        $campDays = explode(',', (string)($camp['allowed_days'] ?? '1,2,3,4,5,6,7'));
                        $campDays = array_map('intval', array_filter($campDays, 'is_numeric'));
                        sort($campDays);
                        $dayNames = [1=>'Sen', 2=>'Sel', 3=>'Rab', 4=>'Kam', 5=>'Jum', 6=>'Sab', 7=>'Ahd'];
                        if (count($campDays) === 7) {
                            $daysDisplay = 'Semua Hari';
                        } elseif ($campDays === [1,2,3,4,5]) {
                            $daysDisplay = 'Senin - Jumat';
                        } elseif ($campDays === [6,7]) {
                            $daysDisplay = 'Sabtu - Ahad';
                        } else {
                            $daysDisplay = implode(', ', array_map(static fn($d) => $dayNames[$d] ?? (string)$d, $campDays));
                        }
                    ?>
                        <div class="reminder-card reminder-campaign-card">
                            <div class="reminder-campaign-header">
                                <div>
                                    <span class="reminder-kicker">Otomasi Peserta Berjalan</span>
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
                                <span>Hari aktif: <strong><?= htmlspecialchars($daysDisplay) ?></strong></span>
                            </div>

                            <div class="reminder-campaign-actions">
                                <?php if ($cStatus === 'running'): ?>
                                    <form method="post" action="actions/reminder-schedule.php" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="pause">
                                        <input type="hidden" name="campaign_id" value="<?= $cId ?>">
                                        <input type="hidden" name="return_page" value="reminder-peserta">
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                                        <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
                                        <button type="submit" class="reminder-campaign-btn pause">
                                            <i class="fa-solid fa-pause"></i> Jeda
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="actions/reminder-schedule.php" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="resume">
                                        <input type="hidden" name="campaign_id" value="<?= $cId ?>">
                                        <input type="hidden" name="return_page" value="reminder-peserta">
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                                        <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
                                        <button type="submit" class="reminder-campaign-btn resume">
                                            <i class="fa-solid fa-play"></i> Lanjutkan
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="actions/reminder-schedule.php" style="display:inline" onsubmit="return confirm('Yakin ingin membatalkan jadwal otomasi ini?')">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="campaign_id" value="<?= $cId ?>">
                                    <input type="hidden" name="return_page" value="reminder-peserta">
                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                                    <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">
                                    <button type="submit" class="reminder-campaign-btn cancel">
                                        <i class="fa-solid fa-stop"></i> Batalkan
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="reminder-card">
                <div class="reminder-card-head">
                    <div><span class="reminder-kicker">Inbox Reminder</span><h2>Permintaan Peserta</h2></div>
                    <span class="reminder-count"><?= count($requests) ?> ditampilkan</span>
                </div>

                <form method="GET" class="reminder-peserta-filter">
                    <input type="hidden" name="page" value="reminder-peserta">
                    <label class="reminder-peserta-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari peserta, nomor, atau pesan..."></label>
                    <select name="status"><option value="">Semua Status</option><option value="menunggu" <?= $status==='menunggu'?'selected':'' ?>>Menunggu</option><option value="terkirim" <?= $status==='terkirim'?'selected':'' ?>>Terkirim</option></select>
                    <select name="halaqoh"><option value="">Semua Halaqoh</option><?php foreach($halaqohList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $halaqoh===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select>
                    <button type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                </form>

                <div class="reminder-peserta-bulk">
                    <div><strong>Kirim reminder menunggu</strong><span>Pilih template lalu kirim semua permintaan yang masih menunggu.</span></div>
                    <form method="POST" action="actions/reminder-peserta.php" id="sendAllPesertaForm">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                        <input type="hidden" name="action" value="send_all">
                        <select name="template_id" <?= (!$templates || !$stats['pending'])?'disabled':'' ?>>
                            <?php foreach($templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="reminder-peserta-bulk-send" type="submit" <?= (!$templates || !$stats['pending'])?'disabled':'' ?>><i class="fa-solid fa-paper-plane"></i> Kirim Semua</button>
                    </form>
                    <button type="button" class="reminder-schedule-btn" id="openPesertaScheduleBtn" <?= empty($templates) ? 'disabled' : '' ?> style="padding:0 12px;height:36px;font-size:10px;margin:0">
                        <i class="fa-solid fa-clock"></i> Atur Jadwal Otomasi (<span id="scheduleSelectedCount">0</span>)
                    </button>
                    <?php if($stats['total']>0): ?><form method="POST" action="actions/reminder-peserta.php" id="deleteAllPesertaForm"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="delete_all"><button class="reminder-peserta-danger" title="Hapus semua"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?>
                </div>

                <!-- Selection Bar -->
                <div class="reminder-peserta-select-bar" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:8px;font-size:11px">
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-weight:700;color:#334155">
                        <input type="checkbox" id="pesertaSelectAll" style="width:16px;height:16px;accent-color:#168044;cursor:pointer">
                        Pilih Semua (<span id="pesertaListCount"><?= count($requests) ?></span>)
                    </label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span id="pesertaSelectedInfo" style="font-weight:700;color:#166534">0 dipilih</span>
                        <button type="button" id="pesertaClearSelection" style="background:none;border:0;color:#64748b;font-size:11px;cursor:pointer;text-decoration:underline">Batal</button>
                    </div>
                </div>

                <?php if(!$requests): ?>
                    <div class="reminder-empty reminder-peserta-empty"><span class="reminder-peserta-empty-icon"><i class="fa-solid fa-inbox"></i></span><strong>Belum ada permintaan</strong><span>Permintaan dari form Tadzkir akan muncul di sini.</span></div>
                <?php else: ?>
                    <div class="reminder-peserta-list">
                        <?php foreach($requests as $request):
                            $pending = ($request['status'] ?? '') === 'menunggu';
                            $selected = $selectedRequest && (int)$selectedRequest['id'] === (int)$request['id'];
                            $rNorm = crmNormalizeNumber((string)$request['peserta_nowa']);
                            $rCount = (int)($historyMap[$rNorm]['count'] ?? 0);
                            $rLastAt = $historyMap[$rNorm]['last_at'] ?? null;
                            $historyLabel = $formatPesertaHistory($rCount, $rLastAt);
                        ?>
                            <article class="reminder-peserta-item <?= $selected?'is-selected':'' ?>" data-request-id="<?= (int)$request['id'] ?>" data-name="<?= htmlspecialchars((string)$request['peserta_nama'],ENT_QUOTES) ?>" data-nowa="<?= htmlspecialchars((string)$request['peserta_nowa'],ENT_QUOTES) ?>" data-halaqoh="<?= htmlspecialchars((string)($request['halaqoh']?:'Umum'),ENT_QUOTES) ?>">
                                <div style="display:flex;align-items:center;padding-left:2px">
                                    <input type="checkbox" class="reminder-peserta-checkbox" value="<?= (int)$request['id'] ?>" data-request-id="<?= (int)$request['id'] ?>" data-name="<?= htmlspecialchars((string)$request['peserta_nama'],ENT_QUOTES) ?>" data-nowa="<?= htmlspecialchars((string)$request['peserta_nowa'],ENT_QUOTES) ?>" data-halaqoh="<?= htmlspecialchars((string)($request['halaqoh']?:'Umum'),ENT_QUOTES) ?>" style="width:16px;height:16px;accent-color:#168044;cursor:pointer">
                                </div>
                                <button type="button" class="reminder-peserta-item-main">
                                    <span class="reminder-avatar"><?= htmlspecialchars(rpInitials((string)$request['peserta_nama'])) ?></span>
                                    <span class="reminder-peserta-item-body">
                                        <strong><?= htmlspecialchars((string)$request['peserta_nama']) ?></strong>
                                        <small><?= htmlspecialchars((string)$request['halaqoh']) ?> · <?= htmlspecialchars((string)$request['peserta_nowa'] ?: '-') ?></small>
                                        <span class="reminder-peserta-source"><?= htmlspecialchars(mb_strimwidth((string)$request['pesan_pengajar'],0,120,'…')) ?></span>
                                        <span class="reminder-peserta-history <?= $rCount > 0 ? 'contacted' : 'never' ?>"><i class="fa-solid fa-rotate-left"></i> <?= htmlspecialchars($historyLabel) ?></span>
                                        <span class="reminder-peserta-time"><?= rpTime((string)$request['created_at']) ?></span>
                                    </span>
                                </button>
                                <span class="reminder-peserta-status <?= $pending?'pending':'sent' ?>"><?= $pending?'Menunggu':'Terkirim' ?></span>
                                <div class="reminder-peserta-actions">
                                    <?php if($pending && $templates): ?><button type="button" class="reminder-peserta-quick-send" title="Kirim" data-quick-send="<?= (int)$request['id'] ?>"><i class="fa-solid fa-paper-plane"></i></button><?php endif; ?>
                                    <form method="POST" action="actions/reminder-peserta.php" class="delete-request-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="delete_request"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button type="submit" class="reminder-peserta-delete" title="Hapus"><i class="fa-solid fa-trash"></i></button></form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="reminder-peserta-side">
            <section class="reminder-card reminder-peserta-compose">
                <div class="reminder-card-head"><div><span class="reminder-kicker">Composer</span><h2>Kirim Reminder</h2></div><span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span></div>
                <div id="pesertaTarget" class="reminder-peserta-target">
                    <?php if($selectedRequest): ?><span class="reminder-avatar"><?= htmlspecialchars(rpInitials((string)$selectedRequest['peserta_nama'])) ?></span><div><strong><?= htmlspecialchars((string)$selectedRequest['peserta_nama']) ?></strong><small><?= htmlspecialchars((string)$selectedRequest['halaqoh']) ?> · <?= htmlspecialchars((string)$selectedRequest['peserta_nowa']) ?></small></div>
                    <?php else: ?><span class="reminder-avatar"><i class="fa-solid fa-user"></i></span><div><strong>Pilih permintaan</strong><small>Target reminder tampil di sini.</small></div><?php endif; ?>
                </div>

                <form method="POST" action="actions/reminder-peserta.php" id="reminderPesertaForm">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                    <input type="hidden" name="action" value="send_one">
                    <input type="hidden" name="request_id" id="pesertaRequestId" value="<?= $selectedRequest?(int)$selectedRequest['id']:0 ?>">
                    <div class="reminder-field"><label for="pesertaTemplate">Template</label><select name="template_id" id="pesertaTemplate" <?= !$templates?'disabled':'' ?>><?php foreach($templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="reminder-peserta-preview" id="pesertaPreview"><?= $previewMessage!==''?htmlspecialchars($previewMessage):'Pilih permintaan dan template untuk melihat preview pesan.' ?></div>
                    <div class="reminder-peserta-helper">Placeholder <code>{peserta_nama}</code> dan <code>{peserta_nowa}</code> akan diganti otomatis.</div>
                    <button type="submit" class="reminder-send-btn" id="sendPesertaBtn" <?= (!$selectedRequest || !$templates || ($selectedRequest['status']??'')==='terkirim')?'disabled':'' ?>><i class="fa-solid fa-paper-plane"></i> Kirim WhatsApp</button>
                </form>
            </section>

            <section class="reminder-card">
                <div class="reminder-card-head compact"><div><span class="reminder-kicker">Template</span><h2>Pesan Tersimpan</h2></div><span class="reminder-count"><?= count($templates) ?> template</span></div>
                <form method="POST" action="actions/reminder-peserta.php" id="templateForm" class="reminder-peserta-template-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                    <input type="hidden" name="action" value="save_template">
                    <input type="hidden" name="template_id" id="templateId">
                    <input type="text" name="template_name" id="templateName" maxlength="120" required placeholder="Nama template">
                    <textarea name="template_content" id="templateContent" maxlength="4000" required placeholder="Tulis isi template..."></textarea>
                    <div class="reminder-peserta-template-help">Gunakan <code>{peserta_nama}</code>, <code>{peserta_nowa}</code>, atau <code>{nama}</code>.</div>
                    <div class="reminder-peserta-template-actions"><button type="submit" class="reminder-peserta-save-template"><i class="fa-solid fa-floppy-disk"></i> Simpan</button><button type="button" class="reminder-peserta-clear-template" id="clearTemplateBtn">Batal</button></div>
                </form>
                <?php if($templates): ?><div class="reminder-peserta-template-list"><?php foreach($templates as $tpl): ?><article class="reminder-peserta-template-item"><div><strong><?= htmlspecialchars((string)$tpl['name']) ?></strong><p><?= htmlspecialchars(mb_strimwidth((string)$tpl['content'],0,180,'…')) ?></p></div><div class="reminder-peserta-template-item-actions"><button type="button" class="edit-peserta-template" data-id="<?= (int)$tpl['id'] ?>" data-name="<?= htmlspecialchars((string)$tpl['name'],ENT_QUOTES) ?>" data-content="<?= htmlspecialchars((string)$tpl['content'],ENT_QUOTES) ?>"><i class="fa-solid fa-pen"></i></button><form method="POST" action="actions/reminder-peserta.php" class="delete-template-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><button type="submit"><i class="fa-solid fa-trash"></i></button></form></div></article><?php endforeach; ?></div><?php else: ?><div class="reminder-empty compact">Belum ada template tersimpan.</div><?php endif; ?>
            </section>
        </aside>
    </div>
</section>

<!-- Schedule Automation Modal with Day Selection -->
<div id="reminderPesertaScheduleModal" hidden style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.42);padding:20px;align-items:center;justify-content:center">
    <div style="width:min(640px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:20px;padding:22px;box-shadow:0 20px 60px rgba(15,23,42,.2)">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px">
            <div>
                <span class="reminder-kicker">Otomasi Pengiriman</span>
                <h2 style="margin:3px 0 0;font-size:18px">Jadwal Reminder Bertahap Peserta</h2>
            </div>
            <button type="button" id="closePesertaScheduleModal" aria-label="Tutup" style="border:0;background:#f1f5f9;border-radius:10px;width:36px;height:36px;cursor:pointer">×</button>
        </div>

        <form method="post" action="actions/reminder-schedule.php" id="pesertaScheduleForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="campaign_type" value="peserta">
            <input type="hidden" name="return_page" value="reminder-peserta">
            <input type="hidden" name="selected" id="pesertaScheduleSelectedInput" value="[]">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
            <input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqoh, ENT_QUOTES) ?>">

            <div style="display:grid;gap:14px">
                <label class="reminder-field">
                    <span>Nama Jadwal / Kampanye</span>
                    <input type="text" name="title" id="pesertaScheduleTitle" maxlength="150" value="Reminder Peserta <?= date('d M Y H:i') ?>" required>
                </label>

                <div class="reminder-field">
                    <span>Template Pesan</span>
                    <select name="template_id" id="pesertaScheduleTemplate" required>
                        <option value="">Pilih template...</option>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= (int)$tpl['id'] ?>">
                                <?= htmlspecialchars($tpl['name']) ?>
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
                            <input type="number" name="delay_message" id="pesertaDelayMessage" min="1" max="60" value="2" required>
                            <small style="color:#64748b;font-size:10px">Jarak waktu antar peserta dalam 1 halaqoh</small>
                        </label>
                        <label class="reminder-field" style="margin-top:0">
                            <span>Jeda Antar Halaqoh (menit)</span>
                            <input type="number" name="delay_halaqoh" id="pesertaDelayHalaqoh" min="1" max="300" value="15" required>
                            <small style="color:#64748b;font-size:10px">Cooldown sebelum lanjut ke halaqoh berikutnya</small>
                        </label>
                    </div>

                    <!-- DAY-OF-WEEK SELECTION -->
                    <div class="reminder-field" style="margin-top:0;margin-bottom:12px">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px">
                            <span style="font-size:11px;font-weight:700;color:#166534">
                                <i class="fa-solid fa-calendar-days"></i> Hari Pengiriman Aktif
                            </span>
                            <div style="display:flex;gap:4px">
                                <button type="button" class="reminder-quick-day-btn" id="pesertaDayAllBtn">Semua Hari</button>
                                <button type="button" class="reminder-quick-day-btn" id="pesertaDayWorkdaysBtn">Sen - Jum</button>
                                <button type="button" class="reminder-quick-day-btn" id="pesertaDayWeekendBtn">Sab - Ahd</button>
                            </div>
                        </div>

                        <div class="reminder-peserta-days-grid" id="pesertaDaysGrid">
                            <label class="reminder-day-chip">
                                <input type="checkbox" name="allowed_days[]" value="1" checked class="peserta-day-cb">
                                <span>Senin</span>
                            </label>
                            <label class="reminder-day-chip">
                                <input type="checkbox" name="allowed_days[]" value="2" checked class="peserta-day-cb">
                                <span>Selasa</span>
                            </label>
                            <label class="reminder-day-chip">
                                <input type="checkbox" name="allowed_days[]" value="3" checked class="peserta-day-cb">
                                <span>Rabu</span>
                            </label>
                            <label class="reminder-day-chip">
                                <input type="checkbox" name="allowed_days[]" value="4" checked class="peserta-day-cb">
                                <span>Kamis</span>
                            </label>
                            <label class="reminder-day-chip">
                                <input type="checkbox" name="allowed_days[]" value="5" checked class="peserta-day-cb">
                                <span>Jumat</span>
                            </label>
                            <label class="reminder-day-chip">
                                <input type="checkbox" name="allowed_days[]" value="6" checked class="peserta-day-cb">
                                <span>Sabtu</span>
                            </label>
                            <label class="reminder-day-chip">
                                <input type="checkbox" name="allowed_days[]" value="7" checked class="peserta-day-cb">
                                <span>Ahad</span>
                            </label>
                        </div>
                        <small style="color:#64748b;font-size:10px;margin-top:4px;display:block">
                            Pesan hanya dikirim pada hari yang dicentang. Jadwal di hari non-aktif otomatis dialihkan ke hari aktif berikutnya pada jam yang sama.
                        </small>
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
                        <input type="datetime-local" name="start_datetime" id="pesertaStartDatetime" style="display:none;margin-top:4px" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>

                <div style="background:#f1f5f9;border-radius:12px;padding:12px 14px;font-size:11px;line-height:1.55;color:#334155" id="pesertaScheduleEstimateBox">
                    <div style="display:flex;justify-content:space-between;gap:8px">
                        <span>Target Terpilih: <strong id="pesertaEstimateTargets">0 orang</strong></span>
                        <span>Jumlah Halaqoh: <strong id="pesertaEstimateHalaqoh">0 grup</strong></span>
                    </div>
                    <div style="margin-top:6px;padding-top:6px;border-top:1px dashed #cbd5e1;color:#0f172a">
                        Estimasi selesai: <strong id="pesertaEstimateDuration">-</strong> (Selesai sekitar <b id="pesertaEstimateEndTime">-</b>)
                    </div>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
                <button type="button" id="cancelPesertaScheduleModal" class="reminder-clear-btn" style="padding:10px 16px;font-size:11px;border-radius:10px">Tutup</button>
                <button type="submit" class="reminder-send-btn" style="width:auto;padding:10px 18px;margin-top:0">
                    <i class="fa-solid fa-clock"></i> Simpan & Jadwalkan Otomasi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const items = [...document.querySelectorAll('.reminder-peserta-item')];
    const templates = <?= json_encode(array_map(static fn(array $t): array => ['id'=>(int)$t['id'],'name'=>(string)$t['name'],'content'=>(string)$t['content']], $templates), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const idEl = document.getElementById('pesertaRequestId'),
          templateEl = document.getElementById('pesertaTemplate'),
          previewEl = document.getElementById('pesertaPreview'),
          targetEl = document.getElementById('pesertaTarget'),
          sendBtn = document.getElementById('sendPesertaBtn'),
          form = document.getElementById('reminderPesertaForm');

    let selected = null;
    const esc = v => String(v||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const initials = n => { const p=String(n||'').trim().split(/\s+/).filter(Boolean); return p.length ? (p[0][0]||'')+(p.length>1?(p[p.length-1][0]||''):'') : '?'; };
    const tpl = () => templates.find(t=>t.id===Number(templateEl?.value||0)) || null;
    const render = (t,r) => String(t||'').replace(/\{peserta_nama\}/gi,r.name).replace(/\{peserta_nowa\}/gi,r.nowa).replace(/\{nama\}/gi,r.name).replace(/\[nama\]/gi,r.name);

    function sync(){
        const t = tpl();
        if(!selected){
            sendBtn.disabled = true;
            previewEl.textContent = 'Pilih permintaan dan template untuk melihat preview pesan.';
            return;
        }
        targetEl.innerHTML = '<span class="reminder-avatar">' + esc(initials(selected.name)) + '</span><div><strong>' + esc(selected.name) + '</strong><small>' + esc(selected.halaqoh) + ' · ' + esc(selected.nowa||'-') + '</small></div>';
        idEl.value = String(selected.id);
        previewEl.textContent = t ? render(t.content, selected) : 'Belum ada template.';
        sendBtn.disabled = selected.status !== 'menunggu' || !t;
    }

    function selectItem(el){
        items.forEach(x => x.classList.remove('is-selected'));
        el.classList.add('is-selected');
        selected = {
            id: Number(el.dataset.requestId),
            name: el.dataset.name || '',
            nowa: el.dataset.nowa || '',
            halaqoh: el.dataset.halaqoh || '',
            status: el.querySelector('.reminder-peserta-status')?.classList.contains('sent') ? 'terkirim' : 'menunggu'
        };
        sync();
        if(innerWidth < 901) {
            document.querySelector('.reminder-peserta-side')?.scrollIntoView({behavior:'smooth',block:'start'});
        }
    }

    items.forEach(el => {
        el.querySelector('.reminder-peserta-item-main')?.addEventListener('click', () => selectItem(el));
        el.querySelector('[data-quick-send]')?.addEventListener('click', () => {
            selectItem(el);
            setTimeout(() => form?.requestSubmit(), 0);
        });
    });

    templateEl?.addEventListener('change', sync);
    form?.addEventListener('submit', e => {
        if(!selected || !tpl() || !confirm('Kirim reminder ke ' + selected.name + '?')) e.preventDefault();
    });

    document.querySelectorAll('.delete-request-form,.delete-template-form').forEach(f => {
        f.addEventListener('submit', e => {
            if(!confirm('Hapus item ini? Tindakan ini tidak bisa dibatalkan.')) e.preventDefault();
        });
    });
    document.getElementById('deleteAllPesertaForm')?.addEventListener('submit', e => {
        if(!confirm('Hapus SEMUA permintaan reminder?')) e.preventDefault();
    });
    document.getElementById('sendAllPesertaForm')?.addEventListener('submit', e => {
        if(!confirm('Kirim ke semua permintaan yang masih menunggu (' + <?= (int)$stats['pending'] ?> + ' peserta)?')) e.preventDefault();
    });

    const tid = document.getElementById('templateId'),
          tn = document.getElementById('templateName'),
          tc = document.getElementById('templateContent');
    document.querySelectorAll('.edit-peserta-template').forEach(b => {
        b.addEventListener('click', () => {
            tid.value = b.dataset.id || '';
            tn.value = b.dataset.name || '';
            tc.value = b.dataset.content || '';
            tn.focus();
            tn.scrollIntoView({behavior:'smooth',block:'center'});
        });
    });
    document.getElementById('clearTemplateBtn')?.addEventListener('click', () => {
        tid.value = '';
        tn.value = '';
        tc.value = '';
        tn.focus();
    });

    const initial = items.find(x => Number(x.dataset.requestId) === Number(idEl.value))
                 || items.find(x => x.querySelector('.reminder-peserta-status')?.classList.contains('pending'))
                 || items[0];
    if (initial) selectItem(initial); else sync();

    /* =========================================================
       MULTI-SELECT & AUTOMATION SCHEDULE ENGINE FOR PESERTA
       ========================================================= */
    const selectAllCb = document.getElementById('pesertaSelectAll');
    const selectedInfoEl = document.getElementById('pesertaSelectedInfo');
    const clearSelectionBtn = document.getElementById('pesertaClearSelection');
    const scheduleCountBadge = document.getElementById('scheduleSelectedCount');
    const checkboxes = [...document.querySelectorAll('.reminder-peserta-checkbox')];

    function getSelectedTargets() {
        const checked = checkboxes.filter(cb => cb.checked);
        return checked.map(cb => ({
            request_id: Number(cb.dataset.requestId || cb.value),
            id: Number(cb.dataset.requestId || cb.value),
            name: cb.dataset.name || '',
            nowa: cb.dataset.nowa || '',
            halaqoh: cb.dataset.halaqoh || 'Umum'
        }));
    }

    function updateSelectionUI() {
        const count = checkboxes.filter(cb => cb.checked).length;
        if (selectedInfoEl) selectedInfoEl.textContent = count + ' dipilih';
        if (scheduleCountBadge) scheduleCountBadge.textContent = String(count);
        if (selectAllCb) {
            selectAllCb.checked = count > 0 && count === checkboxes.length;
            selectAllCb.indeterminate = count > 0 && count < checkboxes.length;
        }
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectionUI);
    });

    selectAllCb?.addEventListener('change', () => {
        checkboxes.forEach(cb => { cb.checked = selectAllCb.checked; });
        updateSelectionUI();
    });

    clearSelectionBtn?.addEventListener('click', () => {
        checkboxes.forEach(cb => { cb.checked = false; });
        updateSelectionUI();
    });

    /* Modal Controls */
    const scheduleModal = document.getElementById('reminderPesertaScheduleModal');
    const openScheduleBtn = document.getElementById('openPesertaScheduleBtn');
    const closeScheduleBtn = document.getElementById('closePesertaScheduleModal');
    const cancelScheduleBtn = document.getElementById('cancelPesertaScheduleModal');
    const scheduleForm = document.getElementById('pesertaScheduleForm');
    const scheduleSelectedInput = document.getElementById('pesertaScheduleSelectedInput');
    const delayMsgInput = document.getElementById('pesertaDelayMessage');
    const delayHalInput = document.getElementById('pesertaDelayHalaqoh');
    const startTypeRadios = document.querySelectorAll('input[name="start_type"]');
    const startDatetimeInput = document.getElementById('pesertaStartDatetime');
    const dayCheckboxes = [...document.querySelectorAll('.peserta-day-cb')];

    function getActiveAllowedDays() {
        return dayCheckboxes.filter(cb => cb.checked).map(cb => Number(cb.value));
    }

    function updateEstimation() {
        let targets = getSelectedTargets();
        if (!targets.length) {
            // Fallback estimation using all pending items
            targets = items
                .filter(el => el.querySelector('.reminder-peserta-status')?.classList.contains('pending'))
                .map(el => ({
                    request_id: Number(el.dataset.requestId),
                    id: Number(el.dataset.requestId),
                    name: el.dataset.name || '',
                    nowa: el.dataset.nowa || '',
                    halaqoh: el.dataset.halaqoh || 'Umum'
                }));
        }

        const estTargetsEl = document.getElementById('pesertaEstimateTargets');
        const estHalaqohEl = document.getElementById('pesertaEstimateHalaqoh');
        const estDurationEl = document.getElementById('pesertaEstimateDuration');
        const estEndTimeEl = document.getElementById('pesertaEstimateEndTime');

        if (!targets.length) {
            if (estTargetsEl) estTargetsEl.textContent = '0 orang';
            if (estHalaqohEl) estHalaqohEl.textContent = '0 grup';
            if (estDurationEl) estDurationEl.textContent = '-';
            if (estEndTimeEl) estEndTimeEl.textContent = '-';
            return;
        }

        const grouped = {};
        targets.forEach(t => {
            const h = t.halaqoh || 'Umum';
            if (!grouped[h]) grouped[h] = [];
            grouped[h].push(t);
        });

        const halaqohCount = Object.keys(grouped).length;
        if (estTargetsEl) estTargetsEl.textContent = targets.length + ' orang';
        if (estHalaqohEl) estHalaqohEl.textContent = halaqohCount + ' grup';

        const delayMsgSec = Math.max(1, Number(delayMsgInput?.value || 2)) * 60;
        const delayHalSec = Math.max(1, Number(delayHalInput?.value || 15)) * 60;
        const allowedDays = getActiveAllowedDays();

        let startTs = Date.now();
        const selectedStartType = [...startTypeRadios].find(r => r.checked)?.value;
        if (selectedStartType === 'custom' && startDatetimeInput?.value) {
            const customTs = new Date(startDatetimeInput.value).getTime();
            if (!isNaN(customTs)) startTs = customTs;
        }

        // Advance timestamp helper to allowed days
        function advanceToAllowed(ts, allowed) {
            if (!allowed.length) return ts;
            const d = new Date(ts);
            let phpDay = d.getDay() === 0 ? 7 : d.getDay();
            let guard = 0;
            while (!allowed.includes(phpDay) && guard < 14) {
                d.setDate(d.getDate() + 1);
                phpDay = d.getDay() === 0 ? 7 : d.getDay();
                guard++;
            }
            return d.getTime();
        }

        let curTs = advanceToAllowed(startTs, allowedDays);
        const groupKeys = Object.keys(grouped);

        for (let g = 0; g < groupKeys.length; g++) {
            const members = grouped[groupKeys[g]];
            for (let m = 0; m < members.length; m++) {
                curTs = advanceToAllowed(curTs, allowedDays);
                if (m < members.length - 1) {
                    curTs += delayMsgSec * 1000;
                }
            }
            curTs += delayHalSec * 1000;
        }
        curTs -= delayHalSec * 1000;

        const totalMinutes = Math.max(1, Math.round((curTs - startTs) / 60000));
        let durationStr = '';
        if (totalMinutes < 60) {
            durationStr = totalMinutes + ' menit';
        } else {
            const h = Math.floor(totalMinutes / 60);
            const m = totalMinutes % 60;
            durationStr = h + ' jam' + (m > 0 ? ' ' + m + ' menit' : '');
        }

        const endDate = new Date(curTs);
        const dayNames = ['Ahad','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        const pad = n => String(n).padStart(2, '0');
        const endStr = dayNames[endDate.getDay()] + ', ' + pad(endDate.getDate()) + '/' + pad(endDate.getMonth()+1) + ' ' + pad(endDate.getHours()) + ':' + pad(endDate.getMinutes());

        if (estDurationEl) estDurationEl.textContent = durationStr;
        if (estEndTimeEl) estEndTimeEl.textContent = endStr;
    }

    function openModal() {
        let targets = getSelectedTargets();
        if (!targets.length) {
            const pendingItems = items.filter(el => el.querySelector('.reminder-peserta-status')?.classList.contains('pending'));
            if (!pendingItems.length) {
                alert('Tidak ada permintaan peserta yang berstatus menunggu.');
                return;
            }
            const ok = confirm('Belum ada peserta yang dipilih. Apakah ingin menjadwalkan SEMUA permintaan yang masih menunggu (' + pendingItems.length + ' orang)?');
            if (!ok) return;
            pendingItems.forEach(el => {
                const cb = el.querySelector('.reminder-peserta-checkbox');
                if (cb) cb.checked = true;
            });
            updateSelectionUI();
            targets = getSelectedTargets();
        }

        if (scheduleSelectedInput) {
            scheduleSelectedInput.value = JSON.stringify(targets);
        }

        updateEstimation();
        if (scheduleModal) {
            scheduleModal.hidden = false;
            scheduleModal.style.display = 'flex';
        }
    }

    function closeModal() {
        if (scheduleModal) {
            scheduleModal.hidden = true;
            scheduleModal.style.display = 'none';
        }
    }

    openScheduleBtn?.addEventListener('click', openModal);
    closeScheduleBtn?.addEventListener('click', closeModal);
    cancelScheduleBtn?.addEventListener('click', closeModal);

    /* Start type switch */
    startTypeRadios.forEach(r => {
        r.addEventListener('change', () => {
            if (startDatetimeInput) {
                startDatetimeInput.style.display = r.value === 'custom' && r.checked ? 'block' : 'none';
            }
            updateEstimation();
        });
    });

    startDatetimeInput?.addEventListener('input', updateEstimation);
    delayMsgInput?.addEventListener('input', updateEstimation);
    delayHalInput?.addEventListener('input', updateEstimation);

    /* Quick Day Buttons */
    document.getElementById('pesertaDayAllBtn')?.addEventListener('click', () => {
        dayCheckboxes.forEach(cb => { cb.checked = true; });
        updateEstimation();
    });

    document.getElementById('pesertaDayWorkdaysBtn')?.addEventListener('click', () => {
        dayCheckboxes.forEach(cb => {
            const v = Number(cb.value);
            cb.checked = (v >= 1 && v <= 5);
        });
        updateEstimation();
    });

    document.getElementById('pesertaDayWeekendBtn')?.addEventListener('click', () => {
        dayCheckboxes.forEach(cb => {
            const v = Number(cb.value);
            cb.checked = (v === 6 || v === 7);
        });
        updateEstimation();
    });

    dayCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateEstimation);
    });

    /* Form validation */
    scheduleForm?.addEventListener('submit', e => {
        const allowed = getActiveAllowedDays();
        if (!allowed.length) {
            alert('Pilih minimal satu hari pengiriman aktif.');
            e.preventDefault();
            return;
        }

        const tplSelect = document.getElementById('pesertaScheduleTemplate');
        if (!tplSelect || !tplSelect.value) {
            alert('Silakan pilih template pesan terlebih dahulu.');
            e.preventDefault();
            return;
        }

        const targets = getSelectedTargets();
        if (!targets.length) {
            alert('Tidak ada target peserta yang dipilih.');
            e.preventDefault();
            return;
        }

        if (scheduleSelectedInput) {
            scheduleSelectedInput.value = JSON.stringify(targets);
        }
    });
})();
</script>
