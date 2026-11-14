<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/reminder-schedule.php';

@set_time_limit(300);

$apiUrl = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : ($apiUrl ?? '');
$apiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : ($apiToken ?? '');

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

$respondJson = static function (array $payload, int $httpStatus = 200): never {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    if ($isAjax) {
        $respondJson(['status' => 'error', 'message' => 'Permintaan tidak valid.'], 403);
    }
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$message = trim((string)($_POST['message'] ?? ''));
$scrollY = isset($_POST['scroll_y']) && is_scalar($_POST['scroll_y'])
    ? max(0, min(100000, (int)$_POST['scroll_y']))
    : 0;
$redirect = '../index.php?page=reminder-pengajar' . ($scrollY > 0 ? '&scroll_y=' . $scrollY : '');

if ($message === '') {
    if ($isAjax) {
        $respondJson(['status' => 'error', 'message' => 'Pesan tidak boleh kosong.'], 422);
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pesan tidak boleh kosong.'];
    header('Location: ' . $redirect);
    exit;
}

if (mb_strlen($message) > 2000) {
    if ($isAjax) {
        $respondJson(['status' => 'error', 'message' => 'Pesan terlalu panjang. Maksimal 2000 karakter.'], 422);
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pesan terlalu panjang. Maksimal 2000 karakter.'];
    header('Location: ' . $redirect);
    exit;
}

$targetId = isset($_POST['target_id']) ? (int)$_POST['target_id'] : 0;
$ids = [];

if ($targetId > 0) {
    $ids = [$targetId];
} else {
    $raw = json_decode((string)($_POST['selected'] ?? '[]'), true);
    if (is_array($raw)) {
        foreach ($raw as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
}

if (!$ids) {
    if ($isAjax) {
        $respondJson(['status' => 'error', 'message' => 'Pilih minimal satu pengajar.'], 422);
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pilih minimal satu pengajar.'];
    header('Location: ' . $redirect);
    exit;
}

if (count($ids) > 20) {
    $antiBannedMsg = 'Proteksi Anti-Banned: Mengirim lebih dari 20 pesan pengajar sekaligus secara instan sangat berisiko membuat nomor WhatsApp diblokir. Harap pilih maksimal 20 pengajar per pengiriman.';
    if ($isAjax) {
        $respondJson(['status' => 'error', 'message' => $antiBannedMsg], 422);
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => $antiBannedMsg];
    header('Location: ' . $redirect);
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
    if ($isAjax) {
        $respondJson(['status' => 'error', 'message' => 'Gagal menyiapkan data pengajar.'], 500);
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan data pengajar.'];
    header('Location: ' . $redirect);
    exit;
}

$refs = [];
foreach ($params as $key => $value) $refs[$key] = &$params[$key];
call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
$stmt->execute();
$targets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$targets) {
    if ($isAjax) {
        $respondJson(['status' => 'error', 'message' => 'Pengajar yang dipilih tidak ditemukan.'], 404);
    }
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pengajar yang dipilih tidak ditemukan.'];
    header('Location: ' . $redirect);
    exit;
}

$logStmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, NOW())");
$historyStmt = $conn->prepare("
    INSERT INTO crm_message_history (nowa, nama, template_id, template_name, message, sent_at, status)
    VALUES (?, ?, NULL, 'Reminder Pengajar', ?, NOW(), 'sent')
");

if ($isAjax) {
    // Mode AJAX: Kirim 1 pengajar per request secara instan (jeda pacing dikelola client-side)
    $target = $targets[0];
    $name = trim((string)$target['nama']) ?: 'Pengajar';
    $halaqoh = trim((string)($target['halaqoh'] ?? ''));
    $number = crmNormalizeNumber((string)$target['nowa']);

    if ($number === '') {
        $respondJson(['status' => 'error', 'message' => 'Nomor WhatsApp ' . $name . ' tidak valid.', 'name' => $name], 422);
    }

    $personalMessage = crmResolveReminderMessage($message, [
        'nama' => $name,
        'nowa' => $number,
        'halaqoh' => $halaqoh,
    ]);

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
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!$curlError && $httpCode >= 200 && $httpCode < 300) {
        $loggedMessage = '[REMINDER] [PENGAJAR] [TERKIRIM] ' . $personalMessage;
        if ($logStmt) {
            $logStmt->bind_param('sss', $number, $name, $loggedMessage);
            $logStmt->execute();
            $logStmt->close();
        }
        if ($historyStmt) {
            $historyStmt->bind_param('sss', $number, $name, $message);
            $historyStmt->execute();
            $historyStmt->close();
        }

        $respondJson([
            'status' => 'success',
            'message' => 'Berhasil dikirim ke ' . $name,
            'name' => $name,
            'id' => (int)$target['id']
        ]);
    } else {
        $errorDetail = $curlError ?: ('HTTP ' . $httpCode);
        $loggedMessage = '[REMINDER] [PENGAJAR] [GAGAL] ' . $personalMessage . ' | ' . $errorDetail;
        if ($logStmt) {
            $logStmt->bind_param('sss', $number, $name, $loggedMessage);
            $logStmt->execute();
            $logStmt->close();
        }
        if ($historyStmt) {
            $historyStmt->close();
        }

        $respondJson([
            'status' => 'error',
            'message' => 'Gagal mengirim ke ' . $name . ' (' . $errorDetail . ')',
            'name' => $name,
            'id' => (int)$target['id']
        ], 502);
    }
}

// Fallback Non-AJAX: Lepaskan lock file sesi PHP agar tab lain tidak hang
session_write_close();

$success = 0;
$failed = 0;
$errors = [];
$totalTargets = count($targets);

foreach ($targets as $index => $target) {
    $name = trim((string)$target['nama']) ?: 'Pengajar';
    $halaqoh = trim((string)($target['halaqoh'] ?? ''));
    $number = crmNormalizeNumber((string)$target['nowa']);

    if ($number === '') {
        $failed++;
        $errors[] = $name;
        continue;
    }

    // Placeholder support & Spintax anti-ban otomatis
    $personalMessage = crmResolveReminderMessage($message, [
        'nama' => $name,
        'nowa' => $number,
        'halaqoh' => $halaqoh,
    ]);

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
        CURLOPT_CONNECTTIMEOUT => 7,
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

    // Jeda acak manusia per pesan (2.5 hingga 4.5 detik) untuk mencegah flag bot Meta/WhatsApp
    if ($index < $totalTargets - 1) {
        usleep(rand(2500000, 4500000));
    }
}

if ($logStmt) $logStmt->close();
if ($historyStmt) $historyStmt->close();

$flash = $success . ' pengajar berhasil dikirimi pesan.';
if ($failed) {
    $flash .= ' ' . $failed . ' gagal' . ($errors ? ': ' . implode(', ', array_slice($errors, 0, 5)) : '') . '.';
}

// Buka sesi kembali untuk menulis flash pesan
session_start();
$_SESSION['crm_flash'] = [
    'type' => $failed > 0 && $success === 0 ? 'error' : 'success',
    'message' => $flash
];

header('Location: ' . $redirect);
exit;
