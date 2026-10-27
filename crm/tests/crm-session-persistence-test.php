<?php
declare(strict_types=1);

/**
 * Test verifikasi sistem session dan login persistence CRM 1 pekan (7 hari).
 */

$sessionFile = __DIR__ . '/../config/session.php';
$bootstrapFile = __DIR__ . '/../config/bootstrap.php';
$loginFile = __DIR__ . '/../login.php';
$logoutFile = __DIR__ . '/../logout.php';

assert(file_exists($sessionFile), 'File session.php harus ada di crm/config/');
$sessionContent = file_get_contents($sessionFile);

// 1. Konstanta durasi 7 hari (604800 detik)
assert(str_contains($sessionContent, '604800'), 'Masa aktif session wajib 604800 detik (7 hari)');
assert(str_contains($sessionContent, 'CRM_SESSION_LIFETIME'), 'Definisi konstanta CRM_SESSION_LIFETIME wajib ada');

// 2. Fungsi-fungsi utama
assert(str_contains($sessionContent, 'function crmSessionInit'), 'crmSessionInit harus diimplementasikan');
assert(str_contains($sessionContent, 'function crmRememberSessionSet'), 'crmRememberSessionSet harus diimplementasikan');
assert(str_contains($sessionContent, 'function crmRememberSessionCheck'), 'crmRememberSessionCheck harus diimplementasikan');
assert(str_contains($sessionContent, 'function crmRememberSessionClear'), 'crmRememberSessionClear harus diimplementasikan');

// 3. Integrasi ke bootstrap.php
$bootstrapContent = file_get_contents($bootstrapFile);
assert(str_contains($bootstrapContent, 'session.php'), 'bootstrap.php harus memanggil session.php');
assert(str_contains($bootstrapContent, 'crmRememberSessionCheck'), 'bootstrap.php harus mengecek auto-login via remember session');

// 4. Integrasi ke login.php
$loginContent = file_get_contents($loginFile);
assert(str_contains($loginContent, 'crmRememberSessionSet'), 'login.php harus mengeset remember session token');
assert(str_contains($loginContent, 'crmRememberSessionCheck'), 'login.php harus auto-redirect jika session token valid');

// 5. Integrasi ke logout.php
$logoutContent = file_get_contents($logoutFile);
assert(str_contains($logoutContent, 'crmRememberSessionClear'), 'logout.php harus menghapus remember session token');

echo "crm-session-persistence-test: OK\n";
