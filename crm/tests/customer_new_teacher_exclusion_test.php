<?php
declare(strict_types=1);

$webhook = file_get_contents(__DIR__ . '/../../webhook.php');
$directory = file_get_contents(__DIR__ . '/../config/chat-directory.php');
$poll = file_get_contents(__DIR__ . '/../pages/chat-poll.php');
$prospect = file_get_contents(__DIR__ . '/../config/prospect.php');

$checks = [
    'teacher directory lookup uses only teacher/instructor tables' => strpos($directory, "table_name IN ('pengajar', 'pengampu')") !== false,
    'teacher lookup supports normalized phone variants' => strpos($directory, "'+' . $number") !== false && strpos($directory, "'0' . substr($number, 2)") !== false,
    'webhook preserves inbound storage before excluding teacher auto reply' => strpos($webhook, 'crmChatStoreMessage(') < strpos($webhook, 'AUTO REPLY/TRIGGER SKIPPED: known teacher contact'),
    'webhook skips auto reply for known teachers' => strpos($webhook, 'elseif ($isTeacherContact)') === false && strpos($webhook, "if ($isTeacherContact)") !== false && strpos($webhook, "'skipped_teacher'") !== false,
    'manual prospect triggers continue to use existing crm_prospect_triggers' => strpos($prospect, 'FROM crm_prospect_triggers WHERE active = 1') !== false,
    'polling excludes known directory contacts from customer-new eligibility' => strpos($poll, 'crmPollIsDisqualified($conn, $variants, $directoryTables)') !== false,
];

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
if ($failed) exit(1);
