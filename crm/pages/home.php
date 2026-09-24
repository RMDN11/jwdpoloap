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
<section class="hero-card">
    <div>
        <span class="eyebrow">CRM Jawwada</span>
        <h1>Halo, Han 👋</h1>
        <p>Kelola percakapan, prospek, follow-up, dan reminder dari satu workspace.</p>
    </div>
    <div class="hero-glow"><i class="fab fa-whatsapp"></i></div>
</section>

<section class="section">
    <div class="section-heading"><h2>Ringkasan Hari Ini</h2><span><?= date('d M Y') ?></span></div>
    <div class="stats-grid">
        <a class="stat-card" href="?page=chat"><span class="stat-icon blue"><i class="fa-regular fa-message"></i></span><strong><?= number_format($chatToday) ?></strong><small>Chat masuk</small></a>
        <a class="stat-card" href="?page=chat"><span class="stat-icon green"><i class="fa-solid fa-user-plus"></i></span><strong><?= number_format($prospects) ?></strong><small>Prospek tersimpan</small></a>
        <a class="stat-card" href="?page=chat"><span class="stat-icon amber"><i class="fa-regular fa-clock"></i></span><strong><?= number_format($followups) ?></strong><small>Follow-up hari ini</small></a>
        <a class="stat-card" href="?page=reminder"><span class="stat-icon red"><i class="fa-regular fa-bell"></i></span><strong><?= number_format($reminders) ?></strong><small>Reminder hari ini</small></a>
    </div>
</section>

<section class="section">
    <div class="section-heading"><h2>Quick Action</h2></div>
    <div class="quick-grid">
        <a href="?page=action"><i class="fa-regular fa-paper-plane"></i><span>Follow Up</span></a>
        <a href="?page=action"><i class="fa-solid fa-bullhorn"></i><span>Broadcast</span></a>
        <a href="?page=reminder"><i class="fa-regular fa-calendar-plus"></i><span>Reminder</span></a>
        <a href="?page=more"><i class="fa-solid fa-layer-group"></i><span>Semua Fitur</span></a>
    </div>
</section>

<section class="section">
    <div class="section-heading"><h2>Aktivitas Terbaru</h2><a href="?page=chat">Lihat semua</a></div>
    <div class="activity-list">
        <?php if (!$recent): ?>
            <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Belum ada aktivitas</strong><p>Data percakapan akan muncul di sini.</p></div>
        <?php else: foreach ($recent as $item): ?>
            <a href="?page=chat" class="activity-item">
                <span class="activity-avatar"><?= strtoupper(substr(trim($item['nama'] ?: 'K'), 0, 1)) ?></span>
                <span class="activity-body">
                    <strong><?= htmlspecialchars($item['nama'] ?: 'Tanpa nama') ?></strong>
                    <small><?= htmlspecialchars(mb_strimwidth(trim($item['message']), 0, 58, '…')) ?></small>
                </span>
                <time><?= htmlspecialchars(date('H:i', strtotime($item['created_at']))) ?></time>
            </a>
        <?php endforeach; endif; ?>
    </div>
</section>
