<?php
declare(strict_types=1);

$crmTitle = 'Reminder';
$tab = (string)($_GET['tab'] ?? 'pembayaran');
$allowedTabs = ['pembayaran', 'pengajar', 'promosi', 'peserta'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'pembayaran';

$tabs = [
    'pembayaran' => ['label'=>'Pembayaran','icon'=>'fa-wallet','description'=>'Kelola pengingat pembayaran peserta.'],
    'pengajar' => ['label'=>'Pengajar','icon'=>'fa-chalkboard-user','description'=>'Kelola pesan dan pengingat untuk pengajar.'],
    'promosi' => ['label'=>'Promosi','icon'=>'fa-bullhorn','description'=>'Kelola broadcast promosi dan target penerima.'],
    'peserta' => ['label'=>'Pengingat Peserta','icon'=>'fa-clock','description'=>'Kelola request pengingat dan tindak lanjut peserta.'],
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
    <p>Satu workspace untuk seluruh komunikasi pengingat. Ringkas, cepat, dan siap dibangun tanpa membawa UI legacy.</p>
  </div>
</section>

<section class="reminder-v2-tabs" aria-label="Pilih workspace reminder">
<?php foreach ($tabs as $key=>$item): ?>
  <a
    href="?page=reminder&tab=<?= urlencode($key) ?>"
    class="reminder-v2-tab <?= $tab===$key?'active':'' ?>"
    aria-label="<?= htmlspecialchars($item['label']) ?>"
    title="<?= htmlspecialchars($item['label']) ?>"
  >
    <span class="reminder-v2-tab-icon"><i class="fa-solid <?= $item['icon'] ?>"></i></span>
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
    <div>
      <span class="reminder-v2-kicker">Workspace</span>
      <h2><?= htmlspecialchars($tabs[$tab]['label']) ?></h2>
      <p><?= htmlspecialchars($tabs[$tab]['description']) ?></p>
    </div>
  </div>

  <div class="reminder-v2-workspace">
    <div class="reminder-v2-section-head">
      <div>
        <span class="reminder-v2-kicker">New build</span>
        <h3><?= htmlspecialchars($tabs[$tab]['label']) ?></h3>
        <p>Area kerja baru untuk <?= strtolower(htmlspecialchars($tabs[$tab]['label'])) ?>. Fokus pada alur yang paling sering dipakai, minim klik, dan tetap nyaman di mobile.</p>
      </div>
    </div>

    <div class="reminder-v2-feature-list" aria-label="Prinsip workspace">
      <span>Minimal</span>
      <span>Fast</span>
      <span>Mobile first</span>
      <span>Bulk action</span>
    </div>
  </div>
</section>
