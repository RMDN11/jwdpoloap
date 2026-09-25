<?php
declare(strict_types=1);

$crmTitle = 'Reminder';

$reminderModules = [
    ['label'=>'Pembayaran','description'=>'Pengingat tagihan peserta','icon'=>'fa-wallet','page'=>'reminder-pembayaran'],
    ['label'=>'Pengajar','description'=>'Kirim pesan ke pengajar','icon'=>'fa-chalkboard-user','page'=>'reminder-pengajar'],
    ['label'=>'Promosi','description'=>'Broadcast dan kampanye','icon'=>'fa-bullhorn','page'=>'reminder-promosi'],
    ['label'=>'Peserta','description'=>'Kelola pengingat peserta','icon'=>'fa-clock','page'=>'reminder-peserta'],
];
?>

<section class="page-head">
    <span class="eyebrow">Workspace</span>
    <h1>Reminder</h1>
    <p>Kelola berbagai kebutuhan pengingat dari satu tempat.</p>
</section>

<div class="action-list reminder-action-list">
<?php foreach ($reminderModules as $module): ?>
    <a href="?page=<?= urlencode($module['page']) ?>" aria-label="<?= htmlspecialchars($module['label']) ?>">
        <i class="fa-solid <?= htmlspecialchars($module['icon']) ?>"></i>
        <div>
            <strong><?= htmlspecialchars($module['label']) ?></strong>
            <span><?= htmlspecialchars($module['description']) ?></span>
        </div>
        <i class="fa-solid fa-chevron-right"></i>
    </a>
<?php endforeach; ?>
</div>
