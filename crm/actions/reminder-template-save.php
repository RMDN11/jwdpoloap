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
$category = trim((string)($_POST['category'] ?? ''));
if ($category === '') {
    $category = 'Pembayaran';
}
$title = trim((string)($_POST['title'] ?? ''));
$content = trim((string)($_POST['content'] ?? ''));
$returnQuery = trim((string)($_POST['return_query'] ?? ''));

$redirectToReminder = static function (string $query): string {
    $params = ['page' => 'reminder-pembayaran', 'manage_templates' => '1'];
    parse_str($query, $parsed);

    foreach (['q', 'bulan', 'csv_file', 'halaqoh', 'status_peserta', 'status_bayar'] as $key) {
        if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) continue;

        $value = trim((string)$parsed[$key]);
        if ($key === 'csv_file') {
            $value = basename($value);
        }
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    return '../index.php?' . http_build_query($params);
};

if ($title === '' || $content === '') {
    if ($isAjax) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Judul dan isi template wajib diisi.']);
        exit;
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Judul dan isi template wajib diisi.'];
    header('Location: ' . $redirectToReminder($returnQuery), true, 303);
    exit;
}

try {
    if ($templateId > 0) {
        $stmt = $conn->prepare('UPDATE wa_templates SET category = ?, title = ?, content = ? WHERE id = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Gagal menyiapkan perubahan template: ' . $conn->error);
        $stmt->bind_param('sssi', $category, $title, $content, $templateId);
        $stmt->execute();
        $stmt->close();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'action' => 'update',
                'message' => 'Template "' . $title . '" berhasil diperbarui.',
                'template' => [
                    'id' => $templateId,
                    'category' => $category,
                    'title' => $title,
                    'content' => $content
                ]
            ]);
            exit;
        }

        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template berhasil diperbarui.'];
    } else {
        $stmt = $conn->prepare('INSERT INTO wa_templates (category, title, content) VALUES (?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Gagal menyiapkan template baru: ' . $conn->error);
        $stmt->bind_param('sss', $category, $title, $content);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'action' => 'create',
                'message' => 'Template baru "' . $title . '" berhasil disimpan.',
                'template' => [
                    'id' => $newId,
                    'category' => $category,
                    'title' => $title,
                    'content' => $content
                ]
            ]);
            exit;
        }

        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template baru berhasil disimpan.'];
    }
} catch (Throwable $e) {
    error_log('CRM reminder template save failed: ' . $e->getMessage());
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan template: ' . $e->getMessage()]);
        exit;
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template gagal disimpan.'];
}

header('Location: ' . $redirectToReminder($returnQuery), true, 303);
exit;

