<?php
$home = file_get_contents(__DIR__ . '/../pages/home.php');
$index = file_get_contents(__DIR__ . '/../index.php');
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

$checks = [
    'home removes today summary' => strpos($home, 'Ringkasan Hari Ini') === false,
    'home has clock and calendar' => strpos($home, 'crmAnalogClock') !== false && strpos($home, 'crmCalendarGrid') !== false,
    'home removes hero date line' => strpos($home, 'id="crmHomeDate"') === false,
    'home has time scene' => strpos($home, 'id="crmHomeScene"') !== false && strpos($home, 'scene-moon') !== false && strpos($home, 'scene-sun') !== false,
    'home links activity to activity page' => strpos($home, '?page=activity') !== false,
    'router allows activity page' => strpos($index, "'activity'") !== false,
    'home hides global timebar' => strpos($index, "$page !== 'home'") !== false && strpos($index, "components/topbar.php") !== false,
    'home icon grid styles exist' => strpos($css, '.home-tools-grid') !== false,
    'home tools have compact centered styles' => strpos($css, '.home-tools-grid strong{font-size:10px') !== false && strpos($css, 'align-items:center') !== false,
    'home tools use original feature routes' => strpos($home, '../kirimgrup.php') !== false && strpos($home, '../pesan.php') !== false && strpos($home, '../manage_templates.php') !== false,
];

$failed = array_keys(array_filter($checks, fn($ok) => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? "PASS" : "FAIL") . " - $name\n";
if ($failed) exit(1);
