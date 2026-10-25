<?php
declare(strict_types=1);

/**
 * Test: Full 6-Step Sequence Scheduling & Smart Name Omission/Extraction
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/crm/config/automation-followup.php';
require_once $baseDir . '/crm/lib/automation-engine.php';

// ==========================================
// 1. Test Smart Name Extraction
// ==========================================
assert(function_exists('crmAutomationExtractExplicitName'), 'crmAutomationExtractExplicitName must exist');

// Should return NULL when name is not explicitly stated
$noNameMsgs = [
    'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada',
    'Kak, saya ingin daftar Kelas Menghafal Pemula',
    'Tahfidz Cilik',
    'Assalamu\'alaikum kak',
    'halo kak mau info biaya',
];
foreach ($noNameMsgs as $msg) {
    $res = crmAutomationExtractExplicitName($msg);
    assert($res === null, "Expected null for msg '{$msg}', got '{$res}'");
}

// Should return cleaned name when explicitly stated
$nameMsgs = [
    'Bismillah, perkenalkan nama saya Muhammad Rizky' => 'Muhammad Rizky',
    'nama saya siti fatimah, mau tanya jadwal' => 'Siti Fatimah',
    'nama sy ahmad' => 'Ahmad',
    'Nama: Budi Santoso' => 'Budi Santoso',
    'nama : Nurul Hidayah' => 'Nurul Hidayah',
    'saya Rina, mau daftar tahfidz cilik' => 'Rina',
];
foreach ($nameMsgs as $msg => $expected) {
    $res = crmAutomationExtractExplicitName($msg);
    assert($res === $expected, "Expected '{$expected}' for msg '{$msg}', got '{$res}'");
}

// ==========================================
// 2. Test Smart Message Rendering (Omit {nama} when not provided)
// ==========================================
$tpl1 = "Bismillah, ahlan kak {nama}! 😊\n\nMau memastikan, apakah info program Tahfidz Private tadi sudah berhasil dibuka kak?";
$tpl2 = "Sekadar info kak {nama}, di Jawwada belajarnya private 1-on-1 via Telepon WA (10 menit/hari) jadi tidak menyita waktu. Biayanya sangat terjangkau mulai Rp135rb/bulan.\n\nKak {nama} rencananya ingin belajar di waktu luang pagi, sore, atau malam kak? ✨";
$tpl3 = "Assalamu'alaikum kak {nama}. 🙏\n\nJika kak {nama} berminat, mau kami bantu simpankan slot belajarnya terlebih dahulu? 😊";
$tpl6 = "Bismillah, kak {nama}. 🙏\n\nJika sewaktu-waktu ingin memulai kembali, nomor ini selalu terbuka untuk kak {nama}. Semoga Allah Ta'ala mudahkan niat mulianya. Barakallahu fiik. 🤲✨";
$tplCilik = "Bismillah, ahlan Bunda/Ayah {nama}! 😊\n\nSenang sekali Bunda/Ayah berniat membimbing ananda dekat dengan Al-Qur'an sejak dini.";

// Case A: No explicit name (null / empty / generic 'Kak' / pushname)
$rendered1 = crmAutomationRenderMessage($tpl1, ['nama' => null]);
assert(!str_contains($rendered1, '{nama}'), "Rendered msg must not contain '{nama}'");
assert(!str_contains($rendered1, 'kak kak'), "Rendered msg must not contain 'kak kak'");
assert(str_contains($rendered1, 'ahlan kak!'), "Rendered msg should say 'ahlan kak!', got: " . $rendered1);

$rendered2 = crmAutomationRenderMessage($tpl2, ['nama' => '']);
assert(!str_contains($rendered2, '{nama}'), "Rendered msg must not contain '{nama}'");
assert(str_contains($rendered2, 'Sekadar info kak,'), "Rendered msg should say 'Sekadar info kak,', got: " . $rendered2);

$rendered3 = crmAutomationRenderMessage($tpl3, ['nama' => 'Kak']);
assert(!str_contains($rendered3, '{nama}'), "Rendered msg must not contain '{nama}'");
assert(str_contains($rendered3, "Assalamu'alaikum kak. 🙏"), "Rendered msg should say 'Assalamu\'alaikum kak. 🙏', got: " . $rendered3);
assert(str_contains($rendered3, "Jika kakak berminat") || str_contains($rendered3, "Jika kak berminat"), "Rendered msg should format 'Jika kakak/kak berminat'");

$rendered6 = crmAutomationRenderMessage($tpl6, ['nama' => 'Unknown']);
assert(!str_contains($rendered6, '{nama}'), "Rendered msg must not contain '{nama}'");
assert(str_contains($rendered6, "Bismillah, kak. 🙏"), "Rendered msg should say 'Bismillah, kak. 🙏', got: " . $rendered6);
assert(str_contains($rendered6, "terbuka untuk kakak") || str_contains($rendered6, "terbuka untuk kak"), "Rendered msg should format 'terbuka untuk kakak'");

$renderedCilik = crmAutomationRenderMessage($tplCilik, ['nama' => null]);
assert(!str_contains($renderedCilik, '{nama}'), "Rendered msg must not contain '{nama}'");
assert(str_contains($renderedCilik, "ahlan Bunda/Ayah!"), "Rendered msg should say 'ahlan Bunda/Ayah!', got: " . $renderedCilik);

// Case B: Explicit name provided ('Fatimah')
$renderedNamed1 = crmAutomationRenderMessage($tpl1, ['nama' => 'Fatimah']);
assert(str_contains($renderedNamed1, 'ahlan kak Fatimah!'), "Expected 'ahlan kak Fatimah!', got: " . $renderedNamed1);

$renderedNamedCilik = crmAutomationRenderMessage($tplCilik, ['nama' => 'Fatimah']);
assert(str_contains($renderedNamedCilik, 'ahlan Bunda/Ayah Fatimah!'), "Expected 'ahlan Bunda/Ayah Fatimah!', got: " . $renderedNamedCilik);

// ==========================================
// 3. Test Static Code Assertions on Config & Page
// ==========================================
$cfgPath = $baseDir . '/crm/config/automation-followup.php';
$cfgContent = file_get_contents($cfgPath);

// Must deduplicate by nowa AND template_id, NOT OR (rule_id = ? AND rule_id > 0)
assert(str_contains($cfgContent, "WHERE nowa = ? AND template_id = ? AND status = 'pending'"), "crmAutomationRegisterQueue must deduplicate by nowa and template_id only");
assert(!str_contains($cfgContent, "(template_id = ? OR (rule_id = ? AND rule_id > 0))"), "crmAutomationRegisterQueue must NOT overwrite across different templates via rule_id");

// Must support 'Tahfidz Private - Follow Up' prefix in sequence matching
assert(str_contains($cfgContent, "%Follow Up ' . \$num") || str_contains($cfgContent, "%Follow Up ' . \$step") || str_contains($cfgContent, "Follow Up ' . \$num") || str_contains($cfgContent, "CONCAT"), "Sequence resolution must match both prefixed and unprefixed Follow Up templates");

// Tab 4 queue must order by pending first and scheduled_at ASC
$pagePath = $baseDir . '/crm/pages/automation-followup.php';
$pageContent = file_get_contents($pagePath);
assert(str_contains($pageContent, "(q.status = 'pending') DESC, q.scheduled_at ASC"), "Tab 4 Queue table must sort pending items chronologically by scheduled_at ASC");

echo "automation-followup-sequence-name-test: OK\n";
