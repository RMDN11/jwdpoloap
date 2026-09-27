<?php
$crmTitle = 'More';

$tools = [
    [
        'title' => 'Template Pesan',
        'description' => 'Kelola template untuk follow up',
        'icon' => 'fa-file-lines',
        'tone' => 'green',
        'href' => '../manage_templates.php',
        'group' => 'Operasional',
    ],
    [
        'title' => 'Auto Reply',
        'description' => 'Atur balasan otomatis WhatsApp',
        'icon' => 'fa-robot',
        'tone' => 'purple',
        'href' => '../manage_auto_reply.php',
        'group' => 'Operasional',
    ],
    [
        'title' => 'Kirim ke Grup',
        'description' => 'Broadcast dan jadwal pesan grup',
        'icon' => 'fa-bullhorn',
        'tone' => 'blue',
        'href' => '?page=group',
        'group' => 'Grup & Broadcast',
    ],
    [
        'title' => 'Kelola Grup',
        'description' => 'Daftar dan pengelolaan grup',
        'icon' => 'fa-users',
        'tone' => 'indigo',
        'href' => '../kelola_grup.php',
        'group' => 'Grup & Broadcast',
    ],
    [
        'title' => 'Analytics',
        'description' => 'Pantau data dan performa CRM',
        'icon' => 'fa-chart-line',
        'tone' => 'amber',
        'href' => '../grafik.php',
        'group' => 'Data',
    ],
];
?>

<section class="more-hero">
    <div class="more-hero-copy">
        <span class="eyebrow">Workspace</span>
        <h1>More</h1>
        <p>Semua tools pendukung CRM, rapi di satu tempat.</p>
    </div>
    <div class="more-hero-icon" aria-hidden="true">
        <i class="fa-solid fa-toolbox"></i>
    </div>
</section>

<section class="more-section" aria-labelledby="more-tools-title">
    <div class="more-section-head">
        <div>
            <span class="more-kicker">Tools</span>
            <h2 id="more-tools-title">Yang kamu butuhkan</h2>
        </div>
        <span class="more-count"><?= count($tools) ?> tools</span>
    </div>

    <div class="more-grid">
        <?php foreach ($tools as $tool): ?>
            <a href="<?= htmlspecialchars($tool['href']) ?>" class="more-card">
                <span class="more-card-icon <?= htmlspecialchars($tool['tone']) ?>">
                    <i class="fa-solid <?= htmlspecialchars($tool['icon']) ?>" aria-hidden="true"></i>
                </span>
                <span class="more-card-copy">
                    <strong><?= htmlspecialchars($tool['title']) ?></strong>
                    <small><?= htmlspecialchars($tool['description']) ?></small>
                </span>
                <span class="more-card-arrow" aria-hidden="true">
                    <i class="fa-solid fa-arrow-up-right"></i>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="more-account">
    <div class="more-account-icon">
        <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
    </div>
    <div class="more-account-copy">
        <strong>Keluar dari CRM</strong>
        <span>Keluar dari sesi akun saat ini</span>
    </div>
    <a href="logout.php" class="more-logout" aria-label="Keluar dari CRM">
        Keluar <i class="fa-solid fa-arrow-right"></i>
    </a>
</section>
