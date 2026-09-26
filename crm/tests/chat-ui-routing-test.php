<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/chat-routing.php';

function assertSameValue($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assertSameValue('1=1', crmChatRoutingRoomSql('all'), 'all room');
assertSameValue("crm_conversations.room = 'customer_baru'", crmChatRoutingRoomSql('customer_baru'), 'customer baru room');
assertSameValue("crm_conversations.room = 'sudah_payment'", crmChatRoutingRoomSql('sudah_payment'), 'payment room');
assertSameValue("crm_conversations.room = 'peserta_pengajar'", crmChatRoutingRoomSql('peserta_pengajar'), 'peserta pengajar room');
assertSameValue('1=1', crmChatRoutingRoomSql('people'), 'legacy people room rejected');
assertSameValue('1=1', crmChatRoutingRoomSql('unknown'), 'unknown room rejected');
assertSameValue("crm_conversations.room = 'lainnya'", crmChatRoutingRoomSql('lainnya'), 'lainnya room');
assertSameValue('1=1', crmChatRoutingRoomSql('invalid'), 'invalid room fallback');

echo "Chat Phase 3 UI routing helper tests passed.\n";
