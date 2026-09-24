<?php
$home = file_get_contents(__DIR__ . '/../pages/home.php');
$index = file_get_contents(__DIR__ . '/../index.php');
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

$checks = [
    'home removes today summary' => strpos($home, 'Ringkasan Hari Ini') === false,
    'home has clock and calendar' => strpos($home, 'crmAnalogClock') !== false && strpos($home, 'crmCalendarGrid') !== false,
    'home removes hero date line' => strpos($home, 'id="crmHomeDate"') === false,
    'home has time scene' => strpos($home, 'id="crmHomeScene"') !== false && strpos($home, 'scene-moon') !== false && strpos($home, 'scene-sun') !== false,
    'home scene supports reduced motion' => strpos($css, '@media (prefers-reduced-motion: reduce)') !== false && strpos($css, '.home-scene .scene-sun') !== false,
    'home uses Jakarta timezone' => strpos($home, "timeZone: 'Asia/Jakarta'") !== false,
    'home has deterministic time periods' => strpos($home, "return 'sunset'") !== false && strpos($home, "return 'night'") !== false,
    'home applies time period to scene element' => strpos($home, "document.querySelector('#crmHomeScene .home-scene')") !== false,
    'home scene has sunset styles' => strpos($css, '.home-scene[data-period="sunset"]') !== false,
    'home night text contrast exists' => strpos($css, '.home-time-copy:has(.home-scene[data-period="night"])') !== false,
    'calendar label removed' => strpos($home, 'home-calendar-kicker') === false && strpos($home, '>Kalender<') === false,
    'mobile activity cards are width constrained' => strpos($css, 'max-width:100%') !== false && strpos($css, '.activity-item{') !== false && strpos($css, 'overflow:hidden') !== false,
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
