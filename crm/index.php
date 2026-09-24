<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$page = $_GET['page'] ?? 'home';
$allowedPages = ['home', 'chat', 'action', 'reminder', 'more'];
if (!in_array($page, $allowedPages, true)) $page = 'home';

$pageFile = __DIR__ . '/pages/' . $page . '.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#166534">
    <meta name="description" content="ReqraWA CRM">
    <title>ReqraWA CRM</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/components/topbar.php'; ?>

    <main class="content">
        <?php require $pageFile; ?>
    </main>

    <?php require __DIR__ . '/components/bottom-nav.php'; ?>
</div>
</body>
</html>
