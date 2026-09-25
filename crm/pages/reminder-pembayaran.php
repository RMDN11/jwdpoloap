<?php
declare(strict_types=1);

$crmTitle = 'Reminder Pembayaran';
$search = trim((string)($_GET['q'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));
$bulan = trim((string)($_GET['bulan'] ?? ''));
$statusBayar = (string)($_GET['status_bayar'] ?? 'belum_lunas');
$hasFilter = isset($_GET['q']) || isset($_GET['halaqoh']) || isset($_GET['bulan']) || isset($_GET['status_bayar']);

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

if ($hasFilter) {
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
        if ($statusBayar === 'belum_lunas') $where[] = "bp.peserta_id IS NULL";
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

    $displayParams = $params;
    $displayTypes = $types;
    $displayParams[] = 100;
    $displayTypes .= 'i';

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $refs = [];
        foreach ($displayParams as $k => $v) $refs[$k] = &$displayParams[$k];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$displayTypes], $refs));
        $stmt->execute();
        $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $totalPeserta = count($participants);
    $belumBayar = count(array_filter($participants, static fn(array $p): bool => (int)$p['is_lunas'] === 0));
}

$pendingRequests = [];
$r = $conn->query("SELECT id, halaqoh, peserta_nama, peserta_nowa, pesan_pengajar, status, created_at FROM reminder_requests ORDER BY created_at DESC LIMIT 12");
if ($r) $pendingRequests = $r->fetch_all(MYSQLI_ASSOC);

$todaySent = 0;
$r = $conn->query("SELECT COUNT(*) total FROM log_wa WHERE DATE(created_at) = CURDATE() AND message LIKE '%[REMINDER]%'");
if ($r && ($row = $r->fetch_assoc())) $todaySent = (int)$row['total'];
?>

<section class="page-head reminder-page-head">
    <div><span class="eyebrow">Pembayaran</span><h1>Reminder Pembayaran</h1><p>Pilih peserta yang perlu diingatkan, gunakan template, lalu kirim langsung dari CRM.</p></div>
    <a class="reminder-manage-link" href="../kelola_reminder.php"><i class="fa-solid fa-clock-rotate-left"></i> Riwayat & permintaan</a>
</section>

<section class="reminder-stats">
    <div class="reminder-stat"><span class="reminder-stat-icon warning"><i class="fa-solid fa-wallet"></i></span><div><strong><?= $belumBayar ?></strong><small>Belum bayar</small></div></div>
    <div class="reminder-stat"><span class="reminder-stat-icon blue"><i class="fa-solid fa-users"></i></span><div><strong><?= $totalPeserta ?></strong><small>Target ditemukan</small></div></div>
    <div class="reminder-stat"><span class="reminder-stat-icon green"><i class="fa-solid fa-paper-plane"></i></span><div><strong><?= $todaySent ?></strong><small>Reminder hari ini</small></div></div>
    <div class="reminder-stat"><span class="reminder-stat-icon purple"><i class="fa-solid fa-inbox"></i></span><div><strong><?= count($pendingRequests) ?></strong><small>Permintaan masuk</small></div></div>
</section>

<div class="reminder-layout">
<section class="reminder-card reminder-target-card">
<div class="reminder-card-head"><div><span class="reminder-kicker">Target</span><h2>Pilih peserta</h2></div><span class="reminder-count" id="reminderSelectedCount">0 dipilih</span></div>
<form class="reminder-filters" method="get">
<input type="hidden" name="page" value="reminder"><input type="hidden" name="tab" value="pembayaran">
<label><span>Cari</span><div class="reminder-input-icon"><i class="fa-solid fa-magnifying-glass"></i><input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / nomor WA"></div></label>
<label><span>Bulan pembayaran</span><select name="bulan"><option value="">Semua bulan</option><?php foreach ($bulanList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $bulan === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select></label>
<label><span>Halaqoh</span><select name="halaqoh"><option value="">Semua halaqoh</option><?php foreach ($halaqohList as $item): ?><option value="<?= htmlspecialchars($item) ?>" <?= $halaqoh === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option><?php endforeach; ?></select></label>
<label><span>Status pembayaran</span><select name="status_bayar"><option value="belum_lunas" <?= $statusBayar==='belum_lunas'?'selected':'' ?>>Belum bayar</option><option value="lunas" <?= $statusBayar==='lunas'?'selected':'' ?>>Sudah bayar</option><option value="semua" <?= $statusBayar==='semua'?'selected':'' ?>>Semua</option></select></label>
<button class="reminder-filter-btn" type="submit"><i class="fa-solid fa-filter"></i> Terapkan</button>
</form>
<?php if (!$hasFilter): ?>
<div class="reminder-empty"><i class="fa-solid fa-filter"></i><strong>Gunakan filter untuk menampilkan peserta</strong><span>Daftar peserta baru dimuat setelah filter dijalankan.</span></div>
<?php else: ?>
<div class="reminder-selectbar"><label><input type="checkbox" id="reminderSelectAll"> <span>Pilih semua yang tampil</span></label><span><?= $totalPeserta ?> peserta</span></div>
<div class="reminder-list">
<?php if (!$participants): ?><div class="reminder-empty"><i class="fa-regular fa-face-frown"></i><strong>Target tidak ditemukan</strong><span>Coba ubah filter pembayaran, bulan, atau pencarian.</span></div>
<?php else: foreach ($participants as $p): ?>
<label class="reminder-person"><input type="checkbox" class="reminder-target" value="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['nama_lengkap'], ENT_QUOTES) ?>" data-wa="<?= htmlspecialchars($p['nowa'], ENT_QUOTES) ?>"><span class="reminder-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$p['nama_lengkap'],0,1))) ?></span><span class="reminder-person-body"><strong><?= htmlspecialchars($p['nama_lengkap']) ?></strong><small><?= htmlspecialchars($p['nowa']) ?> · <?= htmlspecialchars($p['halaqoh'] ?: '-') ?></small></span><span class="reminder-person-status <?= (int)$p['is_lunas'] ? 'paid' : 'unpaid' ?>"><?= (int)$p['is_lunas'] ? 'Lunas' : 'Belum bayar' ?></span></label>
<?php endforeach; endif; ?>
</div>
<?php endif; ?>
</section>

<aside class="reminder-side">
<form class="reminder-card reminder-compose-card" id="reminderSendForm" method="post" action="actions/reminder-send.php">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="mode" value="participants"><input type="hidden" name="selected" id="reminderSelectedInput" value="[]">
<div class="reminder-card-head"><div><span class="reminder-kicker">Pesan</span><h2>Kirim reminder</h2></div><span class="reminder-wa-icon"><i class="fa-brands fa-whatsapp"></i></span></div>
<label class="reminder-field"><span>Template</span><select name="template_id" id="reminderTemplate" <?= !$templates ? 'disabled' : '' ?>><option value="">Pilih template...</option><?php foreach ($templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>" data-content="<?= htmlspecialchars($tpl['content'], ENT_QUOTES) ?>"><?= htmlspecialchars($tpl['title']) ?><?= $tpl['category'] ? ' · '.htmlspecialchars($tpl['category']) : '' ?></option><?php endforeach; ?></select></label>
<label class="reminder-field"><span>Preview pesan</span><textarea id="reminderPreview" rows="9" readonly placeholder="Pilih template untuk melihat preview."></textarea></label>
<div class="reminder-helper"><i class="fa-solid fa-circle-info"></i><span><code>{nama}</code> akan otomatis diganti dengan nama peserta.</span></div>
<button class="reminder-send-btn" type="submit" <?= !$templates ? 'disabled' : '' ?>><i class="fa-solid fa-paper-plane"></i><span>Kirim ke <b id="reminderSendCount">0</b> peserta</span></button>
</form>

<div class="reminder-card">
<div class="reminder-card-head compact"><div><span class="reminder-kicker">Masuk dari pengajar</span><h2>Permintaan reminder</h2></div><span class="reminder-count"><?= count($pendingRequests) ?></span></div>
<div class="reminder-request-list">
<?php if (!$pendingRequests): ?><div class="reminder-empty compact"><i class="fa-regular fa-inbox"></i><span>Belum ada permintaan.</span></div>
<?php else: foreach ($pendingRequests as $req): ?><div class="reminder-request"><div><strong><?= htmlspecialchars($req['peserta_nama']) ?></strong><small><?= htmlspecialchars($req['halaqoh'] ?: '-') ?> · <?= htmlspecialchars(mb_strimwidth($req['pesan_pengajar'],0,80,'…')) ?></small></div><span class="reminder-request-status <?= ($req['status'] ?? '') === 'terkirim' ? 'sent' : 'pending' ?>"><?= ($req['status'] ?? '') === 'terkirim' ? 'Terkirim' : 'Menunggu' ?></span></div><?php endforeach; endif; ?>
</div>
<a class="reminder-secondary-link" href="../kelola_reminder.php">Buka semua permintaan <i class="fa-solid fa-arrow-right"></i></a>
</div>
</aside>
</div>

<script>
(() => {
const checks=[...document.querySelectorAll('.reminder-target')],all=document.getElementById('reminderSelectAll'),count=document.getElementById('reminderSelectedCount'),sendCount=document.getElementById('reminderSendCount'),input=document.getElementById('reminderSelectedInput'),form=document.getElementById('reminderSendForm'),template=document.getElementById('reminderTemplate'),preview=document.getElementById('reminderPreview');
function selected(){return checks.filter(x=>x.checked).map(x=>({id:x.value,name:x.dataset.name||'',nowa:x.dataset.wa||''}));}
function refresh(){const items=selected();count.textContent=items.length+' dipilih';sendCount.textContent=items.length;input.value=JSON.stringify(items);if(all)all.checked=checks.length>0&&items.length===checks.length;}
checks.forEach(x=>x.addEventListener('change',refresh));all?.addEventListener('change',()=>{checks.forEach(x=>x.checked=all.checked);refresh();});
template?.addEventListener('change',()=>{const o=template.options[template.selectedIndex];preview.value=o?.dataset.content||'';});
form?.addEventListener('submit',e=>{const items=selected();if(!items.length){e.preventDefault();alert('Pilih minimal satu peserta terlebih dahulu.');return;}if(!template?.value){e.preventDefault();alert('Pilih template reminder terlebih dahulu.');return;}if(!confirm('Kirim reminder ke '+items.length+' peserta sekarang?'))e.preventDefault();});refresh();
})();
</script>