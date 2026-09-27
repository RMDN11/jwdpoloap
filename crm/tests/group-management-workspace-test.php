<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$index = file_get_contents($root . '/crm/index.php');
$page = file_get_contents($root . '/crm/pages/manage-groups.php');
$more = file_get_contents($root . '/crm/pages/more.php');
$group = file_get_contents($root . '/crm/pages/group.php');

$assertions = [
    'manage-groups route' => str_contains($index, "'manage-groups'"),
    'native management page' => str_contains($page, 'Kelola Grup'),
    'mass import action' => str_contains($page, 'manage_group_action') && str_contains($page, "value="mass_add""),
    'edit action' => str_contains($page, "value="edit""),
    'delete action' => str_contains($page, "value="delete""),
    'csrf protection' => str_contains($page, 'crmVerifyCsrf') && str_contains($page, 'crmCsrfToken'),
    'group search' => str_contains($page, 'groupManageSearch'),
    'edit modal' => str_contains($page, 'groupManageModal'),
    'shared CRM cards' => str_contains($page, 'group-card') && str_contains($page, 'group-card-head') && str_contains($page, 'group-kicker'),
    'desktop compact grid' => str_contains($page, 'minmax(280px,350px)') && str_contains($page, 'minmax(0,1fr)'),
    'More native link' => str_contains($more, 'index.php?page=manage-groups'),
    'Group native link' => str_contains($group, 'index.php?page=manage-groups'),
];

foreach ($assertions as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAILED: {$label}\n");
        exit(1);
    }
}

echo "CRM Group Management workspace test passed.\n";
