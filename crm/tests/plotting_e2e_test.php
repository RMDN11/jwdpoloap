<?php
declare(strict_types=1);

// E2E Rendering test for page=plotting
$baseDir = dirname(__DIR__, 2);

function runPlottingPage(array $params): string
{
    $_GET = $params;
    $_GET['page'] = 'plotting';
    ob_start();
    require __DIR__ . '/../pages/plotting.php';
    return ob_get_clean();
}

// 1. Initial page load (empty selection)
$html1 = runPlottingPage([]);
assert(str_contains($html1, 'Pilih batch yang dibandingkan'), 'Should render source card');
assert(str_contains($html1, '56:1.csv'), 'Should list 56:1.csv in sources');

// 2. CSV comparison
$html2 = runPlottingPage(['csv_a' => 'csv:56:1.csv', 'csv_b' => 'csv:56:1.csv']);
assert(str_contains($html2, 'Hasil Perbandingan'), 'Should render results section');
assert(str_contains($html2, 'Halaqoh Ikhwan (IK)'), 'Should render Ikhwan column');
assert(str_contains($html2, 'Halaqoh Akhwat (AK)'), 'Should render Akhwat column');
assert(str_contains($html2, 'Abdur Rahim (IK 4)'), 'Should render Abdur Rahim halaqoh');

// 3. Search query filter
$html3 = runPlottingPage(['csv_a' => 'csv:56:1.csv', 'csv_b' => 'csv:56:1.csv', 'q' => 'Agus Pajrin']);
assert(str_contains($html3, 'Abdur Rahim (IK 4)'), 'Should find Abdur Rahim where Agus Pajrin is enrolled');

// 4. Gender filter
$htmlIk = runPlottingPage(['csv_a' => 'csv:56:1.csv', 'csv_b' => 'csv:56:1.csv', 'gender' => 'IK']);
assert(str_contains($htmlIk, 'Tidak ada halaqoh akhwat.'), 'Akhwat column should be empty when gender=IK');

$htmlAk = runPlottingPage(['csv_a' => 'csv:56:1.csv', 'csv_b' => 'csv:56:1.csv', 'gender' => 'AK']);
assert(str_contains($htmlAk, 'Tidak ada halaqoh ikhwan.'), 'Ikhwan column should be empty when gender=AK');

// 5. Payment source handling (even if database is offline or empty, page renders cleanly without crashing)
$htmlPay = runPlottingPage(['csv_a' => 'payment:Batch 55', 'csv_b' => 'csv:56:1.csv']);
assert(str_contains($htmlPay, 'Hasil Perbandingan'), 'Should render results section when payment source is selected');

echo "All 5 E2E plotting rendering scenarios passed successfully!\n";
