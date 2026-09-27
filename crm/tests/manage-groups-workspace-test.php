<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../index.php');
$page = file_get_contents(__DIR__ . '/../pages/manage-groups.php');
$core = file_get_contents(__DIR__ . '/../assets/css/workspace-core.css');
$css = file_get_contents(__DIR__ . '/../assets/css/manage-groups.css');
$more = file_get_contents(__DIR__ . '/../pages/more.php');

if ($index === false || $page === false || $core === false || $css === false || $more === false) {
    throw new RuntimeException('Cannot read Kelola Grup workspace files.');
}

foreach (['manage-groups', 'workspace-core.css', 'manage-groups.css'] as $required) {
    if (!str_contains($index, $required)) {
        throw new RuntimeException("Missing shared Kelola Grup integration: {$required}");
    }
}

foreach ([
    'crm-workspace-page',
    'crm-workspace-header',
    'crm-workspace-grid',
    'crm-workspace-card',
    'crm-workspace-header-main',
    'crm-workspace-stat',
    'crm-workspace-field',
    'group-manage-table',
    'groupManageSearch',
    'groupManageModal',
    'crmVerifyCsrf',
] as $required) {
    if (!str_contains($page, $required)) {
        throw new RuntimeException("Missing Kelola Grup workspace element: {$required}");
    }
}

if (str_contains($page, '<style>')) {
    throw new RuntimeException('Kelola Grup still contains inline style blocks.');
}

if (!str_contains($more, "index.php?page=manage-groups")) {
    throw new RuntimeException('More does not route to native Kelola Grup workspace.');
}

foreach (['max-width:1280px', '--crm-green:#168044', 'padding-bottom:104px', 'group-manage-stats', 'group-manage-table-wrap', '.group-manage-modal-card.crm-workspace-card'] as $required) {
    if (!str_contains($core, $required)) {
        throw new RuntimeException("Missing shared workspace core rule: {$required}");
    }
}

echo "CRM Manage Groups workspace test passed.\n";
