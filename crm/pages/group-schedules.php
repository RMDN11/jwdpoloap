<?php
$crmTitle = 'Kelola Jadwal Grup';
$status = (string)($_GET['status'] ?? 'aktif');
if (!in_array($status, ['aktif','terkirim','gagal','semua'], true)) $status = 'aktif';
$jenis = (string)($_GET['jenis'] ?? 'kategori');
if (!in_array($jenis, ['kategori','rutin','sekali'], true)) $jenis = 'kategori';
$statusWhere = match ($status) { 'aktif' => "j.status='pending'", 'terkirim' => "j.status='terkirim'", 'gagal' => "j.status='gagal'", default => "1=1" };
$typeWhere = match ($jenis) { 'rutin' => "j.tipe_jadwal='harian'", 'sekali' => "j.tipe_jadwal IN ('sekali','sekarang')", default => "1=1" };
$where = "WHERE $statusWhere AND $typeWhere";
$rows = [];
$sql = "SELECT j.id,j.id_grup,j.pesan,j.tipe_jadwal,j.jadwal_kirim,j.jam_harian,j.hari_rutin,j.status,j.terakhir_dikirim,COALESCE(w.nama_grup,j.id_grup) nama_grup,COALESCE(NULLIF(w.kategori,''),'Tanpa kategori') kategori FROM jadwal_pesan_grup j LEFT JOIN wa_grup w ON w.id_grup=j.id_grup $where ORDER BY kategori ASC,j.id DESC LIMIT 300";
$res = $conn->query($sql); if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
$counts = [
 'aktif'=>crmCount($conn,"SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='pending'"),
 'terkirim'=>crmCount($conn,"SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='terkirim'"),
 'gagal'=>crmCount($conn,"SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='gagal'")
];
$typeCounts = [
 'kategori'=>crmCount($conn,"SELECT COUNT(*) total FROM jadwal_pesan_grup"),
 'rutin'=>crmCount($conn,"SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE tipe_jadwal='harian'"),
 'sekali'=>crmCount($conn,"SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE tipe_jadwal IN ('sekali','sekarang')")
];
$dayMap=[1=>'Sen',2=>'Sel',3=>'Rab',4=>'Kam',5=>'Jum',6=>'Sab',7=>'Min'];
?>
<section class="page-head group-schedule-page-head"><div><span class="eyebrow">Broadcast · Pengelolaan</span><h1>Kelola Jadwal Grup</h1><p>Kelola jadwal berdasarkan kategori grup, rutin, atau sekali kirim.</p></div><a class="group-manage-link" href="?page=group"><i class="fa-solid fa-arrow-left"></i> Kembali ke Broadcast</a></section>
<section class="group-schedule-stats">
<a class="group-schedule-stat stat-active <?= $status==='aktif'?'active':'' ?>" href="?page=group-schedules&status=aktif&jenis=<?= htmlspecialchars($jenis) ?>"><span><i class="fa-solid fa-arrows-rotate"></i> Aktif</span><strong><?= $counts['aktif'] ?></strong><small>Jadwal yang masih berjalan</small></a>
<a class="group-schedule-stat stat-sent <?= $status==='terkirim'?'active':'' ?>" href="?page=group-schedules&status=terkirim&jenis=<?= htmlspecialchars($jenis) ?>"><span><i class="fa-solid fa-check-double"></i> Terkirim</span><strong><?= $counts['terkirim'] ?></strong><small>Jadwal sekali yang selesai</small></a>
<a class="group-schedule-stat stat-failed <?= $status==='gagal'?'active':'' ?>" href="?page=group-schedules&status=gagal&jenis=<?= htmlspecialchars($jenis) ?>"><span><i class="fa-solid fa-triangle-exclamation"></i> Gagal</span><strong><?= $counts['gagal'] ?></strong><small>Perlu ditinjau</small></a></section>
<section class="group-card group-schedule-management">
<div class="group-schedule-toolbar"><div><span class="group-kicker">Daftar jadwal</span><h2><?= ['kategori'=>'Berdasarkan kategori','rutin'=>'Jadwal rutin','sekali'=>'Jadwal sekali kirim'][$jenis] ?></h2><p class="group-schedule-caption">Maksimal 300 jadwal terbaru ditampilkan.</p></div><form method="get" class="group-schedule-filter"><input type="hidden" name="page" value="group-schedules"><input type="hidden" name="jenis" value="<?= htmlspecialchars($jenis) ?>"><label for="groupScheduleStatus">Status</label><select id="groupScheduleStatus" name="status" onchange="this.form.submit()"><?php foreach(['aktif'=>'Aktif','terkirim'=>'Terkirim','gagal'=>'Gagal','semua'=>'Semua status'] as $v=>$label): ?><option value="<?= $v ?>" <?= $status===$v?'selected':'' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></form></div>
<nav class="group-schedule-tabs" aria-label="Jenis jadwal">
<a class="<?= $jenis==='kategori'?'active':'' ?>" href="?page=group-schedules&jenis=kategori&status=<?= htmlspecialchars($status) ?>"><i class="fa-solid fa-layer-group"></i><span>Kategori</span><b><?= $typeCounts['kategori'] ?></b></a>
<a class="<?= $jenis==='rutin'?'active':'' ?>" href="?page=group-schedules&jenis=rutin&status=<?= htmlspecialchars($status) ?>"><i class="fa-solid fa-arrows-rotate"></i><span>Rutin</span><b><?= $typeCounts['rutin'] ?></b></a>
<a class="<?= $jenis==='sekali'?'active':'' ?>" href="?page=group-schedules&jenis=sekali&status=<?= htmlspecialchars($status) ?>"><i class="fa-regular fa-calendar"></i><span>Sekali</span><b><?= $typeCounts['sekali'] ?></b></a>
</nav>
<?php if (!$rows): ?><div class="group-empty"><i class="fa-regular fa-calendar-xmark"></i><strong>Tidak ada jadwal</strong><span>Tidak ada data pada pilihan ini.</span></div><?php else: ?>
<div class="group-schedule-scrollbox" tabindex="0" aria-label="Daftar jadwal grup yang dapat digulir">
<?php if($jenis==='kategori'): $lastCategory=null; foreach($rows as $r): if($lastCategory!==$r['kategori']): if($lastCategory!==null): ?></div></section><?php endif; $lastCategory=$r['kategori']; ?><section class="group-schedule-category"><header><span><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($lastCategory) ?></span><small>Kategori grup</small></header><div class="group-schedule-category-list"><?php endif; ?>
<article class="group-schedule-item">
<div class="group-schedule-item-main"><div class="group-schedule-item-title"><strong><?= htmlspecialchars($r['nama_grup']) ?></strong><span class="group-schedule-id">#<?= (int)$r['id'] ?></span></div><p><?= htmlspecialchars(mb_strimwidth((string)$r['pesan'],0,150,'…')) ?></p></div>
<div class="group-schedule-item-time"><span class="group-schedule-type"><?= $r['tipe_jadwal']==='harian'?'Rutin':($r['tipe_jadwal']==='sekarang'?'Sekarang':'Sekali') ?></span><?php if($r['tipe_jadwal']==='sekali'): ?><small><?= htmlspecialchars(date('d M Y · H:i',strtotime((string)$r['jadwal_kirim']))) ?></small><?php elseif($r['tipe_jadwal']==='harian'): ?><small><?= htmlspecialchars(substr((string)$r['jam_harian'],0,5)) ?> · <?= htmlspecialchars(implode(', ',array_map(fn($d)=>$dayMap[(int)trim($d)]??trim($d),explode(',',(string)$r['hari_rutin']))) ) ?></small><?php endif; ?></div>
<div class="group-schedule-item-state"><?php $displayStatus=($r['tipe_jadwal']==='harian'&&$r['status']==='pending')?'Aktif':ucfirst((string)$r['status']); $displayClass=($r['tipe_jadwal']==='harian'&&$r['status']==='pending')?'aktif':(string)$r['status']; ?><span class="group-schedule-status status-<?= htmlspecialchars($displayClass) ?>"><?= htmlspecialchars($displayStatus) ?></span><small><?= !empty($r['terakhir_dikirim'])?'Terakhir: '.htmlspecialchars(date('d M Y',strtotime((string)$r['terakhir_dikirim']))):'Belum ada catatan kirim' ?></small></div>
<div class="group-schedule-item-action"><?php if($r['status']==='pending'): ?><form method="post" action="actions/group-schedules.php" onsubmit="return confirm('Batalkan jadwal untuk grup ini?');"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="group-schedule-cancel" type="submit">Batalkan</button></form><?php endif; ?></div>
</article>
<?php endforeach; if($jenis==='kategori' && $lastCategory!==null): ?></div></section><?php endif; ?>
<?php if($jenis!=='kategori'): foreach($rows as $r): ?><article class="group-schedule-item">
<div class="group-schedule-item-main"><div class="group-schedule-item-title"><strong><?= htmlspecialchars($r['nama_grup']) ?></strong><span class="group-schedule-id">#<?= (int)$r['id'] ?></span></div><p><?= htmlspecialchars(mb_strimwidth((string)$r['pesan'],0,150,'…')) ?></p></div>
<div class="group-schedule-item-time"><span class="group-schedule-type"><?= $r['tipe_jadwal']==='harian'?'Rutin':($r['tipe_jadwal']==='sekarang'?'Sekarang':'Sekali') ?></span><?php if($r['tipe_jadwal']==='sekali'): ?><small><?= htmlspecialchars(date('d M Y · H:i',strtotime((string)$r['jadwal_kirim']))) ?></small><?php elseif($r['tipe_jadwal']==='harian'): ?><small><?= htmlspecialchars(substr((string)$r['jam_harian'],0,5)) ?> · <?= htmlspecialchars(implode(', ',array_map(fn($d)=>$dayMap[(int)trim($d)]??trim($d),explode(',',(string)$r['hari_rutin']))) ) ?></small><?php endif; ?></div>
<div class="group-schedule-item-state"><?php $displayStatus=($r['tipe_jadwal']==='harian'&&$r['status']==='pending')?'Aktif':ucfirst((string)$r['status']); $displayClass=($r['tipe_jadwal']==='harian'&&$r['status']==='pending')?'aktif':(string)$r['status']; ?><span class="group-schedule-status status-<?= htmlspecialchars($displayClass) ?>"><?= htmlspecialchars($displayStatus) ?></span><small><?= !empty($r['terakhir_dikirim'])?'Terakhir: '.htmlspecialchars(date('d M Y',strtotime((string)$r['terakhir_dikirim']))):'Belum ada catatan kirim' ?></small></div>
<div class="group-schedule-item-action"><?php if($r['status']==='pending'): ?><form method="post" action="actions/group-schedules.php" onsubmit="return confirm('Batalkan jadwal untuk grup ini?');"><input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="group-schedule-cancel" type="submit">Batalkan</button></form><?php endif; ?></div>
</article><?php endforeach; endif; ?>
</div><?php endif; ?></section>
