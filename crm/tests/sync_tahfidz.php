<?php
declare(strict_types=1);

if (($_GET['token'] ?? '') !== 'jwd_auto_cron') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: application/json');

$targetDir = '/home/wegqxcgv/subdomains/app.reqra.my.id/tahfidz';
$cmd = "cd {$targetDir} && git status 2>&1";
$status = shell_exec($cmd);

$remoteCmd = "cd {$targetDir} && git remote -v 2>&1";
$remotes = shell_exec($remoteCmd);

// If remote is SSH, let's see if we can switch it to HTTPS
if (strpos((string)$remotes, 'git@github.com') !== false) {
    shell_exec("cd {$targetDir} && git remote set-url origin https://github.com/RMDN11/tahfidz.git 2>&1");
}

$pullCmd = "cd {$targetDir} && git pull origin main 2>&1";
$pullOut = shell_exec($pullCmd);

$afterStatus = shell_exec("cd {$targetDir} && git status 2>&1");

echo json_encode([
    'target' => $targetDir,
    'status_before' => $status,
    'remotes' => $remotes,
    'pull_output' => $pullOut,
    'status_after' => $afterStatus,
], JSON_PRETTY_PRINT);
