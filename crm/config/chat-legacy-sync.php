<?php
declare(strict_types=1);

/**
 * Backfill legacy Customer Baru conversations from the real legacy flow.
 *
 * Flow:
 *   log_wa -> active auto_reply_rules trigger -> filter contact -> CRM.
 *
 * log_wa is intentionally the source of candidates because that is where the
 * existing legacy WhatsApp flow records the conversation. We do not require
 * auto_reply_logs here: it is an implementation log of the auto-reply engine,
 * not the source of the legacy conversation.
 */
function crmChatLegacySync(mysqli $conn): int {
    require_once __DIR__ . '/chat.php';
    require_once __DIR__ . '/chat-directory.php';
    require_once __DIR__ . '/prospect.php';
    require_once __DIR__ . '/chat-routing.php';

    if (!crmChatTablesReady($conn)) return 0;

    // Read the exact triggers used by manage_auto_reply.php / AutoReplyEngine.
    $triggerResult = $conn->query(
        "SELECT keyword
         FROM auto_reply_rules
         WHERE is_active = 1
         ORDER BY priority DESC, id DESC"
    );
    if (!$triggerResult) return 0;

    $triggerKeywords = [];
    while ($trigger = $triggerResult->fetch_assoc()) {
        foreach (explode('|', (string)($trigger['keyword'] ?? '')) as $keyword) {
            $keyword = crmChatLegacySyncNormalizeText($keyword);
            if ($keyword !== '') {
                $triggerKeywords[$keyword] = true;
            }
        }
    }

    if (!$triggerKeywords) return 0;

    // Legacy WhatsApp source. Keep this bounded and read-only.
    $legacyResult = $conn->query(
        "SELECT id, nowa, nama, message, created_at
         FROM log_wa
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)
           AND nowa IS NOT NULL
           AND nowa <> ''
         ORDER BY id DESC
         LIMIT 5000"
    );
    if (!$legacyResult) return 0;

    $candidates = [];

    while ($row = $legacyResult->fetch_assoc()) {
        $rawNumber = trim((string)($row['nowa'] ?? ''));
        $number = crmChatNormalizeNumber($rawNumber);
        $message = trim((string)($row['message'] ?? ''));
        $createdAt = (string)($row['created_at'] ?? '');

        if (
            $number === '' ||
            $message === '' ||
            $createdAt === '' ||
            str_contains($rawNumber, '@g') ||
            str_contains($rawNumber, '-')
        ) {
            continue;
        }

        // The only internal number excluded by this legacy backfill.
        if ($number === '6288223053149') continue;

        $normalizedMessage = crmChatLegacySyncNormalizeText($message);

        $matchedTrigger = false;
        foreach (array_keys($triggerKeywords) as $keyword) {
            if (strpos($normalizedMessage, $keyword) !== false) {
                $matchedTrigger = true;
                break;
            }
        }

        if (!$matchedTrigger) continue;

        // Newest matching trigger message wins for each contact.
        if (!isset($candidates[$number])) {
            $candidates[$number] = [
                'id' => (int)$row['id'],
                'number' => $number,
                'name' => trim((string)($row['nama'] ?? '')),
                'message' => $message,
                'created_at' => $createdAt,
            ];
        }
    }

    if (!$candidates) return 0;

    $blocked = crmGetBlockedNumbers($conn);

    // Existing CRM conversations, normalized once for idempotency.
    $existing = [];
    $existingResult = $conn->query(
        "SELECT id, nowa, room, room_source
         FROM crm_conversations"
    );
    if ($existingResult) {
        while ($row = $existingResult->fetch_assoc()) {
            $number = crmChatNormalizeNumber((string)($row['nowa'] ?? ''));
            if ($number !== '') {
                $existing[$number] = [
                    'id' => (int)$row['id'],
                    'room' => (string)($row['room'] ?? 'lainnya'),
                    'room_source' => (string)($row['room_source'] ?? 'auto'),
                ];
            }
        }
    }

    $insertConversation = $conn->prepare(
        "INSERT INTO crm_conversations
            (nowa, nama, last_message_at, last_inbound_at, unread_count, room, room_source, intent_category)
         VALUES (?, NULLIF(?, ''), ?, ?, 0, 'customer_baru', 'auto', ?)
         ON DUPLICATE KEY UPDATE id = id"
    );
    if (!$insertConversation) return 0;

    $routeExisting = $conn->prepare(
        "UPDATE crm_conversations
         SET room = 'customer_baru',
             room_source = 'auto',
             intent_category = COALESCE(?, intent_category)
         WHERE id = ?
           AND room_source <> 'manual'
           AND room <> 'sudah_payment'
           AND room <> 'peserta_pengajar'"
    );
    if (!$routeExisting) {
        $insertConversation->close();
        return 0;
    }

    $messageExists = $conn->prepare(
        "SELECT id
         FROM crm_messages
         WHERE external_id = ?
         LIMIT 1"
    );
    if (!$messageExists) {
        $routeExisting->close();
        $insertConversation->close();
        return 0;
    }

    $insertMessage = $conn->prepare(
        "INSERT INTO crm_messages
            (conversation_id, nowa, direction, sender_type, message, source, external_id, sent_at)
         VALUES (?, ?, 'in', 'recipient', ?, 'legacy_log_wa', ?, ?)"
    );
    if (!$insertMessage) {
        $messageExists->close();
        $routeExisting->close();
        $insertConversation->close();
        return 0;
    }

    $updateActivity = $conn->prepare(
        "UPDATE crm_conversations
         SET last_message_at = GREATEST(COALESCE(last_message_at, '1000-01-01 00:00:00'), ?),
             last_inbound_at = GREATEST(COALESCE(last_inbound_at, '1000-01-01 00:00:00'), ?)
         WHERE id = ?"
    );

    $updated = 0;

    foreach ($candidates as $number => $candidate) {
        if (isset($blocked[$number])) continue;

        // Explicitly exclude real participants / teachers / pengampu.
        if (crmChatLegacySyncIsKnownContact($conn, $number)) continue;

        $message = $candidate['message'];
        $createdAt = $candidate['created_at'];
        $name = $candidate['name'];

        $intent = crmProspectClassifyMessage($message, $conn);
        if ($intent === 'Data CSV/Manual' || $intent === 'Lainnya') {
            $intent = null;
        }

        if (isset($existing[$number])) {
            $existingRow = $existing[$number];

            // Never overwrite manual/payment/participant routing.
            if (
                strtolower(trim($existingRow['room_source'])) === 'manual' ||
                $existingRow['room'] === 'sudah_payment' ||
                $existingRow['room'] === 'peserta_pengajar'
            ) {
                continue;
            }

            $conversationId = (int)$existingRow['id'];
            $routeExisting->bind_param('si', $intent, $conversationId);
            $routeExisting->execute();
        } else {
            $insertConversation->bind_param(
                'sssss',
                $number,
                $name,
                $createdAt,
                $createdAt,
                $intent
            );

            if (!$insertConversation->execute() || $insertConversation->affected_rows !== 1) {
                continue;
            }

            $conversationId = (int)$insertConversation->insert_id;
            if ($conversationId <= 0) continue;

            $existing[$number] = [
                'id' => $conversationId,
                'room' => 'customer_baru',
                'room_source' => 'auto',
            ];
        }

        $externalId = 'legacy-log-wa:' . (int)$candidate['id'];

        $messageExists->bind_param('s', $externalId);
        $messageExists->execute();
        $messageExists->store_result();

        if ($messageExists->num_rows === 0) {
            $insertMessage->bind_param(
                'issss',
                $conversationId,
                $number,
                $message,
                $externalId,
                $createdAt
            );

            if ($insertMessage->execute() && $updateActivity) {
                $updateActivity->bind_param(
                    'ssi',
                    $createdAt,
                    $createdAt,
                    $conversationId
                );
                $updateActivity->execute();
            }
        }

        $updated++;
    }

    if ($updateActivity) $updateActivity->close();
    $insertMessage->close();
    $messageExists->close();
    $routeExisting->close();
    $insertConversation->close();

    return $updated;
}

function crmChatLegacySyncNormalizeText(string $message): string {
    $message = strtolower(trim($message));
    $message = preg_replace('/\s+/u', ' ', $message) ?? '';
    return trim($message);
}

function crmChatLegacySyncIsKnownContact(mysqli $conn, string $number): bool {
    $number = crmChatNormalizeNumber($number);
    if ($number === '') return false;

    foreach (crmChatDirectoryTables($conn) as $table) {
        $stmt = $conn->prepare(
            "SELECT 1
             FROM {$table}
             WHERE nowa = ?
                OR nowa = ?
                OR nowa = ?
                OR nowa = ?
             LIMIT 1"
        );
        if (!$stmt) continue;

        $local = '0' . substr($number, 2);
        $plus = '+' . $number;
        $stmt->bind_param('ssss', $number, $local, $plus, $number);
        $stmt->execute();
        $stmt->store_result();
        $found = $stmt->num_rows > 0;
        $stmt->close();

        if ($found) return true;
    }

    return false;
}
