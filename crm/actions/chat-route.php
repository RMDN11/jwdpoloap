<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/prospect.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/chat-directory.php';
require_once __DIR__ . '/../config/chat-routing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$conversationId = (int)($_POST['conversation_id'] ?? 0);
$clearManual = (string)($_POST['clear_manual'] ?? '') === '1';
$room = trim((string)($_POST['room'] ?? ''));

if ($conversationId <= 0) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Conversation tidak ditemukan.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$stmt = $conn->prepare("SELECT nowa FROM crm_conversations WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $conversationId);
$stmt->execute();
$conversation = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$conversation) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Conversation tidak ditemukan.'];
    header('Location: ../index.php?page=chat');
    exit;
}

if ($clearManual) {
    $stmt = $conn->prepare(
        "UPDATE crm_conversations
         SET room_source = 'auto'
         WHERE id = ?"
    );
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $stmt->close();

    crmChatRoutingEvaluateConversation($conn, $conversationId, null);
    $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Routing dikembalikan ke Auto Routing.'];
} elseif (in_array($room, crmChatRoutingRooms(), true)) {
    $ok = crmChatRoutingPersist($conn, $conversationId, $room, 'manual', null, null);
    $_SESSION['crm_flash'] = $ok
        ? ['type' => 'success', 'message' => 'Routing conversation berhasil dipindahkan.']
        : ['type' => 'error', 'message' => 'Routing gagal disimpan.'];
} else {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Room routing tidak valid.'];
}

$contact = urlencode((string)$conversation['nowa']);
header('Location: ../index.php?page=chat&contact=' . $contact);
exit;
