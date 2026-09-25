<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pesan tidak boleh kosong.'];
    header('Location: ../index.php?page=reminder-pengajar');
    exit;
}

if (mb_strlen($message) > 2000) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pesan terlalu panjang. Maksimal 2000 karakter.'];
    header('Location: ../index.php?page=reminder-pengajar');
    exit;
}

$raw = json_decode((string)($_POST['selected'] ?? '[]'), true);
$ids = [];

if (is_array($raw)) {
    foreach ($raw as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $ids[$id] = true;
    }
}

$ids = array_keys($ids);
if (!$ids) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pilih minimal satu pengajar.'];
    header('Location: ../index.php?page=reminder-pengajar');
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$params = $ids;

$stmt = $conn->prepare("
    SELECT id, nama, nowa, halaqoh
    FROM pengampu
    WHERE id IN ($placeholders)
      AND nowa IS NOT NULL
      AND nowa <> ''
    ORDER BY nama ASC
");
if (!$stmt) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan data pengajar.'];
    header('Location: ../index.php?page=reminder-pengajar');
    exit;
}

$refs = [];
foreach ($params as $key => $value) $refs[$key] = &$params[$key];
call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
$stmt->execute();
$targets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$targets) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pengajar yang dipilih tidak ditemukan.'];
    header('Location: ../index.php?page=reminder-pengajar');
    exit;
}

$logStmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, NOW())");
$historyStmt = $conn->prepare("
    INSERT INTO crm_message_history (nowa, nama, template_id, template_name, message, sent_at, status)
    VALUES (?, ?, NULL, 'Reminder Pengajar', ?, NOW(), 'sent')
");

$success = 0;
$failed = 0;
$errors = [];

foreach ($targets as $target) {
    $name = trim((string)$target['nama']) ?: 'Pengajar';
    $halaqoh = trim((string)($target['halaqoh'] ?? ''));
    $number = crmNormalizeNumber((string)$target['nowa']);

    if ($number === '') {
        $failed++;
        $errors[] = $name;
        continue;
    }

    $personalMessage = str_ireplace(
        ['{nama}', '{NAMA}', '[nama]', '[NAMA]', '{halaqoh}', '{HALAQOH}', '[halaqoh]', '[HALAQOH]'],
        [$name, $name, $name, $name, $halaqoh, $halaqoh, $halaqoh, $halaqoh],
        $message
    );

    $payload = json_encode([
        'recipient_type' => 'individual',
        'to' => $number,
        'type' => 'text',
        'text' => ['body' => $personalMessage]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!$curlError && $httpCode >= 200 && $httpCode < 300) {
        $success++;
        $loggedMessage = '[REMINDER] [PENGAJAR] [TERKIRIM] ' . $personalMessage;

        if ($logStmt) {
            $logStmt->bind_param('sss', $number, $name, $loggedMessage);
            $logStmt->execute();
        }

        if ($historyStmt) {
            $historyStmt->bind_param('sss', $number, $name, $message);
            $historyStmt->execute();
        }
    } else {
        $failed++;
        $errors[] = $name;
        $errorDetail = $curlError ?: ('HTTP ' . $httpCode);
        $loggedMessage = '[REMINDER] [PENGAJAR] [GAGAL] ' . $personalMessage . ' | ' . $errorDetail;

        if ($logStmt) {
            $logStmt->bind_param('sss', $number, $name, $loggedMessage);
            $logStmt->execute();
        }
    }
}

if ($logStmt) $logStmt->close();
if ($historyStmt) $historyStmt->close();

$flash = $success . ' pengajar berhasil dikirimi pesan.';
if ($failed) {
    $flash .= ' ' . $failed . ' gagal' . ($errors ? ': ' . implode(', ', array_slice($errors, 0, 5)) : '') . '.';
}

$_SESSION['crm_flash'] = [
    'type' => $failed > 0 && $success === 0 ? 'error' : 'success',
    'message' => $flash
];

header('Location: ../index.php?page=reminder-pengajar');
exit;
