<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$templateId = (int)($_POST['template_id'] ?? 0);
$category = trim((string)($_POST['category'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$content = trim((string)($_POST['content'] ?? ''));
$returnQuery = trim((string)($_POST['return_query'] ?? ''));

if ($title === '' || $content === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Judul dan isi template wajib diisi.'];
    header('Location: ../index.php?' . ($returnQuery !== '' ? $returnQuery : 'page=reminder-pembayaran'));
    exit;
}

try {
    if ($templateId > 0) {
        $stmt = $conn->prepare('UPDATE wa_templates SET category = ?, title = ?, content = ? WHERE id = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Gagal menyiapkan perubahan template.');
        $stmt->bind_param('sssi', $category, $title, $content, $templateId);
        $stmt->execute();
        $stmt->close();

        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template berhasil diperbarui.'];
    } else {
        $stmt = $conn->prepare('INSERT INTO wa_templates (category, title, content) VALUES (?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Gagal menyiapkan template baru.');
        $stmt->bind_param('sss', $category, $title, $content);
        $stmt->execute();
        $stmt->close();

        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template baru berhasil disimpan.'];
    }
} catch (Throwable $e) {
    error_log('CRM reminder template save failed: ' . $e->getMessage());
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template gagal disimpan.'];
}

header('Location: ../index.php?' . ($returnQuery !== '' ? $returnQuery : 'page=reminder-pembayaran'));
exit;
