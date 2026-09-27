<?php
declare(strict_types=1);

$crmTitle = 'More';

$sections = [
    [
        'eyebrow' => 'Pesan',
        'title' => 'Kelola pesan',
        'description' => 'Siapkan materi dan otomatisasi komunikasi WhatsApp.',
        'items' => [
            [
                'title' => 'Template Pesan',
                'description' => 'Buat dan kelola template follow-up.',
                'icon' => 'fa-file-lines',
                'href' => '../manage_templates.php',
                'tone' => 'green',
            ],
            [
                'title' => 'Auto Reply',
                'description' => 'Atur keyword, balasan, prioritas, dan status rule.',
                'icon' => 'fa-robot',
                'href' => '../manage_auto_reply.php',
                'tone' => 'violet',
            ],
        ],
    ],
    [
        'eyebrow' => 'Distribusi',
        'title' => 'Grup & broadcast',
        'description' => 'Kirim pesan ke grup dan kelola daftar tujuan.',
        'items' => [
            [
                'title' => 'Kirim ke Grup',
                'description' => 'Broadcast sekarang atau jadwalkan pengiriman.',
                'icon' => 'fa-paper-plane',
                'href' => '?page=group',
                'tone' => 'blue',
            ],
            [
                'title' => 'Kelola Grup',
                'description' => 'Atur daftar dan data grup WhatsApp.',
                'icon' => 'fa-users',
                'href' => '../kelola_grup.php',
                'tone' => 'amber',
            ],
        ],
    ],
    [
        'eyebrow' => 'Insight',
        'title' => 'Data & analitik',
        'description' => 'Pantau hasil aktivitas CRM dari satu tempat.',
        'items' => [
            [
                'title' => 'Analytics',
                'description' => 'Lihat grafik dan ringkasan performa CRM.',
                'icon' => 'fa-chart-line',
                'href' => '../grafik.php',
                'tone' => 'indigo',
            ],
        ],
    ],
];

$quickLinks = [
    ['label' => 'Follow Up', 'href' => '?page=chat', 'icon' => 'fa-paper-plane'],
    ['label' => 'Reminder', 'href' => '?page=reminder', 'icon' => 'fa-bell'],
    ['label' => 'Grup', 'href' => '?page=group', 'icon' => 'fa-users'],
];
?>

<section class="more-page">
    <header class="more-hero">
        <div class="more-hero-copy">
            <span class="eyebrow">Workspace</span>
            <h1>More</h1>
            <p>Semua tools pendukung CRM, dirapikan dalam satu workspace.</p>
        </div>
        <div class="more-hero-icon" aria-hidden="true">
            <i class="fa-solid fa-toolbox"></i>
        </div>
    </header>

    <nav class="more-quick-links" aria-label="Akses cepat">
        <?php foreach ($quickLinks as $link): ?>
            <a href="<?= htmlspecialchars($link['href']) ?>">
                <i class="fa-solid <?= htmlspecialchars($link['icon']) ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($link['label']) ?></span>
                <i class="fa-solid fa-arrow-up-right-from-square more-quick-arrow" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="more-sections">
        <?php foreach ($sections as $section): ?>
            <section class="more-section">
                <div class="more-section-head">
                    <div>
                        <span class="eyebrow"><?= htmlspecialchars($section['eyebrow']) ?></span>
                        <h2><?= htmlspecialchars($section['title']) ?></h2>
                    </div>
                    <span class="more-section-count"><?= count($section['items']) ?> tools</span>
                </div>
                <p class="more-section-description"><?= htmlspecialchars($section['description']) ?></p>

                <div class="more-tool-grid">
                    <?php foreach ($section['items'] as $item): ?>
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
            </section>
        <?php endforeach; ?>
    </div>

    <section class="more-account-card">
        <div class="more-account-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <div>
            <span class="eyebrow">Account</span>
            <strong>Sesi CRM aktif</strong>
            <small>Keluar dari workspace dengan aman.</small>
        </div>
        <a href="../logoutwa.php" class="more-logout">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span>Keluar</span>
        </a>
    </section>
</section>
