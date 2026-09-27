<?php
declare(strict_types=1);

$files = [
    __DIR__ . '/../actions/send-message.php',
    __DIR__ . '/../actions/chat-route.php',
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException("Cannot read {$file}");
    }

    if (!str_contains($content, '$returnUrl(')) {
        throw new RuntimeException("Missing return-state redirect helper in {$file}");
    }

    if (preg_match("/header\('Location: \.\.\/index\.php\?page=chat(?:&contact=)?/", $content)) {
        throw new RuntimeException("Legacy state-dropping Chat redirect remains in {$file}");
    }
}

echo "Chat action return-state test passed.\n";
