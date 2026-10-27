<?php
declare(strict_types=1);

if (!defined('CRM_SESSION_LIFETIME')) {
    define('CRM_SESSION_LIFETIME', 604800); // 7 hari (1 pekan)
}

if (!defined('CRM_SESSION_COOKIE_NAME')) {
    define('CRM_SESSION_COOKIE_NAME', 'crm_remember_session');
}

/**
 * Inisialisasi session PHP dengan masa aktif 1 pekan (7 hari).
 */
function crmSessionInit(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = (int)CRM_SESSION_LIFETIME;

    // Pastikan PHP Garbage Collector tidak menghapus session sebelum 7 hari
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.cookie_lifetime', (string)$lifetime);

    // Gunakan direktori session internal proyek jika bisa ditulisi,
    // agar tidak terhapus oleh cron pembersih /tmp server bawaan cPanel/OS
    $storageDir = dirname(__DIR__, 2) . '/storage/sessions';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    if (is_dir($storageDir) && is_writable($storageDir)) {
        session_save_path($storageDir);
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Simpan token autentikasi persisten pada peramban/device selama 7 hari.
 */
function crmRememberSessionSet(int $userId, string $username, string $passwordHash): void
{
    $expires = time() + CRM_SESSION_LIFETIME;
    $secret = defined('ONESENDER_API_TOKEN') ? (string)ONESENDER_API_TOKEN : 'jwd_crm_secret_key_2026';
    $signature = hash_hmac('sha256', "{$userId}|{$username}|{$passwordHash}|{$expires}", $secret);
    $tokenValue = "{$userId}:{$expires}:{$signature}";

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    setcookie(CRM_SESSION_COOKIE_NAME, $tokenValue, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Periksa dan pulihkan login secara otomatis jika session terhapus namun token device masih valid.
 */
function crmRememberSessionCheck(mysqli $conn): bool
{
    if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
        return true;
    }

    $rawCookie = (string)($_COOKIE[CRM_SESSION_COOKIE_NAME] ?? '');
    if ($rawCookie === '') {
        return false;
    }

    $parts = explode(':', $rawCookie, 3);
    if (count($parts) !== 3) {
        return false;
    }

    [$rawUserId, $rawExpires, $clientSignature] = $parts;
    $userId = (int)$rawUserId;
    $expires = (int)$rawExpires;

    if ($userId <= 0 || $expires < time()) {
        crmRememberSessionClear();
        return false;
    }

    $stmt = $conn->prepare('SELECT id, username, password FROM login_event WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        crmRememberSessionClear();
        return false;
    }

    $secret = defined('ONESENDER_API_TOKEN') ? (string)ONESENDER_API_TOKEN : 'jwd_crm_secret_key_2026';
    $expectedSignature = hash_hmac('sha256', "{$user['id']}|{$user['username']}|{$user['password']}|{$expires}", $secret);

    if (!hash_equals($expectedSignature, $clientSignature)) {
        crmRememberSessionClear();
        return false;
    }

    // Token valid: Bangun kembali session aktif
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['logged_in'] = true;
    $_SESSION['role'] = 'user';

    return true;
}

/**
 * Hapus token autentikasi device saat pengguna menekan tombol logout.
 */
function crmRememberSessionClear(): void
{
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    if (isset($_COOKIE[CRM_SESSION_COOKIE_NAME])) {
        unset($_COOKIE[CRM_SESSION_COOKIE_NAME]);
    }

    setcookie(CRM_SESSION_COOKIE_NAME, '', [
        'expires'  => time() - 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
