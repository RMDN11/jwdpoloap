<?php
declare(strict_types=1);

$crmTitle = 'More';

$items = [
    [
        'title' => 'Template Pesan',
        'description' => 'Buat dan kelola template pesan.',
        'icon' => 'fa-file-lines',
        'href' => '../manage_templates.php',
        'tone' => 'green',
    ],
    [
        'title' => 'Auto Reply',
        'description' => 'Atur balasan otomatis WhatsApp.',
        'icon' => 'fa-robot',
        'href' => '../manage_auto_reply.php',
        'tone' => 'violet',
    ],
    [
        'title' => 'Kelola Grup',
        'description' => 'Kelola daftar dan data grup WhatsApp.',
        'icon' => 'fa-users',
        'href' => '../kelola_grup.php',
        'tone' => 'amber',
    ],
    [
        'title' => 'Analytics',
        'description' => 'Lihat data dan performa CRM.',
        'icon' => 'fa-chart-line',
        'href' => '../grafik.php',
        'tone' => 'indigo',
    ],
];
?>

<section class="more-page">
    <header class="more-hero">
        <div class="more-hero-copy">
            <span class="eyebrow">Workspace</span>
            <h1>More</h1>
            <p>Tools pendukung CRM dalam satu tempat.</p>
        </div>
        <div class="more-hero-icon" aria-hidden="true">
            <i class="fa-solid fa-toolbox"></i>
        </div>
    </header>

    <div class="more-tool-grid more-tool-grid-single">
        <?php foreach ($items as $item): ?>
            <a class="more-tool-card" href="<?= htmlspecialchars($item['href']) ?>">
                <span class="more-tool-icon <?= htmlspecialchars($item['tone']) ?>">
                    <i class="fa-solid <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
                </span>
                <span class="more-tool-copy">
                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                    <small><?= htmlspecialchars($item['description']) ?></small>
                </span>
                <i class="fa-solid fa-chevron-right more-tool-arrow" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </div>

    <section class="more-account-card">
        <div class="more-account-icon"><i class="fa-solid fa-right-from-bracket"></i></div>
        <div>
            <span class="eyebrow">Account</span>
            <strong>Keluar</strong>
            <small>Akhiri sesi CRM dengan aman.</small>
        </div>
        <a href="../logoutwa.php" class="more-logout">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span>Keluar</span>
        </a>
    </section>
</section>
