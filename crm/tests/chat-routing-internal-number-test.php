<?php
declare(strict_types=1);

function crmChatNormalizeNumber(string $number): string {
    $number = preg_replace('/\D+/', '', $number) ?? '';
    if ($number !== '' && str_starts_with($number, '0')) {
        $number = '62' . substr($number, 1);
    }
    return $number;
}

require_once __DIR__ . '/../config/chat-routing.php';

function assertTrueValue(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$numbers = crmChatRoutingNormalizedInternalNumbers();

assertTrueValue(in_array('6288223053149', $numbers, true), 'first internal number normalized');
assertTrueValue(in_array('62248232064090227', $numbers, true), 'second internal number normalized');

$sql = crmChatRoutingInternalSql();

assertTrueValue(str_contains($sql, '6288223053149'), 'SQL contains first internal number');
assertTrueValue(str_contains($sql, '62248232064090227'), 'SQL contains second internal number');
assertTrueValue(str_contains($sql, '088223053149'), 'SQL contains local form');
assertTrueValue(str_contains($sql, '+6288223053149'), 'SQL contains plus form');

echo "Chat routing internal-number test passed.\n";
