<?php
declare(strict_types=1);

/**
 * Konfigurasi dan Helper Identifikasi Admin Bot WhatsApp
 */

if (!defined('ADMIN_BOT_ALLOWED_NUMBERS')) {
    define('ADMIN_BOT_ALLOWED_NUMBERS', [
        '6288223053149',
        '62248232064090227'
    ]);
}

if (!defined('GEMINI_API_KEY')) {
    $localConfig = dirname(__DIR__, 2) . '/config.local.php';
    if (file_exists($localConfig)) {
        require_once $localConfig;
    }

    if (defined('LOCAL_GEMINI_API_KEY') && !empty(LOCAL_GEMINI_API_KEY)) {
        define('GEMINI_API_KEY', (string)LOCAL_GEMINI_API_KEY);
    } else {
        $envKey = getenv('GEMINI_API_KEY');
        define('GEMINI_API_KEY', is_string($envKey) ? trim($envKey) : '');
    }
}

if (!defined('GEMINI_API_MODEL')) {
    define('GEMINI_API_MODEL', 'gemini-3.5-flash-lite');
}

if (!defined('ADMIN_BOT_ENABLED')) {
    define('ADMIN_BOT_ENABLED', true);
}

if (!defined('ANTIGRAVITY_BRIDGE_TOKEN')) {
    define('ANTIGRAVITY_BRIDGE_TOKEN', 'agy_bridge_sec_9941a5b82c0f47e3a61');
}

/**
 * Normalisasi nomor WhatsApp ke format standar 62...
 */
function crmAdminBotNormalizeNumber(string $phone): string
{
    $clean = preg_replace('/@.*$/', '', trim($phone));
    $digits = preg_replace('/\D/', '', $clean);

    if (str_starts_with($digits, '08')) {
        $digits = '628' . substr($digits, 2);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '628' . substr($digits, 1);
    }

    return $digits;
}

/**
 * Cek apakah sebuah nomor adalah Admin resmi yang diizinkan
 */
function crmAdminBotIsAdmin(string $phone): bool
{
    if (!defined('ADMIN_BOT_ENABLED') || !ADMIN_BOT_ENABLED) {
        return false;
    }

    $normalized = crmAdminBotNormalizeNumber($phone);
    if ($normalized === '') {
        return false;
    }

    $allowed = defined('ADMIN_BOT_ALLOWED_NUMBERS') ? (array)ADMIN_BOT_ALLOWED_NUMBERS : [];
    foreach ($allowed as $item) {
        if (crmAdminBotNormalizeNumber((string)$item) === $normalized) {
            return true;
        }
    }

    return false;
}

/**
 * Memastikan tabel crm_admin_todos ada di database
 */
function crmAdminBotEnsureTables(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS crm_admin_todos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_phone VARCHAR(50) NOT NULL,
        task TEXT NOT NULL,
        status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_admin_todo_phone_status (admin_phone, status),
        INDEX idx_admin_todo_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $conn->query($sql);

    $sqlAgy = "CREATE TABLE IF NOT EXISTS crm_antigravity_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nowa VARCHAR(50) NOT NULL,
        prompt TEXT NOT NULL,
        status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        result LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        completed_at DATETIME NULL,
        INDEX idx_agy_status (status),
        INDEX idx_agy_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $conn->query($sqlAgy);
}
