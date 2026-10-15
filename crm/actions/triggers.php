<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}
$action = (string)($_POST['action'] ?? '');
$message = 'Aksi trigger tidak valid.';
$type = 'error';
if ($action === 'add') {
    $keyword = trim((string)($_POST['keyword'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    if ($keyword !== '' && mb_strlen($keyword) <= 120 && $category !== '' && mb_strlen($category) <= 100) {
        $stmt = $conn->prepare('INSERT INTO crm_prospect_triggers (keyword, category, active) VALUES (?, ?, 1)');
        if ($stmt) {
            $stmt->bind_param('ss', $keyword, $category);
            if ($stmt->execute()) { $type = 'success'; $message = 'Trigger berhasil ditambahkan.'; }
            else $message = $stmt->errno === 1062 ? 'Kata pemicu sudah terdaftar.' : 'Trigger gagal disimpan.';
            $stmt->close();
        } else $message = 'Tidak dapat menyiapkan penyimpanan trigger.';
    } else $message = 'Kata pemicu dan kategori wajib diisi dengan panjang yang valid.';
} elseif (in_array($action, ['toggle', 'delete'], true)) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        if ($action === 'toggle') {
            $stmt = $conn->prepare('UPDATE crm_prospect_triggers SET active = IF(active = 1, 0, 1) WHERE id = ?');
            if ($stmt) { $stmt->bind_param('i', $id); $ok = $stmt->execute(); $changed = $stmt->affected_rows; $stmt->close(); $type = $ok && $changed === 1 ? 'success' : 'error'; $message = $type === 'success' ? 'Status trigger diperbarui.' : 'Trigger tidak ditemukan atau status tidak berubah.'; }
        } else {
            $stmt = $conn->prepare('DELETE FROM crm_prospect_triggers WHERE id = ?');
            if ($stmt) { $stmt->bind_param('i', $id); $ok = $stmt->execute(); $changed = $stmt->affected_rows; $stmt->close(); $type = $ok && $changed === 1 ? 'success' : 'error'; $message = $type === 'success' ? 'Trigger dihapus.' : 'Trigger tidak ditemukan atau gagal dihapus.'; }
        }
    } else $message = 'ID trigger tidak valid.';
}
$_SESSION['crm_flash'] = ['type' => $type, 'message' => $message];
header('Location: ../index.php?page=triggers');
exit;
