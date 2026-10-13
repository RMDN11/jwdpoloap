<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../actions/send-message.php');
$checks = [
    'blocked list is checked by candidate number instead of loading all numbers' => strpos($source, 'crmGetBlockedNumbers($conn)') === false && strpos($source, 'SELECT 1 FROM blocked_peserta WHERE nowa IN') !== false,
    'send action records stage timings without logging message content or phone numbers' => strpos($source, '[CRM_SEND %s] stage=%s stage_ms=%d total_ms=%d') !== false,
    'provider connection has a separate timeout' => strpos($source, 'CURLOPT_CONNECTTIMEOUT => 5') !== false,
    'provider total timeout remains bounded' => strpos($source, 'CURLOPT_TIMEOUT => 15') !== false,
    'TLS certificate verification is enabled' => strpos($source, 'CURLOPT_SSL_VERIFYPEER => true') !== false,
    'provider latency is measured' => strpos($source, 'CURLINFO_TOTAL_TIME') !== false,
];
$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
if ($failed) exit(1);
