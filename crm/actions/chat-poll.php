<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/chat.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$since = trim((string)($_GET['since'] ?? ''));
if ($since === '') {
    $since = date('Y-m-d H:i:s', time() - 15);
}

$sinceDate = DateTime::createFromFormat('Y-m-d H:i:s', $since);
if (!$sinceDate) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Cursor waktu tidak valid']);
    exit;
}

$sinceSql = $sinceDate->format('Y-m-d H:i:s');

$stmt = $conn->prepare(
    "SELECT
        id,
        nowa,
        nama,
        last_message_at,
        last_inbound_at,
        last_outbound_at,
        unread_count,
        followup_count,
        (
            SELECT cm.message
            FROM crm_messages cm
            WHERE cm.conversation_id = crm_conversations.id
            ORDER BY cm.sent_at DESC, cm.id DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT cm.direction
            FROM crm_messages cm
            WHERE cm.conversation_id = crm_conversations.id
            ORDER BY cm.sent_at DESC, cm.id DESC
            LIMIT 1
        ) AS last_direction
     FROM crm_conversations
     WHERE last_message_at >= ?
     ORDER BY last_message_at DESC, id DESC
     LIMIT 50"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal menyiapkan polling Chat']);
    exit;
}

$stmt->bind_param('s', $sinceSql);
$stmt->execute();
$result = $stmt->get_result();

$updatedAt = $sinceSql;
$conversations = [];

while ($row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['unread_count'] = (int)$row['unread_count'];
    $row['followup_count'] = (int)$row['followup_count'];
    $conversations[] = $row;

    $rowTime = (string)($row['last_message_at'] ?? '');
    if ($rowTime > $updatedAt) $updatedAt = $rowTime;
}
$stmt->close();

$unreadStmt = $conn->query(
    "SELECT COALESCE(SUM(unread_count > 0), 0) AS total
     FROM crm_conversations
     WHERE last_inbound_at >= CURDATE()"
);
$unreadToday = 0;
if ($unreadStmt) {
    $unreadRow = $unreadStmt->fetch_assoc();
    $unreadToday = (int)($unreadRow['total'] ?? 0);
}

echo json_encode([
    'ok' => true,
    'server_time' => date('Y-m-d H:i:s'),
    'cursor' => $updatedAt,
    'unread_today' => $unreadToday,
    'conversations' => $conversations,
], JSON_UNESCAPED_UNICODE);
