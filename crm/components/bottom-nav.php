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
        <a href="?page=<?= urlencode($key) ?>" class="nav-item <?= $active ? 'active' : '' ?> <?= $key === 'action' ? 'action-item' : '' ?>" aria-label="<?= htmlspecialchars($item['label']) ?>" title="<?= htmlspecialchars($item['label']) ?>">
            <?php if ($key === 'action'): ?>
                <span class="action-button" aria-hidden="true"><i class="fa-solid <?= $item['icon'] ?>"></i></span>
            <?php else: ?>
                <i class="fa-solid <?= $item['icon'] ?>" aria-hidden="true"></i>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
