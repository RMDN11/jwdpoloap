<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../../config.php';
if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); exit('Database connection unavailable.'); }
$conn->set_charset('utf8mb4');
function crmCount(mysqli $conn, string $sql): int { $result=$conn->query($sql); if(!$result)return 0; $row=$result->fetch_assoc(); return (int)($row['total']??0); }
function crmNormalizeNumber(string $number): string { return preg_replace('/[^0-9]/','',$number)??''; }
if(empty($_SESSION['crm_csrf']))$_SESSION['crm_csrf']=bin2hex(random_bytes(32));
function crmCsrfToken(): string { return $_SESSION['crm_csrf']??''; }
function crmVerifyCsrf(?string $token): bool { return is_string($token)&&$token!==''&&hash_equals($_SESSION['crm_csrf']??'',$token); }
