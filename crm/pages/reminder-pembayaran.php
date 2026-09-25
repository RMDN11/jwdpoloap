<?php
declare(strict_types=1);

$crmTitle = 'Reminder Pembayaran';

$search = trim((string)($_GET['q'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$bulan = trim((string)($_GET['bulan'] ?? ''));
$statusBayar = (string)($_GET['status_bayar'] ?? 'belum_lunas');
$statusPeserta = trim((string)($_GET['status_peserta'] ?? 'semua'));

$halaqohList = [];
$r = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($r) while ($row = $r->fetch_assoc()) $halaqohList[] = (string)$row['halaqoh'];

$statusList = [];
$r = $conn->query("SELECT DISTINCT status FROM peserta WHERE status IS NOT NULL AND status <> '' ORDER BY status");
if ($r) while ($row = $r->fetch_assoc()) $statusList[] = (string)$row['status'];

$bulanList = [];
$r = $conn->query("SELECT DISTINCT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran <> '' ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $bulanList[] = (string)$row['bulan_pembayaran'];

$templates = [];
$r = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY category, title");
if ($r) $templates = $r->fetch_all(MYSQLI_ASSOC);

$normalizedPhoneSql = "CASE WHEN LEFT(REGEXP_REPLACE(TRIM(p.nowa), '[^0-9]', ''), 1) = '0' THEN CONCAT('62', SUBSTRING(REGEXP_REPLACE(TRIM(p.nowa), '[^0-9]', ''), 2)) ELSE REGEXP_REPLACE(TRIM(p.nowa), '[^0-9]', '') END";
$normalizedLogPhoneSql = "CASE WHEN LEFT(REGEXP_REPLACE(TRIM(lw.nowa), '[^0-9]', ''), 1) = '0' THEN CONCAT('62', SUBSTRING(REGEXP_REPLACE(TRIM(lw.nowa), '[^0-9]', ''), 2)) ELSE REGEXP_REPLACE(TRIM(lw.nowa), '[^0-9]', '') END";

$where = ["p.nowa IS NOT NULL", "p.nowa <> ''"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(p.nama_lengkap LIKE ? OR p.nowa LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
if ($halaqoh !== '') {
    $where[] = "p.halaqoh = ?";
    $params[] = $halaqoh; $types .= 's';
}
if ($statusPeserta !== '' && $statusPeserta !== 'semua') {
    $where[] = "p.status = ?";
    $params[] = $statusPeserta; $types .= 's';
}

$paymentJoin = '';
if ($bulan !== '') {
    $paymentJoin = " LEFT JOIN (SELECT DISTINCT peserta_id FROM pembayaran WHERE bulan_pembayaran = ?) bp ON bp.peserta_id = p.id ";
    array_unshift($params, $bulan);
    $types = 's' . $types;
    if ($statusBayar === 'lunas') $where[] = "bp.peserta_id IS NOT NULL";
    elseif ($statusBayar === 'belum_lunas') $where[] = "bp.peserta_id IS NULL";
} elseif ($statusBayar === 'belum_lunas') {
    $where[] = "NOT EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id = p.id)";
} elseif ($statusBayar === 'lunas') {
    $where[] = "EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id = p.id)";
}

$paymentStatusSql = $bulan !== ''
    ? "CASE WHEN bp.peserta_id IS NOT NULL THEN 1 ELSE 0 END"
    : "CASE WHEN EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id = p.id) THEN 1 ELSE 0 END";

/*
 * Build reminder history once and JOIN it.
 * The previous version executed two correlated scans of log_wa for every
 * participant row, which becomes very expensive as log_wa grows.
 */
$reminderHistoryJoin = " LEFT JOIN (
    SELECT
        {$normalizedLogPhoneSql} AS normalized_phone,
        COUNT(*) AS reminder_count,
        MAX(lw.created_at) AS reminder_last,
        MAX(CASE WHEN lw.created_at >= CURDATE() AND lw.created_at < CURDATE() + INTERVAL 1 DAY THEN 1 ELSE 0 END) AS reminded_today
    FROM log_wa lw
    WHERE lw.message LIKE '%[REMINDER] [TERKIRIM]%'
    GROUP BY normalized_phone
) rh ON rh.normalized_phone = {$normalizedPhoneSql} ";

$sql = "SELECT p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status,
        {$paymentStatusSql} AS is_lunas,
        COALESCE(rh.reminder_count, 0) AS reminder_count,
        rh.reminder_last
        FROM peserta p {$paymentJoin} {$reminderHistoryJoin}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.halaqoh, p.nama_lengkap LIMIT 100";

$whereSql = implode(' AND ', $where);
$unpaidWhere = $where;
if ($bulan !== '') {
    $unpaidWhere[] = "bp.peserta_id IS NULL";
} else {
    $unpaidWhere[] = "NOT EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id = p.id)";
}
$unpaidWhereSql = implode(' AND ', $unpaidWhere);

$belumBayar = 0;
$stmtUnpaid = $conn->prepare("SELECT COUNT(*) total FROM peserta p {$paymentJoin} WHERE {$unpaidWhereSql}");
if ($stmtUnpaid) {
    if ($params) {
        $unpaidParams = $params;
        $unpaidTypes = $types;
        $unpaidRefs = [];
        foreach ($unpaidParams as $key => &$value) $unpaidRefs[$key] = &$value;
        call_user_func_array([$stmtUnpaid, 'bind_param'], array_merge([$unpaidTypes], $unpaidRefs));
    }
    $stmtUnpaid->execute();
    $unpaidRow = $stmtUnpaid->get_result()->fetch_assoc();
    $belumBayar = (int)($unpaidRow['total'] ?? 0);
    $stmtUnpaid->close();
}

$totalFiltered = 0;
$todaySent = 0;
$stmtCount = $conn->prepare("SELECT
    COUNT(*) AS total,
    COALESCE(SUM(CASE WHEN COALESCE(rh.reminded_today, 0) = 1 THEN 1 ELSE 0 END), 0) AS today_sent
    FROM peserta p {$paymentJoin} {$reminderHistoryJoin}
    WHERE {$whereSql}");
if ($stmtCount) {
    if ($params) {
        $countParams = $params;
        $countTypes = $types;
        $countRefs = [];
        foreach ($countParams as $key => &$value) $countRefs[$key] = &$value;
        call_user_func_array([$stmtCount, 'bind_param'], array_merge([$countTypes], $countRefs));
    }
    $stmtCount->execute();
    $countRow = $stmtCount->get_result()->fetch_assoc();
    $totalFiltered = (int)($countRow['total'] ?? 0);
    $todaySent = (int)($countRow['today_sent'] ?? 0);
    $stmtCount->close();
}

$participants = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($params) {
        $refs = [];
        foreach ($params as $key => &$value) $refs[$key] = &$value;
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    }
    $stmt->execute();
    $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

?>

<div class="reminder-payment-top">
    <a class="reminder-back reminder-back-icon" href="?page=reminder" aria-label="Kembali ke Reminder" title="Kembali">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
</div>

<section class="reminder-stats reminder-payment-stats">
    <div class="reminder-stat"><span class="reminder-stat-icon warning"><i class="fa-solid fa-wallet"></i></span><div><strong><?= $belumBayar ?></strong><small>Belum bayar</small></div></div>
    <div class="reminder-stat"><span class="reminder-stat-icon blue"><i class="fa-solid fa-paper-plane"></i></span><div><strong><?= $todaySent ?></strong><small>Reminder hari ini</small></div></div>
</section>

<div class="reminder-payment-grid">
    <section class="reminder-card reminder-target-card">
        <div class="reminder-card-head">
            <div><span class="reminder-kicker">Target</span><h2>Peserta</h2></div>
            <span class="reminder-count" id="reminderSelectedCount">0 dipilih</span>
        </div>

        <div class="reminder-filter-box">
        <form class="reminder-filters" method="get">
            <input type="hidden" name="page" value="reminder-pembayaran">
            <label><span>Cari peserta</span><input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nama atau nomor WhatsApp"></label>
            <label><span>Bulan pembayaran</span><select name="bulan"><option value="">Semua bulan</option><?php foreach ($bulanList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $bulan===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select></label>
            <label><span>Halaqoh</span><select name="halaqoh"><option value="">Semua halaqoh</option><?php foreach ($halaqohList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $halaqoh===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select></label>
            <label><span>Status peserta</span><select name="status_peserta"><option value="semua">Semua</option><?php foreach ($statusList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $statusPeserta===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select></label>
            <label><span>Status pembayaran</span><select name="status_bayar"><option value="belum_lunas" <?= $statusBayar==='belum_lunas'?'selected':'' ?>>Belum bayar</option><option value="lunas" <?= $statusBayar==='lunas'?'selected':'' ?>>Lunas</option><option value="semua" <?= $statusBayar==='semua'?'selected':'' ?>>Semua</option></select></label>
            <button class="reminder-filter-btn" type="submit"><i class="fa-solid fa-filter"></i><span>Filter</span></button>
        </form>
        </div>

        <div class="reminder-selectbar">
            <label><input type="checkbox" id="reminderSelectAll"> Pilih semua</label>
            <span><?= $totalFiltered ?> target</span>
        </div>

        <div class="reminder-list">
        <?php if (!$participants): ?>
            <div class="reminder-empty"><i class="fa-regular fa-face-frown"></i><strong>Tidak ada peserta</strong><span>Coba ubah filter pencarian atau status pembayaran.</span></div>
        <?php else: foreach ($participants as $p): ?>
            <label class="reminder-person">
                <input type="checkbox" class="reminder-target" value="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['nama_lengkap'], ENT_QUOTES) ?>">
                <span class="reminder-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$p['nama_lengkap'],0,1))) ?></span>
                <span class="reminder-person-body">
    <strong><?= htmlspecialchars($p['nama_lengkap']) ?></strong>
    <small><?= htmlspecialchars($p['nowa']) ?> · <?= htmlspecialchars($p['halaqoh'] ?: '-') ?></small>
    <span class="reminder-history">
        <?php if ((int)$p['reminder_count'] > 0): ?>
            <i class="fa-regular fa-clock"></i>
            <?= (int)$p['reminder_count'] ?>x ·
            <?php
            $lastReminder = strtotime((string)$p['reminder_last']);
            echo date('Y-m-d', $lastReminder) === date('Y-m-d') ? 'Hari ini ' . date('H:i', $lastReminder) : date('d M, H:i', $lastReminder);
            ?>
        <?php else: ?>
            <i class="fa-regular fa-clock"></i> Belum pernah dihubungi
        <?php endif; ?>
    </span>
</span>
                <span class="reminder-person-status <?= (int)$p['is_lunas'] ? 'paid' : 'unpaid' ?>"><?= (int)$p['is_lunas'] ? 'Lunas' : 'Belum bayar' ?></span>
            </label>
        <?php endforeach; endif; ?>
        </div>
    </section>

    <aside class="reminder-payment-side">
        <form class="reminder-card reminder-compose-card" id="reminderSendForm" method="post" action="actions/reminder-send.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
            <input type="hidden" name="mode" value="participants">
            <input type="hidden" name="selected" id="reminderSelectedInput" value="[]">

            <div class="reminder-card-head">
                <div><span class="reminder-kicker">WhatsApp</span><h2>Pesan reminder</h2></div>
                <span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span>
            </div>

            <label class="reminder-field"><span>Template pesan</span><select name="template_id" id="reminderTemplate" <?= !$templates?'disabled':'' ?>><option value="">Pilih template...</option><?php foreach ($templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>" data-content="<?= htmlspecialchars($tpl['content'], ENT_QUOTES) ?>"><?= htmlspecialchars($tpl['title']) ?></option><?php endforeach; ?></select></label>
            <label class="reminder-field"><span>Preview pesan</span><textarea id="reminderPreview" rows="10" readonly placeholder="Preview template akan muncul di sini."></textarea></label>
            <div class="reminder-helper"><i class="fa-solid fa-circle-info"></i><span>Gunakan <code>{nama}</code> untuk personalisasi otomatis.</span></div>
            <button class="reminder-send-btn" type="submit" <?= !$templates?'disabled':'' ?>><i class="fa-solid fa-paper-plane"></i> Kirim ke <b id="reminderSendCount">0</b> peserta</button>
        </form>

    </aside>
</div>

<script>
(() => {
const checks=[...document.querySelectorAll('.reminder-target')],all=document.getElementById('reminderSelectAll'),count=document.getElementById('reminderSelectedCount'),sendCount=document.getElementById('reminderSendCount'),input=document.getElementById('reminderSelectedInput'),form=document.getElementById('reminderSendForm'),template=document.getElementById('reminderTemplate'),preview=document.getElementById('reminderPreview');
function selected(){return checks.filter(x=>x.checked).map(x=>Number(x.value)).filter(Boolean);}
function refresh(){const items=selected();count.textContent=items.length+' dipilih';sendCount.textContent=items.length;input.value=JSON.stringify(items);if(all)all.checked=checks.length>0&&items.length===checks.length;}
checks.forEach(x=>x.addEventListener('change',refresh));
all?.addEventListener('change',()=>{checks.forEach(x=>x.checked=all.checked);refresh();});
template?.addEventListener('change',()=>{preview.value=template.options[template.selectedIndex]?.dataset.content||'';});
form?.addEventListener('submit',e=>{if(!selected().length){e.preventDefault();alert('Pilih minimal satu peserta.');return;}if(!template?.value){e.preventDefault();alert('Pilih template pesan.');return;}if(!confirm('Kirim reminder ke '+selected().length+' peserta?'))e.preventDefault();});
refresh();
})();
</script>
