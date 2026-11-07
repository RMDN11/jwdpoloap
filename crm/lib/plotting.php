<?php
declare(strict_types=1);

/**
 * Shared helpers for the Plotting workspace.
 * CSV rows remain filesystem-only; these helpers only calculate derived values.
 */

function plottingNormalize(string $value): string
{
    $value = preg_replace('/\x{FEFF}/u', '', $value) ?? $value;
    $value = trim($value);
    return strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function plottingRetentionPercent(array $previousNames, array $currentNames): float
{
    $previous = [];
    foreach ($previousNames as $name) {
        $key = plottingNormalize((string)$name);
        if ($key !== '') {
            $previous[$key] = true;
        }
    }

    if (!$previous) {
        return 0.0;
    }

    $current = [];
    foreach ($currentNames as $name) {
        $key = plottingNormalize((string)$name);
        if ($key !== '') {
            $current[$key] = true;
        }
    }

    $continued = count(array_intersect_key($previous, $current));
    return round(($continued / count($previous)) * 100, 2);
}

function plottingCurrentMatchesCategory(array $current, string $category, int $participantThreshold, int $salaryThreshold): bool
{
    $participants = (int)($current['total_peserta'] ?? 0);
    $salary = (int)($current['total_gaji'] ?? 0);
    return match ($category) {
        'belum_ada' => $participants === 0,
        'peserta_dikit' => $participants < $participantThreshold,
        'gaji_rendah' => $salary < $salaryThreshold,
        'keduanya' => $participants < $participantThreshold && $salary < $salaryThreshold,
        default => true,
    };
}

function plottingCurrentMatchesCategories(array $current, array $categories, int $participantThreshold, int $salaryThreshold): bool
{
    foreach ($categories as $category) {
        if (!plottingCurrentMatchesCategory($current, (string)$category, $participantThreshold, $salaryThreshold)) {
            return false;
        }
    }
    return true;
}

function plottingParseSourceKey(string $key): array
{
    $key = trim($key);
    if ($key === '') {
        return ['type' => '', 'value' => ''];
    }

    if (str_starts_with($key, 'payment:')) {
        return ['type' => 'payment', 'value' => substr($key, 8)];
    }

    if (str_starts_with($key, 'csv:')) {
        return ['type' => 'csv', 'value' => substr($key, 4)];
    }

    if (str_ends_with(strtolower($key), '.csv') || !str_contains($key, ':')) {
        return ['type' => 'csv', 'value' => $key];
    }

    $pos = strpos($key, ':');
    return [substr($key, 0, $pos), substr($key, $pos + 1)];
}

function plottingDetectCsvColumns(array $headerRow): array
{
    $normHeaders = array_map('plottingNormalize', $headerRow);
    $map = [];

    foreach ($normHeaders as $idx => $h) {
        if ($h === '') continue;
        if (!isset($map['program']) && str_contains($h, 'program')) {
            $map['program'] = $idx;
        }
        if (!isset($map['periode']) && (str_contains($h, 'periode') || str_contains($h, 'level') || str_contains($h, 'paket'))) {
            $map['periode'] = $idx;
        }
        if (!isset($map['kelas']) && (str_contains($h, 'kelas') || str_contains($h, 'grup') || str_contains($h, 'halaqoh'))) {
            $map['kelas'] = $idx;
        }
        if (!isset($map['tutor']) && (str_contains($h, 'tutor') || str_contains($h, 'pengajar') || str_contains($h, 'pengampu') || str_contains($h, 'ustadz'))) {
            $map['tutor'] = $idx;
        }
        if (!isset($map['nama']) && (str_contains($h, 'murid') || str_contains($h, 'santri') || str_contains($h, 'peserta') || $h === 'nama' || str_starts_with($h, 'nama lengkap'))) {
            $map['nama'] = $idx;
        }
        if (!isset($map['jk']) && (str_contains($h, 'kelamin') || str_contains($h, 'gender') || $h === 'jk')) {
            $map['jk'] = $idx;
        }
        if (!isset($map['wa']) && (str_contains($h, 'whatsapp') || (bool)preg_match('/\bwa\b/', $h) || str_contains($h, 'telepon') || str_contains($h, 'no hp'))) {
            $map['wa'] = $idx;
        }
        if (!isset($map['status']) && str_contains($h, 'status')) {
            $map['status'] = $idx;
        }
    }

    $hasPasswordCol = false;
    foreach ($normHeaders as $h) {
        if (str_contains($h, 'password')) {
            $hasPasswordCol = true;
            break;
        }
    }

    return [
        'program' => $map['program'] ?? 0,
        'periode' => $map['periode'] ?? 1,
        'kelas' => $map['kelas'] ?? 2,
        'tutor' => $map['tutor'] ?? 3,
        'nama' => $map['nama'] ?? 4,
        'jk' => $map['jk'] ?? 5,
        'wa' => $map['wa'] ?? 7,
        'status' => $map['status'] ?? ($hasPasswordCol || count($headerRow) > 15 ? 10 : 9),
    ];
}

function plottingCsvRows(string $filename, string $csvDir): array
{
    $cleanFilename = basename($filename);
    $path = $csvDir . '/' . $cleanFilename;
    if (!is_file($path)) {
        if (is_file($filename)) {
            $path = $filename;
        } else {
            return [];
        }
    }

    $handle = fopen($path, 'r');
    if ($handle === false) return [];

    $firstRow = fgetcsv($handle, 0, ',', '"', '');
    if ($firstRow === false) {
        fclose($handle);
        return [];
    }

    $colMap = plottingDetectCsvColumns($firstRow);

    $firstProgram = plottingNormalize((string)($firstRow[$colMap['program']] ?? ''));
    $firstTutor = plottingNormalize((string)($firstRow[$colMap['tutor']] ?? ''));
    $hasHeader = str_contains($firstProgram, 'program') || str_contains($firstTutor, 'tutor') || str_contains($firstTutor, 'pengajar');

    $rows = [];
    $dataRows = [];
    if (!$hasHeader) {
        $dataRows[] = $firstRow;
    }

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $dataRows[] = $row;
    }
    fclose($handle);

    $colProgram = $colMap['program'];
    $colPeriode = $colMap['periode'];
    $colKelas = $colMap['kelas'];
    $colTutor = $colMap['tutor'];
    $colNama = $colMap['nama'];
    $colJk = $colMap['jk'];
    $colWa = $colMap['wa'];
    $colStatus = $colMap['status'];

    foreach ($dataRows as $data) {
        if (count($data) < 5) continue;

        $status = plottingNormalize((string)($data[$colStatus] ?? ''));
        $isActive = in_array($status, ['aktif (on)', 'on', 'aktif'], true)
            || (str_contains($status, 'on') && !str_contains($status, 'off'));
        if (!$isActive) continue;

        $program = trim((string)($data[$colProgram] ?? ''));
        $periode = trim((string)($data[$colPeriode] ?? ''));
        $kelas = trim((string)($data[$colKelas] ?? ''));
        $tutor = trim((string)($data[$colTutor] ?? ''));
        $nama = trim((string)($data[$colNama] ?? ''));
        $jk = trim((string)($data[$colJk] ?? ''));
        $wa = str_replace(['="', '"', "'"], '', trim((string)($data[$colWa] ?? '')));

        if ($nama === '') continue;

        $kelasLower = plottingNormalize($kelas);
        $tutorLower = plottingNormalize($tutor);
        if ($kelasLower === 'antrean (belum ada kelas)' || $kelasLower === 'antrean' || $tutorLower === 'tanpa tutor' || $tutorLower === '-' || $tutor === '') {
            continue;
        }

        $periodeLower = plottingNormalize($periode);
        $harga = 65000;
        $kuota = 1;
        $ketStatus = 'Normal';
        $isTc = false;
        $isNonTc = true;

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

        $isAkClass = str_starts_with(strtoupper($kelas), 'AK');
        $isIkClass = str_starts_with(strtoupper($kelas), 'IK');
        if ($isAkClass) {
            $jenis = 'AK';
        } elseif ($isIkClass) {
            $jenis = 'IK';
        } else {
            $isIk = in_array(plottingNormalize($jk), ['l', 'laki-laki', 'ikhwan', 'laki'], true);
            $jenis = $isIk ? 'IK' : 'AK';
        }

        $groupKey = plottingNormalize($tutor);
        $displayName = ($kelas !== '' && plottingNormalize($kelas) !== $groupKey)
            ? ($tutor . ' (' . $kelas . ')')
            : $tutor;

        if (!isset($rows[$groupKey])) {
            $rows[$groupKey] = [
                'nama_halaqoh' => $displayName,
                'tutor' => $tutor,
                'kelas' => $kelas,
                'jenis' => $jenis,
                'total_peserta' => 0,
                'total_gaji' => 0,
                'total_tc' => 0,
                'total_non_tc' => 0,
                'list_peserta' => [],
            ];
        }

        $rows[$groupKey]['total_peserta'] += $kuota;
        $rows[$groupKey]['total_gaji'] += $harga;
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

    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
    return $rows;
}

function plottingPaymentRows(?mysqli $conn, string $bulan): array
{
    if (!$conn || $conn->connect_errno || trim($bulan) === '') {
        return [];
    }

    $tutorByHalaqoh = [];
    $halaqohByTutor = [];
    $pengampuResult = @$conn->query("SELECT id, nama, halaqoh FROM pengampu WHERE halaqoh IS NOT NULL AND halaqoh <> ''");
    if ($pengampuResult) {
        while ($pRow = $pengampuResult->fetch_assoc()) {
            $hName = trim((string)$pRow['halaqoh']);
            $tName = trim((string)$pRow['nama']);
            if ($hName !== '' && $tName !== '') {
                $tutorByHalaqoh[plottingNormalize($hName)] = $tName;
                $halaqohByTutor[plottingNormalize($tName)] = $hName;
            }
        }
    }

    $stmt = $conn->prepare(
        "SELECT p.id AS peserta_id, p.nama_lengkap, p.nowa, p.halaqoh
         FROM pembayaran b
         INNER JOIN peserta p ON p.id = b.peserta_id
         WHERE (b.bukti_transfer != 'manual_admin' OR b.bukti_transfer IS NULL)
           AND b.bulan_pembayaran = ?
         ORDER BY p.halaqoh, p.nama_lengkap"
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $bulan);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $nama = trim((string)($row['nama_lengkap'] ?? ''));
        $wa = trim((string)($row['nowa'] ?? ''));
        $halaqoh = trim((string)($row['halaqoh'] ?? ''));

        if ($nama === '' || $halaqoh === '' || $halaqoh === '-' || plottingNormalize($halaqoh) === 'antrean') {
            continue;
        }

        $hKey = plottingNormalize($halaqoh);
        $tutor = $tutorByHalaqoh[$hKey] ?? $halaqoh;
        $groupKey = plottingNormalize($tutor);

        $isIk = str_starts_with(strtoupper($halaqoh), 'IK');
        $jenis = $isIk ? 'IK' : 'AK';

        $isTc = str_contains($hKey, 'tc') || str_contains($hKey, 'cilik');
        $harga = $isTc ? 85000 : 65000;
        $kuota = 1;
        $ketStatus = $isTc ? 'TC' : 'Normal';

        $displayName = ($tutor !== $halaqoh) ? ($tutor . ' (' . $halaqoh . ')') : $tutor;

        if (!isset($rows[$groupKey])) {
            $rows[$groupKey] = [
                'nama_halaqoh' => $displayName,
                'tutor' => $tutor,
                'kelas' => $halaqoh,
                'jenis' => $jenis,
                'total_peserta' => 0,
                'total_gaji' => 0,
                'total_tc' => 0,
                'total_non_tc' => 0,
                'list_peserta' => [],
            ];
        }

        $rows[$groupKey]['total_peserta'] += $kuota;
        $rows[$groupKey]['total_gaji'] += $harga;
        $rows[$groupKey]['total_tc'] += $isTc ? $kuota : 0;
        $rows[$groupKey]['total_non_tc'] += !$isTc ? $kuota : 0;
        $rows[$groupKey]['list_peserta'][] = [
            'nama' => $nama,
            'nowa' => $wa,
            'program' => 'Tahfidz Private Online',
            'kuota' => $kuota,
            'gaji' => $harga,
            'ket_status' => $ketStatus,
        ];
    }

    $stmt->close();
    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
    return $rows;
}

function plottingLoadSourceRows(string $sourceKey, string $csvDir, ?mysqli $conn = null): array
{
    $parsed = plottingParseSourceKey($sourceKey);
    $type = $parsed['type'];
    $val = $parsed['value'];

    if ($type === 'payment') {
        return plottingPaymentRows($conn, $val);
    }

    if ($type === 'csv' || $type === '') {
        return plottingCsvRows($val, $csvDir);
    }

    return [];
}

function plottingUnifyGroupKeys(array $dataA, array $dataB): array
{
    $kelasToKey = [];
    foreach ([$dataA, $dataB] as $dataset) {
        foreach ($dataset as $key => $item) {
            $kelas = trim((string)($item['kelas'] ?? ''));
            $tutor = trim((string)($item['tutor'] ?? ''));
            if ($kelas !== '') {
                $kNorm = plottingNormalize($kelas);
                if (!isset($kelasToKey[$kNorm]) || (plottingNormalize($tutor) !== $kNorm)) {
                    $kelasToKey[$kNorm] = $key;
                }
            }
        }
    }

    $remap = static function (array $data) use ($kelasToKey): array {
        $out = [];
        foreach ($data as $key => $item) {
            $kelas = trim((string)($item['kelas'] ?? ''));
            $kNorm = $kelas !== '' ? plottingNormalize($kelas) : '';
            $targetKey = ($kNorm !== '' && isset($kelasToKey[$kNorm])) ? $kelasToKey[$kNorm] : $key;

            if (isset($out[$targetKey])) {
                $out[$targetKey]['total_peserta'] += (int)($item['total_peserta'] ?? 0);
                $out[$targetKey]['total_gaji'] += (int)($item['total_gaji'] ?? 0);
                $out[$targetKey]['total_tc'] += (int)($item['total_tc'] ?? 0);
                $out[$targetKey]['total_non_tc'] += (int)($item['total_non_tc'] ?? 0);
                $out[$targetKey]['list_peserta'] = array_merge($out[$targetKey]['list_peserta'] ?? [], $item['list_peserta'] ?? []);
            } else {
                $out[$targetKey] = $item;
            }
        }
        return $out;
    };

    return [$remap($dataA), $remap($dataB)];
}

function plottingMatchesFilters(array $item, array $categories, int $participantThreshold, int $salaryThreshold, string $searchTerm, string $genderFilter, string $halaqohFilter): bool
{
    $current = $item['b'] ?? ['total_peserta' => 0, 'total_gaji' => 0, 'jenis' => $item['jenis'], 'list_peserta' => []];
    if (!plottingCurrentMatchesCategories($current, $categories, $participantThreshold, $salaryThreshold)) return false;
    if ($genderFilter !== '' && $current['jenis'] !== $genderFilter) return false;
    if ($halaqohFilter !== '' && plottingNormalize($item['name']) !== plottingNormalize($halaqohFilter) && plottingNormalize((string)($item['kelas'] ?? '')) !== plottingNormalize($halaqohFilter)) return false;
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

function plottingRenderCard(array $item, string $sourceA, string $sourceB, callable $sourceShortLabel, callable $formatRupiah, callable $formatPercent, bool $showSalary): string
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
                <span class="plotting-card-metrics"><b class="retention <?= $retentionClass ?>"><?= $formatPercent($retention) ?></b><b class="tc">TC: <?= (int)$current['total_tc'] ?></b><b class="non">Non: <?= (int)$current['total_non_tc'] ?></b><b class="total">Total: <?= (int)$current['total_peserta'] ?></b></span>
                <i class="fa-solid fa-chevron-down"></i>
            </summary>
            <div class="plotting-card-detail">
                <div class="plotting-retention-hero <?= $retentionClass ?>"><span>Retention</span><strong><?= $formatPercent($retention) ?></strong><small><?= (int)$item['continued'] ?> dari <?= (int)$item['previous_total'] ?> peserta batch sebelumnya lanjut</small></div>
                <div class="plotting-batch-comparison">
                    <div><b><?= htmlspecialchars($sourceA !== '' ? $sourceShortLabel($sourceA) : 'Batch sebelumnya') ?></b><strong><?= $a ? (int)$a['total_peserta'] : 0 ?> peserta</strong><?php if ($showSalary): ?><small><?= $formatRupiah((int)($a['total_gaji'] ?? 0)) ?></small><?php endif; ?></div>
                    <div><b><?= htmlspecialchars($sourceB !== '' ? $sourceShortLabel($sourceB) : 'Batch sekarang') ?></b><strong><?= (int)$current['total_peserta'] ?> peserta</strong><?php if ($showSalary): ?><small><?= $formatRupiah((int)$current['total_gaji']) ?></small><?php endif; ?></div>
                </div>
                <div class="plotting-name-columns">
                    <div><span><?= htmlspecialchars($sourceA !== '' ? $sourceShortLabel($sourceA) : 'Batch sebelumnya') ?></span><ul><?php foreach (($a['list_peserta'] ?? []) as $person): ?><li><?= htmlspecialchars($person['nama']) ?></li><?php endforeach; ?><?php if (!$a): ?><li class="muted">Tidak ada</li><?php endif; ?></ul></div>
                    <div><span><?= htmlspecialchars($sourceB !== '' ? $sourceShortLabel($sourceB) : 'Batch sekarang') ?></span><ul><?php foreach (($b['list_peserta'] ?? []) as $person): ?><li><?= htmlspecialchars($person['nama']) ?></li><?php endforeach; ?><?php if (!$b): ?><li class="muted">Tidak ada</li><?php endif; ?></ul></div>
                </div>
            </div>
        </details>
    </article>
    <?php return (string)ob_get_clean();
}
