<?php
declare(strict_types=1);

$crmTitle = 'More';

$items = [
    ['title' => 'Template Pesan', 'description' => 'Kelola template pesan', 'icon' => 'fa-regular fa-file-lines', 'href' => 'index.php?page=templates'],
    ['title' => 'Auto Reply', 'description' => 'Atur balasan otomatis', 'icon' => 'fa-solid fa-robot', 'href' => 'index.php?page=auto-reply'],
    ['title' => 'Kelola Grup', 'description' => 'Daftar dan pengelolaan grup', 'icon' => 'fa-solid fa-users', 'href' => 'index.php?page=manage-groups'],
    ['title' => 'Analytics', 'description' => 'Lihat analitik CRM', 'icon' => 'fa-solid fa-chart-line', 'href' => '../grafik.php'],
];
?>
<section class="page-head">
    <span class="eyebrow">Workspace</span>
    <h1>More</h1>
    <p>Fitur pendukung CRM dikumpulkan di satu tempat.</p>
</section>

<div class="action-list">
    <?php foreach ($items as $item): ?>
        <a href="<?= htmlspecialchars($item['href']) ?>">
            <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
            <div>
                <strong><?= htmlspecialchars($item['title']) ?></strong>
                <span><?= htmlspecialchars($item['description']) ?></span>
            </div>
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    <?php endforeach; ?>

    <a href="../logoutwa.php">
        <i class="fa-solid fa-right-from-bracket danger"></i>
        <div>
            <strong>Keluar</strong>
            <span>Logout akun</span>
        </div>
        <i class="fa-solid fa-chevron-right"></i>
    </a>
</div>
