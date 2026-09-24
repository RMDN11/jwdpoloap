<?php
$home = file_get_contents(__DIR__ . '/../pages/home.php');
$index = file_get_contents(__DIR__ . '/../index.php');
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
$bootstrap = file_get_contents(__DIR__ . '/../config/bootstrap.php');

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
    'home dawn visual exists' => strpos($css, '.home-scene[data-period="dawn"]:before') !== false && strpos($css, '.home-scene[data-period="dawn"] .scene-sun') !== false,
    'home links activity to activity page' => strpos($home, '?page=activity') !== false,
    'router allows activity page' => strpos($index, "'activity'") !== false,
    'home hides global timebar' => strpos($index, "$page !== 'home'") !== false && strpos($index, "components/topbar.php") !== false,
    'home icon grid styles exist' => strpos($css, '.home-tools-grid') !== false,
    'home tools have compact centered styles' => strpos($css, '.home-tools-grid strong{font-size:10px') !== false && strpos($css, 'align-items:center') !== false,
    'home tools use rebuilt feature routes' => strpos($home, '?page=group') !== false && strpos($home, '?page=chat') !== false && strpos($home, '?page=reminder') !== false && strpos($home, '../manage_templates.php') !== false,
];

$nav = file_get_contents(__DIR__ . '/../components/bottom-nav.php');
$checks['action route removed'] = strpos($index, "'action'") === false;
$checks['bottom nav uses Group'] = strpos($nav, "'group' => ['label' => 'Grup'") !== false;
$checks['bottom nav uses Follow Up'] = strpos($nav, "'chat' => ['label' => 'Follow Up'") !== false;
$checks['bottom nav keeps Home centered'] = strpos($nav, "'home' => ['label' => 'Home'") !== false && strpos($nav, 'home-button') !== false;
$checks['bottom nav uses payment Reminder'] = strpos($nav, "'reminder' => ['label' => 'Reminder'") !== false;
$checks['action page removed'] = !file_exists(__DIR__ . '/../pages/action.php');
$checks['action create endpoint removed'] = !file_exists(__DIR__ . '/../actions/create-action.php');
$checks['bootstrap no longer creates Action table'] = strpos($bootstrap, 'CREATE TABLE IF NOT EXISTS crm_actions') === false;
$checks['home has Follow Up shortcut'] = strpos($home, '>Follow Up<') !== false;
$checks['home has Group shortcut'] = strpos($home, '?page=group') !== false;
$checks['home no longer links Action'] = strpos($home, '?page=action') === false;
$checks['reminder is payment workflow'] = strpos(file_get_contents(__DIR__ . '/../pages/reminder.php'), 'Reminder Pembayaran') !== false;
$checks['chat workspace is Follow Up'] = strpos(file_get_contents(__DIR__ . '/../pages/chat.php'), '$crmTitle = \'Follow Up\';') !== false;

$failed = array_keys(array_filter($checks, fn($ok) => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? "PASS" : "FAIL") . " - $name\n";
if ($failed) exit(1);
