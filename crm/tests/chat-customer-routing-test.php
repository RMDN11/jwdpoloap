<?php
declare(strict_types=1);

$chatPage = file_get_contents(__DIR__ . '/../pages/chat.php');
if ($chatPage === false) {
    throw new RuntimeException('Unable to read chat.php');
}

$required = [
    "$_GET['room'] ?? 'customer_baru'",
    "$allowedRooms = ['customer_baru', 'sudah_payment', 'peserta_pengajar', 'lainnya']",
    'crmChatRoutingPaymentDetectedSql()',
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
if ($chatPoll === false || strpos($chatPoll, 'crmChatRoutingIsInternalNumber') === false) {
    throw new RuntimeException('chat-poll.php must exclude internal numbers');
}

echo "CRM Chat customer/payment routing test passed.\n";
