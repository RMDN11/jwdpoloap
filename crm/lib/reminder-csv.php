<?php
declare(strict_types=1);

/**
 * File-only CSV source for reminder peserta.
 *
 * CSV data is intentionally NOT persisted in MySQL. The server filesystem is
 * the source of truth; peserta/pembayaran remain read-only master data.
 */

function crmReminderCsvDir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $dir = dirname(__DIR__, 2) . '/storage/reminder-csv';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder penyimpanan CSV tidak dapat dibuat.');
    }

    return $dir;
}

function crmReminderCsvNormalizeHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    return mb_strtolower(trim($value));
}

function crmReminderCsvNormalizeWa(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits !== '' && str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    }
    return $digits;
}

function crmReminderCsvNormalizeStatus(string $value): string
{
    return strtoupper(trim($value));
}

function crmReminderCsvRequiredHeaders(): array
{
    return [
        'id',
        'program',
        'periode / level',
        'kelas / grup',
        'tutor pengajar',
        'nama murid',
        'jenis kelamin',
        'nama wali',
        'whatsapp wali',
        'email wali',
        'status siswa',
        'jatah per minggu',
        'jatah per hari',
        'sesi selesai',
        'total sesi program',
        'progress (%)',
    ];
}

function crmReminderCsvParse(string $filePath, int $maxRows = 5000): array
{
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        throw new RuntimeException('File CSV tidak dapat dibaca.');
    }

    $header = fgetcsv($handle, null, ',', '"', '');
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('CSV kosong.');
    }

    $headers = array_map('crmReminderCsvNormalizeHeader', $header);
    $positions = [];
    foreach (crmReminderCsvRequiredHeaders() as $required) {
        $index = array_search($required, $headers, true);
        if ($index === false) {
            fclose($handle);
            throw new RuntimeException('Header CSV tidak sesuai template. Kolom yang hilang: ' . $required);
        }
        $positions[$required] = $index;
    }

    $rows = [];
    $seen = [];
    $duplicateCount = 0;
    $rowNo = 1;

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rowNo++;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;

        $get = static function (string $key) use ($row, $positions): string {
            return trim((string)($row[$positions[$key]] ?? ''));
        };

        $sourceId = $get('id');
        $name = $get('nama murid');
        $waRaw = $get('whatsapp wali');
        $normalizedWa = crmReminderCsvNormalizeWa($waRaw);
        $statusSiswa = crmReminderCsvNormalizeStatus($get('status siswa'));

        if ($name === '') continue;

        $duplicateKey = $sourceId !== ''
            ? 'id:' . $sourceId
            : ($normalizedWa !== '' ? 'wa:' . $normalizedWa : 'row:' . $rowNo);

        if (isset($seen[$duplicateKey])) {
            $duplicateCount++;
            continue;
        }
        $seen[$duplicateKey] = true;

        $rows[] = [
            'source_row' => $rowNo,
            'source_id' => $sourceId,
            'program' => $get('program'),
            'periode_level' => $get('periode / level'),
            'kelas_grup' => $get('kelas / grup'),
            'tutor_pengajar' => $get('tutor pengajar'),
            'nama_murid' => $name,
            'jenis_kelamin' => $get('jenis kelamin'),
            'nama_wali' => $get('nama wali'),
            'whatsapp_wali' => $waRaw,
            'email_wali' => $get('email wali'),
            'status_siswa' => $statusSiswa,
            'jatah_per_minggu' => $get('jatah per minggu'),
            'jatah_per_hari' => $get('jatah per hari'),
            'sesi_selesai' => $get('sesi selesai'),
            'total_sesi_program' => $get('total sesi program'),
            'progress' => $get('progress (%)'),
            'normalized_wa' => $normalizedWa,
        ];

        if (count($rows) > $maxRows) {
            fclose($handle);
            throw new RuntimeException('CSV melebihi batas ' . number_format($maxRows, 0, ',', '.') . ' peserta.');
        }
    }

    fclose($handle);

    if (!$rows) {
        throw new RuntimeException('Tidak ada baris peserta yang valid.');
    }

    return [
        'rows' => $rows,
        'row_count' => count($rows),
        'duplicate_count' => $duplicateCount,
    ];
}

function crmReminderCsvSafeFilename(string $filename): bool
{
    return preg_match('/^csv_[0-9]{8}_[0-9]{6}_[a-f0-9]{16}\.csv$/', $filename) === 1;
}

function crmReminderCsvPath(string $filename): string
{
    $filename = basename($filename);
    if (!crmReminderCsvSafeFilename($filename)) {
        throw new RuntimeException('Sumber CSV tidak valid.');
    }

    $dir = crmReminderCsvDir();
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    $realDir = realpath($dir);

    if ($realDir === false || !is_file($path)) {
        throw new RuntimeException('File CSV tidak ditemukan.');
    }

    $realPath = realpath($path);
    if ($realPath === false || dirname($realPath) !== $realDir) {
        throw new RuntimeException('Lokasi file CSV tidak valid.');
    }

    return $realPath;
}

function crmReminderCsvRead(string $filename): array
{
    $path = crmReminderCsvPath($filename);
    return crmReminderCsvParse($path)['rows'];
}

function crmReminderCsvList(): array
{
    $dir = crmReminderCsvDir();
    $items = [];

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metaPath) {
        $raw = file_get_contents($metaPath);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($meta) || empty($meta['file']) || !crmReminderCsvSafeFilename((string)$meta['file'])) {
            continue;
        }

        $csvPath = $dir . DIRECTORY_SEPARATOR . basename((string)$meta['file']);
        if (!is_file($csvPath)) continue;

        $meta['file'] = basename((string)$meta['file']);
        $items[] = $meta;
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b['imported_at'] ?? ''), (string)($a['imported_at'] ?? ''));
    });

    return array_slice($items, 0, 30);
}

function crmReminderCsvImportFile(string $tmpPath, string $originalFilename, string $label, mysqli $conn): array
{
    $parsed = crmReminderCsvParse($tmpPath);
    $dir = crmReminderCsvDir();

    $filename = 'csv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.csv';
    $destination = $dir . DIRECTORY_SEPARATOR . $filename;

    $stored = move_uploaded_file($tmpPath, $destination);

    // Fallback untuk hosting yang memindahkan temporary upload secara terbatas.
    if (!$stored) {
        $stored = @copy($tmpPath, $destination);
        if ($stored) {
            @unlink($tmpPath);
        }
    }

    if (!$stored || !is_file($destination)) {
        throw new RuntimeException('File CSV gagal disimpan ke storage/reminder-csv.');
    }

    try {
        $waMap = [];
        $result = $conn->query("SELECT id, nowa FROM peserta WHERE nowa IS NOT NULL AND nowa <> ''");
        if ($result) {
            while ($participant = $result->fetch_assoc()) {
                $normalized = crmReminderCsvNormalizeWa((string)$participant['nowa']);
                if ($normalized === '') continue;
                if (array_key_exists($normalized, $waMap)) {
                    $waMap[$normalized] = 0;
                } else {
                    $waMap[$normalized] = (int)$participant['id'];
                }
            }
        }

        $matched = 0;
        $unmatched = 0;
        $ambiguous = 0;

        foreach ($parsed['rows'] as $row) {
            if ($row['normalized_wa'] === '' || !array_key_exists($row['normalized_wa'], $waMap)) {
                $unmatched++;
                continue;
            }

            if ($waMap[$row['normalized_wa']] === 0) {
                $ambiguous++;
            } else {
                $matched++;
            }
        }

        $metadata = [
            'file' => $filename,
            'label' => $label,
            'source_filename' => basename($originalFilename),
            'row_count' => $parsed['row_count'],
            'matched_count' => $matched,
            'unmatched_count' => $unmatched + $ambiguous,
            'duplicate_count' => $parsed['duplicate_count'],
            'imported_at' => date('Y-m-d H:i:s'),
        ];

        $metaPath = $dir . DIRECTORY_SEPARATOR . pathinfo($filename, PATHINFO_FILENAME) . '.json';
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($metaPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Metadata CSV gagal disimpan.');
        }

        return $metadata;
    } catch (Throwable $e) {
        @unlink($destination);
        throw $e;
    }
}
