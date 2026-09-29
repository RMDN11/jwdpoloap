<?php
declare(strict_types=1);

$crmTitle = 'Chat';

require_once __DIR__ . '/../config/prospect.php';
require_once __DIR__ . '/../config/chat-directory.php';
require_once __DIR__ . '/../config/chat-routing.php';

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
$range = (string)($_GET['range'] ?? 'today');
$room = (string)($_GET['room'] ?? 'customer_baru');
$selected = trim((string)($_GET['contact'] ?? ''));
$chatPage = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;

if (!in_array($status, ['all', 'new', 'read', 'followed'], true)) {
    $status = 'all';
}
if (!in_array($range, ['today', 'week', 'month', 'all'], true)) {
    $range = 'today';
}
if (!in_array($room, ['customer_baru', 'sudah_payment', 'peserta_pengajar', 'lainnya'], true)) {
    $room = 'customer_baru';
}

$paymentDetectedSql = crmChatRoutingPaymentDetectedSql('c');
$internalSql = crmChatRoutingInternalSql('c');

if ($selected !== '') {
    $selectedNumberForRoom = crmProspectNormalizeNumber($selected);
    $selectedRoomStmt = $conn->prepare(
        "SELECT room
         FROM crm_conversations c
         WHERE (c.nowa = ? OR c.nowa = ?)
           AND {$internalSql}
         LIMIT 1"
    );
    if ($selectedRoomStmt) {
        $selectedRoomStmt->bind_param('ss', $selected, $selectedNumberForRoom);
        $selectedRoomStmt->execute();
        $selectedRoomRow = $selectedRoomStmt->get_result()->fetch_assoc() ?: null;
        $selectedRoomStmt->close();

        $selectedPersistedRoom = (string)($selectedRoomRow['room'] ?? '');
        if (in_array($selectedPersistedRoom, ['sudah_payment', 'peserta_pengajar', 'lainnya'], true)) {
            $room = $selectedPersistedRoom;
            $chatPage = 1;
        }
    }
}

$rangeSql = match ($range) {
    'week' => "c.last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
    'month' => "c.last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    'all' => '1=1',
    default => 'c.last_inbound_at >= CURDATE()',
};

$roomSql = match ($room) {
    'customer_baru' => "(c.room = 'customer_baru' AND NOT ({$paymentDetectedSql}))",
    'sudah_payment' => "(c.room = 'sudah_payment' OR {$paymentDetectedSql})",
    'peserta_pengajar' => "c.room = 'peserta_pengajar'",
    default => "c.room = 'lainnya'",
};

$where = [$rangeSql, $roomSql, $internalSql];
$bind = [];
$types = '';

if ($status === 'new') {
    $where[] = 'unread_count > 0';
} elseif ($status === 'read') {
    $where[] = 'unread_count = 0 AND followup_count = 0';
} elseif ($status === 'followed') {
    $where[] = 'followup_count > 0';
}

if ($search !== '') {
    $where[] = "(
        c.nama LIKE ?
        OR c.nowa LIKE ?
        OR EXISTS (
            SELECT 1
            FROM crm_messages search_message
            WHERE search_message.conversation_id = c.id
              AND search_message.message LIKE ?
        )
    )";
    $searchLike = '%' . $search . '%';
    $bind = [$searchLike, $searchLike, $searchLike];
    $types = 'sss';
}

$whereSql = implode(' AND ', $where);

$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM crm_conversations c
     WHERE {$whereSql}"
);
if ($types !== '') {
    $countStmt->bind_param($types, ...$bind);
}
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$countStmt->close();

$totalContacts = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalContacts / $perPage));
$chatPage = min($chatPage, $totalPages);
$offset = ($chatPage - 1) * $perPage;

$listSql = "
    SELECT
        c.id,
        c.nowa,
        c.nama,
        c.status,
        c.last_message_at,
        c.last_inbound_at,
        c.last_outbound_at,
        c.unread_count,
        c.followup_count,
        c.room,
        c.room_source,
        c.intent_category,
        c.payment_detected_at,
        m.message AS last_message,
        m.direction AS last_direction
    FROM crm_conversations c
    LEFT JOIN crm_messages m
        ON m.id = (
            SELECT latest.id
            FROM crm_messages latest
            WHERE latest.conversation_id = c.id
            ORDER BY latest.sent_at DESC, latest.id DESC
            LIMIT 1
        )
    WHERE {$whereSql}
    ORDER BY COALESCE(c.last_message_at, c.last_inbound_at, c.last_outbound_at, c.created_at) DESC, c.id DESC
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);
$listTypes = $types . 'ii';
$listBind = $bind;
$listBind[] = $perPage;
$listBind[] = $offset;
$listStmt->bind_param($listTypes, ...$listBind);
$listStmt->execute();

$contacts = [];
$listResult = $listStmt->get_result();
while ($row = $listResult->fetch_assoc()) {
    $row['clean_wa'] = crmProspectNormalizeNumber((string)$row['nowa']);
    $row['has_new_message'] = (int)$row['unread_count'] > 0;
    $contacts[] = $row;
}
$listStmt->close();

$roomCounts = [
    'customer_baru' => 0,
    'sudah_payment' => 0,
    'peserta_pengajar' => 0,
    'lainnya' => 0,
];

$roomCountSql = "
    SELECT room_bucket, COUNT(*) AS total
    FROM (
        SELECT CASE
            WHEN c.room = 'sudah_payment' OR {$paymentDetectedSql} THEN 'sudah_payment'
            WHEN c.room = 'customer_baru' AND NOT ({$paymentDetectedSql}) THEN 'customer_baru'
            WHEN c.room = 'peserta_pengajar' THEN 'peserta_pengajar'
            ELSE 'lainnya'
        END AS room_bucket
        FROM crm_conversations c
        WHERE {$rangeSql} AND {$internalSql}
    ) routed
    GROUP BY room_bucket
";

if ($result = $conn->query($roomCountSql)) {
    while ($row = $result->fetch_assoc()) {
        $bucket = (string)($row['room_bucket'] ?? 'lainnya');
        if (array_key_exists($bucket, $roomCounts)) {
            $roomCounts[$bucket] = (int)$row['total'];
        }
    }
}

$stats = [
    'total' => 0,
    'unread' => 0,
    'read_count' => 0,
    'followed_count' => 0,
];

$statsSql = "
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(unread_count > 0), 0) AS unread,
        COALESCE(SUM(unread_count = 0 AND followup_count = 0), 0) AS read_count,
        COALESCE(SUM(followup_count > 0), 0) AS followed_count
    FROM crm_conversations c
    WHERE {$rangeSql} AND ({$roomSql}) AND {$internalSql}
";

if ($result = $conn->query($statsSql)) {
    $statsRow = $result->fetch_assoc() ?: [];
    $stats = [
        'total' => (int)($statsRow['total'] ?? 0),
        'unread' => (int)($statsRow['unread'] ?? 0),
        'read_count' => (int)($statsRow['read_count'] ?? 0),
        'followed_count' => (int)($statsRow['followed_count'] ?? 0),
    ];
}

$selectedContact = null;
$recentMessages = [];
$legacyHistory = [];
$poloapHistory = [];

if ($selected !== '') {
    $selectedNumber = crmProspectNormalizeNumber($selected);

    $selectedStmt = $conn->prepare(
        "SELECT
            id, nowa, nama, status, last_message_at, last_inbound_at,
            last_outbound_at, last_read_at, unread_count, followup_count,
            room, room_source, intent_category, payment_detected_at
         FROM crm_conversations c
         WHERE (nowa = ? OR nowa = ?)
           AND {$internalSql}
         LIMIT 1"
    );
    $selectedStmt->bind_param('ss', $selected, $selectedNumber);
    $selectedStmt->execute();
    $selectedContact = $selectedStmt->get_result()->fetch_assoc() ?: null;
    $selectedStmt->close();

    if ($selectedContact) {
        $selectedContact['clean_wa'] = $selectedNumber;

        $historyStmt = $conn->prepare(
            "SELECT
                id, nowa, message, direction, sender_type, source,
                template_id, template_name, sent_at
             FROM crm_messages
             WHERE conversation_id = ?
             ORDER BY sent_at DESC, id DESC
             LIMIT 50"
        );
        $historyStmt->bind_param('i', $selectedContact['id']);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        while ($row = $historyResult->fetch_assoc()) {
            $recentMessages[] = $row;
        }
        $historyStmt->close();

        $legacyNumbers = array_values(array_unique(array_filter([
            (string)$selectedContact['nowa'],
            (string)$selectedContact['clean_wa'],
            str_starts_with($selectedNumber, '62') ? '0' . substr($selectedNumber, 2) : null,
            str_starts_with($selectedNumber, '62') ? '+' . $selectedNumber : null,
        ])));

        $placeholders = implode(',', array_fill(0, count($legacyNumbers), '?'));
        $legacyTypes = str_repeat('s', count($legacyNumbers));

        $legacyStmt = $conn->prepare(
            "SELECT id, nowa, nama, message, created_at, last_template_name
             FROM log_wa
             WHERE nowa IN ({$placeholders})
               AND nowa <> '6288223053149'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)
             ORDER BY created_at DESC, id DESC
             LIMIT 100"
        );

        if ($legacyStmt) {
            $legacyParams = [$legacyTypes];
            foreach ($legacyNumbers as $key => $value) {
                $legacyParams[] = &$legacyNumbers[$key];
            }
            call_user_func_array([$legacyStmt, 'bind_param'], $legacyParams);

            if ($legacyStmt->execute()) {
                $legacyResult = $legacyStmt->get_result();
                while ($legacyRow = $legacyResult->fetch_assoc()) {
                    $duplicate = false;

                    foreach ($recentMessages as $recentRow) {
                        if ((string)$recentRow['message'] !== (string)$legacyRow['message']) {
                            continue;
                        }

                        $recentTime = strtotime((string)$recentRow['sent_at']);
                        $legacyTime = strtotime((string)$legacyRow['created_at']);

                        if ($recentTime && $legacyTime && abs($recentTime - $legacyTime) <= 120) {
                            $duplicate = true;
                            break;
                        }
                    }

                    if (!$duplicate) {
                        $legacyHistory[] = $legacyRow;
                    }
                }
            }

            $legacyStmt->close();
        }

        $outboundStmt = $conn->prepare(
            "SELECT
                h.id, h.template_id, h.template_name, h.message, h.sent_at, h.status
             FROM crm_message_history h
             WHERE (h.nowa = ? OR h.nowa = ?)
               AND NOT EXISTS (
                   SELECT 1
                   FROM crm_messages cm
                   WHERE cm.nowa = h.nowa
                     AND cm.direction = 'out'
                     AND cm.message = h.message
                     AND ABS(TIMESTAMPDIFF(SECOND, cm.sent_at, h.sent_at)) <= 120
               )
             ORDER BY h.sent_at DESC, h.id DESC
             LIMIT 20"
        );
        $outboundStmt->bind_param('ss', $selectedContact['nowa'], $selectedContact['clean_wa']);
        $outboundStmt->execute();
        $outboundResult = $outboundStmt->get_result();

        while ($row = $outboundResult->fetch_assoc()) {
            $poloapHistory[] = $row;
        }
        $outboundStmt->close();
    }
}

$templates = [];
if ($result = $conn->query("SELECT id, name, content FROM poloap_templates ORDER BY name ASC")) {
    while ($row = $result->fetch_assoc()) {
        $templates[] = $row;
    }
}

function crmChatUrl(
    string $search,
    string $status,
    string $range = 'today',
    string $contact = '',
    int $chatPage = 1,
    string $room = 'customer_baru'
): string {
    $params = [
        'page' => 'chat',
        'status' => $status,
        'range' => $range,
        'room' => $room,
    ];

    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($contact !== '') {
        $params['contact'] = $contact;
    }
    if ($chatPage > 1) {
        $params['p'] = $chatPage;
    }

    return '?' . http_build_query($params);
}

function crmChatPreview(string $text, int $length = 68): string {
    $clean = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    return mb_strimwidth($clean, 0, $length, '…');
}

function crmChatDate(?string $date): string {
    if (!$date) return '';
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y, H:i', $timestamp) : '';
}

$selectedName = trim((string)($selectedContact['nama'] ?? '')) ?: 'Hamba Allah';
?>

<section class="page-head chat-page-head">
    <div>
        <span class="eyebrow">CRM Chat</span>
        <h1>Percakapan</h1>
        <p>Kelola pesan masuk, riwayat, dan balasan dalam satu workspace.</p>
    </div>
    <?php if ($selectedContact): ?>
        <div class="chat-page-actions">
            <a class="chat-wa-link" href="https://wa.me/<?= htmlspecialchars($selectedContact['clean_wa']) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-whatsapp"></i> WhatsApp
            </a>
        </div>
    <?php endif; ?>
</section>

<div class="chat-range-tabs" aria-label="Rentang waktu percakapan">
    <?php foreach (['today' => 'Hari Ini', 'week' => 'Minggu Ini', 'month' => 'Bulan Ini', 'all' => 'Semua Waktu'] as $key => $label): ?>
        <a class="<?= $range === $key ? 'active' : '' ?>"
           href="<?= htmlspecialchars(crmChatUrl($search, $status, $key, '', 1, $room)) ?>">
            <?= htmlspecialchars($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="chat-room-tabs" aria-label="Ruang chat">
    <?php
    $roomLabels = [
        'customer_baru' => ['Customer Baru', 'fa-user-plus'],
        'sudah_payment' => ['Sudah Payment', 'fa-wallet'],
        'peserta_pengajar' => ['Peserta & Pengajar', 'fa-users'],
        'lainnya' => ['Lainnya', 'fa-inbox'],
    ];
    ?>
    <?php foreach ($roomLabels as $key => [$label, $icon]): ?>
        <a data-chat-room="<?= htmlspecialchars($key) ?>"
           class="<?= $room === $key ? 'active' : '' ?>"
           href="<?= htmlspecialchars(crmChatUrl($search, $status, $range, '', 1, $key)) ?>">
            <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
            <span><?= htmlspecialchars($label) ?></span>
            <b><?= (int)$roomCounts[$key] ?></b>
        </a>
    <?php endforeach; ?>
</div>

<div class="chat-stats">
    <a data-chat-stat="all" class="<?= $status === 'all' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search, 'all', $range, '', 1, $room)) ?>">
        <strong><?= $stats['total'] ?></strong><span>Semua</span>
    </a>
    <a data-chat-stat="new" class="<?= $status === 'new' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search, 'new', $range, '', 1, $room)) ?>">
        <strong><?= $stats['unread'] ?></strong><span>Perlu Follow-up</span>
    </a>
    <a data-chat-stat="read" class="<?= $status === 'read' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search, 'read', $range, '', 1, $room)) ?>">
        <strong><?= $stats['read_count'] ?></strong><span>Sudah Dibaca</span>
    </a>
    <a data-chat-stat="followed" class="<?= $status === 'followed' ? 'active' : '' ?>" href="<?= htmlspecialchars(crmChatUrl($search, 'followed', $range, '', 1, $room)) ?>">
        <strong><?= $stats['followed_count'] ?></strong><span>Sudah Follow-up</span>
    </a>
</div>

<form class="search-box" method="get">
    <input type="hidden" name="page" value="chat">
    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
    <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
    <input type="hidden" name="room" value="<?= htmlspecialchars($room) ?>">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, nomor, atau isi pesan...">
    <?php if ($search): ?>
        <a href="<?= htmlspecialchars(crmChatUrl('', $status, $range, '', 1, $room)) ?>">
            <i class="fa-solid fa-xmark"></i>
        </a>
    <?php endif; ?>
</form>

<div class="chat-sheet-backdrop" id="crmChatSheetBackdrop"></div>

<div class="chat-layout">
    <div class="chat-list">
        <?php if (!$contacts): ?>
            <div class="empty-state">
                <i class="fa-regular fa-comments"></i>
                <strong>Tidak ada percakapan</strong>
                <p>Belum ada data yang cocok dengan filter ini.</p>
            </div>
        <?php else: ?>
            <?php foreach ($contacts as $contact): ?>
                <?php
                $name = trim((string)$contact['nama']) ?: 'Hamba Allah';
                $lastActivity = $contact['last_message_at'] ?: ($contact['last_inbound_at'] ?: $contact['last_outbound_at']);
                $selectedHere = $selected !== ''
                    && crmProspectNormalizeNumber($selected) === $contact['clean_wa'];
                $label = match ($contact['room'] ?? '') {
                    'customer_baru' => 'Customer Baru',
                    'sudah_payment' => 'Sudah Payment',
                    'lainnya' => 'Lainnya',
                    default => ($contact['last_direction'] ?? '') === 'in' ? 'Pesan masuk' : 'Dikirim',
                };
                ?>
                <a data-chat-nowa="<?= htmlspecialchars($contact['nowa']) ?>"
                   href="<?= htmlspecialchars(crmChatUrl($search, $status, $range, $contact['nowa'], $chatPage, $room)) ?>"
                   class="chat-item <?= $selectedHere ? 'selected' : '' ?> <?= !empty($contact['has_new_message']) ? 'is-new' : '' ?> <?= ($contact['room'] ?? '') === 'lainnya' ? 'is-other' : '' ?>">
                    <span class="activity-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                    <span class="chat-body">
                        <strong><?= htmlspecialchars($name) ?></strong>
                        <small>
                            <span class="chat-item-label"><?= htmlspecialchars($label) ?></span>
                            · <?= htmlspecialchars(crmChatPreview((string)($contact['last_message'] ?? ''))) ?>
                        </small>
                    </span>
                    <span class="chat-meta">
                        <time><?= htmlspecialchars($lastActivity ? date('H:i', strtotime($lastActivity)) : '') ?></time>
                        <?php if (!empty($contact['has_new_message'])): ?>
                            <b class="chat-new-badge">BARU</b>
                        <?php elseif ((int)$contact['followup_count'] > 0): ?>
                            <i class="fa-solid fa-check-double" title="<?= (int)$contact['followup_count'] ?> follow-up"></i>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <aside class="chat-panel <?= $selectedContact ? 'has-contact' : '' ?>">
        <?php if (!$selectedContact): ?>
            <div class="chat-panel-empty">
                <i class="fa-regular fa-message"></i>
                <strong>Pilih percakapan</strong>
                <span>Pilih percakapan untuk melihat riwayat dan membalas pesan.</span>
            </div>
        <?php else: ?>
            <div class="chat-panel-head">
                <div class="contact-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($selectedName, 0, 1))) ?></div>
                <div class="chat-panel-contact">
                    <strong><?= htmlspecialchars($selectedName) ?></strong>
                    <small><?= htmlspecialchars($selectedContact['nowa']) ?></small>
                </div>
                <a class="chat-panel-close" href="<?= htmlspecialchars(crmChatUrl($search, $status, $range, '', $chatPage, $room)) ?>" aria-label="Tutup percakapan">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </div>

            <div class="chat-routing-box">
                <div class="chat-routing-title">
                    <span><i class="fa-solid fa-route"></i> Routing</span>
                    <small><?= (($selectedContact['room_source'] ?? 'auto') === 'manual') ? 'Manual' : 'Auto' ?></small>
                </div>
                <form method="post" action="actions/chat-route.php" class="chat-routing-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                    <input type="hidden" name="contact_id" value="<?= htmlspecialchars($selectedContact['nowa']) ?>">
                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="return_range" value="<?= htmlspecialchars($range) ?>">
                    <input type="hidden" name="return_room" value="<?= htmlspecialchars($room) ?>">
                    <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="return_p" value="<?= (int)$chatPage ?>">
                    <select name="room" aria-label="Pilih room routing">
                        <?php foreach ([
                            'customer_baru' => 'Customer Baru',
                            'sudah_payment' => 'Sudah Payment',
                            'peserta_pengajar' => 'Peserta & Pengajar',
                            'lainnya' => 'Lainnya',
                        ] as $routingKey => $routingLabel): ?>
                            <option value="<?= htmlspecialchars($routingKey) ?>" <?= (($selectedContact['room'] ?? 'lainnya') === $routingKey) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($routingLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label class="chat-routing-manual">
                        <input type="checkbox" name="manual" value="1" <?= (($selectedContact['room_source'] ?? 'auto') === 'manual') ? 'checked' : '' ?>>
                        Jadikan manual
                    </label>
                    <button type="submit"><i class="fa-solid fa-check"></i> Simpan</button>
                    <?php if (($selectedContact['room_source'] ?? 'auto') === 'manual'): ?>
                        <button type="submit" name="clear_manual" value="1" class="chat-routing-clear">↩ Auto</button>
                    <?php endif; ?>
                </form>
            </div>

            <div class="chat-history-section">
                <div class="section-title-row">
                    <span class="message-label">Percakapan terbaru</span>
                    <small><?= count($recentMessages) ?> log terakhir</small>
                </div>
                <div class="chat-history-scroll">
                    <?php if (!$recentMessages): ?>
                        <div class="history-empty">Belum ada pesan dalam percakapan ini.</div>
                    <?php else: ?>
                        <?php foreach (array_reverse($recentMessages) as $message): ?>
                            <div class="chat-log-item <?= ($message['direction'] ?? '') === 'in' ? 'chat-log-in' : 'chat-log-out' ?>">
                                <div class="chat-log-meta">
                                    <time><?= htmlspecialchars(crmChatDate($message['sent_at'] ?? null)) ?></time>
                                    <span class="chat-log-badge"><?= ($message['direction'] ?? '') === 'in' ? 'Masuk' : 'Admin' ?></span>
                                </div>
                                <p><?= nl2br(htmlspecialchars((string)$message['message'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="legacy-history-section">
                <div class="section-title-row">
                    <span class="message-label">Riwayat WhatsApp Lama</span>
                    <small><?= count($legacyHistory) ?> log lama</small>
                </div>
                <div class="legacy-history-list">
                    <?php if (!$legacyHistory): ?>
                        <div class="history-empty">Belum ada riwayat WhatsApp lama untuk kontak ini.</div>
                    <?php else: ?>
                        <?php foreach (array_reverse($legacyHistory) as $message): ?>
                            <div class="legacy-history-item">
                                <div class="chat-log-meta">
                                    <time><?= htmlspecialchars(crmChatDate($message['created_at'] ?? null)) ?></time>
                                    <span class="chat-log-badge">Legacy</span>
                                </div>
                                <p><?= nl2br(htmlspecialchars((string)$message['message'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="poloap-history-section">
                <div class="section-title-row">
                    <span class="message-label">Riwayat Follow-up Lama</span>
                    <small><?= count($poloapHistory) ?> log lama</small>
                </div>
                <div class="poloap-history-list">
                    <?php if (!$poloapHistory): ?>
                        <div class="history-empty">Belum ada riwayat Poloap untuk kontak ini.</div>
                    <?php else: ?>
                        <?php foreach ($poloapHistory as $history): ?>
                            <div class="poloap-history-item">
                                <div>
                                    <strong><?= htmlspecialchars((string)($history['template_name'] ?? 'Pesan')) ?></strong>
                                    <time><?= htmlspecialchars($history['sent_at'] ? crmChatDate($history['sent_at']) : 'Riwayat lama') ?></time>
                                </div>
                                <?php if (!empty($history['message'])): ?>
                                    <p><?= nl2br(htmlspecialchars((string)$history['message'])) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <form class="send-box" method="post" action="actions/send-message.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
                <input type="hidden" name="contact_id" value="<?= htmlspecialchars($selectedContact['nowa']) ?>">
                <input type="hidden" name="return_status" value="<?= htmlspecialchars($status) ?>">
                <input type="hidden" name="return_range" value="<?= htmlspecialchars($range) ?>">
                <input type="hidden" name="return_room" value="<?= htmlspecialchars($room) ?>">
                <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="return_p" value="<?= (int)$chatPage ?>">

                <label>
                    <span>Template</span>
                    <select name="template_id" id="crmTemplateSelect">
                        <option value="">Pilih template...</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?= (int)$template['id'] ?>"
                                    data-content="<?= htmlspecialchars((string)$template['content'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars((string)$template['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="template-preview" id="crmTemplatePreview">
                    <span>Pilih template untuk melihat isi pesan.</span>
                </div>

                <label>
                    <span>Pesan custom <small>(opsional)</small></span>
                    <textarea name="custom_message" rows="4" placeholder="Tulis pesan untuk <?= htmlspecialchars($selectedName) ?>..."></textarea>
                </label>

                <div class="send-box-foot">
                    <small><i class="fa-solid fa-circle-info"></i> [nama] akan otomatis diganti.</small>
                    <button type="submit"><i class="fa-solid fa-paper-plane"></i> Kirim</button>
                </div>
            </form>
        <?php endif; ?>
    </aside>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="chat-pagination" aria-label="Pagination Chat">
        <?php if ($chatPage > 1): ?>
            <a href="<?= htmlspecialchars(crmChatUrl($search, $status, $range, '', $chatPage - 1, $room)) ?>">
                <i class="fa-solid fa-chevron-left"></i> Sebelumnya
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</span>
        <?php endif; ?>

        <strong>Halaman <?= $chatPage ?> / <?= $totalPages ?></strong>

        <?php if ($chatPage < $totalPages): ?>
            <a href="<?= htmlspecialchars(crmChatUrl($search, $status, $range, '', $chatPage + 1, $room)) ?>">
                Berikutnya <i class="fa-solid fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled">Berikutnya <i class="fa-solid fa-chevron-right"></i></span>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<script>
(() => {
    const backdrop = document.getElementById('crmChatSheetBackdrop');
    const close = document.querySelector('.chat-panel-close');
    if (backdrop && close) {
        backdrop.addEventListener('click', () => close.click());
    }

    const selectedNumber = <?= $selectedContact ? json_encode($selectedContact['nowa']) : 'null' ?>;
    const csrfToken = <?= json_encode(crmCsrfToken()) ?>;
    const chatPollUrl = <?= json_encode('actions/chat-poll.php') ?>;
    const chatCurrentSearch = <?= json_encode($search) ?>;
    const chatCurrentStatus = <?= json_encode($status) ?>;
    const chatCurrentRange = <?= json_encode($range) ?>;
    const chatCurrentRoom = <?= json_encode($room) ?>;
    const chatCurrentPage = <?= (int)$chatPage ?>;
    let chatPollCursor = <?= json_encode(date('Y-m-d H:i:s')) ?>;
    let chatPollBusy = false;

    const normalizeChatNumber = (value) => {
        let number = String(value || '').replace(/\D+/g, '');
        if (number.startsWith('0')) number = '62' + number.slice(1);
        if (number.startsWith('8')) number = '62' + number;
        return number;
    };

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    };

    const markSelectedRead = () => {
        if (!selectedNumber) return Promise.resolve();
        const form = new URLSearchParams();
        form.set('nowa', selectedNumber);
        form.set('csrf', csrfToken);
        return fetch('actions/chat-mark-read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: form.toString(),
            credentials: 'same-origin',
            keepalive: true
        }).then((response) => {
            if (!response.ok) return;
            document.querySelectorAll('.chat-item.is-new').forEach((item) => {
                if (normalizeChatNumber(item.dataset.chatNowa || '') === normalizeChatNumber(selectedNumber)) {
                    item.classList.remove('is-new');
                    item.querySelector('.chat-new-badge')?.remove();
                }
            });
        }).catch(() => {});
    };

    markSelectedRead();

    const renderSelectedHistory = (messages) => {
        const scroll = document.querySelector('.chat-history-scroll');
        const countNode = document.querySelector('.chat-history-section .section-title-row small');
        if (!scroll || !Array.isArray(messages)) return;
        const ordered = [...messages].reverse();
        scroll.innerHTML = ordered.length ? ordered.map((message) => {
            const inbound = String(message.direction || '') === 'in';
            const dateText = message.sent_at ? new Date(String(message.sent_at).replace(' ', 'T')).toLocaleString('id-ID', {
                day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
            }) : '';
            return '<div class="chat-log-item ' + (inbound ? 'chat-log-in' : 'chat-log-out') + '">' +
                '<div class="chat-log-meta"><time>' + escapeHtml(dateText) + '</time><span class="chat-log-badge">' + (inbound ? 'Masuk' : 'Admin') + '</span></div>' +
                '<p>' + escapeHtml(String(message.message || '')).replace(/\n/g, '<br>') + '</p>' +
            '</div>';
        }).join('') : '<div class="history-empty">Belum ada pesan dalam percakapan ini.</div>';
        if (countNode) countNode.textContent = ordered.length + ' log terakhir';
    };

    const updateChatRoomCounts = (counts) => {
        if (!counts) return;
        Object.entries(counts).forEach(([key, value]) => {
            const node = document.querySelector('[data-chat-room="' + key + '"] b');
            if (node && Number.isFinite(Number(value))) node.textContent = String(value);
        });
    };

    const updateChatStats = (stats) => {
        if (!stats) return;
        const values = {all: stats.total, new: stats.unread, read: stats.read_count, followed: stats.followed_count};
        Object.entries(values).forEach(([key, value]) => {
            const node = document.querySelector('[data-chat-stat="' + key + '"] strong');
            if (node && Number.isFinite(Number(value))) node.textContent = String(value);
        });
    };

    const chatItemUrl = (nowa) => {
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'chat');
        params.set('status', chatCurrentStatus);
        params.set('range', chatCurrentRange);
        params.set('room', chatCurrentRoom);
        params.set('contact', nowa);
        params.delete('p');
        return '?' + params.toString();
    };

    const buildChatItem = (row) => {
        const name = String(row.nama || 'Hamba Allah').trim() || 'Hamba Allah';
        const preview = String(row.last_message || '').replace(/\s+/g, ' ').trim().slice(0, 68);
        const direction = row.last_direction === 'in' ? 'Pesan masuk' : 'Dikirim';
        const roomLabel = row.room === 'customer_baru' ? 'Customer Baru'
            : (row.room === 'sudah_payment' ? 'Sudah Payment'
            : (row.room === 'peserta_pengajar' ? 'Peserta & Pengajar'
            : (row.room === 'lainnya' ? 'Lainnya' : direction)));
        const time = row.last_message_at ? new Date(row.last_message_at.replace(' ', 'T')).toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'}) : '';
        const item = document.createElement('a');
        item.className = 'chat-item' + (row.unread_count > 0 ? ' is-new' : '') + (row.room === 'lainnya' ? ' is-other' : '');
        item.dataset.chatNowa = row.nowa;
        item.href = chatItemUrl(row.nowa);
        item.innerHTML = '<span class="activity-avatar">' + escapeHtml(name.slice(0, 1).toUpperCase()) + '</span>' +
            '<span class="chat-body"><strong>' + escapeHtml(name) + '</strong><small><span class="chat-item-label">' + escapeHtml(roomLabel) + '</span> · ' + escapeHtml(preview) + '</small></span>' +
            '<span class="chat-meta"><time>' + escapeHtml(time) + '</time>' +
            (row.unread_count > 0 ? '<b class="chat-new-badge">BARU</b>' : (row.followup_count > 0 ? '<i class="fa-solid fa-check-double"></i>' : '')) + '</span>';
        return item;
    };

    const updateChatItem = (row) => {
        const item = document.querySelector('.chat-item[data-chat-nowa="' + CSS.escape(row.nowa) + '"]');
        if (!item) return;
        const body = item.querySelector('.chat-body');
        const meta = item.querySelector('.chat-meta');
        const roomLabel = row.room === 'customer_baru' ? 'Customer Baru'
            : (row.room === 'sudah_payment' ? 'Sudah Payment'
            : (row.room === 'peserta_pengajar' ? 'Peserta & Pengajar'
            : (row.room === 'lainnya' ? 'Lainnya' : (row.last_direction === 'in' ? 'Pesan masuk' : 'Dikirim'))));
        if (body) {
            const name = body.querySelector('strong');
            const detail = body.querySelector('small');
            if (name) name.textContent = String(row.nama || 'Hamba Allah');
            if (detail) detail.innerHTML = '<span class="chat-item-label">' + escapeHtml(roomLabel) + '</span> · ' + escapeHtml(String(row.last_message || '').replace(/\s+/g, ' ').trim().slice(0, 68));
        }
        if (meta) {
            const timeNode = meta.querySelector('time');
            if (timeNode) timeNode.textContent = row.last_message_at ? new Date(row.last_message_at.replace(' ', 'T')).toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'}) : '';
            meta.querySelector('.chat-new-badge')?.remove();
            if (row.unread_count > 0) {
                item.classList.add('is-new');
                const badge = document.createElement('b');
                badge.className = 'chat-new-badge';
                badge.textContent = 'BARU';
                meta.appendChild(badge);
            } else {
                item.classList.remove('is-new');
            }
        }
    };

    const syncChatList = (row) => {
        const list = document.querySelector('.chat-list');
        if (!list) return;
        const item = document.querySelector('.chat-item[data-chat-nowa="' + CSS.escape(row.nowa) + '"]');
        if (!row.matches_filter) {
            if (item && !item.classList.contains('selected')) item.remove();
            return;
        }
        if (item) {
            updateChatItem(row);
            return;
        }
        if (chatCurrentPage !== 1) return;
        list.querySelector('.empty-state')?.remove();
        list.prepend(buildChatItem(row));
        const items = list.querySelectorAll('.chat-item');
        if (items.length > 20) items[items.length - 1].remove();
    };

    const pollChat = async () => {
        if (chatPollBusy || document.hidden) return;
        chatPollBusy = true;
        try {
            const query = new URLSearchParams({
                since: chatPollCursor, room: chatCurrentRoom, status: chatCurrentStatus,
                range: chatCurrentRange, q: chatCurrentSearch
            });
            if (selectedNumber) query.set('contact', selectedNumber);
            const response = await fetch(chatPollUrl + '?' + query.toString(), {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) return;
            const data = await response.json();
            if (!data?.ok) return;
            chatPollCursor = data.server_time || chatPollCursor;
            updateChatStats(data.stats);
            updateChatRoomCounts(data.room_counts);
            for (const row of (data.conversations || [])) {
                syncChatList(row);
                if (selectedNumber && normalizeChatNumber(row.nowa || '') === normalizeChatNumber(selectedNumber)) {
                    renderSelectedHistory(data.selected_history || []);
                }
            }
            if (selectedNumber) markSelectedRead();
        } catch (_) {
            // Polling is non-critical. Next interval retries.
        } finally {
            chatPollBusy = false;
        }
    };

    window.setInterval(pollChat, 10000);

    const select = document.getElementById('crmTemplateSelect');
    const preview = document.getElementById('crmTemplatePreview');

    if (select && preview) {
        select.addEventListener('change', () => {
            const option = select.options[select.selectedIndex];
            let content = option?.dataset?.content || '';
            const name = <?= json_encode($selectedName, JSON_UNESCAPED_UNICODE) ?>;

            content = content.replace(/\[(nama|NAMA)\]|\{(nama|NAMA)\}/g, name);

            if (!content) {
                preview.innerHTML = '<span>Pilih template untuk melihat isi pesan.</span>';
                return;
            }

            const safe = content
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\n/g, '<br>');

            preview.innerHTML =
                '<span class="template-preview-label">Preview pesan</span><p>' + safe + '</p>';
        });
    }
})();
</script>
