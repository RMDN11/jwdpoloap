<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$page = $_GET['page'] ?? 'home';
$allowedPages = ['home', 'chat', 'reminder', 'more', 'activity', 'group'];
if (!in_array($page, $allowedPages, true)) $page = 'home';

$pageFile = __DIR__ . '/pages/' . $page . '.php';
$crmMaxLogId = 0;
$crmMaxLogResult = $conn->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM log_wa");
if ($crmMaxLogResult && ($crmMaxLogRow = $crmMaxLogResult->fetch_assoc())) {
    $crmMaxLogId = (int)$crmMaxLogRow['max_id'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#166534">
    <meta name="description" content="ReqraWA CRM">
    <title>ReqraWA CRM</title>
    <link rel="icon" type="image/png" href="assets/logowa.png">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
    <?php if ($page === 'group'): ?>
        <link rel="stylesheet" href="assets/css/group-mobile.css?v=<?= filemtime(__DIR__ . '/assets/css/group-mobile.css') ?>">
    <?php endif; ?>
    <?php if ($page === 'reminder'): ?>
        <link rel="stylesheet" href="assets/css/reminder.css?v=<?= filemtime(__DIR__ . '/assets/css/reminder.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>
<div class="app-shell">
    <?php if ($page !== 'home'): ?>
        <?php require __DIR__ . '/components/topbar.php'; ?>
    <?php endif; ?>

    <main class="content">
        <?php if (!empty($_SESSION['crm_flash'])): $flash = $_SESSION['crm_flash']; unset($_SESSION['crm_flash']); ?>
            <div class="crm-flash <?= htmlspecialchars($flash['type'] ?? 'success') ?>">
                <i class="fa-solid <?= ($flash['type'] ?? '') === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
                <span><?= htmlspecialchars($flash['message'] ?? '') ?></span>
            </div>
        <?php endif; ?>
        <?php require $pageFile; ?>
    </main>

    <?php require __DIR__ . '/components/bottom-nav.php'; ?>
</div>
<script>
(() => {
    let lastLogId = <?= $crmMaxLogId ?>;
    let pollBusy = false;

    function updateChatBadge(count) {
        const badge = document.getElementById('crmChatBadge');
        if (!badge) return;
        badge.textContent = String(count);
        badge.hidden = count <= 0;
    }

    function showChatToast(count) {
        const old = document.getElementById('crm-global-chat-toast');
        if (old) old.remove();

        updateChatBadge(count);

        const el = document.createElement('button');
        el.id = 'crm-global-chat-toast';
        el.type = 'button';
        el.className = 'crm-new-chat-toast';
        el.innerHTML = '<i class="fa-solid fa-bell"></i><span>' + count + ' follow-up baru masuk. Buka Follow Up</span>';
        el.onclick = () => { window.location.href = '?page=chat&status=new'; };
        document.body.appendChild(el);

        window.setTimeout(() => {
            if (el.isConnected) el.remove();
        }, 9000);
    }

    function pollChat() {
        if (!lastLogId || pollBusy) return;
        pollBusy = true;

        fetch('pages/chat-poll.php?last_id=' + encodeURIComponent(lastLogId), {
            headers: {'X-Requested-With':'XMLHttpRequest'},
            cache: 'no-store'
        })
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') return;
            const nextId = Number(data.max_id || 0);
            if (nextId > lastLogId) lastLogId = nextId;
            if (Number(data.new_count || 0) > 0) showChatToast(Number(data.new_count));
        })
        .catch(() => {})
        .finally(() => { pollBusy = false; });
    }

    window.setInterval(pollChat, 10000);
})();
</script>

<script>
(() => {
    const dayEl = document.getElementById('crmGlobalDay');
    const dateEl = document.getElementById('crmGlobalDate');
    const timeEl = document.getElementById('crmGlobalTime');
    const hourHand = document.querySelector('.crm-mini-hour');
    const minuteHand = document.querySelector('.crm-mini-minute');
    const secondHand = document.querySelector('.crm-mini-second');
    const days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

    function tickGlobalClock() {
        const now = new Date();
        const h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
        if (dayEl) dayEl.textContent = days[now.getDay()];
        if (dateEl) dateEl.textContent = now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
        if (timeEl) timeEl.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0');
        if (hourHand) hourHand.style.transform = 'translateX(-50%) rotate(' + ((h % 12) * 30 + m * .5) + 'deg)';
        if (minuteHand) minuteHand.style.transform = 'translateX(-50%) rotate(' + (m * 6 + s * .1) + 'deg)';
        if (secondHand) secondHand.style.transform = 'translateX(-50%) rotate(' + (s * 6) + 'deg)';
    }
    tickGlobalClock();
    window.setInterval(tickGlobalClock, 1000);
})();
</script>

</body>
</html>
