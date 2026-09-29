<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/chat-directory.php';
require_once __DIR__ . '/../config/prospect.php';
require_once __DIR__ . '/../config/chat-routing.php';

function assertSameValue($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assertSameValue(true, crmChatRoutingIsPaymentMessage('⛔ Wajib segera diisi'), 'payment pattern');
assertSameValue(true, crmChatRoutingIsPaymentMessage('Mohon diisi untuk pendataan Finance kami'), 'finance payment pattern');
assertSameValue(false, crmChatRoutingIsPaymentMessage('Silakan hubungi Finance'), 'finance alone');
assertSameValue(false, crmChatRoutingIsPaymentMessage('Wajib diisi'), 'partial payment phrase');

assertSameValue('customer_baru', crmChatRoutingResolveAutomaticRoomFake('081234567890', 'Murojaah'), 'qualifying intent room');
assertSameValue('customer_baru', crmChatRoutingResolveAutomaticRoomFake('081234567890', 'Lainnya'), 'unclear intent defaults to customer baru');
assertSameValue('customer_baru', crmChatRoutingResolveAutomaticRoomFake('081234567890', null), 'missing intent defaults to customer baru');
assertSameValue('peserta_pengajar', crmChatRoutingResolveAutomaticRoomFake('6288223053149', 'Murojaah'), 'internal room');

echo "Chat Phase 3 routing wiring tests passed.\n";

function crmChatRoutingResolveAutomaticRoomFake(string $number, ?string $category): string {
    if (crmChatRoutingIsInternalNumber($number)) return 'peserta_pengajar';
    // Unknown contacts stay visible in the default inbox regardless of
    // whether the first message has a recognized prospect intent.
    return 'customer_baru';
}
