<?php
$crmTitle = 'Kelola Jadwal Grup';

$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : 'aktif';
if (!in_array($status, ['aktif', 'terkirim', 'gagal', 'semua'], true)) $status = 'aktif';

$jenis = isset($_GET['jenis']) && is_string($_GET['jenis']) ? $_GET['jenis'] : 'kategori';
if (!in_array($jenis, ['kategori', 'rutin', 'sekali'], true)) $jenis = 'kategori';

$statusSql = "1=1";
if ($status === 'aktif') $statusSql = "j.status='pending'";
elseif ($status === 'terkirim') $statusSql = "j.status='terkirim'";
elseif ($status === 'gagal') $statusSql = "j.status='gagal'";

$typeSql = "1=1";
if ($jenis === 'rutin') $typeSql = "j.tipe_jadwal='harian'";
elseif ($jenis === 'sekali') $typeSql = "j.tipe_jadwal IN ('sekali','sekarang')";

$rows = [];
$sql = "SELECT j.id, j.id_grup, j.pesan, j.tipe_jadwal, j.jadwal_kirim, j.jam_harian, j.hari_rutin, j.status, j.terakhir_dikirim, COALESCE(w.nama_grup, j.id_grup) AS nama_grup, COALESCE(NULLIF(w.kategori, ''), 'Tanpa kategori') AS kategori
        FROM jadwal_pesan_grup j
        LEFT JOIN wa_grup w ON w.id_grup = j.id_grup
        WHERE $statusSql AND $typeSql
        ORDER BY kategori ASC, j.id DESC
        LIMIT 300";
$res = $conn->query($sql);
if ($res instanceof mysqli_result) $rows = $res->fetch_all(MYSQLI_ASSOC);

$counts = [
    'aktif' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='pending'"),
    'terkirim' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='terkirim'"),
    'gagal' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='gagal'")
];
$typeCounts = [
    'rutin' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE tipe_jadwal='harian'"),
    'sekali' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE tipe_jadwal IN ('sekali','sekarang')")
];

$dayMap = [1=>'Sen', 2=>'Sel', 3=>'Rab', 4=>'Kam', 5=>'Jum', 6=>'Sab', 7=>'Min'];
$groupedRows = [];
foreach ($rows as $row) {
    $category = (string)($row['kategori'] ?? 'Tanpa kategori');
    if (!isset($groupedRows[$category])) $groupedRows[$category] = [];
    $groupedRows[$category][] = $row;
}
$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$formatSchedule = static function (array $row) use ($dayMap): string {
    if ($row['tipe_jadwal'] === 'sekali') {
        $timestamp = strtotime((string)$row['jadwal_kirim']);
        return $timestamp ? date('d M Y · H:i', $timestamp) : 'Waktu belum diatur';
    }
    if ($row['tipe_jadwal'] === 'harian') {
        $days = array_map(static function ($day) use ($dayMap) {
            $day = trim((string)$day);
            return $dayMap[(int)$day] ?? $day;
        }, explode(',', (string)$row['hari_rutin']));
        return substr((string)$row['jam_harian'], 0, 5) . ' · ' . implode(', ', $days);
    }
    return 'Kirim segera';
};
$displayStatus = static function (array $row): array {
    if ($row['tipe_jadwal'] === 'harian' && $row['status'] === 'pending') return ['Aktif', 'aktif'];
    return [ucfirst((string)$row['status']), (string)$row['status']];
};
?>
<section class="page-head group-schedule-page-head">
  <div><span class="eyebrow">Broadcast · Pengelolaan</span><h1>Kelola Jadwal Grup</h1><p>Kelola jadwal berdasarkan kategori grup, rutin, atau sekali kirim.</p></div>
  <a class="group-manage-link" href="?page=group"><i class="fa-solid fa-arrow-left"></i> Kembali ke Broadcast</a>
</section>

<section class="group-schedule-stats">
  <a class="group-schedule-stat stat-active <?= $status === 'aktif' ? 'active' : '' ?>" href="?page=group-schedules&amp;status=aktif&amp;jenis=<?= $h($jenis) ?>"><span><i class="fa-solid fa-arrows-rotate"></i> Aktif</span><strong><?= (int)$counts['aktif'] ?></strong><small>Jadwal yang masih berjalan</small></a>
  <a class="group-schedule-stat stat-sent <?= $status === 'terkirim' ? 'active' : '' ?>" href="?page=group-schedules&amp;status=terkirim&amp;jenis=<?= $h($jenis) ?>"><span><i class="fa-solid fa-check-double"></i> Terkirim</span><strong><?= (int)$counts['terkirim'] ?></strong><small>Jadwal sekali yang selesai</small></a>
  <a class="group-schedule-stat stat-failed <?= $status === 'gagal' ? 'active' : '' ?>" href="?page=group-schedules&amp;status=gagal&amp;jenis=<?= $h($jenis) ?>"><span><i class="fa-solid fa-triangle-exclamation"></i> Gagal</span><strong><?= (int)$counts['gagal'] ?></strong><small>Perlu ditinjau</small></a>
</section>

<section class="group-card group-schedule-management">
  <div class="group-schedule-toolbar">
    <div><span class="group-kicker">Daftar jadwal</span><h2><?= $h(['kategori'=>'Jadwal berdasarkan kategori','rutin'=>'Jadwal rutin','sekali'=>'Jadwal sekali kirim'][$jenis]) ?></h2><p class="group-schedule-caption">Maksimal 300 jadwal terbaru ditampilkan.</p><a class="group-schedule-create" href="../kirimgrup.php"><i class="fa-solid fa-plus"></i><span>Buat jadwal baru</span></a></div>
    <form method="get" class="group-schedule-filter">
      <input type="hidden" name="page" value="group-schedules"><input type="hidden" name="jenis" value="<?= $h($jenis) ?>">
      <label for="groupScheduleStatus">Status</label>
      <select id="groupScheduleStatus" name="status" onchange="this.form.submit()">
        <?php foreach (['aktif'=>'Aktif','terkirim'=>'Terkirim','gagal'=>'Gagal','semua'=>'Semua status'] as $value => $label): ?>
          <option value="<?= $h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <nav class="group-schedule-tabs" aria-label="Jenis jadwal">
    <a class="<?= $jenis === 'rutin' ? 'active' : '' ?>" href="?page=group-schedules&amp;jenis=rutin&amp;status=<?= $h($status) ?>"><i class="fa-solid fa-arrows-rotate"></i><span>Rutin</span></a>
    <a class="<?= $jenis === 'sekali' ? 'active' : '' ?>" href="?page=group-schedules&amp;jenis=sekali&amp;status=<?= $h($status) ?>"><i class="fa-regular fa-calendar"></i><span>Sekali</span></a>
  </nav>

  <?php if (!$rows): ?>
    <div class="group-empty"><i class="fa-regular fa-calendar-xmark"></i><strong>Tidak ada jadwal</strong><span>Tidak ada data pada pilihan ini.</span></div>
  <?php else: ?>
    <div class="group-schedule-scrollbox" tabindex="0" aria-label="Daftar jadwal grup yang dapat digulir">
      <?php if ($jenis === 'kategori'): ?>
        <?php foreach ($groupedRows as $category => $categoryRows): ?>
          <section class="group-schedule-category">
            <header><span><i class="fa-solid fa-folder"></i> <?= $h($category) ?></span><small><?= count($categoryRows) ?> jadwal</small></header>
            <div class="group-schedule-category-list">
              <?php foreach ($categoryRows as $row): ?>
                <?php [$label, $class] = $displayStatus($row); ?>
                <article class="group-schedule-item">
                  <div class="group-schedule-item-main"><div class="group-schedule-item-title"><strong><?= $h($row['nama_grup']) ?></strong><span class="group-schedule-id">#<?= (int)$row['id'] ?></span></div><p><?= $h(mb_strimwidth((string)$row['pesan'], 0, 150, '…')) ?></p></div>
                  <div class="group-schedule-item-time"><span class="group-schedule-type"><?= $row['tipe_jadwal'] === 'harian' ? 'Rutin' : ($row['tipe_jadwal'] === 'sekarang' ? 'Sekarang' : 'Sekali') ?></span><small><?= $h($formatSchedule($row)) ?></small></div>
                  <div class="group-schedule-item-state"><span class="group-schedule-status status-<?= $h($class) ?>"><?= $h($label) ?></span><small><?= !empty($row['terakhir_dikirim']) ? 'Terakhir: ' . $h(date('d M Y', strtotime((string)$row['terakhir_dikirim']))) : 'Belum ada catatan kirim' ?></small></div>
                  <div class="group-schedule-item-action"><?php if ($row['status'] === 'pending'): ?><form method="post" action="actions/group-schedules.php" onsubmit="return confirm('Batalkan jadwal untuk grup ini?');"><input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="group-schedule-cancel" type="submit">Batalkan</button></form><?php endif; ?></div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <?php [$label, $class] = $displayStatus($row); ?>
          <article class="group-schedule-item">
            <div class="group-schedule-item-main"><div class="group-schedule-item-title"><strong><?= $h($row['nama_grup']) ?></strong><span class="group-schedule-id">#<?= (int)$row['id'] ?></span><span class="group-schedule-category-chip"><?= $h($row['kategori']) ?></span></div><p><?= $h(mb_strimwidth((string)$row['pesan'], 0, 150, '…')) ?></p></div>
            <div class="group-schedule-item-time"><span class="group-schedule-type"><?= $row['tipe_jadwal'] === 'harian' ? 'Rutin' : ($row['tipe_jadwal'] === 'sekarang' ? 'Sekarang' : 'Sekali') ?></span><small><?= $h($formatSchedule($row)) ?></small></div>
            <div class="group-schedule-item-state"><span class="group-schedule-status status-<?= $h($class) ?>"><?= $h($label) ?></span><small><?= !empty($row['terakhir_dikirim']) ? 'Terakhir: ' . $h(date('d M Y', strtotime((string)$row['terakhir_dikirim']))) : 'Belum ada catatan kirim' ?></small></div>
            <div class="group-schedule-item-action"><?php if ($row['status'] === 'pending'): ?><form method="post" action="actions/group-schedules.php" onsubmit="return confirm('Batalkan jadwal untuk grup ini?');"><input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="group-schedule-cancel" type="submit">Batalkan</button></form><?php endif; ?></div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
