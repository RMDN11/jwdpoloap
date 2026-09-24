<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$lastId = max(0, (int)($_GET['last_id'] ?? 0));
require_once __DIR__ . '/../config/prospect.php';
$disqualified = crmGetDisqualifiedNumbers($conn);
$blocked = crmGetBlockedNumbers($conn);
$stmt = $conn->prepare("SELECT id,nowa,message FROM log_wa WHERE id > ? AND message IS NOT NULL AND message != '' AND message != 'Data CSV/Manual'");
$stmt->bind_param('i', $lastId);
$stmt->execute();
$result = $stmt->get_result();
$count = 0;
while ($row = $result->fetch_assoc()) if (crmIsEligibleProspect($row, $disqualified, $blocked)) $count++;

echo json_encode(['status'=>'success','new_count'=>$count]);
