<?php
declare(strict_types=1);

/**
 * Sync legacy auto-reply contacts into the Phase 2/3 CRM layer.
 *
 * auto_reply_logs explicitly stores the customer's incoming_message, so this
 * is safe to promote into crm_messages as direction=in. The legacy log_wa
 * table remains read-only history and is not modified here.
 *
 * Scope: last 15 days, idempotent, and only for contacts that do not already
 * have a crm_conversations row.
 */
function crmChatLegacySync(mysqli $conn): int {
    require_once __DIR__ . '/chat.php';
    require_once __DIR__ . '/chat-directory.php';
    require_once __DIR__ . '/prospect.php';
    require_once __DIR__ . '/chat-routing.php';

    if (!crmChatTablesReady($conn)) return 0;

    $tableCheck = $conn->query("SHOW TABLES LIKE 'auto_reply_logs'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return 0;

    $latestSql = "
        SELECT l.id, l.contact_id, l.incoming_message, l.created_at
        FROM auto_reply_logs l
        INNER JOIN (
            SELECT contact_id, MAX(id) AS max_id
            FROM auto_reply_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)
              AND contact_id IS NOT NULL
              AND contact_id <> ''
            GROUP BY contact_id
        ) latest ON latest.max_id = l.id
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT 500
    ";

    $result = $conn->query($latestSql);
    if (!$result) return 0;

    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $number = crmChatNormalizeNumber((string)($row['contact_id'] ?? ''));
        $message = trim((string)($row['incoming_message'] ?? ''));
        if ($number === '' || $message === '') continue;

        // Only this number is internal for this legacy backfill.
        if ($number === '6288223053149') continue;

        $row['number'] = $number;
        $row['incoming_message'] = $message;
        $candidates[$number] = $row;
    }

    if (!$candidates) return 0;

    $disqualified = crmGetDisqualifiedNumbers($conn);
    $blocked = crmGetBlockedNumbers($conn);

    // Load existing CRM conversations once so the sync stays cheap and
    // idempotent instead of issuing one SELECT per candidate.
    $existing = [];
    $existingResult = $conn->query("SELECT nowa FROM crm_conversations");
    if ($existingResult) {
        while ($row = $existingResult->fetch_assoc()) {
            $number = crmChatNormalizeNumber((string)$row['nowa']);
            if ($number !== '') $existing[$number] = true;
        }
    }

    // Resolve the latest legacy display name in one query.
    $legacyNames = [];
    $nameResult = $conn->query(
        "SELECT nowa,nama
         FROM log_wa
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)
           AND nowa <> '6288223053149'
         ORDER BY created_at DESC, id DESC
         LIMIT 2500"
    );
    if ($nameResult) {
        while ($row = $nameResult->fetch_assoc()) {
            $number = crmChatNormalizeNumber((string)$row['nowa']);
            $name = trim((string)($row['nama'] ?? ''));
            if ($number !== '' && $name !== '' && !isset($legacyNames[$number])) {
                $legacyNames[$number] = $name;
            }
        }
    }

    $conversationStmt = $conn->prepare(
        "INSERT INTO crm_conversations
            (nowa, nama, last_message_at, last_inbound_at, unread_count, room, room_source, intent_category)
         VALUES (?, NULLIF(?, ''), ?, ?, 0, ?, 'auto', ?)
         ON DUPLICATE KEY UPDATE id = id"
    );
    if (!$conversationStmt) return 0;

    $messageStmt = $conn->prepare(
        "INSERT INTO crm_messages
            (conversation_id, nowa, direction, sender_type, message, source, external_id, sent_at)
         VALUES (?, ?, 'in', 'recipient', ?, 'legacy_auto_reply', ?, ?)
         ON DUPLICATE KEY UPDATE id = id"
    );
    if (!$messageStmt) {
        $conversationStmt->close();
        return 0;
    }

    $updated = 0;

    foreach ($candidates as $number => $row) {
        if (isset($existing[$number])) continue;
        if (isset($disqualified[$number]) || isset($blocked[$number])) continue;

        $message = (string)$row['incoming_message'];
        $createdAt = (string)$row['created_at'];
        $intent = crmProspectClassifyMessage($message, $conn);
        $room = crmChatRoutingIsQualifyingIntent($intent)
            ? 'customer_baru'
            : 'lainnya';
        if ($intent === 'Data CSV/Manual') $intent = null;

        $name = $legacyNames[$number] ?? '';

        $conversationStmt->bind_param(
            'ssssss',
            $number,
            $name,
            $createdAt,
            $createdAt,
            $room,
            $intent
        );

        if (!$conversationStmt->execute() || $conversationStmt->affected_rows !== 1) {
            continue;
        }

        $conversationId = (int)$conversationStmt->insert_id;
        if ($conversationId <= 0) continue;

        $externalId = 'legacy-auto-reply:' . (int)$row['id'];
        $messageStmt->bind_param(
            'issss',
            $conversationId,
            $number,
            $message,
            $externalId,
            $createdAt
        );

        if (!$messageStmt->execute()) {
            // Keep the conversation visible even if one legacy message cannot
            // be promoted. The original log_wa history remains untouched.
            continue;
        }

        $existing[$number] = true;
        $updated++;
    }

    $messageStmt->close();
    $conversationStmt->close();

    return $updated;
}
