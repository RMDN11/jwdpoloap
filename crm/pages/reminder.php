<?php
declare(strict_types=1);

$crmTitle = 'Reminder';
$tab = (string)($_GET['tab'] ?? 'pembayaran');
$allowedTabs = ['pembayaran', 'pengajar', 'promosi', 'peserta'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'pembayaran';

$tabs = [
    'pembayaran' => ['label'=>'Pembayaran','icon'=>'fa-wallet','description'=>'Pengingat tagihan dan status pembayaran.','legacy'=>'../reminder.php'],
    'pengajar' => ['label'=>'Pengajar','icon'=>'fa-chalkboard-user','description'=>'Pesan WhatsApp untuk pengajar berdasarkan halaqoh.','legacy'=>'../wa-tut.php'],
    'promosi' => ['label'=>'Promosi','icon'=>'fa-bullhorn','description'=>'Broadcast promosi dengan target peserta dan media.','legacy'=>'../promosi.php'],
    'peserta' => ['label'=>'Pengingat Peserta','icon'=>'fa-clock','description'=>'Request pengingat peserta yang telat kelas atau perlu tindak lanjut.','legacy'=>'../kelola_reminder.php'],
];

$stats = ['contacts'=>0,'pending'=>0,'today'=>0];
$r = $conn->query("SELECT COUNT(*) total FROM peserta WHERE nowa IS NOT NULL AND nowa <> ''");
if ($r && ($row=$r->fetch_assoc())) $stats['contacts']=(int)$row['total'];
$r = $conn->query("SELECT COUNT(*) total FROM reminder_requests WHERE status <> 'terkirim'");
if ($r && ($row=$r->fetch_assoc())) $stats['pending']=(int)$row['total'];
$r = $conn->query("SELECT COUNT(*) total FROM log_wa WHERE DATE(created_at)=CURDATE() AND message LIKE '%[REMINDER]%'");
if ($r && ($row=$r->fetch_assoc())) $stats['today']=(int)$row['total'];
?>

<section class="page-head reminder-page-head">
  <div>
    <span class="eyebrow">CRM · Reminder</span>
    <h1>Reminder</h1>
    <p>Empat alur komunikasi dalam satu workspace. Logic lama tetap menjadi baseline, UI-nya kita rapikan.</p>
  </div>
</section>

<section class="reminder-v2-tabs" aria-label="Workspace Reminder">
<?php foreach ($tabs as $key=>$item): ?>
  <a href="?page=reminder&tab=<?= urlencode($key) ?>" class="reminder-v2-tab <?= $tab===$key?'active':'' ?>">
    <span class="reminder-v2-tab-icon"><i class="fa-solid <?= $item['icon'] ?>"></i></span>
    <span class="reminder-v2-tab-copy"><strong><?= htmlspecialchars($item['label']) ?></strong><small><?= htmlspecialchars($item['description']) ?></small></span>
  </a>
<?php endforeach; ?>
</section>

<section class="reminder-v2-stats">
  <div class="reminder-v2-stat"><span class="reminder-v2-stat-icon amber"><i class="fa-solid fa-users"></i></span><div><strong><?= $stats['contacts'] ?></strong><small>Kontak peserta</small></div></div>
  <div class="reminder-v2-stat"><span class="reminder-v2-stat-icon purple"><i class="fa-solid fa-inbox"></i></span><div><strong><?= $stats['pending'] ?></strong><small>Request menunggu</small></div></div>
  <div class="reminder-v2-stat"><span class="reminder-v2-stat-icon green"><i class="fa-solid fa-paper-plane"></i></span><div><strong><?= $stats['today'] ?></strong><small>Reminder hari ini</small></div></div>
</section>

<section class="reminder-v2-shell">
  <div class="reminder-v2-hero">
    <div><span class="reminder-v2-kicker">Workspace aktif</span><h2><?= htmlspecialchars($tabs[$tab]['label']) ?></h2><p><?= htmlspecialchars($tabs[$tab]['description']) ?></p></div>
    <a class="reminder-v2-legacy-link" href="<?= htmlspecialchars($tabs[$tab]['legacy']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Buka baseline</a>
  </div>

  <?php if ($tab==='pembayaran'): ?>
    <div class="reminder-v2-workspace">
      <div class="reminder-v2-section-head"><div><span class="reminder-v2-kicker">Baseline: reminder.php</span><h3>Pembayaran</h3><p>Target utama: peserta yang perlu diingatkan pembayaran. Baseline punya pencarian, halaqoh, status peserta, bulan pembayaran, riwayat log, template, dan kirim langsung.</p></div><a class="reminder-v2-primary" href="<?= $tabs[$tab]['legacy'] ?>">Buka pembayaran <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="reminder-v2-feature-list"><span>Filter peserta</span><span>Bulan pembayaran</span><span>Lunas / belum lunas</span><span>Template WA</span><span>Riwayat log</span></div>
    </div>
  <?php elseif ($tab==='pengajar'): ?>
    <div class="reminder-v2-workspace">
      <div class="reminder-v2-section-head"><div><span class="reminder-v2-kicker">Baseline: wa-tut.php</span><h3>Pengajar</h3><p>Target berasal dari <code>pengampu</code>. Baseline mendukung pencarian, filter halaqoh, multi-select, placeholder <code>{nama}</code>/<code>{halaqoh}</code>, pengiriman AJAX satu per satu, dan logging.</p></div><a class="reminder-v2-primary" href="<?= $tabs[$tab]['legacy'] ?>">Buka pengajar <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="reminder-v2-feature-list"><span>Daftar pengajar</span><span>Filter halaqoh</span><span>Multi-select</span><span>Kirim AJAX</span><span>Log pesan</span></div>
    </div>
  <?php elseif ($tab==='promosi'): ?>
    <div class="reminder-v2-workspace">
      <div class="reminder-v2-section-head"><div><span class="reminder-v2-kicker">Baseline: promosi.php</span><h3>Promosi</h3><p>Target promosi menggunakan peserta + filter halaqoh/status/pembayaran. Baseline menangani upload gambar, caption, preview, broadcast media, personalisasi <code>{nama}</code>, dan log WA.</p></div><a class="reminder-v2-primary" href="<?= $tabs[$tab]['legacy'] ?>">Buka promosi <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="reminder-v2-feature-list"><span>Target penerima</span><span>Filter</span><span>Upload gambar</span><span>Preview caption</span><span>Broadcast media</span></div>
    </div>
  <?php else: ?>
    <div class="reminder-v2-workspace">
      <div class="reminder-v2-section-head"><div><span class="reminder-v2-kicker">Baseline: kelola_reminder.php</span><h3>Pengingat Peserta</h3><p>Request datang dari workflow peserta/pengajar melalui <code>reminder_requests</code>. Baseline mendukung status menunggu/terkirim, kirim satu, kirim semua, hapus request, dan template reminder khusus.</p></div><a class="reminder-v2-primary" href="<?= $tabs[$tab]['legacy'] ?>">Buka pengingat peserta <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="reminder-v2-feature-list"><span>Request masuk</span><span>Peserta telat kelas</span><span>Kirim satu / semua</span><span>Template khusus</span><span>Status terkirim</span></div>
    </div>
  <?php endif; ?>
</section>
