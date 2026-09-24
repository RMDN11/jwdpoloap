<?php
$crmTitle = 'Home';

$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

$chatToday = crmCount($conn, "SELECT COUNT(*) total FROM log_wa WHERE created_at BETWEEN '$todayStart' AND '$todayEnd' AND message IS NOT NULL AND message != '' AND message != 'Data CSV/Manual'");
$prospects = crmCount($conn, "SELECT COUNT(*) total FROM log_wa WHERE message != 'Data CSV/Manual' AND message != ''");
$followups = crmCount($conn, "SELECT COUNT(*) total FROM log_wa WHERE last_followup_at BETWEEN '$todayStart' AND '$todayEnd'");
$reminders = crmCount($conn, "SELECT COUNT(*) total FROM reminder_requests WHERE DATE(created_at) = CURDATE()");

$recent = [];
$result = $conn->query("SELECT id, nama, nowa, message, created_at FROM log_wa WHERE message IS NOT NULL AND message != '' ORDER BY id DESC LIMIT 6");
if ($result) {
    while ($row = $result->fetch_assoc()) $recent[] = $row;
}
?>
<section class="home-hero">
    <div class="home-hero-copy">
        <div class="home-hero-top">
            <span class="eyebrow">CRM Jawwada</span>
            <span class="live-pill"><i class="fa-solid fa-circle"></i> Aktif</span>
        </div>
        <h1>Halo, Han <span>👋</span></h1>
        <p>Semua percakapan dan follow-up penting, diringkas di satu tempat.</p>
        <a class="hero-cta" href="?page=chat">
            <span>Buka inbox</span>
            <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>
    <div class="home-hero-orb">
        <i class="fab fa-whatsapp"></i>
        <span></span>
    </div>
</section>

<section class="home-section">
    <div class="section-heading">
        <div>
            <span class="section-kicker">Overview</span>
            <h2>Hari ini</h2>
        </div>
        <span class="section-date"><?= date('d M Y') ?></span>
    </div>

    <div class="home-stats">
        <a class="home-stat primary" href="?page=chat">
            <span class="home-stat-icon"><i class="fa-regular fa-message"></i></span>
            <strong><?= number_format($chatToday) ?></strong>
            <small>Chat masuk</small>
        </a>
        <a class="home-stat" href="?page=chat">
            <span class="home-stat-icon"><i class="fa-solid fa-user-plus"></i></span>
            <strong><?= number_format($prospects) ?></strong>
            <small>Prospek tersimpan</small>
        </a>
        <a class="home-stat" href="?page=chat">
            <span class="home-stat-icon"><i class="fa-regular fa-clock"></i></span>
            <strong><?= number_format($followups) ?></strong>
            <small>Follow-up hari ini</small>
        </a>
        <a class="home-stat" href="?page=reminder">
            <span class="home-stat-icon"><i class="fa-regular fa-bell"></i></span>
            <strong><?= number_format($reminders) ?></strong>
            <small>Reminder hari ini</small>
        </a>
    </div>
</section>

<section class="home-section">
    <div class="section-heading">
        <div>
            <span class="section-kicker">Shortcut</span>
            <h2>Aksi cepat</h2>
        </div>
    </div>

    <div class="home-actions">
        <a href="?page=action" class="home-action home-action-main">
            <span class="home-action-icon"><i class="fa-regular fa-paper-plane"></i></span>
            <span class="home-action-copy"><strong>Follow Up</strong><small>Hubungi prospek</small></span>
            <i class="fa-solid fa-arrow-up-right-from-square home-action-arrow"></i>
        </a>
        <a href="?page=action" class="home-action">
            <span class="home-action-icon"><i class="fa-solid fa-bullhorn"></i></span>
            <span class="home-action-copy"><strong>Broadcast</strong><small>Kirim ke grup</small></span>
        </a>
        <a href="?page=reminder" class="home-action">
            <span class="home-action-icon"><i class="fa-regular fa-calendar-plus"></i></span>
            <span class="home-action-copy"><strong>Reminder</strong><small>Atur pengingat</small></span>
        </a>
        <a href="?page=more" class="home-action">
            <span class="home-action-icon"><i class="fa-solid fa-layer-group"></i></span>
            <span class="home-action-copy"><strong>Semua fitur</strong><small>Kelola workspace</small></span>
        </a>
    </div>
</section>

<section class="home-section home-activity-section">
    <div class="section-heading">
        <div>
            <span class="section-kicker">Live feed</span>
            <h2>Aktivitas terbaru</h2>
        </div>
        <a href="?page=chat">Lihat semua <i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="home-activity-list">
        <?php if (!$recent): ?>
            <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Belum ada aktivitas</strong><p>Data percakapan akan muncul di sini.</p></div>
        <?php else: foreach ($recent as $item): ?>
            <a href="?page=chat" class="home-activity-item">
                <span class="home-activity-avatar"><?= htmlspecialchars(strtoupper(mb_substr(trim($item['nama'] ?: 'K'), 0, 1))) ?></span>
                <span class="home-activity-body">
                    <strong><?= htmlspecialchars($item['nama'] ?: 'Tanpa nama') ?></strong>
                    <small><?= htmlspecialchars(mb_strimwidth(trim($item['message']), 0, 64, '…')) ?></small>
                </span>
                <span class="home-activity-meta">
                    <time><?= htmlspecialchars(date('H:i', strtotime($item['created_at']))) ?></time>
                    <i class="fa-solid fa-chevron-right"></i>
                </span>
            </a>
        <?php endforeach; endif; ?>
    </div>
</section>
