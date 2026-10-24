<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/automation-followup.php';

function crmAutomationRenderMessage(string $template, array $data): string
{
    $nama = trim((string)($data['nama'] ?? ''));
    if ($nama === '' || strtolower($nama) === 'unknown') {
        $nama = 'Kak';
    }

    date_default_timezone_set('Asia/Jakarta');
    $hour = (int)date('G');
    if ($hour >= 4 && $hour < 11) {
        $sapaan = 'Pagi';
    } elseif ($hour >= 11 && $hour < 15) {
        $sapaan = 'Siang';
    } elseif ($hour >= 15 && $hour < 18) {
        $sapaan = 'Sore';
    } else {
        $sapaan = 'Malam';
    }

    $program = trim((string)($data['program'] ?? 'Tahfidz Private'));

    $search = ['{nama}', '{sapaan}', '{program}'];
    $replace = [$nama, $sapaan, $program];

    return str_replace($search, $replace, $template);
}

function crmAutomationDispatchSingle(mysqli $conn, int $queueId, string $apiUrl = '', string $apiToken = ''): array
{
    if ($apiUrl === '' || $apiToken === '') {
        [$defUrl, $defToken] = crmAutomationGetApiCredentials();
        if ($apiUrl === '') $apiUrl = $defUrl;
        if ($apiToken === '') $apiToken = $defToken;
    }

    $stmt = $conn->prepare("
        SELECT q.id, q.nowa, q.nama, q.created_at, q.status, t.name AS template_name, t.content AS template_content, r.name AS rule_name
        FROM crm_automation_queue q
        LEFT JOIN crm_automation_templates t ON t.id = q.template_id
        LEFT JOIN crm_automation_rules r ON r.id = q.rule_id
        WHERE q.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['status' => 'error', 'message' => 'Gagal menyiapkan query antrean.'];
    }

    $stmt->bind_param('i', $queueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['status' => 'error', 'message' => 'Antrean tidak ditemukan.'];
    }

    $nowa = (string)$row['nowa'];
    $nama = (string)($row['nama'] ?? 'Kak');
    $registeredAt = (string)$row['created_at'];

    // 1. Periksa apakah nomor sudah diblokir
    if (crmAutomationIsBlocked($conn, $nowa)) {
        crmAutomationCancelAllPendingForContact($conn, $nowa, 'cancelled_blocked');
        return ['status' => 'cancelled', 'reason' => 'cancelled_blocked'];
    }

    // 2. Periksa apakah peserta sudah membalas chat sebelum jadwal follow up
    if (crmAutomationHasUserReplied($conn, $nowa, $registeredAt)) {
        crmAutomationCancelAllPendingForContact($conn, $nowa, 'cancelled_replied');
        return ['status' => 'cancelled', 'reason' => 'cancelled_replied'];
    }

    // 3. Periksa apakah peserta sudah menerima chat form payment finance / sudah terdaftar
    if (crmAutomationHasReceivedPayment($conn, $nowa)) {
        crmAutomationCancelAllPendingForContact($conn, $nowa, 'cancelled_payment');
        return ['status' => 'cancelled', 'reason' => 'cancelled_payment'];
    }

    $templateContent = (string)($row['template_content'] ?? '');
    if ($templateContent === '') {
        $stmtFail = $conn->prepare("UPDATE crm_automation_queue SET status = 'failed', error_message = 'Template pesan kosong' WHERE id = ?");
        if ($stmtFail) {
            $stmtFail->bind_param('i', $queueId);
            $stmtFail->execute();
            $stmtFail->close();
        }
        return ['status' => 'failed', 'reason' => 'Template pesan kosong'];
    }

    $message = crmAutomationRenderMessage($templateContent, [
        'nama' => $nama,
        'program' => (string)($row['rule_name'] ?? 'Tahfidz Private'),
    ]);

    $payload = json_encode([
        'recipient_type' => 'individual',
        'to' => $nowa,
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
        CURLOPT_USERAGENT => 'AutoReplyEngine/1.0 (Jawwada-CRM)',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        $errMsg = $curlError ?: "HTTP {$httpCode}: " . substr((string)$response, 0, 120);
        $stmtFail = $conn->prepare("UPDATE crm_automation_queue SET status = 'failed', error_message = ? WHERE id = ?");
        if ($stmtFail) {
            $stmtFail->bind_param('si', $errMsg, $queueId);
            $stmtFail->execute();
            $stmtFail->close();
        }
        return ['status' => 'failed', 'reason' => $errMsg];
    }

    // Berhasil terkirim
    $stmtSuccess = $conn->prepare("UPDATE crm_automation_queue SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE id = ?");
    if ($stmtSuccess) {
        $stmtSuccess->bind_param('i', $queueId);
        $stmtSuccess->execute();
        $stmtSuccess->close();
    }

    // Catat ke audit history CRM
    $stmtHist = $conn->prepare("INSERT INTO crm_message_history (nowa, nama, template_name, message, sent_at, status) VALUES (?, ?, ?, ?, NOW(), 'sent')");
    if ($stmtHist) {
        $tplName = 'Automation: ' . (string)($row['template_name'] ?? 'Follow Up');
        $stmtHist->bind_param('ssss', $nowa, $nama, $tplName, $message);
        $stmtHist->execute();
        $stmtHist->close();
    }

    return ['status' => 'sent', 'recipient' => $nowa];
}

function crmAutomationProcessPendingQueue(mysqli $conn, string $apiUrl = '', string $apiToken = ''): array
{
    crmAutomationEnsureTables($conn);

    if ($apiUrl === '' || $apiToken === '') {
        [$defUrl, $defToken] = crmAutomationGetApiCredentials();
        if ($apiUrl === '') $apiUrl = $defUrl;
        if ($apiToken === '') $apiToken = $defToken;
    }

    $sql = "SELECT id FROM crm_automation_queue 
            WHERE status = 'pending' 
              AND scheduled_at <= NOW() 
            ORDER BY scheduled_at ASC 
            LIMIT 25";

    $result = $conn->query($sql);
    if (!$result) {
        return ['processed' => 0, 'sent' => 0, 'cancelled' => 0, 'failed' => 0];
    }

    $summary = ['processed' => 0, 'sent' => 0, 'cancelled' => 0, 'failed' => 0];

    while ($row = $result->fetch_assoc()) {
        $queueId = (int)$row['id'];
        $summary['processed']++;

        $res = crmAutomationDispatchSingle($conn, $queueId, $apiUrl, $apiToken);
        if ($res['status'] === 'sent') {
            $summary['sent']++;
        } elseif ($res['status'] === 'cancelled') {
            $summary['cancelled']++;
        } else {
            $summary['failed']++;
        }
    }

    return $summary;
}
