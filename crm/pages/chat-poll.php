<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/prospect.php';
header('Content-Type: application/json; charset=utf-8');

$lastId = max(0, (int)($_GET['last_id'] ?? 0));
$disqualified = crmGetDisqualifiedNumbers($conn);
$blocked = crmGetBlockedNumbers($conn);

$stmt = $conn->prepare("SELECT id,nama,nowa,message,created_at FROM log_wa WHERE id > ? AND message IS NOT NULL AND message != '' AND message != 'Data CSV/Manual' ORDER BY id ASC LIMIT 300");
$stmt->bind_param('i', $lastId);
$stmt->execute();
$result = $stmt->get_result();

$newCount = 0;
while ($row = $result->fetch_assoc()) {
    if (crmIsEligibleProspect($row, $disqualified, $blocked, $conn)) {
        $newCount++;
    }
}

echo json_encode([
    'status' => 'success',
    'new_count' => $newCount,
]);
