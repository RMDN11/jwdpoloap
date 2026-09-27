<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../index.php');
$page = file_get_contents(__DIR__ . '/../pages/auto-reply.php');
$more = file_get_contents(__DIR__ . '/../pages/more.php');
$css = file_get_contents(__DIR__ . '/../assets/css/auto-reply.css');

if ($index === false || $page === false || $more === false || $css === false) {
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

foreach ([
    'assets/css/auto-reply.css',
    'auto-reply-back',
    'index.php?page=more',
] as $required) {
    if (!str_contains($index . $page, $required)) {
        throw new RuntimeException("Missing Auto Reply polish element: {$required}");
    }
}

if (str_contains($page, '<style>')) {
    throw new RuntimeException('Auto Reply still contains inline style blocks.');
}

if (!str_contains($css, 'padding-bottom:104px')) {
    throw new RuntimeException('Desktop scroll clearance is missing.');
}


$autoCss = file_get_contents(__DIR__ . '/../assets/css/auto-reply.css');
if ($autoCss === false) {
    throw new RuntimeException('Cannot read Auto Reply stylesheet.');
}

foreach ([
    'auto-reply-peserta-head',
    'body:has(.auto-reply-peserta-head) .content',
    '.auto-reply-list{',
    'max-height:620px',
] as $required) {
    if (!str_contains($autoCss, $required)) {
        throw new RuntimeException("Missing Auto Reply Reminder-style element: {$required}");
    }
}

echo "CRM Auto Reply workspace test passed.\n";
