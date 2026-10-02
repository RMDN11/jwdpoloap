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

$checks['normalizes participant name'] =
    crmRetentionNormalizeName("  Aisyah   Najma Fakhira ") === 'aisyah najma fakhira';

$checks['extracts template title from sent reminder log'] =
    crmRetentionExtractTemplateFromLogMessage(
        '[REMINDER] [TERKIRIM] [TEMPLATE] Follow Up Batch 55 | Halo {nama}'
    ) === 'Follow Up Batch 55';

$checks['old reminder log remains compatible without template'] =
    crmRetentionExtractTemplateFromLogMessage(
        '[REMINDER] [TERKIRIM] Assalamu\\\'alaikum {nama}'
    ) === '';

$checks['blank tutor is excluded'] =
    crmRetentionHasTutor('') === false;

$checks['antrean tutor is excluded'] =
    crmRetentionHasTutor('Antrean') === false;

$checks['real tutor is included'] =
    crmRetentionHasTutor('Ustadzah Fulanah') === true;

$csvPath = tempnam(sys_get_temp_dir(), 'retention-csv-');
$csvHandle = fopen($csvPath, 'wb');
fputcsv($csvHandle, crmReminderCsvRequiredHeaders());
fputcsv($csvHandle, [
    'Tahfidz Cilik',
    'Batch 55',
    'AK 1',
    'Tutor',
    'Murid CSV',
    'Akhwat',
    'Wali',
    '081234567890',
    'wali@example.com',
    'Aktif (ON)',
    '5',
    '1',
    '3',
    '20',
    '15%',
]);
fclose($csvHandle);

set_error_handler(
    static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
);

try {
    $parsedCsv = crmReminderCsvParse($csvPath);
    $checks['15-column CSV parses without optional id warning'] =
        count($parsedCsv['rows']) === 1
        && $parsedCsv['rows'][0]['normalized_wa'] === '6281234567890'
        && $parsedCsv['rows'][0]['status_siswa'] === 'ON';

$sharedWaCsvPath = tempnam(sys_get_temp_dir(), 'retention-shared-wa-');
$sharedWaHandle = fopen($sharedWaCsvPath, 'wb');
fputcsv($sharedWaHandle, crmReminderCsvRequiredHeaders());
fputcsv($sharedWaHandle, [
    'Tahfidz', 'Batch 54', 'AK 28', 'Tutor', 'Alif Al Fakhri Saragih',
    'Ikhwan', 'Wali', '081234567890', 'alif@example.com', 'Aktif (ON)',
    '5', '1', '3', '20', '15%',
]);
fputcsv($sharedWaHandle, [
    'Tahfidz', 'Batch 54', 'AK 28', 'Tutor', 'Fakhira Ulina Saragih',
    'Akhwat', 'Wali', '081234567890', 'fakhira@example.com', 'Aktif (ON)',
    '5', '1', '3', '20', '15%',
]);
fclose($sharedWaHandle);

try {
    $sharedWaRows = crmReminderCsvParse($sharedWaCsvPath, 5000, false)['rows'];
    $checks['CSV retention keeps different names with the same WhatsApp'] =
        count($sharedWaRows) === 2
        && $sharedWaRows[0]['nama_murid'] === 'Alif Al Fakhri Saragih'
        && $sharedWaRows[1]['nama_murid'] === 'Fakhira Ulina Saragih';
} finally {
    @unlink($sharedWaCsvPath);
}
} finally {
    restore_error_handler();
    @unlink($csvPath);
}

$records = crmRetentionDeduplicate([
    ['wa' => '0812-1111-1111', 'target_wa' => '0812-1111-1111', 'name' => 'Aisyah Najma', 'group' => 'AK 1'],
    ['wa' => '628111111111', 'target_wa' => '628111111111', 'name' => '  AISYAH   NAJMA ', 'group' => 'AK 1'],
    ['wa' => '', 'target_wa' => '', 'name' => 'No WA', 'group' => 'AK 1'],
    ['wa' => '0822-2222-2222', 'target_wa' => '0822-2222-2222', 'name' => 'B', 'group' => 'AK 2'],
]);

$checks['deduplicates normalized participant names'] = count($records) === 3;
$checks['name key is used for identity'] = isset($records['aisyah najma']);
$checks['same WhatsApp does not merge different names'] = count(crmRetentionDeduplicate([
    ['wa' => '628111111111', 'target_wa' => '628111111111', 'name' => 'Alif Al Fakhri Saragih', 'group' => 'AK 28'],
    ['wa' => '628111111111', 'target_wa' => '628111111111', 'name' => 'Fakhira Ulina Saragih', 'group' => 'AK 28'],
])) === 2;

$checks['case and whitespace variants still match by name'] =
    crmRetentionNormalizeName(' FAKHIRA   Ulina Saragih ') === 'fakhira ulina saragih';
$checks['missing WhatsApp can remain in retention cohort'] = isset($records['no wa']);

$previous = crmRetentionDeduplicate([
    ['wa' => '0812-1111-1111', 'target_wa' => '0812-1111-1111', 'name' => 'Aisyah Najma Fakhira', 'group' => 'AK 1'],
    ['wa' => '0822-2222-2222', 'target_wa' => '0822-2222-2222', 'name' => 'B', 'group' => 'AK 1'],
    ['wa' => '0833-3333-3333', 'target_wa' => '0833-3333-3333', 'name' => 'C', 'group' => 'AK 2'],
]);

$current = crmRetentionDeduplicate([
    // Different WA, same participant name: must still count as continued.
    ['wa' => '628129999999', 'target_wa' => '628129999999', 'name' => ' Aisyah   Najma Fakhira ', 'group' => 'AK 1'],
    ['wa' => '0833-3333-3333', 'target_wa' => '0833-3333-3333', 'name' => 'C', 'group' => 'AK 2'],
]);

$comparison = crmRetentionCompare($previous, $current);

$checks['previous denominator is three'] = $comparison['previous_count'] === 3;
$checks['current cohort is two'] = $comparison['current_count'] === 2;
$checks['continued count is two by name'] = $comparison['continued_count'] === 2;
$checks['not continued count is one'] = $comparison['not_continued_count'] === 1;
$checks['retention rate is 66.7 percent'] = abs($comparison['rate'] - (2 / 3 * 100)) < 0.01;

// Moving AK in the current batch must not turn a continuing participant
// into a non-retained participant when filtering the previous cohort by AK.
$previousAk10 = crmRetentionDeduplicate([
    ['name' => 'Pindah AK', 'wa' => '0811', 'target_wa' => '0811', 'group' => 'AK 10'],
    ['name' => 'Tetap AK', 'wa' => '0822', 'target_wa' => '0822', 'group' => 'AK 10'],
]);
$currentMovedAk = crmRetentionDeduplicate([
    ['name' => 'Pindah AK', 'wa' => '0833', 'target_wa' => '0833', 'group' => 'AK 11'],
]);
$movedComparison = crmRetentionCompare($previousAk10, $currentMovedAk);
$checks['participant moving AK is still continued'] =
    $movedComparison['continued_count'] === 1
    && $movedComparison['not_continued_count'] === 1;

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
