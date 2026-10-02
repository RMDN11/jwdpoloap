<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/reminder-retention.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$templateId = (int)($_POST['template_id'] ?? 0);
$returnQuery = trim((string)($_POST['return_query'] ?? ''));
$rawSelected = json_decode((string)($_POST['selected'] ?? '[]'), true);

$redirectToRetention = static function (string $query): string {
    $params = ['page' => 'reminder-retention'];
    parse_str($query, $parsed);

    foreach (['previous_source', 'current_source', 'ak'] as $key) {
        if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) {
            continue;
        }

        $value = trim((string)$parsed[$key]);
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    if (isset($parsed['analyze']) && (string)$parsed['analyze'] === '1') {
        $params['analyze'] = '1';
    }

    return '../index.php?' . http_build_query($params);
};

$redirect = $redirectToRetention($returnQuery);

if ($templateId <= 0) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template reminder belum dipilih.'];
    header('Location: ' . $redirect, true, 303);
    exit;
}

$template = null;
$stmt = $conn->prepare('SELECT title, content FROM wa_templates WHERE id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$template) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template reminder tidak ditemukan.'];
    header('Location: ' . $redirect, true, 303);
    exit;
}

if (!is_array($rawSelected)) {
    $rawSelected = [];
}

$selected = [];
foreach ($rawSelected as $item) {
    if (!is_array($item)) {
        continue;
    }

    $wa = crmReminderCsvNormalizeWa((string)($item['nowa'] ?? ''));
    if ($wa === '') {
        continue;
    }

    $selected[$wa] = [
        'name' => trim((string)($item['name'] ?? '')),
        'nowa' => $wa,
    ];

    if (count($selected) >= 100) {
        break;
    }
}

if (!$selected) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pilih minimal satu peserta yang tidak lanjut.'];
    header('Location: ' . $redirect, true, 303);
    exit;
}

try {
    parse_str($returnQuery, $parsedReturn);
    $previousSource = trim((string)($parsedReturn['previous_source'] ?? ''));
    $currentSource = trim((string)($parsedReturn['current_source'] ?? ''));
    $selectedAk = trim((string)($parsedReturn['ak'] ?? ''));

    if ($previousSource === '' || $currentSource === '') {
        throw new RuntimeException('Sumber retention tidak lengkap.');
    }

    $previous = crmRetentionLoadSource($conn, $previousSource);
    $current = crmRetentionLoadSource($conn, $currentSource);

    if ($selectedAk !== '') {
        $akKey = crmRetentionNormalizeGroup($selectedAk);
        $previous = array_filter(
            $previous,
            static fn(array $record): bool => (string)($record['group_key'] ?? '') === $akKey
        );
        $current = array_filter(
            $current,
            static fn(array $record): bool => (string)($record['group_key'] ?? '') === $akKey
        );
        $previous = array_values($previous);
        $current = array_values($current);
    }

    $comparison = crmRetentionCompare($previous, $current);
    $allowed = [];

    foreach ($comparison['not_continued'] as $record) {
        $wa = crmReminderCsvNormalizeWa((string)$record['target_wa']);
        if ($wa === '') {
            continue;
        }

        $allowed[$wa] = [
            'name' => (string)$record['name'],
            'nowa' => $wa,
        ];
    }

    $targets = [];
    foreach ($selected as $wa => $item) {
        if (!isset($allowed[$wa])) {
            continue;
        }

        $targets[] = $allowed[$wa];
    }

    if (!$targets) {
        throw new RuntimeException('Target retention sudah berubah. Muat ulang analisis sebelum mengirim.');
    }
} catch (Throwable $e) {
    error_log('CRM retention send validation failed: ' . $e->getMessage());
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    header('Location: ' . $redirect, true, 303);
    exit;
}

$success = 0;
$failed = 0;
$errors = [];

$logStmt = $conn->prepare(
    "INSERT INTO log_wa (nowa, nama, message, created_at)
     VALUES (?, ?, ?, NOW())"
);

foreach ($targets as $target) {
    $name = $target['name'] !== '' ? $target['name'] : 'Kak';
    $number = $target['nowa'];

    $message = str_ireplace(
        ['{nama}', '{NAMA}', '[nama]', '[NAMA]'],
        $name,
        (string)$template['content']
    );
    $message = preg_replace('/ {2,}/', ' ', $message) ?? $message;

    $payload = json_encode([
        'recipient_type' => 'individual',
        'to' => $number,
        'type' => 'text',
        'text' => ['body' => $message],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken,
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
        $logged = '[REMINDER] [TERKIRIM] ' . $message;
        if ($logStmt) {
            $logStmt->bind_param('sss', $number, $name, $logged);
            $logStmt->execute();
        }
    } else {
        $failed++;
        $errors[] = $name;
        $logged = '[REMINDER] [GAGAL] ' . $message;
        if ($logStmt) {
            $logStmt->bind_param('sss', $number, $name, $logged);
            $logStmt->execute();
        }
    }
}

if ($logStmt) {
    $logStmt->close();
}

$message = $success . ' reminder berhasil dikirim.';
if ($failed > 0) {
    $message .= ' ' . $failed . ' gagal';
    if ($errors) {
        $message .= ': ' . implode(', ', array_slice($errors, 0, 5));
    }
    $message .= '.';
}

$_SESSION['crm_flash'] = [
    'type' => $failed > 0 && $success === 0 ? 'error' : 'success',
    'message' => $message,
];

header('Location: ' . $redirect, true, 303);
exit;
