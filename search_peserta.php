<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection unavailable']);
    exit;
}

$conn->set_charset('utf8mb4');

$term = trim((string)($_GET['term'] ?? ''));
$halaqoh = trim((string)($_GET['halaqoh'] ?? ''));

if (mb_strlen($term) < 2 || $halaqoh === '') {
    echo json_encode([]);
    exit;
}

$searchTerm = '%' . $term . '%';
$stmt = $conn->prepare("
    SELECT id, nama_lengkap, nowa 
    FROM peserta 
    WHERE nama_lengkap LIKE ? AND halaqoh = ? 
    ORDER BY nama_lengkap ASC 
    LIMIT 25
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    exit;
}

$stmt->bind_param('ss', $searchTerm, $halaqoh);
$stmt->execute();
$result = $stmt->get_result();
$pesertaList = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($pesertaList);
