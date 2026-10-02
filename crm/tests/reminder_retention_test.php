<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reminder-retention.php';

$checks = [];

$checks['normalizes local WhatsApp'] =
    crmReminderCsvNormalizeWa('0812 3456-7890') === '6281234567890';

$checks['normalizes CSV ON label'] =
    crmReminderCsvNormalizeStatus('Aktif (ON)') === 'ON';

$checks['normalizes CSV OFF label'] =
    crmReminderCsvNormalizeStatus('Aktif (OFF)') === 'OFF';

$records = crmRetentionDeduplicate([
    ['wa' => '0812-1111-1111', 'target_wa' => '0812-1111-1111', 'name' => 'A', 'group' => 'AK 1'],
    ['wa' => '628111111111', 'target_wa' => '628111111111', 'name' => 'A Duplicate', 'group' => 'AK 1'],
    ['wa' => '', 'target_wa' => '', 'name' => 'No WA', 'group' => 'AK 1'],
    ['wa' => '0822-2222-2222', 'target_wa' => '0822-2222-2222', 'name' => 'B', 'group' => 'AK 2'],
]);

$checks['deduplicates normalized WhatsApp'] = count($records) === 2;
$checks['does not match missing WhatsApp'] = !isset($records['']);

$previous = crmRetentionDeduplicate([
    ['wa' => '0812-1111-1111', 'target_wa' => '0812-1111-1111', 'name' => 'A', 'group' => 'AK 1'],
    ['wa' => '0822-2222-2222', 'target_wa' => '0822-2222-2222', 'name' => 'B', 'group' => 'AK 1'],
    ['wa' => '0833-3333-3333', 'target_wa' => '0833-3333-3333', 'name' => 'C', 'group' => 'AK 2'],
]);

$current = crmRetentionDeduplicate([
    ['wa' => '628121111111', 'target_wa' => '628121111111', 'name' => 'A', 'group' => 'AK 1'],
    ['wa' => '0833-3333-3333', 'target_wa' => '0833-3333-3333', 'name' => 'C', 'group' => 'AK 2'],
]);

$comparison = crmRetentionCompare($previous, $current);

$checks['previous denominator is three'] = $comparison['previous_count'] === 3;
$checks['current cohort is two'] = $comparison['current_count'] === 2;
$checks['continued count is two'] = $comparison['continued_count'] === 2;
$checks['not continued count is one'] = $comparison['not_continued_count'] === 1;
$checks['retention rate is 66.7 percent'] = abs($comparison['rate'] - (2 / 3 * 100)) < 0.01;

$breakdown = [];
foreach ($comparison['breakdown'] as $item) {
    $breakdown[$item['group']] = $item;
}

$checks['AK 1 has two previous'] =
    isset($breakdown['AK 1']) && $breakdown['AK 1']['total'] === 2;
$checks['AK 1 has one continued'] =
    isset($breakdown['AK 1']) && $breakdown['AK 1']['continued'] === 1;
$checks['AK 2 has one continued'] =
    isset($breakdown['AK 2']) && $breakdown['AK 2']['continued'] === 1;

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
}

if ($failed) {
    exit(1);
}
