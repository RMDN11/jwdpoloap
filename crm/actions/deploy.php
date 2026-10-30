<?php
declare(strict_types=1);

// Endpoint deploy otomatis untuk Antigravity
// Akses aman menggunakan secret token:
// https://app.reqra.my.id/jwdpoloap/crm/actions/deploy.php?token=jwd_auto_deploy_2026

$expectedToken = 'jwd_auto_deploy_2026';
$providedToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    exit('Akses deploy ditolak. Token tidak valid.');
}

header('Content-Type: text/plain; charset=utf-8');

if (function_exists('opcache_reset')) {
    $res = opcache_reset();
    echo "OPcache reset: " . ($res ? "SUCCESS\n" : "FAILED\n");
} else {
    echo "OPcache reset: function not available\n";
}

$repoDir = dirname(__DIR__, 2);
chdir($repoDir);

if (!function_exists('exec')) {
    echo "ERROR: Fungsi exec() dinonaktifkan di server PHP ini.\n";
    echo "Gunakan cPanel API Token atau tombol Version Control untuk deploy.\n";
    exit;
}

$output = [];
$returnVar = 0;
exec('git pull origin main 2>&1', $output, $returnVar);

$statusStr = ($returnVar === 0) ? 'SUCCESS' : "FAILED (code {$returnVar})";
echo "=== DEPLOY {$statusStr} ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo implode("\n", $output) . "\n";
