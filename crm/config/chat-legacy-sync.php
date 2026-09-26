<?php
declare(strict_types=1);

/**
 * Promote legacy inbound prospects from log_wa into the Phase 3 CRM.
 *
 * Source of truth for this backfill:
 *   1. log_wa contains the actual legacy conversation rows;
 *   2. auto_reply_rules contains the real trigger keywords used by the
 *      existing auto-reply flow;
 *   3. auto_reply_logs is only used as inbound evidence so an outbound
 *      log_wa row is not accidentally treated as a customer message.
 *
 * A contact becomes Customer Baru when:
 *   - a recent log_wa message matches an active auto-reply trigger;
 *   - that same message has inbound evidence in auto_reply_logs;
 *   - the number is not internal, blocked, or already a participant/pengampu.
 *
 * The legacy tables are read-only here. The CRM layer is the only write target.
 */
function crmChatLegacySync(mysqli $conn): int {
    require_once __DIR__ . '/chat.php';
    require_once __DIR__ . '/chat-directory.php';
    require_once __DIR__ . '/prospect.php';
    require_once __DIR__ . '/chat-routing.php';

    if (!crmChatTablesReady($conn)) return 0;

    $tableCheck = $conn->query("SHOW TABLES LIKE 'auto_reply_logs'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return 0;

    /*
     * The existing auto-reply engine uses auto_reply_rules as its real trigger
     * source. Do not introduce a second trigger universe for legacy sync.
     */
    $triggerResult = $conn->query(
        "SELECT id, keyword
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

    /*
     * Build inbound evidence from the existing auto-reply log. This is not
     * the source for the customer list. It only prevents outbound log_wa
     * messages from becoming fake inbound CRM conversations.
     */
    $incomingEvidence = [];
    $evidenceResult = $conn->query(
        "SELECT contact_id, incoming_message, created_at
         FROM auto_reply_logs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)
           AND contact_id IS NOT NULL
           AND contact_id <> ''
         ORDER BY id DESC
         LIMIT 5000"
    );

    if ($evidenceResult) {
        while ($row = $evidenceResult->fetch_assoc()) {
            $number = crmChatNormalizeNumber((string)($row['contact_id'] ?? ''));
            $message = crmChatLegacySyncNormalizeText((string)($row['incoming_message'] ?? ''));
            $createdAt = (string)($row['created_at'] ?? '');

            if ($number === '' || $message === '' || $createdAt === '') continue;

            $incomingEvidence[$number][] = [
                'message' => $message,
                'created_at' => $createdAt,
            ];
        }
    }

    /*
     * Read legacy log_wa directly. This is the important correction from PR
     * #85: the legacy customer flow is represented in log_wa, not only in
     * auto_reply_logs.
     */
    $legacyResult = $conn->query(
        "SELECT id, nowa, nama, message, created_at
         FROM log_wa
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)
           AND nowa IS NOT NULL
           AND nowa <> ''
           AND nowa <> '6288223053149'
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
            str_contains($rawNumber, '@g')
        ) {
            continue;
        }

        // Only this number is internal for this legacy backfill.
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

        /*
         * Confirm that this log_wa row corresponds to an incoming WhatsApp
         * event. The timestamps normally come from the same webhook request,
         * so a small tolerance handles minor DB/request timing differences.
         */
        if (!crmChatLegacySyncHasInboundEvidence(
            $incomingEvidence[$number] ?? [],
            $normalizedMessage,
            $createdAt
        )) {
            continue;
        }

        // Keep only the newest qualifying legacy message per contact.
        if (!isset($candidates[$number])) {
            $row['number'] = $number;
            $row['message'] = $message;
            $row['normalized_message'] = $normalizedMessage;
            $candidates[$number] = $row;
        }
    }

    if (!$candidates) return 0;

    /*
     * Exclude actual known operational contacts. Do not use
     * crmGetDisqualifiedNumbers() here because that legacy helper also treats
     * "already sent registration form" as disqualified. A lead who has
     * received a form is still a customer/prospect until they become a real
     * participant.
     */
    $blocked = crmGetBlockedNumbers($conn);

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

    /*
     * Prepare writes once. Existing conversations are re-routed when they are
     * still automatic/lainnya. Manual routing and payment state are preserved.
     */
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

    $updateConversationActivity = $conn->prepare(
        "UPDATE crm_conversations
         SET last_message_at = GREATEST(COALESCE(last_message_at, '1000-01-01 00:00:00'), ?),
             last_inbound_at = GREATEST(COALESCE(last_inbound_at, '1000-01-01 00:00:00'), ?)
         WHERE id = ?"
    );

    $updated = 0;

    foreach ($candidates as $number => $row) {
        if (isset($blocked[$number])) continue;

        /*
         * peserta/pengampu/pengajar are checked independently from the
         * disqualified prospect list. This keeps "customer baru" available
         * for leads that have already received a registration form.
         */
        if (crmChatRoutingIsKnownContact($conn, $number)) continue;

        $message = (string)$row['message'];
        $createdAt = (string)$row['created_at'];
        $name = trim((string)($row['nama'] ?? ''));

        $intent = crmProspectClassifyMessage($message, $conn);
        if ($intent === 'Data CSV/Manual' || $intent === 'Lainnya') {
            $intent = null;
        }

        $conversationId = 0;

        if (isset($existing[$number])) {
            $existingRow = $existing[$number];
            $conversationId = (int)$existingRow['id'];

            if (
                strtolower(trim((string)$existingRow['room_source'])) === 'manual' ||
                $existingRow['room'] === 'sudah_payment' ||
                $existingRow['room'] === 'peserta_pengajar'
            ) {
                continue;
            }

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

        $externalId = 'legacy-log-wa:' . (int)$row['id'];

        $messageExists->bind_param('s', $externalId);
        $messageExists->execute();
        $alreadyStored = $messageExists->get_result()->num_rows > 0;

        if (!$alreadyStored) {
            $insertMessage->bind_param(
                'issss',
                $conversationId,
                $number,
                $message,
                $externalId,
                $createdAt
            );

            if ($insertMessage->execute() && $updateConversationActivity) {
                $updateConversationActivity->bind_param(
                    'ssi',
                    $createdAt,
                    $createdAt,
                    $conversationId
                );
                $updateConversationActivity->execute();
            }
        }

        $updated++;
    }

    if ($updateConversationActivity) $updateConversationActivity->close();
    $insertMessage->close();
    $messageExists->close();
    $routeExisting->close();
    $insertConversation->close();

    return $updated;
}

/**
 * Normalize a message for trigger comparison without changing the original
 * message stored in CRM.
 */
function crmChatLegacySyncNormalizeText(string $message): string {
    $message = strtolower(trim($message));
    $message = preg_replace('/\\s+/u', ' ', $message) ?? '';
    return trim($message);
}

/**
 * Check whether a log_wa row has matching inbound evidence from the webhook
 * auto-reply logger. Five minutes is intentionally generous for DB timing but
 * still narrow enough to avoid unrelated historical messages.
 */
function crmChatLegacySyncHasInboundEvidence(
    array $evidenceRows,
    string $normalizedMessage,
    string $createdAt
): bool {
    $legacyTime = strtotime($createdAt);
    if ($legacyTime === false) return false;

    foreach ($evidenceRows as $evidence) {
        if (($evidence['message'] ?? '') !== $normalizedMessage) continue;

        $evidenceTime = strtotime((string)($evidence['created_at'] ?? ''));
        if ($evidenceTime === false) continue;

        if (abs($legacyTime - $evidenceTime) <= 300) {
            return true;
        }
    }

    return false;
}
