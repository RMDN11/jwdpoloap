<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reminder-csv.php';
require_once __DIR__ . '/../lib/plotting.php';

/**
 * Plotting
 * Membandingkan snapshot CSV peserta dan/atau data Pembayaran (Tahfidz) antar batch.
 * Logic peserta, kuota, dan gaji mengikuti standar Jawwada.
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

// Payment batches from database
$paymentSources = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    try {
        $result = $conn->query(
            "SELECT bulan_pembayaran, MAX(id) AS last_id
             FROM pembayaran
             WHERE bulan_pembayaran IS NOT NULL
               AND bulan_pembayaran <> ''
             GROUP BY bulan_pembayaran
             ORDER BY last_id DESC"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $paymentSources[] = (string)$row['bulan_pembayaran'];
            }
        }
    } catch (Throwable $e) {
        error_log('CRM plotting payment source list failed: ' . $e->getMessage());
    }
}

// Available options list
$availableOptions = [];
foreach ($csvFiles as $file) {
    $meta = $csvSourceMeta[$file] ?? [];
    $label = trim((string)($meta['label'] ?? ''));
    $original = trim((string)($meta['source_filename'] ?? ''));
    $display = $label !== '' && $original !== '' && $label !== $original ? ($label . ' · ' . $original) : ($label !== '' ? $label : $file);
    $short = $label !== '' ? $label : ($original !== '' ? $original : $file);
    $availableOptions['csv:' . $file] = [
        'type' => 'csv',
        'value' => $file,
        'label' => 'CSV · ' . $display,
        'short_label' => $short,
    ];
}
foreach ($paymentSources as $item) {
    $availableOptions['payment:' . $item] = [
        'type' => 'payment',
        'value' => $item,
        'label' => 'Pembayaran · ' . $item,
        'short_label' => 'Pembayaran ' . $item,
    ];
}

$resolveSourceKey = static function (string $raw, array $availableOptions, array $csvFiles, array $paymentSources): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if (isset($availableOptions[$raw])) return $raw;

    if (isset($availableOptions['csv:' . $raw])) return 'csv:' . $raw;
    if (isset($availableOptions['payment:' . $raw])) return 'payment:' . $raw;

    if (in_array($raw, $csvFiles, true)) return 'csv:' . $raw;
    if (in_array($raw, $paymentSources, true)) return 'payment:' . $raw;

    $parsed = plottingParseSourceKey($raw);
    if ($parsed['type'] === 'csv' && in_array(basename($parsed['value']), $csvFiles, true)) {
        return 'csv:' . basename($parsed['value']);
    }
    if ($parsed['type'] === 'payment' && in_array($parsed['value'], $paymentSources, true)) {
        return 'payment:' . $parsed['value'];
    }

    return '';
};

$rawA = (string)($_GET['source_a'] ?? $_GET['csv_a'] ?? '');
$rawB = (string)($_GET['source_b'] ?? $_GET['csv_b'] ?? '');

$sourceA = $resolveSourceKey($rawA, $availableOptions, $csvFiles, $paymentSources);
$sourceB = $resolveSourceKey($rawB, $availableOptions, $csvFiles, $paymentSources);

$sourceLabel = static function (string $key) use ($availableOptions): string {
    if (isset($availableOptions[$key])) {
        return $availableOptions[$key]['label'];
    }
    $parsed = plottingParseSourceKey($key);
    if ($parsed['type'] === 'payment') return 'Pembayaran · ' . $parsed['value'];
    if ($parsed['type'] === 'csv') return 'CSV · ' . $parsed['value'];
    return $key !== '' ? $key : 'Pilih sumber...';
};

$sourceShortLabel = static function (string $key) use ($availableOptions): string {
    if (isset($availableOptions[$key])) {
        return $availableOptions[$key]['short_label'];
    }
    $parsed = plottingParseSourceKey($key);
    if ($parsed['type'] === 'payment') return 'Pembayaran ' . $parsed['value'];
    if ($parsed['type'] === 'csv') return $parsed['value'];
    return $key !== '' ? $key : 'Batch';
};

$participantThreshold = max(0, (int)($_GET['participant_threshold'] ?? 10));
$salaryThreshold = max(0, (int)($_GET['salary_threshold'] ?? 650000));
$categoryParam = $_GET['category'] ?? [];
$categories = is_array($categoryParam) ? $categoryParam : [$categoryParam];
$categories = array_values(array_intersect(['belum_ada', 'peserta_dikit', 'gaji_rendah'], array_map('strval', $categories)));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$genderFilter = trim((string)($_GET['gender'] ?? ''));
$halaqohFilter = trim((string)($_GET['halaqoh'] ?? ''));
$showSalary = ($_GET['show_salary'] ?? '1') !== '0';

$rawLoadedA = $sourceA !== '' ? plottingLoadSourceRows($sourceA, $csvDir, $conn ?? null) : [];
$rawLoadedB = $sourceB !== '' ? plottingLoadSourceRows($sourceB, $csvDir, $conn ?? null) : [];
[$dataA, $dataB] = plottingUnifyGroupKeys($rawLoadedA, $rawLoadedB);

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
        'kelas' => $b['kelas'] ?? $a['kelas'] ?? '',
        'jenis' => $b['jenis'] ?? $a['jenis'] ?? 'AK',
        'a' => $a, 'b' => $b,
        'retention' => plottingRetentionPercent($previousNames, $currentNames),
        'continued' => $continued, 'previous_total' => count($previousUnique),
    ];
}
$filteredComparison = array_values(array_filter($comparison, static fn(array $item): bool => plottingMatchesFilters($item, $categories, $participantThreshold, $salaryThreshold, $searchTerm, $genderFilter, $halaqohFilter)));
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
    <section class="crm-workspace-header"><div class="crm-workspace-header-main"><span class="crm-workspace-kicker">Data Comparison</span><h1>Plotting</h1><p>Bandingkan dua snapshot CSV atau batch Pembayaran (Tahfidz) untuk melihat retention dan kondisi halaqoh.</p></div></section>

    <section class="crm-workspace-card plotting-source-card">
        <div class="crm-workspace-card-head"><div class="crm-workspace-card-head-main"><span class="crm-workspace-card-kicker">Sumber Data</span><h2>Pilih batch yang dibandingkan</h2></div><span class="plotting-source-count"><?= (count($csvFiles) + count($paymentSources)) ?> sumber tersedia</span></div>
        <div class="crm-workspace-card-body">
            <?php if (!$csvFiles && !$paymentSources): ?><div class="crm-workspace-empty"><div class="crm-workspace-empty-icon"><i class="fa-solid fa-file-csv"></i></div><strong>Belum ada sumber data</strong><span>Upload CSV melalui workspace Reminder CSV atau pastikan data pembayaran di sistem Tahfidz tersedia.</span></div>
            <?php else: ?><form method="get" class="plotting-source-form"><input type="hidden" name="page" value="plotting"><label class="crm-workspace-field"><span>Batch sebelumnya</span><select name="csv_a"><option value="">Pilih Batch / CSV...</option><?php if ($csvFiles): ?><optgroup label="Sumber CSV"><?php foreach ($csvFiles as $file): $optKey = 'csv:' . $file; ?><option value="<?= htmlspecialchars($optKey, ENT_QUOTES) ?>" <?= $optKey === $sourceA ? 'selected' : '' ?>><?= htmlspecialchars($availableOptions[$optKey]['label'] ?? $file) ?></option><?php endforeach; ?></optgroup><?php endif; ?><?php if ($paymentSources): ?><optgroup label="Sumber Pembayaran (Tahfidz)"><?php foreach ($paymentSources as $item): $optKey = 'payment:' . $item; ?><option value="<?= htmlspecialchars($optKey, ENT_QUOTES) ?>" <?= $optKey === $sourceA ? 'selected' : '' ?>><?= htmlspecialchars($availableOptions[$optKey]['label'] ?? ('Pembayaran · ' . $item)) ?></option><?php endforeach; ?></optgroup><?php endif; ?></select></label><label class="crm-workspace-field"><span>Batch sekarang</span><select name="csv_b"><option value="">Pilih Batch / CSV...</option><?php if ($csvFiles): ?><optgroup label="Sumber CSV"><?php foreach ($csvFiles as $file): $optKey = 'csv:' . $file; ?><option value="<?= htmlspecialchars($optKey, ENT_QUOTES) ?>" <?= $optKey === $sourceB ? 'selected' : '' ?>><?= htmlspecialchars($availableOptions[$optKey]['label'] ?? $file) ?></option><?php endforeach; ?></optgroup><?php endif; ?><?php if ($paymentSources): ?><optgroup label="Sumber Pembayaran (Tahfidz)"><?php foreach ($paymentSources as $item): $optKey = 'payment:' . $item; ?><option value="<?= htmlspecialchars($optKey, ENT_QUOTES) ?>" <?= $optKey === $sourceB ? 'selected' : '' ?>><?= htmlspecialchars($availableOptions[$optKey]['label'] ?? ('Pembayaran · ' . $item)) ?></option><?php endforeach; ?></optgroup><?php endif; ?></select></label><button class="crm-workspace-primary" type="submit"><i class="fa-solid fa-code-compare"></i> Bandingkan</button></form><?php endif; ?>
        </div>
    </section>

    <?php if ($sourceA !== '' || $sourceB !== ''): ?>

        <section class="crm-workspace-card plotting-filter-card">
            <div class="plotting-filter-head"><div class="plotting-filter-title"><span class="plotting-filter-icon"><i class="fa-solid fa-filter"></i></span><h2>Filter Data</h2></div><a class="plotting-salary-toggle" href="<?= htmlspecialchars('index.php?' . http_build_query(['page'=>'plotting','csv_a'=>$sourceA,'csv_b'=>$sourceB,'participant_threshold'=>$participantThreshold,'salary_threshold'=>$salaryThreshold,'category'=>$categories,'q'=>$searchTerm,'gender'=>$genderFilter,'halaqoh'=>$halaqohFilter,'show_salary'=>$showSalary ? 0 : 1])) ?>"><i class="fa-solid <?= $showSalary ? 'fa-eye' : 'fa-eye-slash' ?>"></i> Gaji</a></div>
            <form method="get" class="plotting-filter-form"><input type="hidden" name="page" value="plotting"><input type="hidden" name="csv_a" value="<?= htmlspecialchars($sourceA, ENT_QUOTES) ?>"><input type="hidden" name="csv_b" value="<?= htmlspecialchars($sourceB, ENT_QUOTES) ?>"><input type="hidden" name="participant_threshold" value="<?= $participantThreshold ?>"><input type="hidden" name="salary_threshold" value="<?= $salaryThreshold ?>"><?php foreach ($categories as $selectedCategory): ?><input type="hidden" name="category[]" value="<?= htmlspecialchars($selectedCategory, ENT_QUOTES) ?>"><?php endforeach; ?>
                <label class="crm-workspace-field"><span>Cari Peserta / WA</span><input type="search" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>" placeholder="Ketik nama atau WA..."></label>
                <label class="crm-workspace-field"><span>Halaqoh</span><select name="halaqoh"><option value="">Semua</option><?php foreach ($halaqohOptions as $halaqoh): $displayHalaqoh=$dataB[$halaqoh]['nama_halaqoh'] ?? $dataA[$halaqoh]['nama_halaqoh'] ?? $halaqoh; ?><option value="<?= htmlspecialchars($displayHalaqoh, ENT_QUOTES) ?>" <?= (plottingNormalize($halaqohFilter) === plottingNormalize($displayHalaqoh) || plottingNormalize($halaqohFilter) === plottingNormalize((string)($dataB[$halaqoh]['kelas'] ?? $dataA[$halaqoh]['kelas'] ?? ''))) ? 'selected' : '' ?>><?= htmlspecialchars($displayHalaqoh) ?></option><?php endforeach; ?></select></label>
                <div class="plotting-gender-field"><span>Gender</span><div class="plotting-gender-tabs"><button type="submit" name="gender" value="" class="<?= $genderFilter === '' ? 'is-active' : '' ?>">Semua</button><button type="submit" name="gender" value="IK" class="<?= $genderFilter === 'IK' ? 'is-active' : '' ?>">Ikhwan</button><button type="submit" name="gender" value="AK" class="<?= $genderFilter === 'AK' ? 'is-active' : '' ?>">Akhwat</button></div></div>
            </form>
            <div class="plotting-category-row"><span>Filter Kategori Halaqoh <small>(bisa pilih lebih dari satu)</small></span><div class="plotting-category-chips"><?php $baseQuery=['page'=>'plotting','csv_a'=>$sourceA,'csv_b'=>$sourceB,'participant_threshold'=>$participantThreshold,'salary_threshold'=>$salaryThreshold,'q'=>$searchTerm,'gender'=>$genderFilter,'halaqoh'=>$halaqohFilter]; ?><?php foreach ($categories as $selectedCategory) $baseQuery['category'][]=$selectedCategory; ?><?php foreach (['belum_ada'=>'plotting-chip-red','peserta_dikit'=>'plotting-chip-yellow','gaji_rendah'=>'plotting-chip-green'] as $categoryKey => $categoryClass): $nextCategories=$categories; if (in_array($categoryKey,$nextCategories,true)) { $nextCategories=array_values(array_diff($nextCategories,[$categoryKey])); } else { $nextCategories[]=$categoryKey; } $query=$baseQuery; $query['category']=$nextCategories; ?><a class="plotting-chip <?= $categoryClass ?> <?= in_array($categoryKey, $categories, true) ? 'is-active' : '' ?>" href="<?= htmlspecialchars('index.php?' . http_build_query($query)) ?>"><i></i> <?= $categoryKey === 'belum_ada' ? 'Belum Ada Peserta' : ($categoryKey === 'peserta_dikit' ? 'Peserta &lt; ' . $participantThreshold : 'Gaji &lt; ' . $formatRupiah($salaryThreshold)) ?></a><?php endforeach; ?><?php $resetQuery=$baseQuery; $resetQuery['category']=[]; ?><a class="plotting-chip plotting-chip-reset" href="<?= htmlspecialchars('index.php?' . http_build_query($resetQuery)) ?>"><i class="fa-solid fa-xmark"></i> Reset Kategori</a></div></div>
            <div class="plotting-thresholds"><form method="get" id="plotting-threshold-form"><input type="hidden" name="page" value="plotting"><input type="hidden" name="csv_a" value="<?= htmlspecialchars($sourceA, ENT_QUOTES) ?>"><input type="hidden" name="csv_b" value="<?= htmlspecialchars($sourceB, ENT_QUOTES) ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>"><input type="hidden" name="gender" value="<?= htmlspecialchars($genderFilter, ENT_QUOTES) ?>"><input type="hidden" name="halaqoh" value="<?= htmlspecialchars($halaqohFilter, ENT_QUOTES) ?>"><?php foreach ($categories as $selectedCategory): ?><input type="hidden" name="category[]" value="<?= htmlspecialchars($selectedCategory, ENT_QUOTES) ?>"><?php endforeach; ?><label>Peserta di bawah <input type="number" name="participant_threshold" value="<?= $participantThreshold ?>" min="0"></label><label>Gaji di bawah <input type="number" name="salary_threshold" value="<?= $salaryThreshold ?>" min="0" step="50000"></label><button class="plotting-apply-btn" type="submit">Terapkan batas</button></form></div>
        </section>

        <section class="plotting-results-section"><div class="plotting-results-head"><span>Hasil Perbandingan</span><h2><?= count($filteredComparison) ?> Halaqoh</h2><p><?= htmlspecialchars($sourceShortLabel($sourceA ?: 'Batch sebelumnya')) ?> → <?= htmlspecialchars($sourceShortLabel($sourceB ?: 'Batch sekarang')) ?></p></div>
            <?php if (!$filteredComparison): ?><div class="crm-workspace-empty"><div class="crm-workspace-empty-icon"><i class="fa-solid fa-filter-circle-xmark"></i></div><strong>Tidak ada halaqoh yang cocok</strong><span>Sesuaikan filter Batch Sekarang.</span></div>
            <?php else: ?><div class="plotting-halaqoh-grid"><div class="plotting-gender-column"><h3><span>♂</span> Halaqoh Ikhwan (IK)</h3><?php foreach ($ikRows as $item) echo plottingRenderCard($item, $sourceA, $sourceB, $sourceShortLabel, $formatRupiah, $formatPercent, $showSalary); ?><?php if (!$ikRows): ?><div class="plotting-empty-column">Tidak ada halaqoh ikhwan.</div><?php endif; ?></div><div class="plotting-gender-column"><h3><span>♀</span> Halaqoh Akhwat (AK)</h3><?php foreach ($akRows as $item) echo plottingRenderCard($item, $sourceA, $sourceB, $sourceShortLabel, $formatRupiah, $formatPercent, $showSalary); ?><?php if (!$akRows): ?><div class="plotting-empty-column">Tidak ada halaqoh akhwat.</div><?php endif; ?></div></div><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
