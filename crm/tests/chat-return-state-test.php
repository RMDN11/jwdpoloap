<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/chat.php';

function assertSameValue($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ": expected " . var_export($expected, true) .
            ", got " . var_export($actual, true)
        );
    }
}

$actual = crmChatBuildReturnUrl(
    '6281234567890',
    'all',
    'month',
    'customer_baru',
    'cari kak info',
    2
);

$expected = '../index.php?page=chat&status=all&range=month&room=customer_baru&q=cari+kak+info&contact=6281234567890&p=2';

assertSameValue($expected, $actual, 'preserve chat view state');

echo "Chat return-state test passed.\n";
