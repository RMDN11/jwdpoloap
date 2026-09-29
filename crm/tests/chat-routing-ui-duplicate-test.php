<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../pages/chat.php');
if ($page === false) {
    throw new RuntimeException('Cannot read chat.php');
}

$count = substr_count($page, '<div class="chat-routing-box">');
if ($count !== 1) {
    throw new RuntimeException('Expected exactly one routing panel, found ' . $count);
}

if (substr_count($page, 'action="actions/chat-route.php"') !== 1) {
    throw new RuntimeException('Expected exactly one routing form');
}

echo "Chat routing UI duplication test passed.\n";
