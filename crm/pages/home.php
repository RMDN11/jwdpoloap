<?php
$crmTitle = 'Home';

$recent = [];
$result = $conn->query("SELECT id, nama, nowa, message, created_at FROM log_wa WHERE message IS NOT NULL AND message != '' ORDER BY id DESC LIMIT 6");
if ($result) {
    while ($row = $result->fetch_assoc()) $recent[] = $row;
}
?>
<section class="home-time-card">
    <div class="home-time-copy">
        <span class="eyebrow">Dashboard</span>
        <h1 id="crmHomeDay">Hari ini</h1>
        <p id="crmHomeDate">Memuat tanggal...</p>
        <div class="home-clock-row">
            <div class="home-analog-clock" id="crmAnalogClock" aria-label="Jam analog">
                <span class="clock-hand clock-hour"></span>
                <span class="clock-hand clock-minute"></span>
                <span class="clock-hand clock-second"></span>
                <span class="clock-center"></span>
            </div>
            <div>
                <span class="home-clock-label">Waktu sekarang</span>
                <strong id="crmDigitalClock">--:--</strong>
                <small>WIB · waktu lokalmu</small>
            </div>
        </div>
    </div>
    <div class="home-calendar-card">
        <div class="home-calendar-head">
            <div>
                <span class="home-calendar-kicker">Kalender</span>
                <strong id="crmCalendarMonth">Bulan ini</strong>
            </div>
            <i class="fa-regular fa-calendar"></i>
        </div>
        <div class="home-calendar-week">
            <span>Min</span><span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span>
        </div>
        <div class="home-calendar-grid" id="crmCalendarGrid"></div>
    </div>
</section>

<section class="section home-tools-section">
    <div class="section-heading home-section-heading">
        <h2>Tools</h2><a href="?page=action">Semua <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="home-tools-grid">
        <a href="?page=chat" aria-label="Chat"><span class="home-tool-icon green"><i class="fa-regular fa-comments"></i></span><strong>Chat</strong></a>
        <a href="?page=action" aria-label="Prospek"><span class="home-tool-icon blue"><i class="fa-solid fa-user-plus"></i></span><strong>Prospek</strong></a>
        <a href="../pesan.php" aria-label="Follow-up"><span class="home-tool-icon amber"><i class="fa-regular fa-paper-plane"></i></span><strong>Follow-up</strong></a>
        <a href="?page=reminder" aria-label="Reminder"><span class="home-tool-icon red"><i class="fa-regular fa-bell"></i></span><strong>Reminder</strong></a>
        <a href="../kirimgrup.php" aria-label="Pesan Grup"><span class="home-tool-icon purple"><i class="fa-solid fa-bullhorn"></i></span><strong>Grup</strong></a>
        <a href="../manage_templates.php" aria-label="Template"><span class="home-tool-icon teal"><i class="fa-regular fa-file-lines"></i></span><strong>Template</strong></a>
        <a href="../kelola_grup.php" aria-label="Kelola Grup"><span class="home-tool-icon slate"><i class="fa-solid fa-users"></i></span><strong>Kontak</strong></a>
        <a href="../grafik.php" aria-label="Analytics"><span class="home-tool-icon indigo"><i class="fa-solid fa-chart-simple"></i></span><strong>Data</strong></a>
    </div>
</section>

<section class="section">
    <div class="section-heading home-section-heading">
        <h2>Aktivitas</h2><a href="?page=activity">Semua <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="activity-list">
        <?php if (!$recent): ?>
            <div class="empty-state"><i class="fa-regular fa-comments"></i><strong>Belum ada aktivitas</strong><p>Data percakapan akan muncul di sini.</p></div>
        <?php else: foreach ($recent as $item): ?>
            <a href="?page=chat&contact=<?= urlencode((string)$item['nowa']) ?>" class="activity-item">
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

<script>
(() => {
    const dayEl = document.getElementById('crmHomeDay');
    const dateEl = document.getElementById('crmHomeDate');
    const digitalEl = document.getElementById('crmDigitalClock');
    const monthEl = document.getElementById('crmCalendarMonth');
    const gridEl = document.getElementById('crmCalendarGrid');
    const hourHand = document.querySelector('.clock-hour');
    const minuteHand = document.querySelector('.clock-minute');
    const secondHand = document.querySelector('.clock-second');
    const dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const monthNames = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

    function renderCalendar(now) {
        if (!gridEl || !monthEl) return;
        const year = now.getFullYear();
        const month = now.getMonth();
        monthEl.textContent = monthNames[month] + ' ' + year;
        const firstDay = new Date(year, month, 1).getDay();
        const totalDays = new Date(year, month + 1, 0).getDate();
        const prevDays = new Date(year, month, 0).getDate();
        const cells = [];
        for (let i = firstDay - 1; i >= 0; i--) cells.push({day: prevDays - i, muted: true});
        for (let day = 1; day <= totalDays; day++) cells.push({day, muted: false, today: day === now.getDate()});
        while (cells.length < 42) cells.push({day: cells.length - firstDay - totalDays + 1, muted: true});
        gridEl.innerHTML = cells.slice(0, 42).map(cell =>
            '<span class="' + (cell.muted ? 'muted ' : '') + (cell.today ? 'today' : '') + '">' + cell.day + '</span>'
        ).join('');
    }

    function tick() {
        const now = new Date();
        const h = now.getHours();
        const m = now.getMinutes();
        const s = now.getSeconds();
        if (dayEl) dayEl.textContent = dayNames[now.getDay()];
        if (dateEl) dateEl.textContent = now.getDate() + ' ' + monthNames[now.getMonth()] + ' ' + now.getFullYear();
        if (digitalEl) digitalEl.textContent = [h,m].map(v => String(v).padStart(2,'0')).join(':');
        if (hourHand) hourHand.style.transform = 'translateX(-50%) rotate(' + ((h % 12) * 30 + m * .5) + 'deg)';
        if (minuteHand) minuteHand.style.transform = 'translateX(-50%) rotate(' + (m * 6 + s * .1) + 'deg)';
        if (secondHand) secondHand.style.transform = 'translateX(-50%) rotate(' + (s * 6) + 'deg)';
        renderCalendar(now);
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
