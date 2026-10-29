<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db-retention.php';

$checks = [];

$checks['crmPruneAutoReplyLogs exists'] = function_exists('crmPruneAutoReplyLogs');
$checks['crmPruneLogWaGroupBroadcasts exists'] = function_exists('crmPruneLogWaGroupBroadcasts');
$checks['crmPruneLogWaLegacy exists'] = function_exists('crmPruneLogWaLegacy');
$checks['crmRunAllDbRetention exists'] = function_exists('crmRunAllDbRetention');

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
}
if ($failed) {
    exit(1);
}
