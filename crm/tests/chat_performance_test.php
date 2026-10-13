<?php
declare(strict_types=1);

$chat = file_get_contents(__DIR__ . '/../pages/chat.php');
$poll = file_get_contents(__DIR__ . '/../pages/chat-poll.php');

$checks = [
    'chat inbox uses persisted routing state instead of repeated payment-log scan' => strpos($chat, "c.payment_detected_at IS NOT NULL OR c.room = 'sudah_payment'") !== false,
    'chat polling no longer loads all disqualified numbers' => strpos($poll, 'crmGetDisqualifiedNumbers($conn)') === false,
    'chat polling no longer loads all blocked numbers' => strpos($poll, 'crmGetBlockedNumbers($conn)') === false,
    'chat polling checks exclusions for candidate numbers' => strpos($poll, 'crmPollIsDisqualified($conn, $variants, $directoryTables)') !== false && strpos($poll, 'crmPollIsBlocked($conn, $variants)') !== false,
    'chat polling batch is bounded' => strpos($poll, 'LIMIT 200') !== false,
    'chat polling retains max log id for cursor advancement' => strpos($poll, 'SELECT COALESCE(MAX(id), 0) AS max_id FROM log_wa') !== false,
    'chat polling retains eligible existing prospect fallback' => strpos($poll, 'crmFindEligibleProspectByNumber') !== false,
];

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
}
if ($failed) exit(1);
