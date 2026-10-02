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
 * The comparison identity is the normalized participant name.
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
 * Participant identity for retention is the name, not WhatsApp.
 * Only case and repeated whitespace are normalized.
 */
function crmRetentionNormalizeName(string $value): string
{
    $value = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $value);
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtolower($value);
}

function crmRetentionHasTutor(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    $normalized = crmRetentionNormalizeName($value);
    return !in_array($normalized, ['-', 'antrean', 'antrian'], true);
}

/**
 * Keep exactly one record per normalized participant name.
 * WhatsApp remains contact data for follow-up, not the retention identity.
 */
function crmRetentionDeduplicate(array $records): array
{
    $unique = [];

    foreach ($records as $record) {
        $name = trim((string)($record['name'] ?? ''));
        $nameKey = crmRetentionNormalizeName($name);
        if ($nameKey === '' || isset($unique[$nameKey])) {
            continue;
        }

        $record['name'] = $name !== '' ? $name : 'Tanpa nama';
        $record['name_key'] = $nameKey;
        $record['wa'] = crmReminderCsvNormalizeWa((string)($record['wa'] ?? ''));
        $record['target_wa'] = crmReminderCsvNormalizeWa((string)($record['target_wa'] ?? $record['wa']));
        $record['group'] = trim((string)($record['group'] ?? ''));
        $record['group_key'] = crmRetentionNormalizeGroup($record['group']);
        $record['peserta_id'] = (int)($record['peserta_id'] ?? 0);

        $unique[$nameKey] = $record;
    }

    return $unique;
}

function crmRetentionLoadCsv(string $filename): array
{
    // Retention identity is name-only, so the CSV parser must not drop
    // different participants who happen to share the same WhatsApp number.
    $rows = crmReminderCsvRead($filename, false);
    $records = [];

    foreach ($rows as $row) {
        // Peserta antrean / belum memiliki tutor tidak masuk cohort retention.
        if (!crmRetentionHasTutor((string)($row['tutor_pengajar'] ?? ''))) {
            continue;
        }

        if (crmReminderCsvNormalizeStatus((string)($row['status_siswa'] ?? '')) !== 'ON') {
            continue;
        }

        $records[] = [
            'wa' => (string)($row['whatsapp_wali'] ?? ''),
            'target_wa' => (string)($row['whatsapp_wali'] ?? ''),
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
         WHERE (b.bukti_transfer != 'manual_admin' OR b.bukti_transfer IS NULL)
           AND b.bulan_pembayaran = ?
         ORDER BY p.id ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('Gagal menyiapkan sumber pembayaran.');
    }

    $stmt->bind_param('s', $bulan);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $records[] = [
            'wa' => (string)$row['nowa'],
            'target_wa' => (string)$row['nowa'],
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
    $currentByName = $current;
    $continued = [];
    $notContinued = [];
    $breakdown = [];

    foreach ($previous as $nameKey => $record) {
        $nameKey = (string)($record['name_key'] ?? crmRetentionNormalizeName((string)($record['name'] ?? '')));
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

        if ($nameKey !== '' && isset($currentByName[$nameKey])) {
            $continued[] = $record + ['current' => $currentByName[$nameKey]];
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

function crmRetentionLoadHistory(mysqli $conn, array $records): array
{
    $numbers = [];
    foreach ($records as $record) {
        $wa = crmReminderCsvNormalizeWa((string)($record['target_wa'] ?? $record['wa'] ?? ''));
        if ($wa !== '') {
            $numbers[$wa] = true;
        }
    }

    if (!$numbers) {
        return [];
    }

    $history = [];
    $numbers = array_keys($numbers);

    foreach (array_chunk($numbers, 250) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        $stmt = $conn->prepare(
            "SELECT nowa, created_at, message
             FROM log_wa
             WHERE message LIKE '[REMINDER] [TERKIRIM]%'
               AND nowa IN ({$placeholders})
             ORDER BY created_at DESC"
        );

        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan riwayat reminder retention.');
        }

        $params = $chunk;
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $wa = crmReminderCsvNormalizeWa((string)$row['nowa']);
            if ($wa === '') {
                continue;
            }

            if (!isset($history[$wa])) {
                $history[$wa] = [
                    'count' => 0,
                    'last_at' => null,
                    'today' => false,
                    'last_template' => '',
                ];
            }

            $history[$wa]['count']++;
            if ($history[$wa]['last_at'] === null) {
                $history[$wa]['last_at'] = (string)$row['created_at'];
            }

            if ($history[$wa]['last_template'] === '') {
                $message = (string)($row['message'] ?? '');
                if (preg_match('/^\[REMINDER\] \[TERKIRIM\] \[TEMPLATE\] (.*?) \| /', $message, $matches)) {
                    $history[$wa]['last_template'] = trim((string)$matches[1]);
                }
            }

            if (date('Y-m-d', strtotime((string)$row['created_at'])) === date('Y-m-d')) {
                $history[$wa]['today'] = true;
            }
        }

        $stmt->close();
    }

    return $history;
}
