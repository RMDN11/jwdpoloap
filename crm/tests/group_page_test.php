<?php
$index = file_get_contents(__DIR__ . '/../index.php');
$home = file_get_contents(__DIR__ . '/../pages/home.php');
$group = file_get_contents(__DIR__ . '/../pages/group.php');
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

$checks = [
    'router allows group page' => strpos($index, "'group'") !== false,
    'group page has composer' => strpos($group, 'crmGroupForm') !== false && strpos($group, 'crmGroupMessage') !== false,
    'group page has target selector' => strpos($group, 'crm-group-target') !== false,
    'group page uses legacy sending engine endpoint' => strpos($group, "../kirimgrup.php") !== false && strpos($group, 'ajax_kirim_grup') !== false,
    'group page has scheduling modes' => strpos($group, 'groupSchedule') !== false && strpos($group, 'harian') !== false,
    'group page has preview' => strpos($group, 'crmGroupPreview') !== false,
    'group page has compact styles' => strpos($css, '.group-compose-grid') !== false && strpos($css, '.group-phone') !== false,
    'home routes group tool to CRM' => strpos($home, '?page=group') !== false,
];

$failed = array_keys(array_filter($checks, fn($ok) => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? "PASS" : "FAIL") . " - $name\n";
if ($failed) exit(1);
