<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$keyword = trim((string)($_POST['keyword'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));

if ($keyword === '' || $category === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Trigger dan kategori wajib diisi.'];
    header('Location: ../index.php?page=chat');
    exit;
}

if (mb_strlen($keyword) < 2 || mb_strlen($category) < 2) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Trigger dan kategori terlalu pendek.'];
    header('Location: ../index.php?page=chat');
    exit;
}

if (in_array(strtolower($category), ['lainnya', 'data csv/manual'], true)) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kategori tersebut tidak dapat dipakai sebagai trigger prospek.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$stmt = $conn->prepare("INSERT INTO crm_prospect_triggers (keyword, category, active) VALUES (?, ?, 1)");
if (!$stmt) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan trigger.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$stmt->bind_param('ss', $keyword, $category);
if ($stmt->execute()) {
    $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Trigger berhasil ditambahkan.'];
} elseif ($stmt->errno === 1062) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Trigger tersebut sudah ada.'];
} else {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Trigger gagal ditambahkan.'];
}
$stmt->close();

header('Location: ../index.php?page=chat');
exit;
