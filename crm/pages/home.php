<?php
$crmTitle = 'Home';

require_once __DIR__ . '/../config/prospect.php';

$homeAnalytics = [
    'valid' => 0,
    'observed' => 0,
    'rate' => 0,
    'top' => '-',
    'top_count' => 0,
    'quality' => 0,
    'daily' => array_fill(0, 6, 0),
];

$homeDisqualified = crmGetDisqualifiedNumbers($conn);
$homeBlocked = crmGetBlockedNumbers($conn);
$homeCategories = [];
foreach (crmGetProspectTriggers($conn) as $trigger) {
    $category = trim((string)($trigger['category'] ?? ''));
    if ($category !== '' && !in_array($category, $homeCategories, true)) $homeCategories[] = $category;
}
$homeCategoryTotals = array_fill_keys($homeCategories, 0);
$homeObserved = [];
$homeValid = [];

$homeFrom = date('Y-m-d', strtotime('-29 days'));
$homeTo = date('Y-m-d');
$homeStmt = $conn->prepare("SELECT id, nowa, message, created_at FROM log_wa WHERE created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY created_at ASC,id ASC");
if ($homeStmt) {
    $homeStmt->bind_param('ss', $homeFrom, $homeTo);
    $homeStmt->execute();
    $homeResult = $homeStmt->get_result();
    while ($homeRow = $homeResult->fetch_assoc()) {
        $raw = (string)($homeRow['nowa'] ?? '');
        $number = crmProspectNormalizeNumber($raw);
        if ($number === '' || str_contains($raw, '@g') || str_contains($raw, '-')) continue;
        $homeObserved[$number] = true;
        if (isset($homeValid[$number])) continue;
        if (!crmIsEligibleProspect($homeRow, $homeDisqualified, $homeBlocked, $conn)) continue;

        $homeValid[$number] = true;
        $classification = crmProspectClassifyMessage((string)$homeRow['message'], $conn);
        $homeCategoryTotals[$classification] = (int)($homeCategoryTotals[$classification] ?? 0) + 1;

        $created = strtotime((string)$homeRow['created_at']);
        $daysAgo = (int)floor((strtotime($homeTo) - strtotime(date('Y-m-d', $created))) / 86400);
        if ($daysAgo >= 0 && $daysAgo < 6) $homeAnalytics['daily'][5 - $daysAgo]++;
    }
    $homeStmt->close();
}

$homeAnalytics['valid'] = count($homeValid);
$homeAnalytics['observed'] = count($homeObserved);
$homeAnalytics['quality'] = max(0, $homeAnalytics['observed'] - $homeAnalytics['valid']);
$homeAnalytics['rate'] = $homeAnalytics['observed'] > 0 ? ($homeAnalytics['valid'] / $homeAnalytics['observed']) * 100 : 0;
arsort($homeCategoryTotals);
foreach ($homeCategoryTotals as $category => $count) {
    if ($count > 0) {
        $homeAnalytics['top'] = $category;
        $homeAnalytics['top_count'] = $count;
        break;
    }
}
$homeDailyMax = max(1, ...$homeAnalytics['daily']);

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
                <strong id="crmDigitalClock">--:--</strong>
                <small>WIB</small>
            </div>
        </div>
    </div>
    <div class="home-calendar-card">
        <div class="home-calendar-head">
            <div>
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

<section class="section home-analytics-section">
    <div class="section-heading home-analytics-heading">
        <div>
            <span class="home-analytics-kicker"><i class="fa-solid fa-sparkles"></i> Data Intelligence</span>
            <h2>Analytics</h2>
        </div>
        <a href="?page=analytics">Buka Analytics <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
    </div>
    <div class="home-analytics-bento">
        <a href="?page=analytics" class="home-analytics-feature">
            <div class="home-analytics-feature-top">
                <span class="home-analytics-icon"><i class="fa-solid fa-chart-line"></i></span>
                <span class="home-analytics-live"><span></span> 30 Hari Terakhir</span>
            </div>
            <div class="home-analytics-feature-copy">
                <strong><?=number_format($homeAnalytics['valid'])?> lead valid.</strong>
                <p><?=number_format($homeAnalytics['observed'])?> kontak unik terobservasi dalam 30 hari. <?=number_format($homeAnalytics['rate'], 1)?>% lolos kriteria prospek.</p>
            </div>
            <div class="home-analytics-chart" aria-hidden="true">
                <?php foreach ($homeAnalytics['daily'] as $dailyCount): ?><span class="bar" style="height:<?=max(8, round(($dailyCount / $homeDailyMax) * 100))?>%" title="<?=number_format($dailyCount)?> lead"></span><?php endforeach; ?>
            </div>
            <div class="home-analytics-feature-foot"><span>6 hari terakhir • <?=number_format(array_sum($homeAnalytics['daily']))?> lead</span><i class="fa-solid fa-arrow-right"></i></div>
        </a>
        <a href="?page=analytics" class="home-analytics-tile tile-lead"><span class="home-analytics-tile-icon"><i class="fa-solid fa-user-check"></i></span><div><strong><?=number_format($homeAnalytics['valid'])?> Lead Valid</strong><small>30 hari terakhir</small></div><i class="fa-solid fa-arrow-up-right"></i></a>
        <a href="?page=analytics" class="home-analytics-tile tile-quality"><span class="home-analytics-tile-icon"><i class="fa-solid fa-shield-halved"></i></span><div><strong><?=number_format($homeAnalytics['quality'])?> Tidak Masuk</strong><small><?=number_format($homeAnalytics['rate'], 1)?>% data lolos kriteria</small></div><i class="fa-solid fa-arrow-up-right"></i></a>
        <a href="?page=analytics" class="home-analytics-tile tile-interest"><span class="home-analytics-tile-icon"><i class="fa-solid fa-layer-group"></i></span><div><strong><?=htmlspecialchars($homeAnalytics['top'])?></strong><small><?=number_format($homeAnalytics['top_count'])?> lead terklasifikasi</small></div><i class="fa-solid fa-arrow-up-right"></i></a>
        <a href="?page=analytics" class="home-analytics-tile tile-trend"><span class="home-analytics-tile-icon"><i class="fa-solid fa-arrow-trend-up"></i></span><div><strong><?=number_format(array_sum($homeAnalytics['daily']))?> Lead</strong><small>6 hari terakhir</small></div><i class="fa-solid fa-arrow-up-right"></i></a>
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
        parts.forEach(part => {
            if (part.type !== 'literal') value[part.type] = part.value;
        });
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
        if (total >= 420 && total < 900) return 'day';
        if (total >= 900 && total < 1050) return 'afternoon';
        if (total >= 1050 && total < 1110) return 'sunset';
        return 'night';
    }

    function renderCalendar(now) {
        if (!gridEl || !monthEl) return;
        const year = now.year;
        const month = now.month;
        monthEl.textContent = monthNames[month] + ' ' + year;
        const firstDay = new Date(Date.UTC(year, month, 1)).getUTCDay();
        const totalDays = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
        const prevDays = new Date(Date.UTC(year, month, 0)).getUTCDate();
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
        const sceneEl = document.querySelector('#crmHomeScene .home-scene');
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
