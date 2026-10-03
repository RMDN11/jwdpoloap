<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reminder-csv.php';
require_once __DIR__ . '/../lib/plotting.php';

/**
 * Plotting
 * Membandingkan beberapa snapshot CSV peserta tanpa menyimpan baris CSV ke database.
 * Logic peserta, kuota, dan gaji mengikuti reqnew.php.
 */

$csvDir = dirname(__DIR__, 2) . '/storage/reminder-csv';
$csvSources = crmReminderCsvList();
$csvFiles = [];
$csvSourceMeta = [];

foreach ($csvSources as $meta) {
    $file = basename((string)($meta['file'] ?? ''));
    if ($file === '' || !is_file($csvDir . '/' . $file)) continue;
    $csvFiles[] = $file;
    $csvSourceMeta[$file] = $meta;
}

$csvLabel = static function (string $file) use ($csvSourceMeta): string {
    $meta = $csvSourceMeta[$file] ?? [];
    $label = trim((string)($meta['label'] ?? ''));
    $original = trim((string)($meta['source_filename'] ?? ''));
    if ($label !== '' && $original !== '') return $label . ' · ' . $original;
    if ($label !== '') return $label;
    if ($original !== '') return $original;
    return $file;
};

$csvShortLabel = static function (string $file) use ($csvSourceMeta): string {
    $meta = $csvSourceMeta[$file] ?? [];
    $label = trim((string)($meta['label'] ?? ''));
    if ($label !== '') return $label;
    $original = trim((string)($meta['source_filename'] ?? ''));
    return $original !== '' ? $original : $file;
};

$sourceA = trim((string)($_GET['csv_a'] ?? ''));
$sourceB = trim((string)($_GET['csv_b'] ?? ''));
if (!in_array($sourceA, $csvFiles, true)) $sourceA = '';
if (!in_array($sourceB, $csvFiles, true)) $sourceB = '';

$participantThreshold = max(0, (int)($_GET['participant_threshold'] ?? 10));
$salaryThreshold = max(0, (int)($_GET['salary_threshold'] ?? 650000));
$category = trim((string)($_GET['category'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$genderFilter = trim((string)($_GET['gender'] ?? ''));
$halaqohFilter = trim((string)($_GET['halaqoh'] ?? ''));
$showSalary = ($_GET['show_salary'] ?? '1') !== '0';

function plottingCsvRows(string $filename, string $csvDir): array
{
    $path = $csvDir . '/' . basename($filename);
    if (!is_file($path)) return [];
    $handle = fopen($path, 'r');
    if ($handle === false) return [];
    $rows = [];
    if (fgetcsv($handle, 0, ',') === false) { fclose($handle); return []; }

    while (($data = fgetcsv($handle, 0, ',')) !== false) {
        if (count($data) < 10) continue;
        $status = plottingNormalize((string)($data[9] ?? ''));
        if ($status !== 'aktif (on)' && $status !== 'on') continue;

        $program = trim((string)($data[0] ?? ''));
        $periode = trim((string)($data[1] ?? ''));
        $kelas = trim((string)($data[2] ?? ''));
        $tutor = trim((string)($data[3] ?? ''));
        $nama = trim((string)($data[4] ?? ''));
        $jk = trim((string)($data[5] ?? ''));
        $wa = str_replace(['="', '"'], '', trim((string)($data[7] ?? '')));

        $kelasLower = plottingNormalize($kelas);
        $tutorLower = plottingNormalize($tutor);
        if ($kelasLower === 'antrean (belum ada kelas)' || $kelasLower === 'antrean' || $tutorLower === 'tanpa tutor' || $tutorLower === '-' || $tutor === '') continue;

        $periodeLower = plottingNormalize($periode);
        $harga = 65000; $kuota = 1; $ketStatus = 'Normal'; $isTc = false; $isNonTc = true;
        if (str_contains($periodeLower, 'tahfidz cilik intensif') || str_contains($periodeLower, 'tc intensif')) {
            $harga = 170000; $kuota = 2; $ketStatus = 'TC Intensif'; $isTc = true; $isNonTc = false;
        } elseif (str_contains($periodeLower, 'super intensif')) {
            $harga = 260000; $kuota = 4; $ketStatus = 'Super Intensif';
        } elseif (str_contains($periodeLower, 'intensif plus')) {
            $harga = 195000; $kuota = 3; $ketStatus = 'Intensif Plus';
        } elseif (str_contains($periodeLower, 'intensif')) {
            $harga = 130000; $kuota = 2; $ketStatus = 'Intensif';
        } elseif (str_contains($periodeLower, 'tahfidz cilik') || str_contains($periodeLower, 'cilik')) {
            $harga = 85000; $kuota = 1; $ketStatus = 'TC'; $isTc = true; $isNonTc = false;
        } elseif (str_contains($periodeLower, 'normal') || str_contains($periodeLower, 'reguler')) {
            $harga = 65000; $kuota = 1; $ketStatus = 'Normal';
        } elseif ($periode !== '') {
            $ketStatus = $periode;
        }

        $jenis = in_array(plottingNormalize($jk), ['l', 'laki-laki', 'ikhwan'], true) ? 'IK' : 'AK';
        $groupKey = plottingNormalize($tutor);
        if (!isset($rows[$groupKey])) {
            $rows[$groupKey] = ['nama_halaqoh' => $tutor, 'jenis' => $jenis, 'total_peserta' => 0, 'total_gaji' => 0, 'total_tc' => 0, 'total_non_tc' => 0, 'list_peserta' => []];
        }
        $rows[$groupKey]['total_peserta'] += $kuota;
        $rows[$groupKey]['total_gaji'] += $harga;
        $rows[$groupKey]['total_tc'] += $isTc ? $kuota : 0;
        $rows[$groupKey]['total_non_tc'] += $isNonTc ? $kuota : 0;
        $rows[$groupKey]['list_peserta'][] = ['nama' => $nama, 'nowa' => $wa, 'program' => $program, 'kuota' => $kuota, 'gaji' => $harga, 'ket_status' => $ketStatus];
    }
    fclose($handle);
    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
    return $rows;
}

function plottingMatchesFilters(array $item, string $category, int $participantThreshold, int $salaryThreshold, string $searchTerm, string $genderFilter, string $halaqohFilter): bool
{
    $current = $item['b'] ?? ['total_peserta' => 0, 'total_gaji' => 0, 'jenis' => $item['jenis'], 'list_peserta' => []];
    if (!plottingCurrentMatchesCategory($current, $category, $participantThreshold, $salaryThreshold)) return false;
    if ($genderFilter !== '' && $current['jenis'] !== $genderFilter) return false;
    if ($halaqohFilter !== '' && plottingNormalize($item['name']) !== plottingNormalize($halaqohFilter)) return false;
    if ($searchTerm !== '') {
        $needle = plottingNormalize($searchTerm);
        $found = false;
        foreach ([$item['a']['list_peserta'] ?? [], $item['b']['list_peserta'] ?? []] as $people) {
            foreach ($people as $person) {
                if (str_contains(plottingNormalize((string)$person['nama']), $needle) || str_contains(plottingNormalize((string)$person['nowa']), $needle)) { $found = true; break 2; }
            }
        }
        if (!$found) return false;
    }
    return true;
}

function plottingRenderCard(array $item, string $sourceA, string $sourceB, callable $csvShortLabel, callable $formatRupiah, callable $formatPercent, bool $showSalary): string
{
    $a = $item['a'];
    $b = $item['b'];
    $current = $b ?? ['total_peserta' => 0, 'total_gaji' => 0, 'total_tc' => 0, 'total_non_tc' => 0, 'list_peserta' => []];
    $retention = (float)$item['retention'];
    $retentionClass = $retention >= 80 ? 'is-good' : ($retention >= 50 ? 'is-mid' : 'is-low');
    ob_start(); ?>
    <article class="plotting-halaqoh-card">
        <details>
            <summary>
                <strong><?= htmlspecialchars($item['name']) ?></strong>
                <span class="plotting-card-metrics"><b class="tc">TC: <?= (int)$current['total_tc'] ?></b><b class="non">Non: <?= (int)$current['total_non_tc'] ?></b><b class="total">Total: <?= (int)$current['total_peserta'] ?></b></span>
                <i class="fa-solid fa-chevron-down"></i>
            </summary>
            <div class="plotting-card-detail">
                <div class="plotting-retention-hero <?= $retentionClass ?>"><span>Retention</span><strong><?= $formatPercent($retention) ?></strong><small><?= (int)$item['continued'] ?> dari <?= (int)$item['previous_total'] ?> peserta batch sebelumnya lanjut</small></div>
                <div class="plotting-batch-comparison">
                    <div><b><?= htmlspecialchars($sourceA !== '' ? $csvShortLabel($sourceA) : 'Batch sebelumnya') ?></b><strong><?= $a ? (int)$a['total_peserta'] : 0 ?> peserta</strong><?php if ($showSalary): ?><small><?= $formatRupiah((int)($a['total_gaji'] ?? 0)) ?></small><?php endif; ?></div>
                    <div><b><?= htmlspecialchars($sourceB !== '' ? $csvShortLabel($sourceB) : 'Batch sekarang') ?></b><strong><?= (int)$current['total_peserta'] ?> peserta</strong><?php if ($showSalary): ?><small><?= $formatRupiah((int)$current['total_gaji']) ?></small><?php endif; ?></div>
                </div>
                <div class="plotting-name-columns">
                    <div><span><?= htmlspecialchars($sourceA !== '' ? $csvShortLabel($sourceA) : 'Batch sebelumnya') ?></span><ul><?php foreach (($a['list_peserta'] ?? []) as $person): ?><li><?= htmlspecialchars($person['nama']) ?></li><?php endforeach; ?><?php if (!$a): ?><li class="muted">Tidak ada</li><?php endif; ?></ul></div>
                    <div><span><?= htmlspecialchars($sourceB !== '' ? $csvShortLabel($sourceB) : 'Batch sekarang') ?></span><ul><?php foreach (($b['list_peserta'] ?? []) as $person): ?><li><?= htmlspecialchars($person['nama']) ?></li><?php endforeach; ?><?php if (!$b): ?><li class="muted">Tidak ada</li><?php endif; ?></ul></div>
                </div>
            </div>
        </details>
    </article>
    <?php return (string)ob_get_clean();
}

$dataA = $sourceA !== '' ? plottingCsvRows($sourceA, $csvDir) : [];
$dataB = $sourceB !== '' ? plottingCsvRows($sourceB, $csvDir) : [];
$groupKeys = array_values(array_unique(array_merge(array_keys($dataA), array_keys($dataB))));
sort($groupKeys, SORT_NATURAL | SORT_FLAG_CASE);

$comparison = [];
foreach ($groupKeys as $key) {
    $a = $dataA[$key] ?? null; $b = $dataB[$key] ?? null;
    $previousNames = array_map(static fn(array $p): string => (string)$p['nama'], $a['list_peserta'] ?? []);
    $currentNames = array_map(static fn(array $p): string => (string)$p['nama'], $b['list_peserta'] ?? []);
    $previousUnique = array_values(array_unique(array_filter(array_map('plottingNormalize', $previousNames))));
    $continued = 0;
    $currentUnique = array_fill_keys(array_values(array_unique(array_filter(array_map('plottingNormalize', $currentNames)))), true);
    foreach ($previousUnique as $name) if (isset($currentUnique[$name])) $continued++;
    $comparison[] = [
        'key' => $key,
        'name' => $b['nama_halaqoh'] ?? $a['nama_halaqoh'] ?? $key,
        'jenis' => $b['jenis'] ?? $a['jenis'] ?? 'AK',
        'a' => $a, 'b' => $b,
        'retention' => plottingRetentionPercent($previousNames, $currentNames),
        'continued' => $continued, 'previous_total' => count($previousUnique),
    ];
}
$filteredComparison = array_values(array_filter($comparison, static fn(array $item): bool => plottingMatchesFilters($item, $category, $participantThreshold, $salaryThreshold, $searchTerm, $genderFilter, $halaqohFilter)));
$ikRows = array_values(array_filter($filteredComparison, static fn(array $item): bool => $item['jenis'] === 'IK'));
$akRows = array_values(array_filter($filteredComparison, static fn(array $item): bool => $item['jenis'] === 'AK'));
$halaqohOptions = $groupKeys;
$totalCurrentQuota = array_sum(array_column($dataB, 'total_peserta'));
$totalCurrentSalary = array_sum(array_column($dataB, 'total_gaji'));
$totalCurrentTc = array_sum(array_column($dataB, 'total_tc'));
$totalCurrentNonTc = array_sum(array_column($dataB, 'total_non_tc'));
$formatRupiah = static fn(int $value): string => 'Rp ' . number_format($value, 0, ',', '.');
$formatPercent = static fn(float $value): string => rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%';
?>
<div class="crm-workspace-page crm-plotting-page">
    <div class="crm-workspace-back"><a href="index.php?page=more" title="Kembali ke More" aria-label="Kembali ke More"><i class="fa-solid fa-arrow-left"></i></a></div>
    <section class="crm-workspace-header"><div class="crm-workspace-header-main"><span class="crm-workspace-kicker">Data Comparison</span><h1>Plotting</h1><p>Bandingkan dua snapshot CSV untuk melihat retention dan kondisi halaqoh.</p></div></section>

    <section class="crm-workspace-card plotting-source-card">
        <div class="crm-workspace-card-head"><div class="crm-workspace-card-head-main"><span class="crm-workspace-card-kicker">CSV Source</span><h2>Pilih batch yang dibandingkan</h2></div><span class="plotting-source-count"><?= count($csvFiles) ?> file tersedia</span></div>
        <div class="crm-workspace-card-body">
            <?php if (!$csvFiles): ?><div class="crm-workspace-empty"><div class="crm-workspace-empty-icon"><i class="fa-solid fa-file-csv"></i></div><strong>Belum ada CSV</strong><span>Upload CSV melalui workspace Reminder CSV terlebih dahulu.</span></div>
            <?php else: ?><form method="get" class="plotting-source-form"><input type="hidden" name="page" value="plotting"><label class="crm-workspace-field"><span>Batch sebelumnya</span><select name="csv_a"><option value="">Pilih CSV...</option><?php foreach ($csvFiles as $file): ?><option value="<?= htmlspecialchars($file, ENT_QUOTES) ?>" <?= $file === $sourceA ? 'selected' : '' ?>><?= htmlspecialchars($csvLabel($file)) ?></option><?php endforeach; ?></select></label><label class="crm-workspace-field"><span>Batch sekarang</span><select name="csv_b"><option value="">Pilih CSV...</option><?php foreach ($csvFiles as $file): ?><option value="<?= htmlspecialchars($file, ENT_QUOTES) ?>" <?= $file === $sourceB ? 'selected' : '' ?>><?= htmlspecialchars($csvLabel($file)) ?></option><?php endforeach; ?></select></label><button class="crm-workspace-primary" type="submit"><i class="fa-solid fa-code-compare"></i> Bandingkan</button></form><?php endif; ?>
        </div>
    </section>

    <?php if ($sourceA !== '' || $sourceB !== ''): ?>
        <section class="plotting-summary-grid">
            <div class="plotting-summary-card"><span>NON TC</span><strong><?= $totalCurrentNonTc ?></strong></div>
            <div class="plotting-summary-card plotting-summary-card-blue"><span>TC / CILIK</span><strong><?= $totalCurrentTc ?></strong></div>
            <div class="plotting-summary-card plotting-summary-card-green"><span>TOTAL PESERTA</span><strong><?= $totalCurrentQuota ?></strong></div>
            <div class="plotting-summary-card"><span>COP (x30k)</span><strong><?= $formatRupiah(count($dataB) * 30000) ?></strong></div>
            <div class="plotting-summary-card"><span>GAJI</span><strong><?= $formatRupiah($totalCurrentSalary) ?></strong></div>
            <div class="plotting-summary-card plotting-summary-card-dark"><span>BATCH SEKARANG</span><strong><?= htmlspecialchars($csvShortLabel($sourceB ?: $sourceA)) ?></strong></div>
        </section>

        <section class="crm-workspace-card plotting-filter-card">
            <div class="plotting-filter-head"><div class="plotting-filter-title"><span class="plotting-filter-icon"><i class="fa-solid fa-filter"></i></span><h2>Filter Data</h2></div><a class="plotting-salary-toggle" href="<?= htmlspecialchars('index.php?' . http_build_query(['page'=>'plotting','csv_a'=>$sourceA,'csv_b'=>$sourceB,'participant_threshold'=>$participantThreshold,'salary_threshold'=>$salaryThreshold,'category'=>$category,'q'=>$searchTerm,'gender'=>$genderFilter,'halaqoh'=>$halaqohFilter,'show_salary'=>$showSalary ? 0 : 1])) ?>"><i class="fa-solid <?= $showSalary ? 'fa-eye' : 'fa-eye-slash' ?>"></i> Gaji</a></div>
            <form method="get" class="plotting-filter-form"><input type="hidden" name="page" value="plotting"><input type="hidden" name="csv_a" value="<?= htmlspecialchars($sourceA, ENT_QUOTES) ?>"><input type="hidden" name="csv_b" value="<?= htmlspecialchars($sourceB, ENT_QUOTES) ?>"><input type="hidden" name="participant_threshold" value="<?= $participantThreshold ?>"><input type="hidden" name="salary_threshold" value="<?= $salaryThreshold ?>"><input type="hidden" name="category" value="<?= htmlspecialchars($category, ENT_QUOTES) ?>">
                <label class="crm-workspace-field"><span>Cari Peserta / WA</span><input type="search" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>" placeholder="Ketik nama atau WA..."></label>
                <label class="crm-workspace-field"><span>Halaqoh</span><select name="halaqoh"><option value="">Semua</option><?php foreach ($halaqohOptions as $halaqoh): $displayHalaqoh=$dataB[$halaqoh]['nama_halaqoh'] ?? $dataA[$halaqoh]['nama_halaqoh'] ?? $halaqoh; ?><option value="<?= htmlspecialchars($displayHalaqoh, ENT_QUOTES) ?>" <?= plottingNormalize($halaqohFilter) === plottingNormalize($displayHalaqoh) ? 'selected' : '' ?>><?= htmlspecialchars($displayHalaqoh) ?></option><?php endforeach; ?></select></label>
                <div class="plotting-gender-field"><span>Gender</span><div class="plotting-gender-tabs"><button type="submit" name="gender" value="" class="<?= $genderFilter === '' ? 'is-active' : '' ?>">Semua</button><button type="submit" name="gender" value="IK" class="<?= $genderFilter === 'IK' ? 'is-active' : '' ?>">Ikhwan</button><button type="submit" name="gender" value="AK" class="<?= $genderFilter === 'AK' ? 'is-active' : '' ?>">Akhwat</button></div></div>
            </form>
            <div class="plotting-category-row"><span>Filter Kategori Halaqoh</span><div class="plotting-category-chips"><?php $baseQuery=['page'=>'plotting','csv_a'=>$sourceA,'csv_b'=>$sourceB,'participant_threshold'=>$participantThreshold,'salary_threshold'=>$salaryThreshold,'q'=>$searchTerm,'gender'=>$genderFilter,'halaqoh'=>$halaqohFilter]; ?><a class="plotting-chip plotting-chip-red <?= $category === 'belum_ada' ? 'is-active' : '' ?>" href="<?= htmlspecialchars('index.php?' . http_build_query($baseQuery + ['category'=>'belum_ada'])) ?>"><i></i> Belum Ada Peserta</a><a class="plotting-chip plotting-chip-yellow <?= $category === 'peserta_dikit' ? 'is-active' : '' ?>" href="<?= htmlspecialchars('index.php?' . http_build_query($baseQuery + ['category'=>'peserta_dikit'])) ?>"><i></i> Peserta &lt; <?= $participantThreshold ?></a><a class="plotting-chip plotting-chip-green <?= $category === 'gaji_rendah' ? 'is-active' : '' ?>" href="<?= htmlspecialchars('index.php?' . http_build_query($baseQuery + ['category'=>'gaji_rendah'])) ?>"><i></i> Gaji &lt; <?= $formatRupiah($salaryThreshold) ?></a><a class="plotting-chip plotting-chip-reset" href="<?= htmlspecialchars('index.php?' . http_build_query($baseQuery + ['category'=>''])) ?>"><i class="fa-solid fa-xmark"></i> Reset Kategori</a></div></div>
            <div class="plotting-thresholds"><form method="get" id="plotting-threshold-form"><input type="hidden" name="page" value="plotting"><input type="hidden" name="csv_a" value="<?= htmlspecialchars($sourceA, ENT_QUOTES) ?>"><input type="hidden" name="csv_b" value="<?= htmlspecialchars($sourceB, ENT_QUOTES) ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>"><input type="hidden" name="gender" value="<?= htmlspecialchars($genderFilter, ENT_QUOTES) ?>"><input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqohFilter, ENT_QUOTES) ?>"><input type="hidden" name="category" value="<?= htmlspecialchars($category, ENT_QUOTES) ?>"><label>Peserta di bawah <input type="number" name="participant_threshold" value="<?= $participantThreshold ?>" min="0"></label><label>Gaji di bawah <input type="number" name="salary_threshold" value="<?= $salaryThreshold ?>" min="0" step="50000"></label><button class="plotting-apply-btn" type="submit">Terapkan batas</button></form></div>
        </section>

        <section class="plotting-results-section"><div class="plotting-results-head"><span>Hasil Perbandingan</span><h2><?= count($filteredComparison) ?> Halaqoh</h2><p><?= htmlspecialchars($csvShortLabel($sourceA ?: 'Batch sebelumnya')) ?> → <?= htmlspecialchars($csvShortLabel($sourceB ?: 'Batch sekarang')) ?></p></div>
            <?php if (!$filteredComparison): ?><div class="crm-workspace-empty"><div class="crm-workspace-empty-icon"><i class="fa-solid fa-filter-circle-xmark"></i></div><strong>Tidak ada halaqoh yang cocok</strong><span>Sesuaikan filter Batch Sekarang.</span></div>
            <?php else: ?><div class="plotting-halaqoh-grid"><div class="plotting-gender-column"><h3><span>♂</span> Halaqoh Ikhwan (IK)</h3><?php foreach ($ikRows as $item) echo plottingRenderCard($item, $sourceA, $sourceB, $csvShortLabel, $formatRupiah, $formatPercent, $showSalary); ?><?php if (!$ikRows): ?><div class="plotting-empty-column">Tidak ada halaqoh ikhwan.</div><?php endif; ?></div><div class="plotting-gender-column"><h3><span>♀</span> Halaqoh Akhwat (AK)</h3><?php foreach ($akRows as $item) echo plottingRenderCard($item, $sourceA, $sourceB, $csvShortLabel, $formatRupiah, $formatPercent, $showSalary); ?><?php if (!$akRows): ?><div class="plotting-empty-column">Tidak ada halaqoh akhwat.</div><?php endif; ?></div></div><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
