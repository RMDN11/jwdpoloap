<?php
$currentPage = $page ?? 'home';
$nav = [
    'home' => ['label' => 'Home', 'icon' => 'fa-house'],
    'chat' => ['label' => 'Chat', 'icon' => 'fa-comments'],
    'action' => ['label' => 'Action', 'icon' => 'fa-plus'],
    'reminder' => ['label' => 'Reminder', 'icon' => 'fa-bell'],
    'more' => ['label' => 'More', 'icon' => 'fa-grid-2'],
];
?>
<nav class="bottom-nav" aria-label="Navigasi CRM">
    <?php foreach ($nav as $key => $item): ?>
        <?php $active = $currentPage === $key; ?>
        <a href="?page=<?= urlencode($key) ?>" class="nav-item <?= $active ? 'active' : '' ?> <?= $key === 'action' ? 'action-item' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
            <?php if ($key === 'action'): ?>
                <span class="action-button"><i class="fa-solid fa-plus"></i></span>
                <span><?= $item['label'] ?></span>
            <?php else: ?>
                <i class="fa-solid <?= $item['icon'] ?>"></i>
                <span><?= $item['label'] ?></span>
                <?php if ($key === 'chat'): ?><b class="nav-badge" id="crmChatBadge" hidden>0</b><?php endif; ?>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
