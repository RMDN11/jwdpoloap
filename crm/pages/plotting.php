<?php
declare(strict_types=1);

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

function plottingNormalize(string $value): string
{
    $value = preg_replace('/\x{FEFF}/u', '', $value) ?? $value;
    $value = trim($value);
    return strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function plottingCsvRows(string $filename, string $csvDir): array
{
    $path = $csvDir . '/' . basename($filename);
    if (!is_file($path)) return [];

    $handle = fopen($path, 'r');
    if ($handle === false) return [];

    $rows = [];
    $header = fgetcsv($handle, 0, ',');
    if ($header === false) {
        fclose($handle);
        return [];
    }

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

        // Sama seperti reqnew.php: tanpa tutor / antrean tidak masuk
        // perhitungan halaqoh dan gaji.
        $kelasLower = plottingNormalize($kelas);
        $tutorLower = plottingNormalize($tutor);
        if (
            $kelasLower === 'antrean (belum ada kelas)' ||
            $kelasLower === 'antrean' ||
            $tutorLower === 'tanpa tutor' ||
            $tutorLower === '-' ||
            $tutor === ''
        ) {
            continue;
        }

        if ($kelas === '') $kelas = $tutor;

        $periodeLower = plottingNormalize($periode);
        $harga = 65000;
        $kuota = 1;
        $ketStatus = 'Normal';
        $isTc = false;
        $isNonTc = true;

        if (
            str_contains($periodeLower, 'tahfidz cilik intensif') ||
            str_contains($periodeLower, 'tc intensif')
        ) {
            $harga = 170000;
            $kuota = 2;
            $ketStatus = 'TC Intensif';
            $isTc = true;
            $isNonTc = false;
        } elseif (str_contains($periodeLower, 'super intensif')) {
            $harga = 260000;
            $kuota = 4;
            $ketStatus = 'Super Intensif';
        } elseif (str_contains($periodeLower, 'intensif plus')) {
            $harga = 195000;
            $kuota = 3;
            $ketStatus = 'Intensif Plus';
        } elseif (str_contains($periodeLower, 'intensif')) {
            $harga = 130000;
            $kuota = 2;
            $ketStatus = 'Intensif';
        } elseif (
            str_contains($periodeLower, 'tahfidz cilik') ||
            str_contains($periodeLower, 'cilik')
        ) {
            $harga = 85000;
            $kuota = 1;
            $ketStatus = 'TC';
            $isTc = true;
            $isNonTc = false;
        } elseif (
            str_contains($periodeLower, 'normal') ||
            str_contains($periodeLower, 'reguler')
        ) {
            $harga = 65000;
            $kuota = 1;
            $ketStatus = 'Normal';
        } elseif ($periode !== '') {
            $ketStatus = $periode;
        }

        $jenis = in_array(plottingNormalize($jk), ['l', 'laki-laki', 'ikhwan'], true) ? 'IK' : 'AK';
        $groupKey = plottingNormalize($tutor);

        if (!isset($rows[$groupKey])) {
            $rows[$groupKey] = [
                'nama_halaqoh' => $tutor,
                'jenis' => $jenis,
                'total_peserta' => 0,
                'total_gaji' => 0,
                'total_baru' => 0,
                'total_tc' => 0,
                'total_non_tc' => 0,
                'list_peserta' => [],
            ];
        }

        $rows[$groupKey]['total_peserta'] += $kuota;
        $rows[$groupKey]['total_gaji'] += $harga;
        $rows[$groupKey]['total_baru']++;
        $rows[$groupKey]['total_tc'] += $isTc ? $kuota : 0;
        $rows[$groupKey]['total_non_tc'] += $isNonTc ? $kuota : 0;
        $rows[$groupKey]['list_peserta'][] = [
            'nama' => $nama,
            'nowa' => $wa,
            'program' => $program,
            'kuota' => $kuota,
            'gaji' => $harga,
            'ket_status' => $ketStatus,
        ];
    }

    fclose($handle);
    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
    return $rows;
}

function plottingMatchesCategory(array $item, string $category, int $participantThreshold, int $salaryThreshold): bool
{
    return match ($category) {
        'peserta_dikit' => $item['total_peserta'] < $participantThreshold,
        'gaji_rendah' => $item['total_gaji'] < $salaryThreshold,
        'keduanya' => $item['total_peserta'] < $participantThreshold && $item['total_gaji'] < $salaryThreshold,
        default => true,
    };
}

function plottingIndex(array $groups): array
{
    $index = [];
    foreach ($groups as $key => $group) {
        $index[plottingNormalize((string)$key)] = $group;
    }
    return $index;
}

$dataA = $sourceA !== '' ? plottingCsvRows($sourceA, $csvDir) : [];
$dataB = $sourceB !== '' ? plottingCsvRows($sourceB, $csvDir) : [];
$indexA = plottingIndex($dataA);
$indexB = plottingIndex($dataB);

$groupKeys = array_values(array_unique(array_merge(array_keys($indexA), array_keys($indexB))));
sort($groupKeys, SORT_NATURAL | SORT_FLAG_CASE);

$comparison = [];
foreach ($groupKeys as $key) {
    $a = $indexA[$key] ?? null;
    $b = $indexB[$key] ?? null;
    $current = $b ?? $a;

    $comparison[] = [
        'key' => $key,
        'name' => $b['nama_halaqoh'] ?? $a['nama_halaqoh'] ?? $key,
        'jenis' => $b['jenis'] ?? $a['jenis'] ?? 'AK',
        'a' => $a,
        'b' => $b,
        'delta_peserta' => ($b['total_peserta'] ?? 0) - ($a['total_peserta'] ?? 0),
        'delta_gaji' => ($b['total_gaji'] ?? 0) - ($a['total_gaji'] ?? 0),
        'filter_item' => $current,
    ];
}

$filteredComparison = array_values(array_filter($comparison, static function (array $item) use ($category, $participantThreshold, $salaryThreshold): bool {
    return plottingMatchesCategory($item['filter_item'], $category, $participantThreshold, $salaryThreshold);
}));

$totalGroupsA = count($dataA);
$totalGroupsB = count($dataB);
$totalQuotaA = array_sum(array_column($dataA, 'total_peserta'));
$totalQuotaB = array_sum(array_column($dataB, 'total_peserta'));
$totalSalaryA = array_sum(array_column($dataA, 'total_gaji'));
$totalSalaryB = array_sum(array_column($dataB, 'total_gaji'));

$formatRupiah = static fn(int $value): string => 'Rp ' . number_format($value, 0, ',', '.');
?>

<div class="crm-workspace-page crm-plotting-page">
    <div class="crm-workspace-back">
        <a href="index.php?page=more" title="Kembali ke More" aria-label="Kembali ke More"><i class="fa-solid fa-arrow-left"></i></a>
    </div>

    <section class="crm-workspace-header">
        <div class="crm-workspace-header-main">
            <span class="crm-workspace-kicker">Data Comparison</span>
            <h1>Plotting</h1>
            <p>Bandingkan dua snapshot CSV untuk melihat perubahan peserta, halaqoh, dan gaji.</p>
        </div>
    </section>

    <section class="crm-workspace-card plotting-source-card">
        <div class="crm-workspace-card-head">
            <div class="crm-workspace-card-head-main">
                <span class="crm-workspace-card-kicker">CSV Source</span>
                <h2>Pilih batch yang dibandingkan</h2>
            </div>
            <span class="plotting-source-count"><?= count($csvFiles) ?> file tersedia</span>
        </div>
        <div class="crm-workspace-card-body">
            <?php if (!$csvFiles): ?>
                <div class="crm-workspace-empty">
                    <div class="crm-workspace-empty-icon"><i class="fa-solid fa-file-csv"></i></div>
                    <strong>Belum ada CSV</strong>
                    <span>Upload CSV melalui workspace Reminder CSV terlebih dahulu.</span>
                </div>
            <?php else: ?>
                <form method="get" class="plotting-source-form">
                    <input type="hidden" name="page" value="plotting">
                    <label class="crm-workspace-field">
                        <span>Batch sebelumnya</span>
                        <select name="csv_a">
                            <option value="">Pilih CSV...</option>
                            <?php foreach ($csvFiles as $file): ?>
                                <option value="<?= htmlspecialchars($file, ENT_QUOTES) ?>" <?= $file === $sourceA ? 'selected' : '' ?>><?= htmlspecialchars($csvLabel($file)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-workspace-field">
                        <span>Batch sekarang</span>
                        <select name="csv_b">
                            <option value="">Pilih CSV...</option>
                            <?php foreach ($csvFiles as $file): ?>
                                <option value="<?= htmlspecialchars($file, ENT_QUOTES) ?>" <?= $file === $sourceB ? 'selected' : '' ?>><?= htmlspecialchars($csvLabel($file)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="crm-workspace-primary" type="submit"><i class="fa-solid fa-code-compare"></i> Bandingkan</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($sourceA !== '' || $sourceB !== ''): ?>
        <section class="plotting-summary-grid">
            <div class="plotting-summary-card">
                <span><?= htmlspecialchars($sourceA !== '' ? $csvShortLabel($sourceA) : 'Batch sebelumnya') ?></span>
                <strong><?= $totalQuotaA ?></strong>
                <small><?= $totalGroupsA ?> halaqoh · <?= $formatRupiah($totalSalaryA) ?></small>
            </div>
            <div class="plotting-summary-card plotting-summary-card-current">
                <span><?= htmlspecialchars($sourceB !== '' ? $csvShortLabel($sourceB) : 'Batch sekarang') ?></span>
                <strong><?= $totalQuotaB ?></strong>
                <small><?= $totalGroupsB ?> halaqoh · <?= $formatRupiah($totalSalaryB) ?></small>
            </div>
            <div class="plotting-summary-card plotting-summary-card-delta">
                <span>Perubahan</span>
                <strong><?= $totalQuotaB - $totalQuotaA >= 0 ? '+' : '' ?><?= $totalQuotaB - $totalQuotaA ?></strong>
                <small><?= $formatRupiah($totalSalaryB - $totalSalaryA) ?></small>
            </div>
        </section>

        <section class="crm-workspace-card plotting-filter-card">
            <div class="crm-workspace-card-head">
                <div class="crm-workspace-card-head-main">
                    <span class="crm-workspace-card-kicker">Filter Plotting</span>
                    <h2>Cari halaqoh yang perlu diperhatikan</h2>
                </div>
            </div>
            <div class="crm-workspace-card-body">
                <form method="get" class="plotting-filter-form">
                    <input type="hidden" name="page" value="plotting">
                    <input type="hidden" name="csv_a" value="<?= htmlspecialchars($sourceA, ENT_QUOTES) ?>">
                    <input type="hidden" name="csv_b" value="<?= htmlspecialchars($sourceB, ENT_QUOTES) ?>">
                    <label class="crm-workspace-field">
                        <span>Peserta di bawah</span>
                        <input type="number" name="participant_threshold" min="0" value="<?= $participantThreshold ?>">
                    </label>
                    <label class="crm-workspace-field">
                        <span>Gaji di bawah</span>
                        <input type="number" name="salary_threshold" min="0" step="50000" value="<?= $salaryThreshold ?>">
                    </label>
                    <label class="crm-workspace-field">
                        <span>Kategori</span>
                        <select name="category">
                            <option value="">Semua halaqoh</option>
                            <option value="peserta_dikit" <?= $category === 'peserta_dikit' ? 'selected' : '' ?>>Peserta &lt; <?= $participantThreshold ?></option>
                            <option value="gaji_rendah" <?= $category === 'gaji_rendah' ? 'selected' : '' ?>>Gaji &lt; <?= $formatRupiah($salaryThreshold) ?></option>
                            <option value="keduanya" <?= $category === 'keduanya' ? 'selected' : '' ?>>Peserta &lt; <?= $participantThreshold ?> + Gaji &lt; <?= $formatRupiah($salaryThreshold) ?></option>
                        </select>
                    </label>
                    <button class="crm-workspace-primary" type="submit"><i class="fa-solid fa-filter"></i> Terapkan</button>
                </form>
            </div>
        </section>

        <section class="crm-workspace-card plotting-table-card">
            <div class="crm-workspace-card-head">
                <div class="crm-workspace-card-head-main">
                    <span class="crm-workspace-card-kicker">Halaqoh</span>
                    <h2><?= count($filteredComparison) ?> hasil perbandingan</h2>
                </div>
            </div>

            <?php if (!$filteredComparison): ?>
                <div class="crm-workspace-empty">
                    <div class="crm-workspace-empty-icon"><i class="fa-solid fa-filter-circle-xmark"></i></div>
                    <strong>Tidak ada halaqoh yang cocok</strong>
                    <span>Naikkan batas filter atau pilih kategori lain.</span>
                </div>
            <?php else: ?>
                <div class="plotting-table-wrap">
                    <table class="plotting-table">
                        <thead>
                            <tr>
                                <th>Halaqoh</th>
                                <th><?= htmlspecialchars($sourceA !== '' ? $csvShortLabel($sourceA) : 'Batch 1') ?></th>
                                <th><?= htmlspecialchars($sourceB !== '' ? $csvShortLabel($sourceB) : 'Batch 2') ?></th>
                                <th>Perubahan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($filteredComparison as $item): ?>
                            <?php
                            $a = $item['a'];
                            $b = $item['b'];
                            $deltaClass = $item['delta_peserta'] < 0 ? 'is-down' : ($item['delta_peserta'] > 0 ? 'is-up' : '');
                            $filterItem = $item['filter_item'];
                            ?>
                            <tr>
                                <td>
                                    <details class="plotting-row-details">
                                        <summary>
                                            <span>
                                                <strong><?= htmlspecialchars($item['name']) ?></strong>
                                                <small><?= htmlspecialchars($item['jenis']) ?></small>
                                            </span>
                                            <i class="fa-solid fa-chevron-down"></i>
                                        </summary>
                                        <div class="plotting-detail-grid">
                                            <div>
                                                <b><?= htmlspecialchars($sourceA ?: 'Batch 1') ?></b>
                                                <span><?= $a ? count($a['list_peserta']) . ' nama' : 'Tidak ada' ?></span>
                                                <?php if ($a): ?>
                                                    <ul>
                                                        <?php foreach ($a['list_peserta'] as $p): ?><li><?= htmlspecialchars($p['nama']) ?></li><?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <b><?= htmlspecialchars($sourceB ?: 'Batch 2') ?></b>
                                                <span><?= $b ? count($b['list_peserta']) . ' nama' : 'Tidak ada' ?></span>
                                                <?php if ($b): ?>
                                                    <ul>
                                                        <?php foreach ($b['list_peserta'] as $p): ?><li><?= htmlspecialchars($p['nama']) ?></li><?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </details>
                                </td>
                                <td>
                                    <strong><?= $a['total_peserta'] ?? 0 ?></strong>
                                    <small><?= $formatRupiah($a['total_gaji'] ?? 0) ?></small>
                                </td>
                                <td>
                                    <strong><?= $b['total_peserta'] ?? 0 ?></strong>
                                    <small><?= $formatRupiah($b['total_gaji'] ?? 0) ?></small>
                                </td>
                                <td>
                                    <span class="plotting-delta <?= $deltaClass ?>">
                                        <?= $item['delta_peserta'] >= 0 ? '+' : '' ?><?= $item['delta_peserta'] ?>
                                    </span>
                                    <small><?= $formatRupiah($item['delta_gaji']) ?></small>
                                    <?php if ($filterItem): ?>
                                        <div class="plotting-flags">
                                            <?php if ($filterItem['total_peserta'] < $participantThreshold): ?><span>Peserta &lt; <?= $participantThreshold ?></span><?php endif; ?>
                                            <?php if ($filterItem['total_gaji'] < $salaryThreshold): ?><span>Gaji &lt; <?= $formatRupiah($salaryThreshold) ?></span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
