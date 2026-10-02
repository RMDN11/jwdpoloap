<?php
declare(strict_types=1);

require_once __DIR__ . '/reminder-csv.php';

/**
 * Retention source helpers.
 *
 * A source is either:
 * - csv:<safe CSV filename>
 * - payment:<bulan_pembayaran>
 *
 * CSV rows stay filesystem-only. Payment data is read-only from pembayaran.
 * The comparison identity is the normalized WhatsApp number.
 */

function crmRetentionSourceKey(string $type, string $value): string
{
    return $type . ':' . $value;
}

function crmRetentionParseSourceKey(string $source): array
{
    $source = trim($source);
    $pos = strpos($source, ':');
    if ($pos === false) {
        return ['', ''];
    }

    return [substr($source, 0, $pos), substr($source, $pos + 1)];
}

function crmRetentionNormalizeGroup(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtolower($value);
}

function crmRetentionDisplayGroup(string $value): string
{
    $value = trim($value);
    return $value !== '' ? $value : 'Tanpa AK';
}

/**
 * Keep exactly one record per normalized WhatsApp number.
 * Missing WhatsApp is never matched by name.
 */
function crmRetentionDeduplicate(array $records): array
{
    $unique = [];

    foreach ($records as $record) {
        $wa = crmReminderCsvNormalizeWa((string)($record['wa'] ?? ''));
        if ($wa === '' || isset($unique[$wa])) {
            continue;
        }

        $record['wa'] = $wa;
        $record['target_wa'] = crmReminderCsvNormalizeWa((string)($record['target_wa'] ?? $wa));
        if ($record['target_wa'] === '') {
            $record['target_wa'] = $wa;
        }
        $record['group'] = trim((string)($record['group'] ?? ''));
        $record['group_key'] = crmRetentionNormalizeGroup($record['group']);
        $record['name'] = trim((string)($record['name'] ?? '')) ?: 'Tanpa nama';
        $record['peserta_id'] = (int)($record['peserta_id'] ?? 0);

        $unique[$wa] = $record;
    }

    return $unique;
}

function crmRetentionLoadCsv(string $filename): array
{
    $rows = crmReminderCsvRead($filename);
    $records = [];

    foreach ($rows as $row) {
        if (crmReminderCsvNormalizeStatus((string)($row['status_siswa'] ?? '')) !== 'ON') {
            continue;
        }

        $wa = crmReminderCsvNormalizeWa((string)($row['whatsapp_wali'] ?? ''));
        if ($wa === '') {
            continue;
        }

        $records[] = [
            'wa' => $wa,
            'target_wa' => $wa,
            'name' => (string)($row['nama_murid'] ?? ''),
            'group' => (string)($row['kelas_grup'] ?? ''),
            'peserta_id' => 0,
        ];
    }

    return crmRetentionDeduplicate($records);
}

function crmRetentionLoadPayment(mysqli $conn, string $bulan): array
{
    $records = [];
    $stmt = $conn->prepare(
        "SELECT p.id AS peserta_id, p.nama_lengkap, p.nowa, p.halaqoh
         FROM pembayaran b
         INNER JOIN peserta p ON p.id = b.peserta_id
         WHERE b.bulan_pembayaran = ?
           AND p.nowa IS NOT NULL
           AND p.nowa <> ''
         ORDER BY p.id ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('Gagal menyiapkan sumber pembayaran.');
    }

    $stmt->bind_param('s', $bulan);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $wa = crmReminderCsvNormalizeWa((string)$row['nowa']);
        if ($wa === '') {
            continue;
        }

        $records[] = [
            'wa' => $wa,
            'target_wa' => $wa,
            'name' => (string)$row['nama_lengkap'],
            'group' => (string)$row['halaqoh'],
            'peserta_id' => (int)$row['peserta_id'],
        ];
    }

    $stmt->close();

    return crmRetentionDeduplicate($records);
}

function crmRetentionLoadSource(mysqli $conn, string $source): array
{
    [$type, $value] = crmRetentionParseSourceKey($source);

    if ($type === 'csv') {
        if (!crmReminderCsvSafeFilename($value)) {
            throw new RuntimeException('Sumber CSV retention tidak valid.');
        }

        return crmRetentionLoadCsv($value);
    }

    if ($type === 'payment') {
        if ($value === '') {
            throw new RuntimeException('Periode pembayaran belum dipilih.');
        }

        return crmRetentionLoadPayment($conn, $value);
    }

    throw new RuntimeException('Jenis sumber retention tidak dikenal.');
}

function crmRetentionCompare(array $previous, array $current): array
{
    $currentByWa = $current;
    $continued = [];
    $notContinued = [];
    $breakdown = [];

    foreach ($previous as $wa => $record) {
        $groupKey = (string)($record['group_key'] ?? crmRetentionNormalizeGroup((string)($record['group'] ?? '')));
        $groupLabel = crmRetentionDisplayGroup((string)($record['group'] ?? ''));

        if (!isset($breakdown[$groupKey])) {
            $breakdown[$groupKey] = [
                'group' => $groupLabel,
                'total' => 0,
                'continued' => 0,
                'not_continued' => 0,
                'rate' => 0.0,
            ];
        }

        $breakdown[$groupKey]['total']++;

        if (isset($currentByWa[$wa])) {
            $continued[] = $record + ['current' => $currentByWa[$wa]];
            $breakdown[$groupKey]['continued']++;
        } else {
            $notContinued[] = $record;
            $breakdown[$groupKey]['not_continued']++;
        }
    }

    foreach ($breakdown as &$item) {
        $item['rate'] = $item['total'] > 0
            ? ($item['continued'] / $item['total']) * 100
            : 0.0;
    }
    unset($item);

    uasort($breakdown, static function (array $a, array $b): int {
        return strcasecmp((string)$a['group'], (string)$b['group']);
    });

    $previousCount = count($previous);
    $currentCount = count($current);
    $continuedCount = count($continued);
    $notContinuedCount = count($notContinued);

    return [
        'previous_count' => $previousCount,
        'current_count' => $currentCount,
        'continued_count' => $continuedCount,
        'not_continued_count' => $notContinuedCount,
        'rate' => $previousCount > 0 ? ($continuedCount / $previousCount) * 100 : 0.0,
        'continued' => $continued,
        'not_continued' => $notContinued,
        'breakdown' => array_values($breakdown),
    ];
}
