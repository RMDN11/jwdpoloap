<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/plotting.php';

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' | expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

expectSame(60.0, plottingRetentionPercent(['Alya', 'Bima', 'Caca', 'Dina', 'Eka'], ['Alya', 'Bima', 'Caca', 'Fira', 'Gita']), 'Retention should be based on previous cohort names');
expectSame(0.0, plottingRetentionPercent([], ['Alya']), 'Empty previous cohort should return zero');
expectSame(66.67, plottingRetentionPercent(['Alya', 'Bima', 'Caca'], ['Alya', 'Bima', 'Dina']), 'Retention should round to two decimals');
expectSame(50.0, plottingRetentionPercent([' Alya  ', 'Bima', 'Alya'], ['alya', 'Dina']), 'Retention identity should normalize case and repeated whitespace');
expectSame(true, plottingCurrentMatchesCategory(['total_peserta' => 9, 'total_gaji' => 600000], 'peserta_dikit', 10, 650000), 'Participant filter must use current batch');
expectSame(false, plottingCurrentMatchesCategory(['total_peserta' => 12, 'total_gaji' => 600000], 'peserta_dikit', 10, 650000), 'Participant filter must reject current batch above threshold');
expectSame(true, plottingCurrentMatchesCategory(['total_peserta' => 12, 'total_gaji' => 600000], 'gaji_rendah', 10, 650000), 'Salary filter must use current batch');

expectSame(true, plottingCurrentMatchesCategories(['total_peserta' => 9, 'total_gaji' => 600000], ['peserta_dikit', 'gaji_rendah'], 10, 650000), 'Multiple category filters should use AND logic');
expectSame(false, plottingCurrentMatchesCategories(['total_peserta' => 9, 'total_gaji' => 700000], ['peserta_dikit', 'gaji_rendah'], 10, 650000), 'Multiple category filters should reject when one condition fails');
expectSame(true, plottingCurrentMatchesCategories(['total_peserta' => 0, 'total_gaji' => 0], ['belum_ada', 'gaji_rendah'], 10, 650000), 'Empty group should match combined empty/salary filters');
expectSame(true, plottingCurrentMatchesCategories(['total_peserta' => 20, 'total_gaji' => 2000000], [], 10, 650000), 'No category filter should show all groups');

echo "11/11 plotting tests passed\n";
