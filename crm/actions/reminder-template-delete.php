<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || isset($_POST['ajax']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa atau token keamanan tidak valid. Silakan muat ulang halaman.']);
        exit;
    }
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$templateId = (int)($_POST['template_id'] ?? 0);
$returnQuery = trim((string)($_POST['return_query'] ?? ''));

$params = ['page' => 'reminder-pembayaran', 'manage_templates' => '1'];
parse_str($returnQuery, $parsed);
foreach (['q', 'bulan', 'csv_file', 'halaqoh', 'status_peserta', 'status_bayar'] as $key) {
    if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) continue;
    $value = trim((string)$parsed[$key]);
    if ($key === 'csv_file') $value = basename($value);
    if ($value !== '') $params[$key] = $value;
}
$redirect = '../index.php?' . http_build_query($params);

if ($templateId <= 0) {
    if ($isAjax) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Template tidak valid.']);
        exit;
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template tidak valid.'];
    header('Location: ' . $redirect, true, 303);
    exit;
}

try {
    $stmt = $conn->prepare('DELETE FROM wa_templates WHERE id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Gagal menyiapkan penghapusan template: ' . $conn->error);
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($deleted) {
            echo json_encode(['success' => true, 'message' => 'Template berhasil dihapus.', 'deleted_id' => $templateId]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Template tidak ditemukan atau sudah dihapus.']);
        }
        exit;
    }

    $_SESSION['crm_flash'] = $deleted
        ? ['type' => 'success', 'message' => 'Template berhasil dihapus.']
        : ['type' => 'error', 'message' => 'Template tidak ditemukan.'];
} catch (Throwable $e) {
    error_log('CRM reminder template delete failed: ' . $e->getMessage());
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus template: ' . $e->getMessage()]);
        exit;
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template gagal dihapus.'];
}

header('Location: ' . $redirect, true, 303);
exit;

