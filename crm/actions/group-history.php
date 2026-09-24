<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'delete_all') {
    $ok = $conn->query("DELETE FROM log_wa WHERE message LIKE '%[GRUP]%'");
    $_SESSION['crm_flash'] = [
        'type' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Riwayat pesan grup berhasil dihapus.' : 'Gagal menghapus riwayat pesan grup.'
    ];
} else {
    $_SESSION['crm_flash'] = [
        'type' => 'error',
        'message' => 'Aksi riwayat tidak dikenali.'
    ];
}

header('Location: ../?page=group');
exit;
