<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$token = $_GET['token'] ?? '';
if ($token !== 'jwd_auto_cron') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'No DB connection']);
    exit;
}

// 1. Ambil semua aturan yang ada
$res = $conn->query("SELECT id, keyword, reply, priority, is_active FROM auto_reply_rules ORDER BY id ASC");
$rules = [];
while ($row = $res->fetch_assoc()) {
    $rules[] = $row;
}

// 2. Konten baru 2 bubble chat dengan penanda [SPLIT]
$newReply = "{Bismillah, ahlan kak! 😊|Assalamu'alaikum kak! 😊|Halo kak, terima kasih ya sudah menghubungi Jawwada 😊}\n\nDi Jawwada pembelajarannya privat 1-on-1 dibimbing langsung oleh Asatidz hafidz 30 juz. Bimbingannya intensif dan fokus personal, jadi bacaan disimak serta dibimbing langsung dari nol sampai fasih.\n[SPLIT]\n{Biar kami bantu siapkan info kelas yang paling pas|Biar kami bantu carikan info yang paling cocok}, rencananya program tahfidz privat ini untuk kakak sendiri atau ananda nih kak? 🙏\n\n(Rincian silabus lengkap juga bisa diintip santai di sini ya: https://reqra.my.id/jawwada/tahfidz)";

// 3. Update aturan yang relevan dengan pendaftaran/LP Tahfidz (ID 25, 27, 28)
$updatedIds = [];
$targetIds = [25, 27, 28];

$stmt = $conn->prepare("UPDATE auto_reply_rules SET reply = ? WHERE id = ?");
foreach ($targetIds as $tid) {
    $stmt->bind_param('si', $newReply, $tid);
    $stmt->execute();
    $updatedIds[] = $tid;
}
$stmt->close();

echo json_encode([
    'total_rules' => count($rules),
    'updated_ids' => $updatedIds,
    'new_reply_sample' => $newReply,
    'rules_overview' => array_map(function($r) {
        return ['id' => $r['id'], 'kw' => substr($r['keyword'], 0, 40), 'reply_len' => strlen($r['reply'])];
    }, $rules)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
