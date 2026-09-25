<?php
declare(strict_types=1);

$crmTitle = 'Reminder';
$tab = (string)($_GET['tab'] ?? 'pembayaran');
$allowedTabs = ['pembayaran', 'pengajar', 'promosi', 'peserta'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'pembayaran';

$tabs = [
    'pembayaran' => ['label'=>'Pembayaran','icon'=>'fa-wallet','legacy'=>'?page=reminder-pembayaran'],
    'pengajar' => ['label'=>'Pengajar','icon'=>'fa-chalkboard-user','legacy'=>'../wa-tut.php'],
    'promosi' => ['label'=>'Promosi','icon'=>'fa-bullhorn','legacy'=>'../promosi.php'],
    'peserta' => ['label'=>'Pengingat Peserta','icon'=>'fa-clock','legacy'=>'../kelola_reminder.php'],
];
?>

<section class="reminder-icon-page" aria-label="Reminder">
  <div class="reminder-icon-grid">
  <?php foreach ($tabs as $key=>$item): ?>
    <a
      href="?page=reminder&tab=<?= urlencode($key) ?>"
      class="reminder-icon-box <?= $tab===$key?'active':'' ?>"
      title="<?= htmlspecialchars($item['label']) ?>"
      aria-label="<?= htmlspecialchars($item['label']) ?>"
    >
      <i class="fa-solid <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
    </a>
  <?php endforeach; ?>
  </div>
</section>
