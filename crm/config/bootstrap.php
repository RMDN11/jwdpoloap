<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../auth_checkwa.php';
require_once __DIR__ . '/../../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

function crmCount(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if (!$result) return 0;
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function crmNormalizeNumber(string $number): string {
    $number = preg_replace('/\D+/', '', $number);
    if (str_starts_with($number, '0')) $number = '62' . substr($number, 1);
    return $number;
}
