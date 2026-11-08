<?php
declare(strict_types=1);

$chatPage = file_get_contents(__DIR__ . '/../pages/chat.php');
if ($chatPage === false) {
    throw new RuntimeException('Unable to read chat.php');
}
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
if ($css === false) {
    throw new RuntimeException('Unable to read app.css');
}

$required = [
    '$_GET[\'room\'] ?? \'customer_baru\'',
    '$allowedRooms = [\'customer_baru\', \'sudah_payment\', \'peserta_pengajar\', \'lainnya\']',
    '$selectedNumberForRoom = crmProspectNormalizeNumber($selected);',
    'if (in_array($selectedPersistedRoom, [\'sudah_payment\', \'peserta_pengajar\', \'lainnya\'], true))',
    '$room = $selectedPersistedRoom;',
    '.chat-history-section',
    'payment_detected_at IS NOT NULL',
    'NOT ({$paymentDetectedSql})',
    'c.room = \'sudah_payment\' OR {$paymentDetectedSql}',
];

foreach ($required as $needle) {
    if (strpos($chatPage, $needle) === false) {
        throw new RuntimeException('chat.php missing expected routing marker: ' . $needle);
    }
}

if (strpos($css, 'background:linear-gradient(145deg,#f7fbff 0%,#eef6ff 100%)') === false) {
    throw new RuntimeException('app.css missing expected chat background gradient');
}

if (strpos($chatPage, "'all' => ['label' => 'Semua Chat'") !== false) {
    throw new RuntimeException('Semua Chat room tab must not be rendered');
}

$chatPoll = file_get_contents(__DIR__ . '/../pages/chat-poll.php');
if ($chatPoll === false) {
    throw new RuntimeException('Unable to read chat-poll.php');
}

if (strpos($chatPoll, 'crmChatRoutingIsInternalNumber') === false) {
    throw new RuntimeException('chat-poll.php must exclude internal numbers');
}

if (strpos($chatPoll, "['all', 'customer_baru'") !== false || strpos($chatPoll, "'people', 'other'") !== false) {
    throw new RuntimeException('chat-poll.php must not use legacy room buckets');
}

echo "CRM Chat customer/payment routing test passed.\n";
