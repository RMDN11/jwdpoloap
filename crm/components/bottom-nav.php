<?php
$currentPage = $page ?? 'home';
$nav = [
    'group' => ['label' => 'Grup', 'icon' => 'fa-users'],
    'chat' => ['label' => 'Follow Up', 'icon' => 'fa-paper-plane'],
    'home' => ['label' => 'Home', 'icon' => 'fa-house'],
    'reminder' => ['label' => 'Reminder', 'icon' => 'fa-bell'],
    'more' => ['label' => 'More', 'icon' => 'fa-grid-2'],
];
?>
<nav class="bottom-nav" aria-label="Navigasi CRM">
    <?php foreach ($nav as $key => $item): ?>
        <?php $active = $currentPage === $key; ?>
        <a href="?page=<?= urlencode($key) ?>" class="nav-item <?= $active ? 'active' : '' ?> <?= $key === 'home' ? 'home-item' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
            <?php if ($key === 'home'): ?>
                <span class="home-button"><i class="fa-solid fa-house"></i></span>
                <span><?= $item['label'] ?></span>
            <?php else: ?>
                <i class="fa-solid <?= $item['icon'] ?>"></i>
                <span><?= $item['label'] ?></span>
                <?php if ($key === 'chat'): ?><b class="nav-badge" id="crmChatBadge" hidden>0</b><?php endif; ?>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
