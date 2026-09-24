<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/prospect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$lastId = max(0, (int)($_GET['last_id'] ?? 0));
$disqualified = crmGetDisqualifiedNumbers($conn);
$blocked = crmGetBlockedNumbers($conn);

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
    LIMIT 1000");
$stmt->bind_param('i', $lastId);
$stmt->execute();
$result = $stmt->get_result();

$newContacts = [];
$eligibleHistoryCache = [];

while ($row = $result->fetch_assoc()) {
    $number = crmProspectNormalizeNumber((string)$row['nowa']);
    if ($number === '') continue;
    $newContacts[$number][] = $row;
}

foreach ($newContacts as $number => $rows) {
    $isEligible = false;

    foreach ($rows as $row) {
        if (crmIsEligibleProspect($row, $disqualified, $blocked, $conn)) {
            $isEligible = true;
            break;
        }
    }

    /*
     * Existing prospects can reply with a generic question. That message
     * is not enough to create a new prospect, but it is still a new chat
     * event for an already-qualified contact.
     */
    if (!$isEligible && !isset($eligibleHistoryCache[$number])) {
        $eligibleHistoryCache[$number] = crmFindEligibleProspectByNumber($conn, $number, $disqualified, $blocked) !== null;
    }
    if (!$isEligible) $isEligible = $eligibleHistoryCache[$number] ?? false;

    if (!$isEligible) continue;
}

$newCount = count(array_filter($newContacts, static function (array $rows) use ($disqualified, $blocked, $conn): bool {
    $number = '';
    foreach ($rows as $row) {
        $number = crmProspectNormalizeNumber((string)$row['nowa']);
        if ($number !== '') break;
    }
    if ($number === '') return false;

    foreach ($rows as $row) {
        if (crmIsEligibleProspect($row, $disqualified, $blocked, $conn)) return true;
    }

    return crmFindEligibleProspectByNumber($conn, $number, $disqualified, $blocked) !== null;
}));

echo json_encode([
    'status' => 'success',
    'new_count' => $newCount,
    'max_id' => $maxId,
]);
