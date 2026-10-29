<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/log-rotator.php';

$tmpFile = sys_get_temp_dir() . '/crm_log_rotator_test_' . uniqid() . '.log';

$checks = [];

// 1. Initial append
crmAppendLog($tmpFile, "line 1\n");
$checks['file is created on append'] = file_exists($tmpFile) && (int)filesize($tmpFile) > 0;

// 2. Multiple appends below maxBytes
for ($i = 2; $i <= 10; $i++) {
    crmAppendLog($tmpFile, "line $i\n");
}
$checks['appends correctly'] = strpos(file_get_contents($tmpFile), "line 10\n") !== false;

// 3. Test rotation threshold (set small maxBytes like 200 bytes, keepBytes 50 bytes)
for ($i = 11; $i <= 50; $i++) {
    crmAppendLog($tmpFile, "structured log row $i - payload details here\n", 300, 80);
}
$content = file_get_contents($tmpFile);
$checks['rotates when exceeding threshold'] = strpos($content, '[LOG ROTATED') !== false;
$checks['keeps recent lines'] = strpos($content, 'structured log row 50') !== false;
$checks['size is kept below or near threshold'] = filesize($tmpFile) < 300;

// Cleanup
@unlink($tmpFile);

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
}
if ($failed) {
    exit(1);
}
