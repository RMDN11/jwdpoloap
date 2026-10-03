<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/reminder-retention.php';

$redirectBase = '../index.php?page=reminder-retention';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$previousSource = trim((string)($_POST['previous_source'] ?? ''));
$currentSource = trim((string)($_POST['current_source'] ?? ''));
$retentionAk = trim((string)($_POST['retention_ak'] ?? ''));

// Retention Rate dipakai dari workspace "Semua AK". Setelah direct send,
    // jangan pindahkan filter ke AK yang baru saja dikirim.
    $redirect = $redirectBase . '&previous_source=' . rawurlencode($previousSource)
        . '&current_source=' . rawurlencode($currentSource)
        . '&ak=&analyze=1';

if ($previousSource === '' || $currentSource === '' || $retentionAk === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Konteks retention tidak lengkap.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $previous = crmRetentionLoadSource($conn, $previousSource);
    $current = crmRetentionLoadSource($conn, $currentSource);
    $akKey = crmRetentionNormalizeGroup($retentionAk);

    $previous = array_values(array_filter(
        $previous,
        static fn(array $record): bool => (string)($record['group_key'] ?? '') === $akKey
    ));

    $comparison = crmRetentionCompare($previous, $current);
    $notContinued = array_values(array_filter(
        $comparison['not_continued'] ?? [],
        static fn(array $record): bool => trim((string)($record['name'] ?? '')) !== ''
    ));

    if (!$notContinued) {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Tidak ada peserta yang tidak lanjut pada AK ini.'];
        header('Location: ' . $redirect);
        exit;
    }

    $names = array_map(static fn(array $record): string => trim((string)$record['name']), $notContinued);
    $nameLines = array_map(static fn(string $name): string => '• ' . $name, $names);

    $message = "Assalamu'alaikum {nama}

"
        . 'Izin menginformasikan ' . count($names) . ' peserta yang tidak melanjutkan pada periode berikutnya dari '
        . $retentionAk . ' dari total ' . count($previous) . ":

"
        . implode("
", $nameLines)
        . "

Mohon dibantu follow up bila diperlukan. Jazakallahu khairan.";

    $result = $conn->query("SELECT id, nama, nowa, halaqoh FROM pengampu WHERE nowa IS NOT NULL AND nowa <> '' ORDER BY nama ASC");
    $targets = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (crmRetentionNormalizeGroup((string)($row['halaqoh'] ?? '')) === $akKey) {
                $targets[] = $row;
            }
        }
    }

    if (!$targets) {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Tidak ditemukan tutor untuk ' . $retentionAk . '.'];
        header('Location: ' . $redirect);
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
        $number = crmNormalizeNumber((string)$target['nowa']);

        if ($number === '') {
            $failed++;
            $errors[] = $name;
            continue;
        }

        $personalMessage = str_ireplace(
            ['{nama}', '{NAMA}', '[nama]', '[NAMA]', '{halaqoh}', '{HALAQOH}', '[halaqoh]', '[HALAQOH]'],
            [$name, $name, $name, $name, $retentionAk, $retentionAk, $retentionAk, $retentionAk],
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

    $flash = $success . ' tutor ' . $retentionAk . ' berhasil dikirimi reminder.';
    if ($failed) {
        $flash .= ' ' . $failed . ' gagal' . ($errors ? ': ' . implode(', ', array_slice($errors, 0, 5)) : '') . '.';
    }

    $_SESSION['crm_flash'] = [
        'type' => $failed > 0 && $success === 0 ? 'error' : 'success',
        'message' => $flash
    ];
} catch (Throwable $e) {
    error_log('CRM direct retention tutor reminder failed: ' . $e->getMessage());
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pengiriman reminder tutor gagal diproses.'];
}

header('Location: ' . $redirect);
exit;
