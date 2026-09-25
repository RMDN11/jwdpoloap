<?php
declare(strict_types=1);

$crmTitle = 'Reminder';

$tabs = [
    ['label' => 'Pembayaran', 'icon' => 'fa-wallet', 'href' => '?page=reminder-pembayaran', 'desc' => 'Pengingat pembayaran peserta'],
    ['label' => 'Pengajar', 'icon' => 'fa-chalkboard-user', 'href' => '?page=reminder-pengajar', 'desc' => 'Kirim pesan kepada pengajar'],
    ['label' => 'Promosi', 'icon' => 'fa-bullhorn', 'href' => '?page=reminder-promosi', 'desc' => 'Kirim promosi dan broadcast peserta'],
    ['label' => 'Pengingat Peserta', 'icon' => 'fa-clock', 'href' => '../kelola_reminder.php', 'desc' => 'Kelola reminder dan follow-up peserta'],
];
?>

<section class="page-head">
    <span class="eyebrow">Workspace</span>
    <h1>Reminder</h1>
    <p>Kelola pengingat pembayaran, pengajar, promosi, dan peserta.</p>
</section>

<div class="action-list reminder-workspace-list">
    <?php foreach ($tabs as $item): ?>
        <a href="<?= htmlspecialchars($item['href']) ?>">
            <i class="fa-solid <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
            <div>
                <strong><?= htmlspecialchars($item['label']) ?></strong>
                <span><?= htmlspecialchars($item['desc']) ?></span>
            </div>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </a>
    <?php endforeach; ?>
</div>
