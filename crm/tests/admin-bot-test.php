<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__, 2);
$configPath = $baseDir . '/crm/config/admin-bot.php';
$libPath = $baseDir . '/crm/lib/admin-bot.php';
$webhookPath = $baseDir . '/webhook.php';
$autoReplyPath = $baseDir . '/auto_reply_engine.php';

$files = [$configPath, $libPath, $webhookPath, $autoReplyPath];
foreach ($files as $f) {
    if (!file_exists($f)) {
        throw new RuntimeException("Missing required file: {$f}");
    }
}

$configContent = file_get_contents($configPath);
$libContent = file_get_contents($libPath);
$webhookContent = file_get_contents($webhookPath);
$autoReplyContent = file_get_contents($autoReplyPath);

// 1. Verifikasi Token Konfigurasi Admin Bot
$requiredConfigTokens = [
    'ADMIN_BOT_ALLOWED_NUMBERS',
    'GEMINI_API_KEY',
    'GEMINI_API_MODEL',
    'ADMIN_BOT_ENABLED',
    'crmAdminBotNormalizeNumber',
    'crmAdminBotIsAdmin',
    'crmAdminBotEnsureTables',
    'crm_admin_todos'
];

foreach ($requiredConfigTokens as $token) {
    if (!str_contains($configContent, $token)) {
        throw new RuntimeException("admin-bot.php missing required token: {$token}");
    }
}

// 2. Verifikasi Engine Admin Bot
$requiredLibTokens = [
    'crmAdminBotAddTodo',
    'crmAdminBotGetTodos',
    'crmAdminBotCompleteTodo',
    'crmAdminBotDeleteTodo',
    'crmAdminBotFormatTodosResponse',
    'crmAdminBotGetSystemStatus',
    'crmAdminBotGetQueueStatus',
    'crmAdminBotGetHelpMessage',
    'crmAdminBotCallGemini',
    'crmAdminBotSendMessage',
    'crmAdminBotProcessMessage',
    '!todo',
    '!status',
    '!queue',
    '!help',
    '!ai'
];

foreach ($requiredLibTokens as $token) {
    if (!str_contains($libContent, $token)) {
        throw new RuntimeException("admin-bot.php lib missing required token: {$token}");
    }
}

// 3. Verifikasi Webhook Dispatcher
$requiredWebhookTokens = [
    'crm/config/admin-bot.php',
    'crm/lib/admin-bot.php',
    'crmAdminBotIsAdmin',
    'crmAdminBotProcessMessage',
    'admin_bot'
];

foreach ($requiredWebhookTokens as $token) {
    if (!str_contains($webhookContent, $token)) {
        throw new RuntimeException("webhook.php missing required admin bot token: {$token}");
    }
}

// 4. Verifikasi Isolasi Auto Reply Engine
if (!str_contains($autoReplyContent, 'crmAdminBotIsAdmin')) {
    throw new RuntimeException("auto_reply_engine.php missing crmAdminBotIsAdmin protection.");
}

echo "admin-bot-test: OK\n";
