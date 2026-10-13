<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/prospect.php';
require_once __DIR__ . '/../config/chat-routing.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$lastId = max(0, (int)($_GET['last_id'] ?? 0));

// Cache daftar tabel direktori sekali per request; jangan membaca seluruh isi
// tabel peserta/pengajar dan seluruh log_wa setiap 10 detik hanya untuk polling badge.
$directoryTables = [];
foreach (['peserta', 'calon_peserta', 'pengampu', 'pengajar'] as $table) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $exists = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    if ($exists && $exists->num_rows > 0) $directoryTables[] = $safeTable;
}

function crmPollNumberVariants(string $number): array {
    $normalized = crmProspectNormalizeNumber($number);
    if ($normalized === '') return [];
    return array_values(array_unique([$normalized, '0' . substr($normalized, 2), '+' . $normalized]));
}

function crmPollIsBlocked(mysqli $conn, array $variants): bool {
    if (!$variants) return false;
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $stmt = $conn->prepare("SELECT 1 FROM blocked_peserta WHERE nowa IN ($placeholders) LIMIT 1");
    if (!$stmt) return false;
    $types = str_repeat('s', count($variants));
    $stmt->bind_param($types, ...$variants);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

function crmPollIsDisqualified(mysqli $conn, array $variants, array $directoryTables): bool {
    if (!$variants) return true;
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    foreach ($directoryTables as $table) {
        $stmt = $conn->prepare("SELECT 1 FROM {$table} WHERE nowa IN ($placeholders) LIMIT 1");
        if (!$stmt) continue;
        $types = str_repeat('s', count($variants));
        $stmt->bind_param($types, ...$variants);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
    }

    $stmt = $conn->prepare("SELECT 1 FROM log_wa WHERE nowa IN ($placeholders) AND (is_form_sent = 1 OR LOWER(message) LIKE '%penempatan halaqoh%' OR LOWER(message) LIKE '%silahkan isi link form berikut%' OR LOWER(message) LIKE '%silakan isi link form berikut%') LIMIT 1");
    if (!$stmt) return false;
    $types = str_repeat('s', count($variants));
    $stmt->bind_param($types, ...$variants);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

$maxId = $lastId;
$maxResult = $conn->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM log_wa");
if ($maxResult && ($maxRow = $maxResult->fetch_assoc())) {
    $maxId = (int)$maxRow['max_id'];
}

$stmt = $conn->prepare("SELECT id,nama,nowa,message,created_at,last_followup_at
    FROM log_wa
    WHERE id > ?
      AND message IS NOT NULL
      AND message != ''
      AND message != 'Data CSV/Manual'
    ORDER BY id ASC
    LIMIT 200");
$stmt->bind_param('i', $lastId);
$stmt->execute();
$result = $stmt->get_result();

$newContacts = [];
while ($row = $result->fetch_assoc()) {
    $number = crmProspectNormalizeNumber((string)$row['nowa']);
    if ($number === '' || crmChatRoutingIsInternalNumber((string)$row['nowa'])) continue;
    $newContacts[$number][] = $row;
}

$eligibleNewNumbers = [];
foreach ($newContacts as $number => $rows) {
    $variants = crmPollNumberVariants((string)$rows[0]['nowa']);
    $isDisqualified = crmPollIsDisqualified($conn, $variants, $directoryTables);
    $isBlocked = crmPollIsBlocked($conn, $variants);
    $disqualified = $isDisqualified ? [$number => true] : [];
    $blocked = $isBlocked ? [$number => true] : [];

    $isEligible = false;
    foreach ($rows as $row) {
        if (crmIsEligibleProspect($row, $disqualified, $blocked, $conn)) {
            $isEligible = true;
            break;
        }
    }

    // Generic questions are allowed as new activity only for an already-qualified contact.
    if (!$isEligible && !$isDisqualified && !$isBlocked) {
        $isEligible = crmFindEligibleProspectByNumber($conn, (string)$rows[0]['nowa'], $disqualified, $blocked) !== null;
    }

    if ($isEligible) $eligibleNewNumbers[$number] = true;
}

$newCount = count($eligibleNewNumbers);

echo json_encode([
    'status' => 'success',
    'new_count' => $newCount,
    'max_id' => $maxId,
]);
