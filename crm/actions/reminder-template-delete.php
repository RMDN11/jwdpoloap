<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$templateId = (int)($_POST['template_id'] ?? 0);
$returnQuery = trim((string)($_POST['return_query'] ?? ''));

$params = ['page' => 'reminder-pembayaran'];
parse_str($returnQuery, $parsed);
foreach (['q', 'bulan', 'csv_file', 'halaqoh', 'status_peserta', 'status_bayar'] as $key) {
    if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) continue;
    $value = trim((string)$parsed[$key]);
    if ($key === 'csv_file') $value = basename($value);
    if ($value !== '') $params[$key] = $value;
}
$redirect = '../index.php?' . http_build_query($params);

if ($templateId <= 0) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template tidak valid.'];
    header('Location: ' . $redirect, true, 303);
    exit;
}

try {
    $stmt = $conn->prepare('DELETE FROM wa_templates WHERE id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Gagal menyiapkan penghapusan template.');
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    $_SESSION['crm_flash'] = $deleted
        ? ['type' => 'success', 'message' => 'Template berhasil dihapus.']
        : ['type' => 'error', 'message' => 'Template tidak ditemukan.'];
} catch (Throwable $e) {
    error_log('CRM reminder template delete failed: ' . $e->getMessage());
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template gagal dihapus.'];
}

header('Location: ' . $redirect, true, 303);
exit;
