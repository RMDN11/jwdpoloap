<?php
declare(strict_types=1);

function crmProspectNormalizeNumber(string $number): string {
    $number = preg_replace('/\D+/', '', $number) ?? '';
    if ($number !== '' && str_starts_with($number, '0')) {
        $number = '62' . substr($number, 1);
    }
    return $number;
}

function crmProspectClassifyMessage(?string $message): string {
    if (empty($message)) return 'Data CSV/Manual';

    $m = strtolower($message);

    if (strpos($m, 'bingung mau pilih program') !== false || strpos($m, 'saya bingung') !== false) return 'Bingung';
    if (strpos($m, 'ziyadah pemula') !== false) return 'Ziyadah Pemula';
    if (strpos($m, 'ziyadah lanjutan') !== false) return 'Ziyadah Lanjutan';
    if (strpos($m, "muroja'ah") !== false || strpos($m, 'murojaah') !== false) return "Muroja'ah";
    if (strpos($m, 'tahfidz cilik') !== false) return 'Tahfidz Cilik';
    if (strpos($m, 'intensif') !== false) return 'Mode Intensif';
    if (strpos($m, 'normal') !== false) return 'Mode Normal';
    if (strpos($m, 'kak, mau') !== false || strpos($m, 'mau ikut') !== false || strpos($m, 'minat') !== false) return 'Ekspresi Minat';
    if (strpos($m, 'input manual') !== false || strpos($m, 'csv') !== false) return 'Data CSV/Manual';

    return 'Lainnya';
}

function crmGetDisqualifiedNumbers(mysqli $conn): array {
    $disqualified = ['6288223053149' => true];

    foreach (['peserta', 'calon_peserta', 'pengampu', 'pengajar'] as $table) {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $exists = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
        if (!$exists || $exists->num_rows === 0) continue;

        $rows = $conn->query("SELECT nowa FROM {$safeTable} WHERE nowa IS NOT NULL AND nowa != ''");
        if (!$rows) continue;

        while ($row = $rows->fetch_assoc()) {
            $number = crmProspectNormalizeNumber((string)$row['nowa']);
            if ($number !== '') $disqualified[$number] = true;
        }
    }

    $rows = $conn->query("SELECT nowa FROM log_wa WHERE is_form_sent = 1 OR message LIKE '%penempatan halaqoh%'");
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            $number = crmProspectNormalizeNumber((string)$row['nowa']);
            if ($number !== '') $disqualified[$number] = true;
        }
    }

    return $disqualified;
}

function crmGetBlockedNumbers(mysqli $conn): array {
    $blocked = [];
    $rows = $conn->query("SELECT nowa FROM blocked_peserta");
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            $number = crmProspectNormalizeNumber((string)$row['nowa']);
            if ($number !== '') $blocked[$number] = true;
        }
    }
    return $blocked;
}

function crmIsEligibleProspect(array $row, array $disqualified, array $blocked): bool {
    $rawNumber = (string)($row['nowa'] ?? '');
    $number = crmProspectNormalizeNumber($rawNumber);

    if ($number === '' || str_contains($rawNumber, '@g') || str_contains($rawNumber, '-')) return false;
    if (isset($disqualified[$number]) || isset($blocked[$number])) return false;

    $classification = crmProspectClassifyMessage((string)($row['message'] ?? ''));
    return $classification !== 'Lainnya' && $classification !== 'Data CSV/Manual';
}


function crmFindEligibleProspectByNumber(mysqli $conn, string $contactId, array $disqualified, array $blocked): ?array {
    $contactId = trim($contactId);
    $normalized = crmProspectNormalizeNumber($contactId);
    if ($contactId === '' || $normalized === '') return null;

    $stmt = $conn->prepare("SELECT id,nama,nowa,message,created_at,last_followup_at,last_template_name,template_history FROM log_wa WHERE nowa = ? OR nowa = ? ORDER BY id DESC LIMIT 100");
    if (!$stmt) return null;

    $stmt->bind_param('ss', $contactId, $normalized);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (crmIsEligibleProspect($row, $disqualified, $blocked, $conn)) {
            $stmt->close();
            return $row;
        }
    }

    $stmt->close();
    return null;
}
