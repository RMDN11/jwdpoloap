<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/chat-directory.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database connection unavailable']);
    exit;
}

$conn->set_charset('utf8mb4');

$since = trim((string)($_GET['since'] ?? ''));
$sinceDate = $since !== '' ? DateTime::createFromFormat('Y-m-d H:i:s', $since) : false;
if (!$sinceDate) {
    $sinceDate = new DateTime('-15 seconds');
}

$sinceSql = $sinceDate->format('Y-m-d H:i:s');
$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));
$range = trim((string)($_GET['range'] ?? 'today'));
$room = trim((string)($_GET['room'] ?? 'all'));

if (!in_array($status, ['all', 'new', 'followed'], true)) $status = 'all';
if (!in_array($range, ['today', 'week', 'month', 'all'], true)) $range = 'today';
if (!in_array($room, ['all', 'people', 'other'], true)) $room = 'all';
$roomSql = crmChatRoomSql($conn, $room);
$knownSql = crmChatKnownContactSql($conn);

$rangeSql = match ($range) {
    'week' => "last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
    'month' => "last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    'all' => "1=1",
    default => "last_inbound_at >= CURDATE()",
};

$statusSql = match ($status) {
    'new' => "unread_count > 0",
    'followed' => "unread_count = 0",
    default => "1=1",
};

$searchSql = $search !== ''
    ? "(
        nama LIKE ?
        OR nowa LIKE ?
        OR EXISTS (
            SELECT 1
            FROM crm_messages cms
            WHERE cms.conversation_id = crm_conversations.id
              AND cms.message LIKE ?
        )
    )"
    : "1=1";

$matchSql = "($rangeSql) AND ($statusSql) AND ($roomSql) AND ($searchSql)";

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
        CASE WHEN $knownSql THEN 'people' ELSE 'other' END AS contact_room,
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
        ) AS last_direction,
        CASE WHEN $matchSql THEN 1 ELSE 0 END AS matches_filter
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

$params = [];
$types = '';

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= 'sss';
}
$params[] = $sinceSql;
$types .= 's';

$bind = [$types];
foreach ($params as $key => $value) {
    $bind[] = &$params[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bind);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Polling Chat gagal dijalankan']);
    exit;
}

$result = $stmt->get_result();
$conversations = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['unread_count'] = (int)$row['unread_count'];
    $row['followup_count'] = (int)$row['followup_count'];
    $row['matches_filter'] = (bool)$row['matches_filter'];
    $conversations[] = $row;
}
$stmt->close();

$statsRangeSql = match ($range) {
    'week' => "last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
    'month' => "last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    'all' => "1=1",
    default => "last_inbound_at >= CURDATE()",
};

$statsStmt = $conn->query(
    "SELECT
        SUM(($roomSql)) AS total,
        COALESCE(SUM(($roomSql) AND ($statsRangeSql) AND unread_count > 0), 0) AS unread,
        COALESCE(SUM(($roomSql) AND ($statsRangeSql) AND unread_count = 0), 0) AS read_count
     FROM crm_conversations"
);
$stats = ['total' => 0, 'unread' => 0, 'read_count' => 0];
if ($statsStmt) {
    $statsRow = $statsStmt->fetch_assoc();
    $stats = [
        'total' => (int)($statsRow['total'] ?? 0),
        'unread' => (int)($statsRow['unread'] ?? 0),
        'read_count' => (int)($statsRow['read_count'] ?? 0),
    ];
}

$unreadStmt = $conn->query(
    "SELECT COALESCE(SUM(unread_count > 0), 0) AS total
     FROM crm_conversations
     WHERE ($roomSql) AND last_inbound_at >= CURDATE()"
);
$unreadToday = 0;
if ($unreadStmt) {
    $unreadRow = $unreadStmt->fetch_assoc();
    $unreadToday = (int)($unreadRow['total'] ?? 0);
}

$dbNowResult = $conn->query("SELECT NOW() AS server_time");
$dbNowRow = $dbNowResult ? $dbNowResult->fetch_assoc() : null;
$serverTime = (string)($dbNowRow['server_time'] ?? date('Y-m-d H:i:s'));

echo json_encode([
    'ok' => true,
    'server_time' => $serverTime,
    'unread_today' => $unreadToday,
    'stats' => $stats,
    'conversations' => $conversations,
], JSON_UNESCAPED_UNICODE);
