<?php
declare(strict_types=1);

/**
 * Phase 3 routing foundation.
 *
 * This layer decides how a conversation is routed without hiding messages.
 * Manual routing always wins over automatic classification.
 */

function crmChatRoutingRooms(): array {
    return [
        'customer_baru',
        'sudah_payment',
        'peserta_pengajar',
        'lainnya',
    ];
}

function crmChatRoutingInternalNumbers(): array {
    // Keep internal/admin numbers centralized here.
    // Add future Jawwada operational numbers in this single directory.
    return [
        '6288223053149',
        '62248232064090227',
    ];
}

function crmChatRoutingNormalizedInternalNumbers(): array {
    $numbers = [];
    foreach (crmChatRoutingInternalNumbers() as $number) {
        $normalized = function_exists('crmProspectNormalizeNumber')
            ? crmProspectNormalizeNumber((string)$number)
            : crmChatNormalizeNumber((string)$number);
        if ($normalized !== '') $numbers[$normalized] = true;
    }
    return array_keys($numbers);
}

function crmChatRoutingInternalSql(string $alias = 'crm_conversations'): string {
    $checks = [];
    foreach (crmChatRoutingNormalizedInternalNumbers() as $number) {
        $local = '0' . substr($number, 2);
        $plus = '+' . $number;
        $checks[] = sprintf(
            "BINARY %s.nowa = BINARY '%s' OR BINARY %s.nowa = BINARY '%s' OR BINARY %s.nowa = BINARY '%s'",
            $alias,
            addslashes($number),
            $alias,
            addslashes($local),
            $alias,
            addslashes($plus)
        );
    }
    return $checks ? 'NOT (' . implode(' OR ', $checks) . ')' : '1=1';
}

function crmChatRoutingIsInternalNumber(string $nowa): bool {
    $normalized = function_exists('crmProspectNormalizeNumber')
        ? crmProspectNormalizeNumber($nowa)
        : crmChatNormalizeNumber($nowa);
    if ($normalized === '') return false;
    return in_array($normalized, crmChatRoutingNormalizedInternalNumbers(), true);
}

function crmChatRoutingIsKnownContact(mysqli $conn, string $nowa): bool {
    $number = function_exists('crmProspectNormalizeNumber')
        ? crmProspectNormalizeNumber($nowa)
        : crmChatNormalizeNumber($nowa);
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
        $stmt->bind_param('ssss', $number, $local, $plus, $nowa);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($found) return true;
    }

    return false;
}

function crmChatRoutingLatestInbound(mysqli $conn, int $conversationId): ?array {
    $stmt = $conn->prepare(
        "SELECT id, message, sent_at
         FROM crm_messages
         WHERE conversation_id = ? AND direction = 'in'
         ORDER BY sent_at DESC, id DESC
         LIMIT 1"
    );
    if (!$stmt) return null;

    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function crmChatRoutingClassifyInbound(mysqli $conn, int $conversationId, string $message): ?string {
    $message = trim($message);
    if ($message === '') return null;

    $category = function_exists('crmProspectClassifyMessage')
        ? crmProspectClassifyMessage($message, $conn)
        : 'Lainnya';

    if ($category === 'Data CSV/Manual') return null;
    return $category !== '' ? $category : 'Lainnya';
}

function crmChatRoutingIsQualifyingIntent(?string $category): bool {
    return is_string($category)
        && trim($category) !== ''
        && strtolower(trim($category)) !== 'lainnya'
        && strtolower(trim($category)) !== 'data csv/manual';
}

function crmChatRoutingNormalizeMessage(string $message): string {
    $message = strtolower(trim($message));
    $message = preg_replace('/\s+/u', ' ', $message) ?? '';
    return trim($message);
}

function crmChatRoutingIsPaymentMessage(string $message): bool {
    $m = crmChatRoutingNormalizeMessage($message);
    if ($m === '') return false;

    $patterns = [
        '/wajib\s+segera\s+diisi/u',
        '/mohon\s+diisi\s+untuk\s+pendataan\s+finance\s+kami/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $m)) return true;
    }

    return false;
}

function crmChatRoutingHasManualOverride(array $conversation): bool {
    return strtolower(trim((string)($conversation['room_source'] ?? 'auto'))) === 'manual';
}

function crmChatRoutingResolveAutomaticRoom(mysqli $conn, array $conversation, ?string $intentCategory = null): array {
    $nowa = (string)($conversation['nowa'] ?? '');

    if (crmChatRoutingIsInternalNumber($nowa)) {
        return ['room' => 'peserta_pengajar', 'source' => 'auto', 'reason' => 'internal'];
    }

    if (crmChatRoutingIsKnownContact($conn, $nowa)) {
        return ['room' => 'peserta_pengajar', 'source' => 'auto', 'reason' => 'known_contact'];
    }

    if (crmChatRoutingIsQualifyingIntent($intentCategory)) {
        return ['room' => 'customer_baru', 'source' => 'auto', 'reason' => 'qualifying_intent'];
    }

    return ['room' => 'lainnya', 'source' => 'auto', 'reason' => 'no_qualifying_intent'];
}

function crmChatRoutingPersist(mysqli $conn, int $conversationId, string $room, string $source, ?string $intentCategory = null, ?string $paymentDetectedAt = null): bool {
    if ($conversationId <= 0 || !in_array($room, crmChatRoutingRooms(), true)) return false;
    if (!in_array($source, ['auto', 'manual'], true)) return false;

    $stmt = $conn->prepare(
        "UPDATE crm_conversations
         SET room = ?, room_source = ?, intent_category = ?, payment_detected_at = ?
         WHERE id = ?"
    );
    if (!$stmt) return false;

    $stmt->bind_param('ssssi', $room, $source, $intentCategory, $paymentDetectedAt, $conversationId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function crmChatRoutingEvaluateConversation(mysqli $conn, int $conversationId, ?string $latestInboundMessage = null): ?array {
    if ($conversationId <= 0) return null;

    $stmt = $conn->prepare(
        "SELECT id, nowa, nama, room, room_source, intent_category, payment_detected_at
         FROM crm_conversations
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) return null;

    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $conversation = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$conversation) return null;
    if (crmChatRoutingHasManualOverride($conversation)) return $conversation;

    $intent = $latestInboundMessage !== null
        ? crmChatRoutingClassifyInbound($conn, $conversationId, $latestInboundMessage)
        : (string)($conversation['intent_category'] ?? '');

    if ($intent !== null && $intent !== '') {
        $conversation['intent_category'] = $intent;
    }

    $resolved = crmChatRoutingResolveAutomaticRoom($conn, $conversation, $conversation['intent_category'] ?? null);

    $paymentAt = $conversation['payment_detected_at'] ?? null;
    if ($paymentAt !== null && $paymentAt !== '') {
        $resolved = ['room' => 'sudah_payment', 'source' => 'auto', 'reason' => 'payment_state'];
    }

    crmChatRoutingPersist(
        $conn,
        (int)$conversation['id'],
        $resolved['room'],
        'auto',
        $conversation['intent_category'] !== '' ? $conversation['intent_category'] : null,
        $paymentAt !== '' ? $paymentAt : null
    );

    $conversation['room'] = $resolved['room'];
    $conversation['room_source'] = 'auto';
    $conversation['routing_reason'] = $resolved['reason'];

    return $conversation;
}

function crmChatRoutingRecordPaymentIfMatched(mysqli $conn, int $conversationId, string $message, ?string $detectedAt = null): bool {
    if ($conversationId <= 0 || !crmChatRoutingIsPaymentMessage($message)) return false;

    $stmt = $conn->prepare("SELECT room_source FROM crm_conversations WHERE id = ? LIMIT 1");
    if (!$stmt) return false;

    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row || strtolower(trim((string)($row['room_source'] ?? 'auto'))) === 'manual') {
        return false;
    }

    $detectedAt = $detectedAt ?: date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "UPDATE crm_conversations
         SET room = 'sudah_payment', room_source = 'auto', payment_detected_at = ?
         WHERE id = ? AND room_source <> 'manual'"
    );
    if (!$stmt) return false;

    $stmt->bind_param('si', $detectedAt, $conversationId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}


function crmChatRoutingRoomSql(string $room, string $alias = 'crm_conversations'): string {
    $allowed = crmChatRoutingRooms();
    if (!in_array($room, $allowed, true) || $room === 'all') return '1=1';
    $escaped = addslashes($room);
    return "{$alias}.room = '{$escaped}'";
}
