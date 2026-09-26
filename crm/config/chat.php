<?php
declare(strict_types=1);

/**
 * Phase 2 chat storage.
 *
 * The legacy log_wa/crm_message_history tables remain the compatibility layer.
 * These helpers fail closed when the new tables have not been migrated yet.
 */

function crmChatTablesReady(mysqli $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;

    $required = ['crm_conversations', 'crm_messages', 'crm_followups'];
    foreach ($required as $table) {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        if (!$result || $result->num_rows === 0) {
            $ready = false;
            return false;
        }
    }

    $ready = true;
    return true;
}

function crmChatNormalizeNumber(string $number): string {
    $number = preg_replace('/\D+/', '', $number) ?? '';
    if ($number !== '' && str_starts_with($number, '0')) {
        $number = '62' . substr($number, 1);
    }
    return $number;
}

function crmChatGetOrCreateConversation(mysqli $conn, string $nowa, ?string $nama = null): ?int {
    if (!crmChatTablesReady($conn)) return null;

    $number = crmChatNormalizeNumber($nowa);
    if ($number === '') return null;
    $name = trim((string)$nama);

    $stmt = $conn->prepare(
        "INSERT INTO crm_conversations (nowa, nama)
         VALUES (?, NULLIF(?, ''))
         ON DUPLICATE KEY UPDATE
            nama = CASE
                WHEN VALUES(nama) IS NOT NULL AND VALUES(nama) <> '' THEN VALUES(nama)
                ELSE nama
            END"
    );
    if (!$stmt) return null;
    $stmt->bind_param('ss', $number, $name);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $stmt->close();

    $idStmt = $conn->prepare("SELECT id FROM crm_conversations WHERE nowa = ? LIMIT 1");
    if (!$idStmt) return null;
    $idStmt->bind_param('s', $number);
    $idStmt->execute();
    $row = $idStmt->get_result()->fetch_assoc();
    $idStmt->close();

    return $row ? (int)$row['id'] : null;
}

function crmChatStoreMessage(
    mysqli $conn,
    string $nowa,
    string $nama,
    string $message,
    string $direction,
    string $senderType,
    string $source = 'crm',
    ?string $sentAt = null,
    ?string $externalId = null,
    ?int $templateId = null,
    ?string $templateName = null
): ?int {
    if (!crmChatTablesReady($conn)) return null;

    $number = crmChatNormalizeNumber($nowa);
    $message = trim($message);
    $direction = strtolower(trim($direction));
    $senderType = strtolower(trim($senderType));

    if ($number === '' || $message === '' || !in_array($direction, ['in', 'out'], true)) {
        return null;
    }

    $conversationId = crmChatGetOrCreateConversation($conn, $number, $nama);
    if (!$conversationId) return null;

    $sentAt = $sentAt ?: date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO crm_messages
            (conversation_id, nowa, direction, sender_type, message, source, template_id, template_name, external_id, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?)"
    );
    if (!$stmt) return null;

    $external = trim((string)$externalId);
    $templateName = trim((string)$templateName);
    $templateIdValue = $templateId ?? 0;
    $stmt->bind_param(
        'isssssisss',
        $conversationId,
        $number,
        $direction,
        $senderType,
        $message,
        $source,
        $templateIdValue,
        $templateName,
        $external,
        $sentAt
    );

    if (!$stmt->execute()) {
        // A repeated provider event with the same external_id is harmless.
        $error = $stmt->errno;
        $stmt->close();
        if ($error === 1062 && $external !== '') {
            return 0;
        }
        return null;
    }

    $messageId = (int)$stmt->insert_id;
    $stmt->close();

    if ($direction === 'in') {
        $update = $conn->prepare(
            "UPDATE crm_conversations
             SET last_message_at = ?, last_inbound_at = ?, unread_count = unread_count + 1
             WHERE id = ?"
        );
    } else {
        $update = $conn->prepare(
            "UPDATE crm_conversations
             SET last_message_at = ?, last_outbound_at = ?
             WHERE id = ?"
        );
    }

    if ($update) {
        $update->bind_param('ssi', $sentAt, $sentAt, $conversationId);
        $update->execute();
        $update->close();
    }

    return $messageId;
}

function crmChatRecordFollowup(
    mysqli $conn,
    int $conversationId,
    ?int $messageId = null,
    ?int $templateId = null,
    ?string $templateName = null,
    ?string $sentAt = null,
    string $status = 'sent'
): ?int {
    if (!crmChatTablesReady($conn)) return null;
    if ($conversationId <= 0) return null;

    $sentAt = $sentAt ?: date('Y-m-d H:i:s');
    $templateIdValue = $templateId ?? 0;
    $templateName = trim((string)$templateName);
    $status = trim($status) !== '' ? trim($status) : 'sent';

    $stmt = $conn->prepare(
        "INSERT INTO crm_followups
            (conversation_id, message_id, template_id, template_name, sent_at, status)
         VALUES (?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?)"
    );
    if (!$stmt) return null;

    $messageIdValue = $messageId ?? 0;
    $stmt->bind_param(
        'iiisss',
        $conversationId,
        $messageIdValue,
        $templateIdValue,
        $templateName,
        $sentAt,
        $status
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $followupId = (int)$stmt->insert_id;
    $stmt->close();

    $countStmt = $conn->prepare(
        "UPDATE crm_conversations
         SET followup_count = followup_count + 1
         WHERE id = ?"
    );
    if ($countStmt) {
        $countStmt->bind_param('i', $conversationId);
        $countStmt->execute();
        $countStmt->close();
    }

    return $followupId;
}

function crmChatRefreshFollowupCount(mysqli $conn, int $conversationId): bool {
    if (!crmChatTablesReady($conn) || $conversationId <= 0) return false;

    $stmt = $conn->prepare(
        "UPDATE crm_conversations c
         SET followup_count = (
             SELECT COUNT(*)
             FROM crm_followups f
             WHERE f.conversation_id = c.id
         )
         WHERE c.id = ?"
    );
    if (!$stmt) return false;
    $stmt->bind_param('i', $conversationId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function crmChatMarkRead(mysqli $conn, string $nowa): bool {
    if (!crmChatTablesReady($conn)) return false;

    $number = crmChatNormalizeNumber($nowa);
    if ($number === '') return false;

    $stmt = $conn->prepare(
        "UPDATE crm_conversations
         SET last_read_at = NOW(), unread_count = 0
         WHERE nowa = ?"
    );
    if (!$stmt) return false;
    $stmt->bind_param('s', $number);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function crmChatGetUnreadCount(mysqli $conn): int {
    if (!crmChatTablesReady($conn)) return 0;

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM crm_conversations
         WHERE unread_count > 0
           AND last_inbound_at >= CURDATE()"
    );
    if (!$result) return 0;
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}
