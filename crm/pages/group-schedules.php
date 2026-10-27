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
$sql = "SELECT j.id, j.id_grup, j.pesan, j.media_path, j.tipe_jadwal, j.jadwal_kirim, j.jam_harian, j.hari_rutin, j.status, j.terakhir_dikirim,
               COALESCE(w.nama_grup, j.id_grup) AS nama_grup,
               COALESCE(NULLIF(w.kategori, ''), 'Tanpa kategori') AS kategori
        FROM jadwal_pesan_grup j
        LEFT JOIN wa_grup w ON w.id_grup = j.id_grup
        WHERE $statusSql AND $typeSql
        ORDER BY j.id DESC
        LIMIT 500";
$res = $conn->query($sql);
if ($res instanceof mysqli_result) $rows = $res->fetch_all(MYSQLI_ASSOC);

$counts = [
    'aktif' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='pending'"),
    'terkirim' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='terkirim'"),
    'gagal' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='gagal'")
];

date_default_timezone_set('Asia/Jakarta');
$nowJakarta = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$todayDay = (int)$nowJakarta->format('N');
$todayDate = $nowJakarta->format('Y-m-d');
$currentTime = $nowJakarta->format('H:i:s');
$currentDateTime = $nowJakarta->format('Y-m-d H:i:s');

// Gunakan waktu aplikasi Asia/Jakarta secara eksplisit agar hasil tidak bergantung
// pada timezone default MySQL di server hosting.
$routineDueToday = crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='pending' AND tipe_jadwal='harian' AND FIND_IN_SET('$todayDay', hari_rutin) > 0 AND jam_harian <= '$currentTime'");
$routineSentToday = crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='pending' AND tipe_jadwal='harian' AND terakhir_dikirim='$todayDate' AND FIND_IN_SET('$todayDay', hari_rutin) > 0");
$onceWaiting = crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='pending' AND tipe_jadwal IN ('sekali','sekarang') AND (tipe_jadwal='sekarang' OR jadwal_kirim <= '$currentDateTime')");
$onceSentToday = crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE status='terkirim' AND tipe_jadwal IN ('sekali','sekarang') AND terakhir_dikirim='$todayDate'");
// Jika belum ada jadwal rutin yang jatuh tempo, jangan menampilkan progres 100% yang menyesatkan.
$routineProgress = $routineDueToday > 0 ? (int)round(min(1, $routineSentToday / $routineDueToday) * 100) : 0;

$typeCounts = [
    'rutin' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE tipe_jadwal='harian'"),
    'sekali' => crmCount($conn, "SELECT COUNT(*) total FROM jadwal_pesan_grup WHERE tipe_jadwal IN ('sekali','sekarang')")
];

$dayMap = [1=>'Sen', 2=>'Sel', 3=>'Rab', 4=>'Kam', 5=>'Jum', 6=>'Sab', 7=>'Min'];

/* Grouping schedules by Message and Schedule Configuration */
$campaigns = [];
foreach ($rows as $row) {
    $campKey = md5(
        $row['pesan'] . '|' .
        $row['tipe_jadwal'] . '|' .
        ($row['jadwal_kirim'] ?? '') . '|' .
        ($row['jam_harian'] ?? '') . '|' .
        ($row['hari_rutin'] ?? '') . '|' .
        ($row['media_path'] ?? '')
    );
    if (!isset($campaigns[$campKey])) {
        $campaigns[$campKey] = [
            'key' => $campKey,
            'pesan' => (string)$row['pesan'],
            'media_path' => (string)($row['media_path'] ?? ''),
            'tipe_jadwal' => (string)$row['tipe_jadwal'],
            'jadwal_kirim' => $row['jadwal_kirim'],
            'jam_harian' => $row['jam_harian'],
            'hari_rutin' => $row['hari_rutin'],
            'status' => (string)$row['status'],
            'all_ids' => [],
            'pending_ids' => [],
            'groups' => [],
            'categories' => [],
            'pending_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'latest_sent' => null,
        ];
    }
    $rowId = (int)$row['id'];
    $campaigns[$campKey]['all_ids'][] = $rowId;
    if ($row['status'] === 'pending') {
        $campaigns[$campKey]['pending_ids'][] = $rowId;
        $campaigns[$campKey]['pending_count']++;
    } elseif ($row['status'] === 'terkirim') {
        $campaigns[$campKey]['sent_count']++;
    } elseif ($row['status'] === 'gagal') {
        $campaigns[$campKey]['failed_count']++;
    }
    if (!empty($row['terakhir_dikirim'])) {
        if (!$campaigns[$campKey]['latest_sent'] || $row['terakhir_dikirim'] > $campaigns[$campKey]['latest_sent']) {
            $campaigns[$campKey]['latest_sent'] = $row['terakhir_dikirim'];
        }
    }
    $catName = trim((string)($row['kategori'] ?? '')) ?: 'Tanpa kategori';
    $campaigns[$campKey]['categories'][$catName] = true;
    $campaigns[$campKey]['groups'][] = [
        'id' => $rowId,
        'id_grup' => (string)$row['id_grup'],
        'nama_grup' => (string)$row['nama_grup'],
        'kategori' => $catName,
        'status' => (string)$row['status'],
        'terakhir_dikirim' => $row['terakhir_dikirim']
    ];
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
  <div>
    <span class="eyebrow">Broadcast · Pengelolaan</span>
    <h1>Kelola Jadwal Grup</h1>
    <p>Kelola jadwal berdasarkan isi pesan dan total halaqoh penerima, batalkan sekaligus, atau edit pesan jadwal.</p>
  </div>
  <a class="group-manage-link" href="?page=group"><i class="fa-solid fa-arrow-left"></i> Kembali ke Broadcast</a>
</section>

<section class="group-schedule-stats">
  <a class="group-schedule-stat stat-active <?= $status === 'aktif' ? 'active' : '' ?>" href="?page=group-schedules&amp;status=aktif&amp;jenis=<?= $h($jenis) ?>">
    <span><i class="fa-solid fa-arrows-rotate"></i> Aktif</span>
    <strong><?= (int)$counts['aktif'] ?></strong>
    <small>Jadwal yang masih berjalan</small>
  </a>
  <a class="group-schedule-stat stat-sent <?= $status === 'terkirim' ? 'active' : '' ?>" href="?page=group-schedules&amp;status=terkirim&amp;jenis=<?= $h($jenis) ?>">
    <span><i class="fa-solid fa-check-double"></i> Terkirim</span>
    <strong><?= (int)$counts['terkirim'] ?></strong>
    <small>Jadwal sekali yang selesai</small>
  </a>
  <a class="group-schedule-stat stat-failed <?= $status === 'gagal' ? 'active' : '' ?>" href="?page=group-schedules&amp;status=gagal&amp;jenis=<?= $h($jenis) ?>">
    <span><i class="fa-solid fa-triangle-exclamation"></i> Gagal</span>
    <strong><?= (int)$counts['gagal'] ?></strong>
    <small>Perlu ditinjau</small>
  </a>
</section>

<section class="group-schedule-progress-panel">
  <div class="group-schedule-progress-heading">
    <div>
      <span class="group-kicker">Aktivitas hari ini</span>
      <h2>Progres pengiriman</h2>
      <p>Ringkasan jadwal berdasarkan catatan pengiriman di database.</p>
    </div>
    <span class="group-schedule-live"><i></i> Pemantauan</span>
  </div>
  <div class="group-schedule-progress-grid">
    <article class="group-schedule-progress-card">
      <div class="group-schedule-progress-top">
        <span class="group-schedule-progress-icon"><i class="fa-solid fa-arrows-rotate"></i></span>
        <span class="group-schedule-progress-label">Jadwal rutin hari ini</span>
      </div>
      <div class="group-schedule-progress-value">
        <strong><?= (int)$routineSentToday ?></strong><span>/ <?= (int)$routineDueToday ?> terkirim</span>
      </div>
      <div class="group-schedule-progress-track" role="progressbar" aria-label="Progres jadwal rutin hari ini" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $routineProgress ?>">
        <span style="width:<?= $routineProgress ?>%"></span>
      </div>
      <small><?= $routineDueToday > 0 ? $routineProgress . '% dari jadwal rutin yang jatuh tempo hari ini' : 'Belum ada jadwal rutin yang jatuh tempo' ?></small>
    </article>
    <article class="group-schedule-progress-card">
      <div class="group-schedule-progress-top">
        <span class="group-schedule-progress-icon once"><i class="fa-regular fa-calendar"></i></span>
        <span class="group-schedule-progress-label">Jadwal sekali menunggu</span>
      </div>
      <div class="group-schedule-progress-value">
        <strong><?= (int)$onceWaiting ?></strong><span>perlu diproses</span>
      </div>
      <div class="group-schedule-progress-foot">
        <i class="fa-solid fa-check"></i> <?= (int)$onceSentToday ?> jadwal sekali tercatat terkirim hari ini
      </div>
    </article>
  </div>
</section>

<section class="group-card group-schedule-management">
  <div class="group-schedule-toolbar">
    <div>
      <span class="group-kicker">Daftar jadwal</span>
      <h2><?= $h(['kategori'=>'Jadwal berdasarkan pesan & kategori','rutin'=>'Jadwal rutin','sekali'=>'Jadwal sekali kirim'][$jenis]) ?></h2>
      <p class="group-schedule-caption">Dikelompokkan berdasarkan isi pesan dan total halaqoh penerima.</p>
      <a class="group-schedule-create" href="index.php?page=group"><i class="fa-solid fa-plus"></i><span>Buat jadwal baru</span></a>
    </div>
    <form method="get" class="group-schedule-filter">
      <input type="hidden" name="page" value="group-schedules">
      <input type="hidden" name="jenis" value="<?= $h($jenis) ?>">
      <label for="groupScheduleStatus">Status</label>
      <select id="groupScheduleStatus" name="status" onchange="this.form.submit()">
        <?php foreach (['aktif'=>'Aktif','terkirim'=>'Terkirim','gagal'=>'Gagal','semua'=>'Semua status'] as $value => $label): ?>
          <option value="<?= $h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <nav class="group-schedule-tabs" aria-label="Jenis jadwal">
    <a class="<?= $jenis === 'kategori' ? 'active' : '' ?>" href="?page=group-schedules&amp;jenis=kategori&amp;status=<?= $h($status) ?>">
      <i class="fa-solid fa-layer-group"></i><span>Semua Pesan</span>
    </a>
    <a class="<?= $jenis === 'rutin' ? 'active' : '' ?>" href="?page=group-schedules&amp;jenis=rutin&amp;status=<?= $h($status) ?>">
      <i class="fa-solid fa-arrows-rotate"></i><span>Rutin</span>
    </a>
    <a class="<?= $jenis === 'sekali' ? 'active' : '' ?>" href="?page=group-schedules&amp;jenis=sekali&amp;status=<?= $h($status) ?>">
      <i class="fa-regular fa-calendar"></i><span>Sekali</span>
    </a>
  </nav>

  <?php if (!$campaigns): ?>
    <div class="group-empty">
      <i class="fa-regular fa-calendar-xmark"></i>
      <strong>Tidak ada jadwal</strong>
      <span>Tidak ada data pada pilihan filter ini.</span>
    </div>
  <?php else: ?>
    <div class="group-schedule-scrollbox" tabindex="0" aria-label="Daftar jadwal grup yang dapat digulir">
      <?php foreach ($campaigns as $c): ?>
        <article class="group-schedule-campaign-card" id="card-<?= $c['key'] ?>">
          <div class="group-schedule-campaign-header">
            <div class="group-schedule-header-left">
              <span class="group-schedule-halaqoh-chip">
                <i class="fa-solid fa-users"></i> <strong><?= count($c['groups']) ?></strong> Halaqoh
              </span>
              <?php if (!empty($c['categories'])): ?>
                <span class="group-schedule-categories-chip" title="<?= $h(implode(', ', array_keys($c['categories']))) ?>">
                  <i class="fa-solid fa-folder-tree"></i> <?= $h(implode(', ', array_slice(array_keys($c['categories']), 0, 2))) ?><?= count($c['categories']) > 2 ? ' +'.(count($c['categories']) - 2) : '' ?>
                </span>
              <?php endif; ?>
              <span class="group-schedule-type <?= $c['tipe_jadwal'] === 'harian' ? 'routine' : 'once' ?>">
                <i class="fa-solid <?= $c['tipe_jadwal'] === 'harian' ? 'fa-repeat' : 'fa-calendar' ?>"></i>
                <?= $c['tipe_jadwal'] === 'harian' ? 'Rutin' : 'Sekali' ?>
              </span>
              <span class="group-schedule-time-text">
                <i class="fa-regular fa-clock"></i>
                <?= $formatSchedule(['tipe_jadwal'=>$c['tipe_jadwal'],'jadwal_kirim'=>$c['jadwal_kirim'],'jam_harian'=>$c['jam_harian'],'hari_rutin'=>$c['hari_rutin']]) ?>
              </span>
            </div>
            <div class="group-schedule-header-right">
              <?php if ($c['pending_count'] > 0): ?>
                <span class="group-schedule-status status-aktif">
                  <i class="fa-solid fa-circle-dot"></i> Aktif (<?= $c['pending_count'] ?> pending)
                </span>
              <?php elseif ($c['failed_count'] > 0): ?>
                <span class="group-schedule-status status-gagal">
                  <i class="fa-solid fa-triangle-exclamation"></i> <?= $c['failed_count'] ?> Gagal
                </span>
              <?php else: ?>
                <span class="group-schedule-status status-terkirim">
                  <i class="fa-solid fa-check-double"></i> Selesai Terkirim
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div class="group-schedule-message-box">
            <div class="group-schedule-message-label">
              <span><i class="fa-regular fa-message"></i> Isi Pesan WhatsApp</span>
              <?php if (!empty($c['media_path'])): ?>
                <span class="group-schedule-media-pill"><i class="fa-solid fa-image"></i> Ada Gambar</span>
              <?php endif; ?>
            </div>
            <div class="group-schedule-message-text"><?= nl2br($h($c['pesan'])) ?></div>
          </div>

          <div class="group-schedule-footer-bar">
            <div class="group-schedule-btn-group">
              <button type="button" class="group-schedule-action-btn btn-detail" onclick="toggleScheduleDetail('<?= $c['key'] ?>')">
                <i class="fa-solid fa-list-ul"></i> Detail Halaqoh (<?= count($c['groups']) ?>)
                <i class="fa-solid fa-chevron-down detail-arrow" id="arrow-<?= $c['key'] ?>"></i>
              </button>

              <?php if ($c['pending_count'] > 0): ?>
                <button type="button" class="group-schedule-action-btn btn-edit" data-key="<?= $c['key'] ?>" data-ids="<?= implode(',', $c['pending_ids']) ?>" onclick="openEditScheduleModal(this)">
                  <i class="fa-solid fa-pen-to-square"></i> Edit Pesan
                </button>
              <?php endif; ?>
            </div>

            <div class="group-schedule-cancel-wrap">
              <?php if ($c['pending_count'] > 0): ?>
                <form method="post" action="actions/group-schedules.php" onsubmit="return confirm('Batalkan jadwal untuk SEMUA <?= $c['pending_count'] ?> halaqoh dengan pesan ini?');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>">
                  <input type="hidden" name="action" value="cancel_batch">
                  <input type="hidden" name="return_page" value="group-schedules">
                  <input type="hidden" name="id_list" value="<?= implode(',', $c['pending_ids']) ?>">
                  <input type="hidden" name="status" value="<?= $h($status) ?>">
                  <input type="hidden" name="jenis" value="<?= $h($jenis) ?>">
                  <button type="submit" class="group-schedule-cancel-batch">
                    <i class="fa-solid fa-ban"></i> Batalkan Semua (<?= $c['pending_count'] ?>)
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <!-- Expandable Detail Panel -->
          <div class="group-schedule-detail-panel" id="detail-<?= $c['key'] ?>" hidden>
            <div class="group-schedule-detail-head">
              <div>
                <strong>Daftar Halaqoh Penerima</strong>
                <small>Total <?= count($c['groups']) ?> halaqoh terdaftar dalam jadwal ini</small>
              </div>
              <input type="search" placeholder="Cari halaqoh..." class="group-schedule-detail-search" oninput="filterDetailList('<?= $c['key'] ?>', this.value)">
            </div>
            <div class="group-schedule-detail-list">
              <?php foreach ($c['groups'] as $g): ?>
                <div class="group-schedule-detail-row" data-name="<?= strtolower($h($g['nama_grup'])) ?>">
                  <div class="group-schedule-detail-info">
                    <span class="group-detail-chip"><?= $h($g['nama_grup']) ?></span>
                    <small class="group-detail-cat"><i class="fa-regular fa-folder"></i> <?= $h($g['kategori']) ?></small>
                    <span class="group-detail-id">#<?= (int)$g['id'] ?></span>
                  </div>
                  <div class="group-schedule-detail-status">
                    <?php [$subLabel, $subClass] = $displayStatus($g); ?>
                    <span class="group-schedule-status status-<?= $h($subClass) ?>"><?= $h($subLabel) ?></span>
                    <?php if (!empty($g['terakhir_dikirim'])): ?>
                      <small class="group-detail-date">Kirim: <?= $h(date('d/m/Y', strtotime((string)$g['terakhir_dikirim']))) ?></small>
                    <?php endif; ?>
                  </div>
                  <div class="group-schedule-detail-action">
                    <?php if ($g['status'] === 'pending'): ?>
                      <form method="post" action="actions/group-schedules.php" onsubmit="return confirm('Batalkan jadwal untuk halaqoh <?= $h($g['nama_grup']) ?>?');">
                        <input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                        <input type="hidden" name="return_page" value="group-schedules">
                        <input type="hidden" name="status" value="<?= $h($status) ?>">
                        <input type="hidden" name="jenis" value="<?= $h($jenis) ?>">
                        <button type="submit" class="group-detail-cancel-btn" title="Batalkan satu halaqoh ini">
                          <i class="fa-solid fa-xmark"></i> Batalkan
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Edit Message Modal -->
<div id="groupEditScheduleModal" hidden style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.45);padding:18px;align-items:center;justify-content:center">
  <div class="group-edit-modal-card">
    <div class="group-edit-modal-header">
      <div>
        <span class="group-kicker">Edit Pesan</span>
        <h3>Ubah Isi Pesan Jadwal</h3>
        <p id="groupEditModalSubtitle">Memperbarui pesan untuk seluruh halaqoh pending dalam jadwal ini</p>
      </div>
      <button type="button" class="group-edit-modal-close" onclick="closeEditScheduleModal()" aria-label="Tutup">×</button>
    </div>
    <form method="post" action="actions/group-schedules.php" id="groupEditScheduleForm">
      <input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>">
      <input type="hidden" name="action" value="edit_message">
      <input type="hidden" name="return_page" value="group-schedules">
      <input type="hidden" name="status" value="<?= $h($status) ?>">
      <input type="hidden" name="jenis" value="<?= $h($jenis) ?>">
      <input type="hidden" name="id_list" id="groupEditIdList">

      <label class="group-edit-field">
        <span>Isi Pesan Baru</span>
        <textarea name="pesan" id="groupEditMessageText" rows="6" required placeholder="Tulis isi pesan baru..."></textarea>
      </label>

      <div class="group-edit-modal-footer">
        <button type="button" class="group-edit-btn-cancel" onclick="closeEditScheduleModal()">Batal</button>
        <button type="submit" class="group-edit-btn-save"><i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleScheduleDetail(key) {
  const panel = document.getElementById('detail-' + key);
  const arrow = document.getElementById('arrow-' + key);
  if (!panel) return;
  const isHidden = panel.hidden;
  panel.hidden = !isHidden;
  if (arrow) {
    arrow.style.transform = isHidden ? 'rotate(180deg)' : '';
  }
}

function filterDetailList(key, query) {
  const panel = document.getElementById('detail-' + key);
  if (!panel) return;
  const q = String(query || '').toLowerCase().trim();
  const rows = panel.querySelectorAll('.group-schedule-detail-row');
  rows.forEach(r => {
    const name = r.dataset.name || '';
    r.style.display = (q === '' || name.includes(q)) ? '' : 'none';
  });
}

function openEditScheduleModal(btn) {
  const modal = document.getElementById('groupEditScheduleModal');
  const card = btn.closest('.group-schedule-campaign-card');
  const idListInput = document.getElementById('groupEditIdList');
  const textInput = document.getElementById('groupEditMessageText');
  const subtitle = document.getElementById('groupEditModalSubtitle');

  if (!modal || !card) return;
  const ids = btn.dataset.ids || '';
  const idCount = ids ? ids.split(',').length : 0;
  const currentMsg = card.querySelector('.group-schedule-message-text')?.innerText || '';

  if (idListInput) idListInput.value = ids;
  if (textInput) {
    textInput.value = currentMsg.trim();
    setTimeout(() => textInput.focus(), 60);
  }
  if (subtitle) {
    subtitle.textContent = 'Akan diterapkan untuk ' + idCount + ' halaqoh pending dalam jadwal ini';
  }

  modal.hidden = false;
  modal.style.display = 'flex';
}

function closeEditScheduleModal() {
  const modal = document.getElementById('groupEditScheduleModal');
  if (modal) {
    modal.hidden = true;
    modal.style.display = 'none';
  }
}
</script>
