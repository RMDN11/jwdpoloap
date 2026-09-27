<?php
declare(strict_types=1);

$crmTitle = 'More';

$items = [
    ['title' => 'Template Pesan', 'description' => 'Kelola template pesan', 'icon' => 'fa-regular fa-file-lines', 'href' => '../manage_templates.php'],
    ['title' => 'Auto Reply', 'description' => 'Atur balasan otomatis', 'icon' => 'fa-solid fa-robot', 'href' => '../manage_auto_reply.php'],
    ['title' => 'Kelola Grup', 'description' => 'Daftar dan pengelolaan grup', 'icon' => 'fa-solid fa-users', 'href' => '../kelola_grup.php'],
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
