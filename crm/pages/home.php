<?php
$crmTitle = 'Home';

$recent = [];
$result = $conn->query("SELECT id, nama, nowa, message, created_at FROM log_wa WHERE message IS NOT NULL AND message != '' ORDER BY id DESC LIMIT 6");
if ($result) {
    while ($row = $result->fetch_assoc()) $recent[] = $row;
}
?>
<section class="home-time-card">
    <div class="home-time-copy" id="crmHomeScene">
        <div class="home-scene" aria-hidden="true">
            <span class="scene-sun"></span>
            <span class="scene-moon"></span>
            <span class="scene-cloud scene-cloud-a"></span>
            <span class="scene-cloud scene-cloud-b"></span>
            <span class="scene-stars"></span>
        </div>
        <span class="eyebrow">Halo Han!</span>
        <h1 id="crmHomeDay">Hari ini</h1>
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
    <div class="section-heading">
        <h2>Tools</h2>
        <a href="?page=more">Semua <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="home-tools-grid">
        <a href="?page=chat"><span class="home-tool-icon green"><i class="fa-regular fa-comments"></i></span><strong>Chat</strong></a>
        <a href="?page=action"><span class="home-tool-icon blue"><i class="fa-solid fa-user-plus"></i></span><strong>Prospek</strong></a>
        <a href="../pesan.php"><span class="home-tool-icon amber"><i class="fa-regular fa-paper-plane"></i></span><strong>Follow-up</strong></a>
        <a href="?page=reminder"><span class="home-tool-icon red"><i class="fa-regular fa-bell"></i></span><strong>Reminder</strong></a>
        <a href="?page=group"><span class="home-tool-icon purple"><i class="fa-solid fa-users"></i></span><strong>Grup</strong></a>
        <a href="../promosi.php"><span class="home-tool-icon teal"><i class="fa-solid fa-bullhorn"></i></span><strong>Promosi</strong></a>
        <a href="../manage_templates.php"><span class="home-tool-icon slate"><i class="fa-regular fa-file-lines"></i></span><strong>Template</strong></a>
        <a href="../grafik.php"><span class="home-tool-icon indigo"><i class="fa-solid fa-chart-simple"></i></span><strong>Data</strong></a>
    </div>
</section>

<section class="section">
    <div class="section-heading"><h2>Aktivitas</h2><a href="?page=activity">Semua <i class="fa-solid fa-arrow-right"></i></a></div>
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
    const digitalEl = document.getElementById('crmDigitalClock');
    const monthEl = document.getElementById('crmCalendarMonth');
    const gridEl = document.getElementById('crmCalendarGrid');
    const sceneEl = document.getElementById('crmHomeScene');
    const hourHand = document.querySelector('.clock-hour');
    const minuteHand = document.querySelector('.clock-minute');
    const secondHand = document.querySelector('.clock-second');
    const dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const monthNames = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

    function getJakartaParts() {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Jakarta',
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            weekday: 'short',
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            hour12: false
        }).formatToParts(new Date());
        const value = {};
        parts.forEach(part => { if (part.type !== 'literal') value[part.type] = part.value; });
        const weekdayMap = {Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6};
        return {
            year: Number(value.year),
            month: Number(value.month) - 1,
            day: Number(value.day),
            weekday: weekdayMap[value.weekday] ?? 0,
            hour: Number(value.hour) % 24,
            minute: Number(value.minute),
            second: Number(value.second)
        };
    }

    function getTimePeriod(hour, minute) {
        const total = hour * 60 + minute;
        if (total >= 300 && total < 420) return 'dawn';
        if (total >= 420 && total < 1020) return 'day';
        if (total >= 1020 && total < 1080) return 'sunset';
        return 'night';
    }

    function renderCalendar(now) {
        if (!gridEl || !monthEl) return;
        const year = now.year;
        const month = now.month;
        monthEl.textContent = monthNames[month] + ' ' + year;
        const firstDay = new Date(year, month, 1).getDay();
        const totalDays = new Date(year, month + 1, 0).getDate();
        const prevDays = new Date(year, month, 0).getDate();
        const cells = [];
        for (let i = firstDay - 1; i >= 0; i--) cells.push({day: prevDays - i, muted: true});
        for (let day = 1; day <= totalDays; day++) cells.push({day, muted: false, today: day === now.day});
        while (cells.length < 42) cells.push({day: cells.length - firstDay - totalDays + 1, muted: true});
        gridEl.innerHTML = cells.slice(0, 42).map(cell =>
            '<span class="' + (cell.muted ? 'muted ' : '') + (cell.today ? 'today' : '') + '">' + cell.day + '</span>'
        ).join('');
    }

    function tick() {
        const now = getJakartaParts();
        if (dayEl) dayEl.textContent = dayNames[now.weekday];
        if (sceneEl) sceneEl.dataset.period = getTimePeriod(now.hour, now.minute);
        if (digitalEl) digitalEl.textContent = [now.hour, now.minute].map(v => String(v).padStart(2,'0')).join(':');
        if (hourHand) hourHand.style.transform = 'translateX(-50%) rotate(' + ((now.hour % 12) * 30 + now.minute * .5) + 'deg)';
        if (minuteHand) minuteHand.style.transform = 'translateX(-50%) rotate(' + (now.minute * 6 + now.second * .1) + 'deg)';
        if (secondHand) secondHand.style.transform = 'translateX(-50%) rotate(' + (now.second * 6) + 'deg)';
        renderCalendar(now);
    }

    tick();
    setInterval(tick, 1000);
})();
</script>
