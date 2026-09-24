<?php
require_once 'config.php';

header('Content-Type: application/json');

$term = $_GET['term'] ?? '';
$halaqoh = $_GET['halaqoh'] ?? ''; // Ambil halaqoh dari parameter GET

if (strlen($term) < 2 || empty($halaqoh)) {
    echo json_encode([]);
    exit;
}

$searchTerm = '%' . $term . '%';
// Tambahkan kondisi WHERE untuk halaqoh
$stmt = $conn->prepare("SELECT id, nama_lengkap, nowa FROM peserta WHERE nama_lengkap LIKE ? AND halaqoh = ? LIMIT 10");
$stmt->bind_param("ss", $searchTerm, $halaqoh);
$stmt->execute();
$result = $stmt->get_result();
$pesertaList = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($pesertaList);
?>