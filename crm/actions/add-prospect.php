<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$nama = trim((string)($_POST['nama'] ?? ''));
$rawNumber = trim((string)($_POST['nowa'] ?? ''));

if ($nama === '' || $rawNumber === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nama dan nomor WhatsApp wajib diisi.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$number = crmNormalizeNumber($rawNumber);
if (!preg_match('/^62\d{9,13}$/', $number)) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$prospectFile = __DIR__ . '/../config/prospect.php';
require_once $prospectFile;

$disqualified = crmGetDisqualifiedNumbers($conn);
$blocked = crmGetBlockedNumbers($conn);

if (isset($disqualified[$number]) || isset($blocked[$number])) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor sudah terdaftar, diblokir, atau tidak dapat menjadi prospek.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$check = $conn->prepare("SELECT id FROM log_wa WHERE nowa = ? OR nowa = ? LIMIT 1");
$check->bind_param('ss', $number, $rawNumber);
$check->execute();

if ($check->get_result()->num_rows > 0) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor sudah ada di database.'];
    header('Location: ../index.php?page=chat');
    exit;
}
$check->close();

$insert = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, 'Data CSV/Manual', NOW())");
$insert->bind_param('ss', $number, $nama);

if ($insert->execute()) {
    $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Prospek berhasil ditambahkan.'];
} else {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Prospek gagal ditambahkan.'];
}
$insert->close();

header('Location: ../index.php?page=chat');
exit;
