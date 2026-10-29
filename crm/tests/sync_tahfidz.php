<?php
declare(strict_types=1);

if (($_GET['token'] ?? '') !== 'jwd_auto_cron') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: application/json');

echo json_encode([
    'disabled_functions' => ini_get('disable_functions'),
    'can_exec' => function_exists('exec'),
    'can_shell_exec' => function_exists('shell_exec'),
], JSON_PRETTY_PRINT);
