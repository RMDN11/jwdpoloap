<?php
declare(strict_types=1);

function crmProspectNormalizeNumber(string $number): string {
    $number = preg_replace('/\D+/', '', $number) ?? '';
    if ($number !== '' && str_starts_with($number, '0')) {
        $number = '62' . substr($number, 1);
    }
    return $number;
}

function crmIsGenericInquiry(?string $message): bool {
    $m = strtolower(trim((string)$message));
    if ($m === '') return false;

    $hasQuestionCue = preg_match('/\b(mau\s+tanya|mau\s+tnya|mau\s+nanya|afwan|minta\s+info|ingin\s+tanya|boleh\s+tanya)\b/i', $m);
    $hasStrongIntent = preg_match('/\b(mau\s+ikut|ingin\s+ikut|minat|daftar|pendaftaran|bergabung|gabung|ikut\s+kelas|ikut\s+program)\b/i', $m);

    return (bool)$hasQuestionCue && !$hasStrongIntent;
}

function crmGetProspectTriggers(mysqli $conn): array {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $fallback = [
        ['id'=>0, 'keyword'=>'bingung mau pilih program', 'category'=>'Bingung'],
        ['id'=>0, 'keyword'=>'saya bingung', 'category'=>'Bingung'],
        ['id'=>0, 'keyword'=>'ziyadah pemula', 'category'=>'Ziyadah Pemula'],
        ['id'=>0, 'keyword'=>'ziyadah lanjutan', 'category'=>'Ziyadah Lanjutan'],
        ['id'=>0, 'keyword'=>"muroja'ah", 'category'=>"Muroja'ah"],
        ['id'=>0, 'keyword'=>'murojaah', 'category'=>"Muroja'ah"],
        ['id'=>0, 'keyword'=>'tahfidz cilik', 'category'=>'Tahfidz Cilik'],
        ['id'=>0, 'keyword'=>'intensif', 'category'=>'Mode Intensif'],
        ['id'=>0, 'keyword'=>'normal', 'category'=>'Mode Normal'],
        ['id'=>0, 'keyword'=>'kak, mau', 'category'=>'Ekspresi Minat'],
        ['id'=>0, 'keyword'=>'mau ikut', 'category'=>'Ekspresi Minat'],
        ['id'=>0, 'keyword'=>'minat', 'category'=>'Ekspresi Minat'],
    ];

    $result = $conn->query("SELECT id,keyword,category FROM crm_prospect_triggers WHERE active = 1 ORDER BY category ASC, id ASC");
    if (!$result) {
        $cache = $fallback;
        return $cache;
    }

    $triggers = [];
    while ($row = $result->fetch_assoc()) {
        $triggers[] = [
            'id' => (int)$row['id'],
            'keyword' => (string)$row['keyword'],
            'category' => (string)$row['category'],
        ];
    }

    $cache = $triggers ?: $fallback;
    return $cache;
}

function crmProspectClassifyMessage(?string $message, ?mysqli $conn = null): string {
    if (empty($message)) return 'Data CSV/Manual';

    $m = strtolower(trim($message));
    if (crmIsGenericInquiry($m)) return 'Lainnya';

    $fallback = [
        ['keyword'=>'bingung mau pilih program','category'=>'Bingung'],
        ['keyword'=>'saya bingung','category'=>'Bingung'],
        ['keyword'=>'ziyadah pemula','category'=>'Ziyadah Pemula'],
        ['keyword'=>'ziyadah lanjutan','category'=>'Ziyadah Lanjutan'],
        ['keyword'=>"muroja'ah",'category'=>"Muroja'ah"],
        ['keyword'=>'murojaah','category'=>"Muroja'ah"],
        ['keyword'=>'tahfidz cilik','category'=>'Tahfidz Cilik'],
        ['keyword'=>'intensif','category'=>'Mode Intensif'],
        ['keyword'=>'normal','category'=>'Mode Normal'],
        ['keyword'=>'kak, mau','category'=>'Ekspresi Minat'],
        ['keyword'=>'mau ikut','category'=>'Ekspresi Minat'],
        ['keyword'=>'minat','category'=>'Ekspresi Minat'],
    ];
    $triggers = $conn instanceof mysqli ? crmGetProspectTriggers($conn) : $fallback;

    foreach ($triggers as $trigger) {
        $keyword = strtolower(trim((string)($trigger['keyword'] ?? '')));
        $category = trim((string)($trigger['category'] ?? ''));
        if ($keyword !== '' && $category !== '' && strpos($m, $keyword) !== false) {
            return $category;
        }
    }

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

    $rows = $conn->query("SELECT nowa FROM log_wa WHERE is_form_sent = 1 OR LOWER(message) LIKE '%penempatan halaqoh%' OR LOWER(message) LIKE '%silahkan isi link form berikut%' OR LOWER(message) LIKE '%silakan isi link form berikut%'");
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

function crmIsEligibleProspect(array $row, array $disqualified, array $blocked, ?mysqli $conn = null): bool {
    $rawNumber = (string)($row['nowa'] ?? '');
    $number = crmProspectNormalizeNumber($rawNumber);

    if ($number === '' || str_contains($rawNumber, '@g') || str_contains($rawNumber, '-')) return false;
    if (isset($disqualified[$number]) || isset($blocked[$number])) return false;
    if (crmIsGenericInquiry((string)($row['message'] ?? ''))) return false;

    $classification = crmProspectClassifyMessage((string)($row['message'] ?? ''), $conn);
    return $classification !== 'Lainnya' && $classification !== 'Data CSV/Manual';
}
