<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../index.php');
$page = file_get_contents(__DIR__ . '/../pages/auto-reply.php');
$more = file_get_contents(__DIR__ . '/../pages/more.php');
$css = file_get_contents(__DIR__ . '/../assets/css/auto-reply.css');
$core = file_get_contents(__DIR__ . '/../assets/css/workspace-core.css');

if ($index === false || $page === false || $more === false || $css === false || $core === false) {
    throw new RuntimeException('Cannot read CRM auto reply workspace files.');
}

foreach (["'auto-reply'", "'templates'"] as $required) {
    if (!str_contains($index, $required)) {
        throw new RuntimeException("Missing CRM route: {$required}");
    }
}

foreach ([
    'Auto Reply',
    'crm-workspace-page',
    'crm-workspace-grid',
    'crm-workspace-card',
    'crm-workspace-header-main',
    'crm-workspace-stat',
    'crm-workspace-field',
    'crm-workspace-actions',
    'crm-workspace-kicker',
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
    'assets/css/workspace-core.css',
    'index.php?page=more',
] as $required) {
    if (!str_contains($index . $page, $required)) {
        throw new RuntimeException("Missing Auto Reply shared workspace integration: {$required}");
    }
}

foreach (['max-width:1280px', '--crm-green:#168044', 'padding-bottom:104px', 'grid-template-columns:minmax(280px,.85fr) minmax(360px,1.15fr)', 'crm-workspace-field input', 'auto-reply-modal-card'] as $required) {
    if (!str_contains($core, $required)) {
        throw new RuntimeException("Missing shared workspace core rule: {$required}");
    }
}

if (str_contains($page, '<style>')) {
    throw new RuntimeException('Auto Reply still contains inline style blocks.');
}

echo "CRM Auto Reply workspace test passed.\n";
