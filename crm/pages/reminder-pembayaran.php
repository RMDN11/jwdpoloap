<?php
declare(strict_types=1);

$crmTitle = 'Reminder Pembayaran';

$search = trim((string)($_GET['q'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$bulan = trim((string)($_GET['bulan'] ?? ''));
$statusBayar = (string)($_GET['status_bayar'] ?? 'belum_lunas');

$halaqohList = [];
$r = $conn->query("SELECT DISTINCT halaqoh FROM peserta WHERE halaqoh IS NOT NULL AND halaqoh <> '' ORDER BY halaqoh");
if ($r) while ($row = $r->fetch_assoc()) $halaqohList[] = (string)$row['halaqoh'];

$bulanList = [];
$r = $conn->query("SELECT DISTINCT bulan_pembayaran FROM pembayaran WHERE bulan_pembayaran IS NOT NULL AND bulan_pembayaran <> '' ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $bulanList[] = (string)$row['bulan_pembayaran'];

$templates = [];
$r = $conn->query("SELECT id, category, title, content FROM wa_templates ORDER BY category, title");
if ($r) $templates = $r->fetch_all(MYSQLI_ASSOC);

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

$sql = "SELECT p.id, p.nama_lengkap, p.nowa, p.halaqoh, p.status,
        {$paymentStatusSql} AS is_lunas
        FROM peserta p {$paymentJoin}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.halaqoh, p.nama_lengkap LIMIT 100";

$participants = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $bind = $params;
    $bind[] = 100;
    $bindTypes = $types . 'i';
    $refs = [];
    foreach ($bind as $k => &$value) $refs[$k] = &$value;
    call_user_func_array([$stmt, 'bind_param'], array_merge([$bindTypes], $refs));
    $stmt->execute();
    $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$belumBayar = count(array_filter($participants, static fn(array $p): bool => (int)$p['is_lunas'] === 0));
$todaySent = 0;
$r = $conn->query("SELECT COUNT(*) total FROM log_wa WHERE DATE(created_at)=CURDATE() AND message LIKE '%[REMINDER]%'");
if ($r && ($row = $r->fetch_assoc())) $todaySent = (int)$row['total'];
?>

<section class="page-head reminder-page-head">
    <div>
        <a class="reminder-back" href="?page=reminder"><i class="fa-solid fa-arrow-left"></i> Reminder</a>
        <span class="eyebrow">Pembayaran</span>
        <h1>Reminder Pembayaran</h1>
        <p>Pilih peserta, pilih template, lalu kirim.</p>
    </div>
</section>

<section class="reminder-stats">
    <div class="reminder-stat"><span class="reminder-stat-icon warning"><i class="fa-solid fa-wallet"></i></span><div><strong><?= $belumBayar ?></strong><small>Belum bayar</small></div></div>
    <div class="reminder-stat"><span class="reminder-stat-icon blue"><i class="fa-solid fa-users"></i></span><div><strong><?= count($participants) ?></strong><small>Target</small></div></div>
    <div class="reminder-stat"><span class="reminder-stat-icon green"><i class="fa-solid fa-paper-plane"></i></span><div><strong><?= $todaySent ?></strong><small>Hari ini</small></div></div>
</section>

<div class="reminder-layout">
<section class="reminder-card">
    <div class="reminder-card-head"><div><span class="reminder-kicker">Target</span><h2>Pilih peserta</h2></div><span class="reminder-count" id="reminderSelectedCount">0 dipilih</span></div>

    <form class="reminder-filters" method="get">
        <input type="hidden" name="page" value="reminder-pembayaran">
        <label><span>Cari</span><input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / nomor WA"></label>
        <label><span>Bulan</span><select name="bulan"><option value="">Semua</option><?php foreach ($bulanList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $bulan===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select></label>
        <label><span>Halaqoh</span><select name="halaqoh"><option value="">Semua</option><?php foreach ($halaqohList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $halaqoh===$item?'selected':'' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select></label>
        <label><span>Status</span><select name="status_bayar"><option value="belum_lunas" <?= $statusBayar==='belum_lunas'?'selected':'' ?>>Belum bayar</option><option value="lunas" <?= $statusBayar==='lunas'?'selected':'' ?>>Lunas</option><option value="semua" <?= $statusBayar==='semua'?'selected':'' ?>>Semua</option></select></label>
        <button class="reminder-filter-btn" type="submit"><i class="fa-solid fa-filter"></i></button>
    </form>

    <div class="reminder-selectbar"><label><input type="checkbox" id="reminderSelectAll"> Pilih semua</label><span><?= count($participants) ?> peserta</span></div>

    <div class="reminder-list">
    <?php if (!$participants): ?>
        <div class="reminder-empty"><i class="fa-regular fa-face-frown"></i><strong>Tidak ada target</strong><span>Ubah filter untuk mencari peserta.</span></div>
    <?php else: foreach ($participants as $p): ?>
        <label class="reminder-person">
            <input type="checkbox" class="reminder-target" data-name="<?= htmlspecialchars($p['nama_lengkap'], ENT_QUOTES) ?>" data-wa="<?= htmlspecialchars($p['nowa'], ENT_QUOTES) ?>">
            <span class="reminder-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$p['nama_lengkap'],0,1))) ?></span>
            <span class="reminder-person-body"><strong><?= htmlspecialchars($p['nama_lengkap']) ?></strong><small><?= htmlspecialchars($p['nowa']) ?> · <?= htmlspecialchars($p['halaqoh'] ?: '-') ?></small></span>
            <span class="reminder-person-status <?= (int)$p['is_lunas'] ? 'paid' : 'unpaid' ?>"><?= (int)$p['is_lunas'] ? 'Lunas' : 'Belum bayar' ?></span>
        </label>
    <?php endforeach; endif; ?>
    </div>
</section>

<aside class="reminder-side">
<form class="reminder-card" id="reminderSendForm" method="post" action="actions/reminder-send.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
    <input type="hidden" name="mode" value="participants">
    <input type="hidden" name="selected" id="reminderSelectedInput" value="[]">

    <div class="reminder-card-head"><div><span class="reminder-kicker">Pesan</span><h2>Kirim</h2></div><span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span></div>
    <label class="reminder-field"><span>Template</span><select name="template_id" id="reminderTemplate" <?= !$templates?'disabled':'' ?>><option value="">Pilih template...</option><?php foreach ($templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>" data-content="<?= htmlspecialchars($tpl['content'], ENT_QUOTES) ?>"><?= htmlspecialchars($tpl['title']) ?></option><?php endforeach; ?></select></label>
    <label class="reminder-field"><span>Preview</span><textarea id="reminderPreview" rows="9" readonly placeholder="Pilih template."></textarea></label>
    <div class="reminder-helper"><i class="fa-solid fa-circle-info"></i><span><code>{nama}</code> otomatis diganti nama peserta.</span></div>
    <button class="reminder-send-btn" type="submit" <?= !$templates?'disabled':'' ?>><i class="fa-solid fa-paper-plane"></i> Kirim <b id="reminderSendCount">0</b></button>
</form>
</aside>
</div>

<script>
(() => {
const checks=[...document.querySelectorAll('.reminder-target')],all=document.getElementById('reminderSelectAll'),count=document.getElementById('reminderSelectedCount'),sendCount=document.getElementById('reminderSendCount'),input=document.getElementById('reminderSelectedInput'),form=document.getElementById('reminderSendForm'),template=document.getElementById('reminderTemplate'),preview=document.getElementById('reminderPreview');
function selected(){return checks.filter(x=>x.checked).map(x=>({name:x.dataset.name||'',nowa:x.dataset.wa||''}));}
function refresh(){const items=selected();count.textContent=items.length+' dipilih';sendCount.textContent=items.length;input.value=JSON.stringify(items);if(all)all.checked=checks.length>0&&items.length===checks.length;}
checks.forEach(x=>x.addEventListener('change',refresh));all?.addEventListener('change',()=>{checks.forEach(x=>x.checked=all.checked);refresh();});
template?.addEventListener('change',()=>{preview.value=template.options[template.selectedIndex]?.dataset.content||'';});
form?.addEventListener('submit',e=>{if(!selected().length){e.preventDefault();alert('Pilih minimal satu peserta.');return;}if(!template?.value){e.preventDefault();alert('Pilih template.');return;}if(!confirm('Kirim reminder sekarang?'))e.preventDefault();});refresh();
})();
</script>
