<?php
declare(strict_types=1);

$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
if ($css === false) throw new RuntimeException('Cannot read app.css');

foreach ([
    '.chat-log-in{background:#fff',
    '.chat-log-out{background:#f3f5f5',
    '.chat-log-out .chat-log-badge{background:#e6e9e8',
] as $needle) {
    if (!str_contains($css, $needle)) {
        throw new RuntimeException('Missing neutral conversation history style: ' . $needle);
    }
}

echo "Chat history neutral color test passed.\n";
