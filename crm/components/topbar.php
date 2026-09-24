<?php
$crmTitle = $crmTitle ?? 'ReqraWA';
?>
<header class="topbar">
    <div class="brand">
        <a class="brand-link" href="index.php?page=home" aria-label="ReqraWA Home">
            <div class="brand-mark"><i class="fab fa-whatsapp"></i></div>
            <div>
                <div class="brand-name">Reqra<span>WA</span></div>
                <div class="brand-subtitle">CRM</div>
            </div>
        </a>
    </div>
    <div class="top-actions">
        <span class="page-title-desktop"><?= htmlspecialchars($crmTitle) ?></span>
        <button class="icon-btn" aria-label="Notifikasi"><i class="fa-regular fa-bell"></i></button>
        <div class="avatar">H</div>
    </div>
</header>
