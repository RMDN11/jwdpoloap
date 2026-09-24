<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$conn->set_charset('utf8mb4');

$conn->query("CREATE TABLE IF NOT EXISTS crm_message_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nowa VARCHAR(50) NOT NULL,
    nama VARCHAR(150) NULL,
    template_id INT NULL,
    template_name VARCHAR(150) NULL,
    message TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(30) NOT NULL DEFAULT 'sent',
    INDEX idx_crm_history_nowa_sent (nowa, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function crmCount(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if (!$result) return 0;
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function crmNormalizeNumber(string $number): string {
    $number = preg_replace('/\D+/', '', $number) ?? '';
    if (str_starts_with($number, '0')) {
        $number = '62' . substr($number, 1);
    }
    return $number;
}

if (empty($_SESSION['crm_csrf'])) {
    $_SESSION['crm_csrf'] = bin2hex(random_bytes(32));
}

function crmCsrfToken(): string {
    return (string)($_SESSION['crm_csrf'] ?? '');
}

function crmVerifyCsrf(?string $token): bool {
    return is_string($token)
        && $token !== ''
        && hash_equals(crmCsrfToken(), $token);
}
