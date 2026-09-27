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

assertSameValue('6281234567890', crmProspectNormalizeNumber('081234567890'), 'normalisasi nomor 08');
assertSameValue('6281234567890', crmProspectNormalizeNumber('+62 812-3456-7890'), 'normalisasi nomor +62');

assertSameValue(true, crmChatRoutingIsPaymentMessage("⛔ Wajib segera diisi\nMohon diisi untuk pendataan Finance kami"), 'payment message with line break');
assertSameValue(true, crmChatRoutingIsPaymentMessage("📋 KBM 4 OKTOBER\n⛔ Wajib   segera   diisi\nMohon\\ndiisi para peserta untuk pendataan Finance kami"), 'payment message with whitespace variants');
assertSameValue(true, crmChatRoutingIsPaymentMessage("Wajib segeradiisi\nMohon diisi untuk pendataan Finance kami"), 'payment message without space in segera diisi');
assertSameValue(false, crmChatRoutingIsPaymentMessage('⛔ Wajib segera diisi'), 'payment marker 2 required');
assertSameValue(false, crmChatRoutingIsPaymentMessage('Mohon diisi untuk pendataan Finance kami'), 'payment marker 1 required');
assertSameValue(false, crmChatRoutingIsPaymentMessage('Silakan hubungi Finance jika ada pertanyaan.'), 'finance alone');
assertSameValue(false, crmChatRoutingIsPaymentMessage('Wajib diisi'), 'partial payment phrase');

assertSameValue(true, crmChatRoutingIsQualifyingIntent("Muroja'ah"), 'qualifying intent');
assertSameValue(false, crmChatRoutingIsQualifyingIntent('Lainnya'), 'non qualifying intent');

$rooms = crmChatRoutingRooms();
foreach (['customer_baru','sudah_payment','peserta_pengajar','lainnya'] as $room) {
    if (!in_array($room, $rooms, true)) {
        throw new RuntimeException('missing room: ' . $room);
    }
}

$internal = crmChatRoutingNormalizedInternalNumbers();
if (!in_array('6288223053149', $internal, true)) {
    throw new RuntimeException('internal number directory missing expected initial number');
}
if (!in_array('248232064090227', $internal, true) || !in_array('62248232064090227', $internal, true)) {
    throw new RuntimeException('admin number directory missing 248232064090227 variants');
}

$paymentSql = crmChatRoutingPaymentDetectedSql();
foreach (['log_wa', 'wajibsegeradiisi', 'mohondiisiuntukpendataanfinancekami'] as $needle) {
    if (stripos($paymentSql, $needle) === false) {
        throw new RuntimeException('payment SQL missing expected marker: ' . $needle);
    }
}

echo "Chat Phase 3 routing foundation tests passed.\n";
