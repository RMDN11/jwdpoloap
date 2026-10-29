<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
crmSessionInit();

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logged_in']) && isset($conn) && $conn instanceof mysqli) {
    crmRememberSessionCheck($conn);
}

if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

// Auto migration
$checkCol = $conn->query("SHOW COLUMNS FROM peserta LIKE 'status_lanjut'");
if ($checkCol && $checkCol->num_rows === 0) {
    $conn->query("ALTER TABLE peserta ADD COLUMN status_lanjut VARCHAR(10) DEFAULT 'ya'");
}
$checkColAlasan = $conn->query("SHOW COLUMNS FROM peserta LIKE 'alasan_tidak_lanjut'");
if ($checkColAlasan && $checkColAlasan->num_rows === 0) {
    $conn->query("ALTER TABLE peserta ADD COLUMN alasan_tidak_lanjut TEXT");
}

$action = (string)($_GET['ajax_action'] ?? '');

// 1. Live Search Peserta
if ($action === 'search') {
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $halaqoh = trim((string)($_GET['halaqoh'] ?? ''));

    $sql = "SELECT id, nama_lengkap, nowa, halaqoh, COALESCE(status_lanjut, 'ya') AS status_lanjut, COALESCE(alasan_tidak_lanjut, '') AS alasan_tidak_lanjut FROM peserta WHERE 1=1";
    $params = [];
    $types = '';

    if ($keyword !== '') {
        $sql .= " AND (nama_lengkap LIKE ? OR nowa LIKE ?)";
        $searchParam = "%{$keyword}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'ss';
    }
    if ($halaqoh !== '') {
        $sql .= " AND halaqoh = ?";
        $params[] = $halaqoh;
        $types .= 's';
    }

    $sql .= " ORDER BY nama_lengkap ASC LIMIT 50";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// 2. Toggle Status (Ubah status 'ya' / 'tidak')
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status_lanjut = (string)($_POST['status_lanjut'] ?? 'ya');
    if (!in_array($status_lanjut, ['ya', 'tidak'], true)) {
        $status_lanjut = 'ya';
    }
    $alasan = trim((string)($_POST['alasan'] ?? ''));

    if ($status_lanjut === 'ya') {
        $stmt = $conn->prepare("UPDATE peserta SET status_lanjut = ?, alasan_tidak_lanjut = '' WHERE id = ?");
        $stmt->bind_param("si", $status_lanjut, $id);
    } else {
        $stmt = $conn->prepare("UPDATE peserta SET status_lanjut = ? WHERE id = ?");
        $stmt->bind_param("si", $status_lanjut, $id);
    }

    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'msg' => $ok ? 'Status berhasil diperbarui' : 'Gagal memperbarui status'
    ]);
    exit;
}

// 3. Ambil Daftar Peserta yang "Tidak Lanjut"
if ($action === 'get_list') {
    $sql = "SELECT id, nama_lengkap, nowa, halaqoh, COALESCE(alasan_tidak_lanjut, '') AS alasan_tidak_lanjut FROM peserta WHERE status_lanjut = 'tidak' ORDER BY nama_lengkap ASC";
    $result = $conn->query($sql);

    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// 4. Update Alasan Tidak Lanjut
if ($action === 'update_alasan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $alasan = trim((string)($_POST['alasan'] ?? ''));

    $stmt = $conn->prepare("UPDATE peserta SET alasan_tidak_lanjut = ? WHERE id = ?");
    $stmt->bind_param("si", $alasan, $id);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'msg' => $ok ? 'Alasan berhasil diperbarui' : 'Gagal memperbarui alasan'
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Aksi tidak dikenal']);
exit;
