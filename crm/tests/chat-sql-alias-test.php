<?php
declare(strict_types=1);

$chatPage = file_get_contents(__DIR__ . '/../pages/chat.php');
if ($chatPage === false) {
    throw new RuntimeException('Unable to read chat.php');
}

$required = [
    "\$paymentDetectedSql = crmChatRoutingPaymentDetectedSql('c');",
    "\$internalSql = crmChatRoutingInternalSql('c');",
    "'week' => \"c.last_inbound_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)\",",
    "'month' => \"c.last_inbound_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')\",",
    "default => 'c.last_inbound_at >= CURDATE()',",
    "c.room = 'customer_baru'",
    "c.room = 'sudah_payment'",
    "FROM crm_conversations c",
    "c.nama LIKE ?",
    "c.nowa LIKE ?",
    "search_message.conversation_id = c.id",
];

foreach ($required as $needle) {
    if (strpos($chatPage, $needle) === false) {
        throw new RuntimeException('chat.php missing alias regression marker: ' . $needle);
    }
}

$forbidden = [
    "crm_conversations.room = 'customer_baru'",
    "crm_conversations.room = 'sudah_payment'",
    "crm_conversations.room = 'peserta_pengajar'",
    "crm_conversations.room = 'lainnya'",
    "crm_conversations.id",
    "crm_conversations.last_inbound_at",
];

foreach ($forbidden as $needle) {
    if (strpos($chatPage, $needle) !== false) {
        throw new RuntimeException('chat.php still contains unaliased conversation reference: ' . $needle);
    }
}

echo "CRM Chat SQL alias regression test passed.\n";
