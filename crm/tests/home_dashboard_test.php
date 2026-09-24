<?php
$home = file_get_contents(__DIR__ . '/home.php');
$index = file_get_contents(__DIR__ . '/../index.php');
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

$checks = [
    'home removes today summary' => strpos($home, 'Ringkasan Hari Ini') === false,
    'home has clock and calendar' => strpos($home, 'crm-clock') !== false && strpos($home, 'crm-calendar') !== false,
    'home links activity to activity page' => strpos($home, '?page=activity') !== false,
    'router allows activity page' => strpos($index, "'activity'") !== false,
    'home icon grid styles exist' => strpos($css, '.home-tools-grid') !== false,
];

$failed = array_keys(array_filter($checks, fn($ok) => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? "PASS" : "FAIL") . " - $name\n";
if ($failed) exit(1);
