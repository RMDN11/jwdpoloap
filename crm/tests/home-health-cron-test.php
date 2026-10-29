<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && ($_GET['token'] ?? '') !== 'jwd_auto_cron') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../lib/cron-health.php';
require_once dirname(__DIR__, 2) . '/config.php';

$checks = [];

// 1. Function existence
$checks['crmFormatAgo exists'] = function_exists('crmFormatAgo');
$checks['crmGetSystemHealthSummary exists'] = function_exists('crmGetSystemHealthSummary');

// 2. Format ago tests
$checks['crmFormatAgo zero'] = crmFormatAgo(0) === 'Belum tercatat';
$checks['crmFormatAgo recent'] = crmFormatAgo(time() - 20) === 'Baru saja';
$checks['crmFormatAgo minutes'] = strpos(crmFormatAgo(time() - 300), 'mnt lalu') !== false;
$checks['crmFormatAgo hours'] = strpos(crmFormatAgo(time() - 7200), 'jam lalu') !== false;

// 3. Home page integration
$homePage = file_get_contents(__DIR__ . '/../pages/home.php');
$checks['home requires cron-health.php'] = strpos($homePage, 'cron-health.php') !== false;
$checks['home renders home-health-section'] = strpos($homePage, 'home-health-section') !== false;
$checks['home renders Health Check Cron'] = strpos($homePage, 'Health Check Cron') !== false;
$checks['home renders home-health-grid'] = strpos($homePage, 'home-health-grid') !== false;

// 4. CSS check
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
$checks['css contains home-health-section'] = strpos($css, '.home-health-section') !== false;
$checks['css contains home-health-card'] = strpos($css, '.home-health-card') !== false;
$checks['css contains pulse-dot'] = strpos($css, '.pulse-dot') !== false;

// 5. Live DB check
$liveSummary = isset($conn) && ($conn instanceof mysqli) ? crmGetSystemHealthSummary($conn) : [];
$checks['live summary has 4 schedulers'] = count($liveSummary) === 4;

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));

if (!$isCli) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => empty($failed) ? 'success' : 'failed',
        'checks' => $checks,
        'live_summary' => $liveSummary,
    ], JSON_PRETTY_PRINT);
    exit;
}

foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
}
if ($failed) {
    exit(1);
}
