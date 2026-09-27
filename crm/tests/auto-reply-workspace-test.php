<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../index.php');
$page = file_get_contents(__DIR__ . '/../pages/auto-reply.php');
$more = file_get_contents(__DIR__ . '/../pages/more.php');

if ($index === false || $page === false || $more === false) {
    throw new RuntimeException('Cannot read CRM auto reply workspace files.');
}

foreach (["'auto-reply'", "'templates'"] as $required) {
    if (!str_contains($index, $required)) {
        throw new RuntimeException("Missing CRM route: {$required}");
    }
}

foreach ([
    'Auto Reply',
    'page-head',
    'group-card',
    'group-kicker',
    'Tambah Rule',
    'autoReplyForm',
    'auto-reply-test',
    'auto-reply-edit',
    'auto_reply_rules',
    'AutoReplyEngine',
    'crmVerifyCsrf',
    'components/topbar.php',
    'components/bottom-nav.php',
] as $required) {
    if (!str_contains($page . $index, $required)) {
        throw new RuntimeException("Missing auto reply workspace element: {$required}");
    }
}

foreach ([
    "'href' => 'index.php?page=templates'",
    "'href' => 'index.php?page=auto-reply'",
] as $required) {
    if (!str_contains($more, $required)) {
        throw new RuntimeException("More workspace route is missing: {$required}");
    }
}

echo "CRM Auto Reply workspace test passed.\n";
