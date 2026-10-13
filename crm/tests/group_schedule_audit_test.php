<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../pages/group-schedules.php');
$router = file_get_contents(__DIR__ . '/../index.php');
$cron = file_get_contents(__DIR__ . '/../../cron_grup.php');

$checks = [
    'schedule page route is allow-listed' => strpos($router, "'group-schedules'") !== false,
    'progress uses explicit Jakarta application timezone' => strpos($page, "new DateTimeZone('Asia/Jakarta')") !== false,
    'progress does not depend on MySQL CURTIME or NOW timezone' => strpos($page, 'CURTIME()') === false && strpos($page, 'NOW()') === false,
    'overdue once and immediate pending schedules are counted' => strpos($page, "tipe_jadwal IN ('sekali','sekarang')") !== false && strpos($page, "tipe_jadwal='sekarang' OR jadwal_kirim") !== false,
    'routine progress does not claim 100 percent when none is due' => strpos($page, '$routineDueToday > 0 ? (int)round(min(1, $routineSentToday / $routineDueToday) * 100) : 0') !== false,
    'routine schedule remains active after send' => strpos($cron, "SET terakhir_dikirim = CURDATE() WHERE id = ?") !== false,
    'one-time schedule gets terminal sent status' => strpos($cron, "SET status = 'terkirim', terakhir_dikirim = CURDATE() WHERE id = ?") !== false,
    'cron checks and logs schedule update execution' => strpos($cron, 'ERROR UPDATE jadwal ID') !== false && strpos($cron, 'affected_rows=') !== false,
];

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
}
if ($failed) exit(1);
