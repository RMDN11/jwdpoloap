<?php
$crmTitle = $crmTitle ?? 'ReqraWA';
?>
<header class="topbar" aria-label="Header CRM">
    <div class="brand">
        <a class="brand-link" href="index.php?page=home" aria-label="ReqraWA Home">
            <div class="brand-mark"><i class="fab fa-whatsapp"></i></div>
            <div class="brand-copy">
                <div class="brand-name">Reqra<span>WA</span></div>
                <div class="brand-subtitle">CRM</div>
            </div>
        </a>
    </div>

    <div class="topbar-page">
        <span class="topbar-page-kicker">Workspace</span>
        <strong><?= htmlspecialchars($crmTitle) ?></strong>
    </div>

    <div class="top-actions">
        <button class="icon-btn" type="button" aria-label="Notifikasi">
            <i class="fa-regular fa-bell"></i>
            <span class="top-notification-dot" aria-hidden="true"></span>
        </button>
        <div class="avatar" aria-label="Profil Han">H</div>
    </div>
</header>
