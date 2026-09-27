<?php
declare(strict_types=1);

$chatPage = file_get_contents(__DIR__ . '/../pages/chat.php');
if ($chatPage === false) {
    throw new RuntimeException('Unable to read chat.php');
}

$required = [
    '$_GET[\'room\'] ?? \'customer_baru\'',
    '$allowedRooms = [\'customer_baru\', \'sudah_payment\', \'peserta_pengajar\', \'lainnya\']',
    'crmChatRoutingPaymentDetectedSql()',
    'payment_detected_at IS NOT NULL',
    'NOT ({$paymentDetectedSql})',
    "crm_conversations.room = 'sudah_payment' OR {$paymentDetectedSql}",
];

foreach ($required as $needle) {
    if (strpos($chatPage, $needle) === false) {
        throw new RuntimeException('chat.php missing expected routing marker: ' . $needle);
    }
}

if (strpos($chatPage, "'all' => ['label' => 'Semua Chat'") !== false) {
    throw new RuntimeException('Semua Chat room tab must not be rendered');
}

$chatPoll = file_get_contents(__DIR__ . '/../pages/chat-poll.php');
if ($chatPoll === false) {
    throw new RuntimeException('Unable to read chat-poll.php');
}
foreach ([
    "$allowedRooms = ['customer_baru', 'sudah_payment', 'peserta_pengajar', 'lainnya']",
    '$paymentDetectedSql = crmChatRoutingPaymentDetectedSql();',
    '$internalSql = crmChatRoutingInternalSql();',
    'AND ($internalSql)',
    'NOT ({$paymentDetectedSql})',
    "crm_conversations.room = 'sudah_payment' OR {$paymentDetectedSql}",
] as $needle) {
    if (strpos($chatPoll, $needle) === false) {
        throw new RuntimeException('chat-poll.php missing expected routing marker: ' . $needle);
    }
}
if (strpos($chatPoll, "['all', 'customer_baru'") !== false || strpos($chatPoll, "'people', 'other'") !== false) {
    throw new RuntimeException('chat-poll.php must not use legacy room buckets');
}

echo "CRM Chat customer/payment routing test passed.\n";
