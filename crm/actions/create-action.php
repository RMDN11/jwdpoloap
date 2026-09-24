<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/prospect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$contactNowa = trim((string)($_POST['contact_nowa'] ?? ''));
$type = trim((string)($_POST['type'] ?? 'task'));
$priority = trim((string)($_POST['priority'] ?? 'normal'));
$dueAtInput = trim((string)($_POST['due_at'] ?? ''));

$allowedTypes = ['task', 'follow_up', 'call', 'message', 'other'];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];

if ($title === '' || mb_strlen($title) > 180) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Judul Action wajib diisi dan maksimal 180 karakter.'];
    header('Location: ../index.php?page=action');
    exit;
}

if (!in_array($type, $allowedTypes, true)) $type = 'task';
if (!in_array($priority, $allowedPriorities, true)) $priority = 'normal';

$contactName = null;
$normalizedContact = null;

if ($contactNowa !== '') {
    $disqualified = crmGetDisqualifiedNumbers($conn);
    $blocked = crmGetBlockedNumbers($conn);
    $contact = crmFindEligibleProspectByNumber($conn, $contactNowa, $disqualified, $blocked);

    if (!$contact) {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak tidak ditemukan di daftar prospek aktif.'];
        header('Location: ../index.php?page=action');
        exit;
    }

    $normalizedContact = crmProspectNormalizeNumber((string)$contact['nowa']);
    $contactName = trim((string)($contact['nama'] ?? '')) ?: null;
}

$dueAt = null;
if ($dueAtInput !== '') {
    $dueDate = DateTime::createFromFormat('Y-m-d\\TH:i', $dueAtInput, new DateTimeZone('Asia/Jakarta'));
    $dateErrors = DateTime::getLastErrors();
    $hasDateErrors = is_array($dateErrors)
        ? ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)
        : false;

    if (!$dueDate || $hasDateErrors) {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Format deadline tidak valid.'];
        header('Location: ../index.php?page=action');
        exit;
    }

    $dueAt = $dueDate->format('Y-m-d H:i:s');
}

$stmt = $conn->prepare(
    "INSERT INTO crm_actions
        (contact_nowa, contact_name, title, description, type, priority, status, due_at)
     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
);

if (!$stmt) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Action gagal dibuat.'];
    header('Location: ../index.php?page=action');
    exit;
}

$stmt->bind_param(
    'sssssss',
    $normalizedContact,
    $contactName,
    $title,
    $description,
    $type,
    $priority,
    $dueAt
);

$created = $stmt->execute();
$stmt->close();

$_SESSION['crm_flash'] = $created
    ? ['type' => 'success', 'message' => 'Action berhasil dibuat.']
    : ['type' => 'error', 'message' => 'Action gagal disimpan.'];

header('Location: ../index.php?page=action');
exit;
