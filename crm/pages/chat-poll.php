<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$lastId = max(0, (int)($_GET['last_id'] ?? 0));
$stmt = $conn->prepare("SELECT COUNT(id) AS total FROM log_wa WHERE id > ? AND message IS NOT NULL AND message != '' AND message != 'Data CSV/Manual'");
$stmt->bind_param('i', $lastId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

echo json_encode([
    'status' => 'success',
    'new_count' => (int)($row['total'] ?? 0),
]);
