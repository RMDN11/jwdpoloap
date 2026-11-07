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

$hasExec = function_exists('exec');

if ($hasExec) {
    $output = [];
    $returnVar = 0;
    @exec('git pull origin main 2>&1', $output, $returnVar);

    if ($returnVar === 0) {
        echo "=== DEPLOY SUCCESS (via git CLI) ===\n";
        echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        echo implode("\n", $output) . "\n";
        exit;
    }
    echo "Git CLI execution returned code {$returnVar}. Mencoba fallback ke GitHub File Sync...\n";
} else {
    echo "Fungsi exec() dinonaktifkan. Menggunakan mode fallback GitHub File Sync...\n";
}

// Fallback: GitHub API Sync
if (!extension_loaded('curl')) {
    echo "ERROR: Ekstensi cURL tidak tersedia untuk fallback deployment.\n";
    echo "Gunakan tombol Version Control di cPanel untuk deploy.\n";
    exit;
}

$ch = curl_init('https://api.github.com/repos/RMDN11/jwdpoloap/commits/main');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'JWD-Deploy-Engine');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !is_string($res)) {
    echo "ERROR: Gagal menghubungi GitHub API (HTTP {$httpCode}).\n";
    echo "Gunakan tombol Version Control di cPanel untuk deploy.\n";
    exit;
}

$commitData = json_decode($res, true);
$sha = (string)($commitData['sha'] ?? '');
$commitMsg = trim((string)($commitData['commit']['message'] ?? ''));
$files = (array)($commitData['files'] ?? []);

if ($sha === '' || empty($files)) {
    echo "ERROR: Respon GitHub commit tidak valid.\n";
    exit;
}

echo "=== DEPLOY VIA GITHUB SYNC ===\n";
echo "Commit: " . substr($sha, 0, 7) . " - " . strtok($commitMsg, "\n") . "\n";
echo "Jumlah file diupdate: " . count($files) . "\n\n";

$synced = 0;
$failed = 0;

foreach ($files as $f) {
    $filename = (string)($f['filename'] ?? '');
    $status = (string)($f['status'] ?? '');
    if ($filename === '' || str_contains($filename, '..')) continue;

    $targetPath = $repoDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filename);

    if ($status === 'removed') {
        if (is_file($targetPath)) {
            @unlink($targetPath);
            echo "[-] Dihapus: {$filename}\n";
        }
        continue;
    }

    $rawUrl = (string)($f['raw_url'] ?? "https://raw.githubusercontent.com/RMDN11/jwdpoloap/{$sha}/{$filename}");
    $c = curl_init($rawUrl);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($c, CURLOPT_USERAGENT, 'JWD-Deploy-Engine');
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($c, CURLOPT_TIMEOUT, 15);
    $content = curl_exec($c);
    $rawCode = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);

    if ($rawCode === 200 && is_string($content)) {
        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir)) {
            @mkdir($parentDir, 0755, true);
        }
        if (file_put_contents($targetPath, $content, LOCK_EX) !== false) {
            echo "[✓] {$status}: {$filename}\n";
            $synced++;
        } else {
            echo "[✗] Gagal menulis: {$filename}\n";
            $failed++;
        }
    } else {
        echo "[✗] Gagal mengunduh ({$rawCode}): {$filename}\n";
        $failed++;
    }
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

echo "\n=== DEPLOY SELESAI ===\n";
echo "Berhasil disinkronkan: {$synced} file, Gagal: {$failed} file.\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
