<?php
declare(strict_types=1);

$crmTitle = 'Reminder';

$reminderModules = [
    ['label'=>'Pembayaran','icon'=>'fa-wallet','page'=>'reminder-pembayaran'],
    ['label'=>'Pengajar','icon'=>'fa-chalkboard-user','page'=>'reminder-pengajar'],
    ['label'=>'Promosi','icon'=>'fa-bullhorn','page'=>'reminder-promosi'],
    ['label'=>'Pengingat Peserta','icon'=>'fa-clock','page'=>'reminder-peserta'],
];
?>

<section class="page-head reminder-launcher-head">
    <div>
        <span class="eyebrow">CRM · Communication</span>
        <h1>Reminder</h1>
        <p>Pilih workspace.</p>
    </div>
</section>

<nav class="reminder-launcher" aria-label="Reminder workspace">
<?php foreach ($reminderModules as $module): ?>
    <a
        class="reminder-launcher-btn"
        href="?page=<?= urlencode($module['page']) ?>"
        aria-label="<?= htmlspecialchars($module['label']) ?>"
        title="<?= htmlspecialchars($module['label']) ?>"
    >
        <i class="fa-solid <?= htmlspecialchars($module['icon']) ?>"></i>
    </a>
<?php endforeach; ?>
</nav>
