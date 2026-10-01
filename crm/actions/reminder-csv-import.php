<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/reminder-csv.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$label = trim((string)($_POST['label'] ?? ''));
$file = $_FILES['csv'] ?? null;
$redirect = '../index.php?page=reminder-csv';

if (
    $label === ''
    || mb_strlen($label) > 100
    || !is_array($file)
    || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_uploaded_file((string)($file['tmp_name'] ?? ''))
) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Label dan file CSV wajib diisi.'];
    header('Location: ' . $redirect);
    exit;
}

if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Ukuran CSV maksimal 5 MB.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $metadata = crmReminderCsvImportFile(
        (string)$file['tmp_name'],
        (string)$file['name'],
        $label,
        $conn
    );

    $_SESSION['crm_flash'] = [
        'type' => 'success',
        'message' => sprintf(
            'CSV %s tersimpan di folder server: %d baris, %d cocok, %d belum cocok, %d duplikat.',
            $label,
            (int)$metadata['row_count'],
            (int)$metadata['matched_count'],
            (int)$metadata['unmatched_count'],
            (int)$metadata['duplicate_count']
        ),
    ];
} catch (Throwable $e) {
    error_log('CRM CSV file import failed: ' . $e->getMessage());
    $_SESSION['crm_flash'] = [
        'type' => 'error',
        'message' => 'Import dibatalkan. CSV tidak dimasukkan ke database. Periksa format CSV dan izin folder server.',
    ];
}

header('Location: ' . $redirect);
exit;
