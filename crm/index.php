<?php
session_start();

if (!file_exists('../auth_checkwa.php')) {
    die('<div style="padding:30px;font-family:system-ui">Sistem autentikasi tidak ditemukan.</div>');
}
require_once '../auth_checkwa.php';

$page = $_GET['page'] ?? 'home';
$allowedPages = ['home','chat','action','reminder','more'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'home';
}

$nav = [
    'home' => ['label' => 'Home', 'icon' => 'fa-house'],
    'chat' => ['label' => 'Chat', 'icon' => 'fa-comments'],
    'action' => ['label' => 'Action', 'icon' => 'fa-plus'],
    'reminder' => ['label' => 'Reminder', 'icon' => 'fa-bell'],
    'more' => ['label' => 'More', 'icon' => 'fa-grid-2'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#166534">
    <title>ReqraWA CRM</title>
    <link rel="icon" type="image/png" href="../LOGOJWD.png">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark"><i class="fab fa-whatsapp"></i></div>
            <div>
                <div class="brand-name">Reqra<span>WA</span></div>
                <div class="brand-subtitle">CRM</div>
            </div>
        </div>
        <div class="top-actions">
            <button class="icon-btn" aria-label="Notifikasi"><i class="fa-regular fa-bell"></i></button>
            <div class="avatar">H</div>
        </div>
    </header>

    <main class="content">
        <?php if ($page === 'home'): ?>
            <section class="hero-card">
                <div>
                    <span class="eyebrow">CRM Jawwada</span>
                    <h1>Halo, Han 👋</h1>
                    <p>Kelola percakapan dan follow-up dari satu tempat.</p>
                </div>
                <div class="hero-glow"><i class="fab fa-whatsapp"></i></div>
            </section>

            <section class="section">
                <div class="section-heading"><h2>Ringkasan Hari Ini</h2><span>Hari ini</span></div>
                <div class="stats-grid">
                    <div class="stat-card"><span class="stat-icon blue"><i class="fa-regular fa-message"></i></span><strong>0</strong><small>Chat masuk</small></div>
                    <div class="stat-card"><span class="stat-icon green"><i class="fa-solid fa-user-plus"></i></span><strong>0</strong><small>Prospek</small></div>
                    <div class="stat-card"><span class="stat-icon amber"><i class="fa-regular fa-clock"></i></span><strong>0</strong><small>Follow-up</small></div>
                    <div class="stat-card"><span class="stat-icon red"><i class="fa-regular fa-bell"></i></span><strong>0</strong><small>Reminder</small></div>
                </div>
            </section>

            <section class="section">
                <div class="section-heading"><h2>Quick Action</h2></div>
                <div class="quick-grid">
                    <a href="?page=chat"><i class="fa-regular fa-paper-plane"></i><span>Follow Up</span></a>
                    <a href="?page=action"><i class="fa-solid fa-bullhorn"></i><span>Broadcast</span></a>
                    <a href="?page=reminder"><i class="fa-regular fa-calendar-plus"></i><span>Reminder</span></a>
                    <a href="?page=more"><i class="fa-solid fa-layer-group"></i><span>Semua Fitur</span></a>
                </div>
            </section>

            <section class="section empty-state">
                <i class="fa-regular fa-comments"></i>
                <strong>Aktivitas terbaru akan muncul di sini</strong>
                <p>Foundation CRM V2 sudah siap. Modul lama akan kita migrasikan satu per satu.</p>
            </section>

        <?php elseif ($page === 'chat'): ?>
            <section class="page-head"><span class="eyebrow">CRM</span><h1>Chat</h1><p>Inbox dan percakapan akan dibangun di modul ini.</p></section>
            <div class="placeholder"><i class="fa-regular fa-comments"></i><strong>Chat module</strong><span>Next: inbox, search, contact, conversation.</span></div>

        <?php elseif ($page === 'action'): ?>
            <section class="page-head"><span class="eyebrow">Quick Action</span><h1>Aksi</h1><p>Pusat pengiriman pesan dan aktivitas CRM.</p></section>
            <div class="action-list">
                <a href="#"><i class="fa-regular fa-paper-plane"></i><div><strong>Follow Up</strong><span>Kirim pesan personal</span></div><i class="fa-solid fa-chevron-right"></i></a>
                <a href="#"><i class="fa-solid fa-users"></i><div><strong>Pesan Grup</strong><span>Kirim ke grup WhatsApp</span></div><i class="fa-solid fa-chevron-right"></i></a>
                <a href="#"><i class="fa-solid fa-bullhorn"></i><div><strong>Promosi</strong><span>Broadcast promosi</span></div><i class="fa-solid fa-chevron-right"></i></a>
            </div>

        <?php elseif ($page === 'reminder'): ?>
            <section class="page-head"><span class="eyebrow">Automation</span><h1>Reminder</h1><p>Kelola pengingat dan follow-up otomatis.</p></section>
            <div class="placeholder"><i class="fa-regular fa-bell"></i><strong>Reminder module</strong><span>Next: today, upcoming, overdue, automation.</span></div>

        <?php else: ?>
            <section class="page-head"><span class="eyebrow">Workspace</span><h1>More</h1><p>Fitur CRM lainnya akan dikumpulkan di sini.</p></section>
            <div class="action-list">
                <a href="#"><i class="fa-regular fa-file-lines"></i><div><strong>Template Pesan</strong><span>Kelola template</span></div><i class="fa-solid fa-chevron-right"></i></a>
                <a href="#"><i class="fa-solid fa-robot"></i><div><strong>Auto Reply</strong><span>Balasan otomatis</span></div><i class="fa-solid fa-chevron-right"></i></a>
                <a href="#"><i class="fa-solid fa-chart-line"></i><div><strong>Analytics</strong><span>Statistik CRM</span></div><i class="fa-solid fa-chevron-right"></i></a>
                <a href="../logoutwa.php"><i class="fa-solid fa-right-from-bracket danger"></i><div><strong>Keluar</strong><span>Logout akun</span></div><i class="fa-solid fa-chevron-right"></i></a>
            </div>
        <?php endif; ?>
    </main>

    <nav class="bottom-nav" aria-label="Navigasi CRM">
        <?php foreach ($nav as $key => $item): ?>
            <?php $active = $page === $key; ?>
            <a href="?page=<?= htmlspecialchars($key) ?>" class="nav-item <?= $active ? 'active' : '' ?> <?= $key === 'action' ? 'action-item' : '' ?>">
                <?php if ($key === 'action'): ?>
                    <span class="action-button"><i class="fa-solid fa-plus"></i></span>
                    <span><?= $item['label'] ?></span>
                <?php else: ?>
                    <i class="fa-solid <?= $item['icon'] ?>"></i>
                    <span><?= $item['label'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
</body>
</html>
