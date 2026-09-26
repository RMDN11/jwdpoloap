<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/prospect.php';
require_once __DIR__ . '/../config/chat.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals(crmCsrfToken(), $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'CSRF token tidak valid']);
    exit;
}

$nowa = trim((string)($_POST['nowa'] ?? ''));
if ($nowa === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Nomor WhatsApp wajib diisi']);
    exit;
}

if (!crmChatMarkRead($conn, $nowa)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal menandai percakapan sebagai sudah dibaca']);
    exit;
}

echo json_encode(['ok' => true]);
