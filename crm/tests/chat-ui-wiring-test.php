<?php
declare(strict_types=1);

$chatPage = file_get_contents(__DIR__ . '/../pages/chat.php');
if ($chatPage === false) throw new RuntimeException('Cannot read chat.php');

$required = [
    'actions/chat-route.php' => 'manual routing form',
    'actions/chat-mark-read.php' => 'read-state action',
    'actions/chat-poll.php' => 'live polling endpoint',
    'chat-routing-box' => 'routing UI',
    'markSelectedRead' => 'mark selected conversation read',
    'window.setInterval(pollChat, 10000)' => 'live polling interval',
    'name="return_room"' => 'routing return room state',
];

foreach ($required as $needle => $label) {
    if (!str_contains($chatPage, $needle)) {
        throw new RuntimeException("Missing {$label}: {$needle}");
    }
}

if (str_contains($chatPage, "'people' => 'Peserta & Pengajar'")) {
    throw new RuntimeException('Legacy people routing key remains in chat UI');
}

$paymentRouting = file_get_contents(__DIR__ . '/../config/chat-routing.php');
if ($paymentRouting === false) throw new RuntimeException('Cannot read chat-routing.php');

if (!str_contains($paymentRouting, "COALESCE({$alias}.room_source, 'auto')"))) {
    throw new RuntimeException('Payment routing must respect manual room source');
}

echo "Chat UI wiring test passed.\n";
