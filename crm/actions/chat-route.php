<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/prospect.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/chat-routing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$contactId = trim((string)($_POST['contact_id'] ?? ''));
$requestedRoom = trim((string)($_POST['room'] ?? 'lainnya'));
$clearManual = (string)($_POST['clear_manual'] ?? '') === '1';

if ($contactId === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Percakapan tidak ditemukan.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$normalized = crmProspectNormalizeNumber($contactId);
$stmt = $conn->prepare(
    "SELECT id, nowa, room, room_source, intent_category, payment_detected_at
     FROM crm_conversations
     WHERE nowa = ? OR nowa = ?
     LIMIT 1"
);
if (!$stmt) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Routing gagal disiapkan.'];
    header('Location: ../index.php?page=chat&contact=' . urlencode($contactId));
    exit;
}
$stmt->bind_param('ss', $normalized, $contactId);
$stmt->execute();
$conversation = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$conversation) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Conversation tidak ditemukan.'];
    header('Location: ../index.php?page=chat');
    exit;
}

if ($clearManual) {
    $resetStmt = $conn->prepare(
        "UPDATE crm_conversations SET room_source = 'auto' WHERE id = ?"
    );
    $resetOk = false;
    if ($resetStmt) {
        $resetStmt->bind_param('i', $conversation['id']);
        $resetOk = $resetStmt->execute();
        $resetStmt->close();
    }

    $routing = $resetOk
        ? crmChatRoutingEvaluateConversation($conn, (int)$conversation['id'])
        : null;

    $_SESSION['crm_flash'] = $routing
        ? ['type' => 'success', 'message' => 'Routing dikembalikan ke Auto Routing.']
        : ['type' => 'error', 'message' => 'Auto routing gagal dievaluasi.'];

    header('Location: ../index.php?page=chat&contact=' . urlencode($conversation['nowa']));
    exit;
}

if (!in_array($requestedRoom, ['customer_baru', 'sudah_payment', 'people', 'lainnya'], true)) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Room routing tidak valid.'];
    header('Location: ../index.php?page=chat&contact=' . urlencode($conversation['nowa']));
    exit;
}

$manual = (string)($_POST['manual'] ?? '') === '1';

if (!$manual) {
    $routing = crmChatRoutingEvaluateConversation($conn, (int)$conversation['id']);
    $_SESSION['crm_flash'] = $routing
        ? ['type' => 'success', 'message' => 'Routing dikembalikan ke Auto Routing.']
        : ['type' => 'error', 'message' => 'Auto routing gagal dievaluasi.'];
    header('Location: ../index.php?page=chat&contact=' . urlencode($conversation['nowa']));
    exit;
}

$paymentAt = $requestedRoom === 'sudah_payment'
    ? ($conversation['room'] === 'sudah_payment' && !empty($conversation['payment_detected_at'])
        ? $conversation['payment_detected_at']
        : date('Y-m-d H:i:s'))
    : ($conversation['payment_detected_at'] ?? null);

$ok = crmChatRoutingPersist(
    $conn,
    (int)$conversation['id'],
    $requestedRoom,
    'manual',
    $conversation['intent_category'] ?? null,
    $paymentAt
);

if (!$ok) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Routing gagal disimpan.'];
} else {
    $_SESSION['crm_flash'] = [
        'type' => 'success',
        'message' => $manual ? 'Routing manual berhasil disimpan.' : 'Routing otomatis berhasil diterapkan.'
    ];
}

header('Location: ../index.php?page=chat&contact=' . urlencode($conversation['nowa']));
exit;
