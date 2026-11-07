<?php
declare(strict_types=1);

/**
 * Test Refresh Persistence for page=plotting and page=reminder-retention.
 */

if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}

$baseDir = dirname(__DIR__, 2);
require_once __DIR__ . '/../lib/plotting.php';
require_once __DIR__ . '/../lib/reminder-csv.php';
require_once __DIR__ . '/../lib/reminder-retention.php';

// Mock dummy mysqli if not connected
if (!isset($conn) || !($conn instanceof mysqli)) {
    class DummyRefreshConn extends mysqli {
        public function __construct() {}
        public function query($sql, $resultmode = MYSQLI_STORE_RESULT): mixed { return false; }
        public function real_escape_string(string $string): string { return addslashes($string); }
    }
    $conn = new DummyRefreshConn();
}

// -------------------------------------------------------------
// 1. PLOTTING PERSISTENCE TEST
// -------------------------------------------------------------
$_SESSION = [];
$_GET = ['page' => 'plotting', 'csv_a' => 'csv:56:1.csv', 'csv_b' => 'csv:56:1.csv'];

ob_start();
require __DIR__ . '/../pages/plotting.php';
$html1 = ob_get_clean();

assert($_SESSION['crm_plotting_source_a'] === 'csv:56:1.csv', 'Plotting should save source_a to session');
assert($_SESSION['crm_plotting_source_b'] === 'csv:56:1.csv', 'Plotting should save source_b to session');
assert(str_contains($html1, 'crm-btn-refresh'), 'Plotting should render refresh button in header');
assert(str_contains($html1, 'replaceState'), 'Plotting should synchronize URL via replaceState');

// Simulate page refresh WITHOUT GET parameters: should restore from session
$_GET = ['page' => 'plotting'];
ob_start();
require __DIR__ . '/../pages/plotting.php';
$html2 = ob_get_clean();

assert(str_contains($html2, 'Hasil Perbandingan'), 'Plotting should restore comparison from session on refresh without GET params');
assert(str_contains($html2, 'Abdur Rahim (IK 4)'), 'Plotting should render data restored from session');
assert(str_contains($html2, 'crm-btn-refresh'), 'Plotting refreshed page should have refresh button');

echo "PASS - plotting persists selection across refresh via session and replaceState\n";

// -------------------------------------------------------------
// 2. REMINDER RETENTION PERSISTENCE TEST
// -------------------------------------------------------------
$_SESSION = [];
$_GET = ['page' => 'reminder-retention', 'previous_source' => 'csv:56:1.csv', 'current_source' => 'csv:56:1.csv', 'analyze' => '1'];

// Mock templates
$templates = [
    ['id' => 1, 'title' => 'Default', 'content' => 'Halo {nama}'],
];

ob_start();
require __DIR__ . '/../pages/reminder-retention.php';
$htmlRet1 = ob_get_clean();

assert($_SESSION['crm_retention_previous_source'] === 'csv:56:1.csv', 'Retention should save previous_source to session');
assert($_SESSION['crm_retention_current_source'] === 'csv:56:1.csv', 'Retention should save current_source to session');
assert($_SESSION['crm_retention_analyze'] === '1', 'Retention should save analyze=1 to session');
assert(str_contains($htmlRet1, 'crm-btn-refresh'), 'Retention should render refresh button');
assert(str_contains($htmlRet1, 'targetStorageKey'), 'Retention should have targetStorageKey script for checkbox persistence');
assert(str_contains($htmlRet1, 'replaceState'), 'Retention should synchronize URL via replaceState');

// Simulate page refresh WITHOUT GET parameters: should restore from session and auto-analyze
$_GET = ['page' => 'reminder-retention'];
ob_start();
require __DIR__ . '/../pages/reminder-retention.php';
$htmlRet2 = ob_get_clean();

assert(str_contains($htmlRet2, 'crm-btn-refresh'), 'Refreshed retention page should have refresh button');
assert(str_contains($htmlRet2, 'Analisis aktif') || str_contains($htmlRet2, 'Retention Rate'), 'Refreshed retention page should restore analysis from session');
assert(str_contains($htmlRet2, '56:1.csv'), 'Refreshed retention page should keep 56:1.csv selected');

echo "PASS - reminder-retention persists selection across refresh via session, replaceState, and targetStorageKey\n";

echo "ALL REFRESH PERSISTENCE TESTS PASSED!\n";
