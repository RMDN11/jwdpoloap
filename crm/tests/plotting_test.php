<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/plotting.php';

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' | expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

// 1. Existing Retention Percent tests
expectSame(60.0, plottingRetentionPercent(['Alya', 'Bima', 'Caca', 'Dina', 'Eka'], ['Alya', 'Bima', 'Caca', 'Fira', 'Gita']), 'Retention should be based on previous cohort names');
expectSame(0.0, plottingRetentionPercent([], ['Alya']), 'Empty previous cohort should return zero');
expectSame(66.67, plottingRetentionPercent(['Alya', 'Bima', 'Caca'], ['Alya', 'Bima', 'Dina']), 'Retention should round to two decimals');
expectSame(50.0, plottingRetentionPercent([' Alya  ', 'Bima', 'Alya'], ['alya', 'Dina']), 'Retention identity should normalize case and repeated whitespace');

// 2. Existing Category filter tests
expectSame(true, plottingCurrentMatchesCategory(['total_peserta' => 9, 'total_gaji' => 600000], 'peserta_dikit', 10, 650000), 'Participant filter must use current batch');
expectSame(false, plottingCurrentMatchesCategory(['total_peserta' => 12, 'total_gaji' => 600000], 'peserta_dikit', 10, 650000), 'Participant filter must reject current batch above threshold');
expectSame(true, plottingCurrentMatchesCategory(['total_peserta' => 12, 'total_gaji' => 600000], 'gaji_rendah', 10, 650000), 'Salary filter must use current batch');
expectSame(true, plottingCurrentMatchesCategories(['total_peserta' => 9, 'total_gaji' => 600000], ['peserta_dikit', 'gaji_rendah'], 10, 650000), 'Multiple category filters should use AND logic');
expectSame(false, plottingCurrentMatchesCategories(['total_peserta' => 9, 'total_gaji' => 700000], ['peserta_dikit', 'gaji_rendah'], 10, 650000), 'Multiple category filters should reject when one condition fails');
expectSame(true, plottingCurrentMatchesCategories(['total_peserta' => 0, 'total_gaji' => 0], ['belum_ada', 'gaji_rendah'], 10, 650000), 'Empty group should match combined empty/salary filters');
expectSame(true, plottingCurrentMatchesCategories(['total_peserta' => 20, 'total_gaji' => 2000000], [], 10, 650000), 'No category filter should show all groups');

// 3. Source key parser tests
expectSame(['type' => 'csv', 'value' => '56:1.csv'], plottingParseSourceKey('csv:56:1.csv'), 'Should parse csv prefix with colons in filename');
expectSame(['type' => 'csv', 'value' => 'sample.csv'], plottingParseSourceKey('sample.csv'), 'Should parse bare .csv filename as csv type');
expectSame(['type' => 'payment', 'value' => 'Batch 56'], plottingParseSourceKey('payment:Batch 56'), 'Should parse payment prefix');
expectSame(['type' => '', 'value' => ''], plottingParseSourceKey(''), 'Empty key should return empty type and value');

// 4. Header detection tests: 16-column (new template with Password Login Web) vs 15-column (legacy template)
$newTemplateHeader = [
    'Program', 'Periode / Level', 'Kelas / Grup', 'Tutor Pengajar', 'Nama Murid',
    'Jenis Kelamin', 'Nama Wali', 'WhatsApp Wali', 'Email Wali', 'Password Login Web',
    'Status Siswa', 'Jatah Per Minggu', 'Jatah Per Hari', 'Sesi Selesai',
    'Total Sesi Program', 'Progress (%)'
];
$newColMap = plottingDetectCsvColumns($newTemplateHeader);
expectSame(10, $newColMap['status'], 'New template with Password Login Web must map Status Siswa to column 10');
expectSame(3, $newColMap['tutor'], 'Tutor column should be index 3');
expectSame(4, $newColMap['nama'], 'Nama murid should be index 4, not nama wali');
expectSame(7, $newColMap['wa'], 'WhatsApp column should be index 7');

$legacyTemplateHeader = [
    'Program', 'Periode / Level', 'Kelas / Grup', 'Tutor Pengajar', 'Nama Murid',
    'Jenis Kelamin', 'Nama Wali', 'WhatsApp Wali', 'Email Wali', 'Status Siswa',
    'Jatah Per Minggu', 'Jatah Per Hari', 'Sesi Selesai', 'Total Sesi Program', 'Progress (%)'
];
$legacyColMap = plottingDetectCsvColumns($legacyTemplateHeader);
expectSame(9, $legacyColMap['status'], 'Legacy template must map Status Siswa to column 9');

// 5. CSV Parsing with new template (56:1.csv)
$realCsvPath = dirname(__DIR__, 2) . '/storage/reminder-csv/56:1.csv';
if (!is_file($realCsvPath)) {
    $realCsvPath = '/Users/macbookair/Downloads/56:1.csv';
}
if (is_file($realCsvPath)) {
    $csvData = plottingCsvRows($realCsvPath, dirname($realCsvPath));
    expectSame(true, count($csvData) >= 30, 'Actual 56:1.csv must parse at least 30 active halaqoh groups');
    expectSame(true, isset($csvData['abdur rahim']), 'Abdur Rahim group must be present in parsed 56:1.csv');
    expectSame('IK', $csvData['abdur rahim']['jenis'], 'Abdur Rahim (IK 4) must have IK gender');
    expectSame(true, $csvData['abdur rahim']['total_peserta'] >= 5, 'Abdur Rahim must have active participants');
}

// 6. CSV Parsing with legacy template synthetic data
$tmpLegacyCsv = tempnam(sys_get_temp_dir(), 'legacy_csv_');
$fp = fopen($tmpLegacyCsv, 'w');
fputcsv($fp, $legacyTemplateHeader, ',', '"', '');
fputcsv($fp, [
    'Tahfidz Private Online', 'Paket Normal', 'AK 1', 'Ustadzah Sarah', 'Fulanah',
    'Perempuan', 'Wali Fulanah', '62811111111', 'wali@example.com', 'Aktif (ON)',
    '5x', '1x', '0', '20', '0%'
], ',', '"', '');
fputcsv($fp, [
    'Tahfidz Private Online', 'Tahfidz Cilik', 'AK 1', 'Ustadzah Sarah', 'Cilik Kid',
    'Perempuan', 'Wali Cilik', '62822222222', 'cilik@example.com', 'Aktif (ON)',
    '5x', '1x', '0', '20', '0%'
], ',', '"', '');
fputcsv($fp, [
    'Tahfidz Private Online', 'Paket Normal', 'AK 1', 'Ustadzah Sarah', 'Off Kid',
    'Perempuan', 'Wali Off', '62833333333', 'off@example.com', 'OFF (Belum Bayar)',
    '5x', '1x', '0', '20', '0%'
], ',', '"', '');
fputcsv($fp, [
    'Tahfidz Private Online', 'Paket Normal', 'Antrean', 'Tanpa Tutor', 'Antrean Kid',
    'Perempuan', 'Wali Antrean', '62844444444', 'antrean@example.com', 'Aktif (ON)',
    '5x', '1x', '0', '20', '0%'
], ',', '"', '');
fclose($fp);

$legacyParsed = plottingCsvRows($tmpLegacyCsv, dirname($tmpLegacyCsv));
@unlink($tmpLegacyCsv);

expectSame(true, isset($legacyParsed['ustadzah sarah']), 'Legacy CSV Ustadzah Sarah group must exist');
expectSame(2, $legacyParsed['ustadzah sarah']['total_peserta'], 'Off and Antrean participants must be excluded');
expectSame(1, $legacyParsed['ustadzah sarah']['total_tc'], 'One TC participant must be counted');
expectSame(1, $legacyParsed['ustadzah sarah']['total_non_tc'], 'One non-TC participant must be counted');
expectSame(65000 + 85000, $legacyParsed['ustadzah sarah']['total_gaji'], 'Total salary must sum normal + TC rates');

// 7. Unifying Group Keys across CSV and Payment data
$sourceACsv = [
    'abdur rahim' => [
        'nama_halaqoh' => 'Abdur Rahim (IK 4)',
        'tutor' => 'Abdur Rahim',
        'kelas' => 'IK 4',
        'jenis' => 'IK',
        'total_peserta' => 7,
        'total_gaji' => 455000,
        'total_tc' => 0,
        'total_non_tc' => 7,
        'list_peserta' => [['nama' => 'Agus Pajrin Jaman'], ['nama' => 'Andi Muhammad Fazli']]
    ]
];
$sourceBPayment = [
    'ik 4' => [
        'nama_halaqoh' => 'IK 4',
        'tutor' => 'IK 4',
        'kelas' => 'IK 4',
        'jenis' => 'IK',
        'total_peserta' => 2,
        'total_gaji' => 130000,
        'total_tc' => 0,
        'total_non_tc' => 2,
        'list_peserta' => [['nama' => 'Agus Pajrin Jaman'], ['nama' => 'Budi Baru']]
    ]
];

[$unifiedA, $unifiedB] = plottingUnifyGroupKeys($sourceACsv, $sourceBPayment);
expectSame(true, isset($unifiedB['abdur rahim']), 'Payment halaqoh IK 4 must unify to canonical key abdur rahim');
expectSame(2, $unifiedB['abdur rahim']['total_peserta'], 'Unified payment total peserta must be preserved');

$retention = plottingRetentionPercent(
    array_column($unifiedA['abdur rahim']['list_peserta'], 'nama'),
    array_column($unifiedB['abdur rahim']['list_peserta'], 'nama')
);
expectSame(50.0, $retention, 'Retention between CSV and Payment batch should be 50% (1 continued out of 2)');

echo "21/21 plotting tests passed successfully!\n";
