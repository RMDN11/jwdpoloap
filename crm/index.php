<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$page = $_GET['page'] ?? 'home';
$allowedPages = ['home', 'chat', 'action', 'reminder', 'more'];
if (!in_array($page, $allowedPages, true)) $page = 'home';

$pageFile = __DIR__ . '/pages/' . $page . '.php';
$cssVersion = @filemtime(__DIR__ . '/assets/css/app.css') ?: '1';
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
    <link rel="stylesheet" href="assets/css/app.css?v=<?= urlencode((string)$cssVersion) ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/components/topbar.php'; ?>

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
</body>
</html>
