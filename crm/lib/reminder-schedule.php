<?php
declare(strict_types=1);

/**
 * Core Reminder Automation & Scheduling Engine
 * Manages campaigns, halaqoh-based queuing, inter-message delays, and cron dispatch.
 */

function crmEnsureReminderScheduleTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS crm_reminder_campaigns (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        campaign_type ENUM('pembayaran', 'peserta') NOT NULL DEFAULT 'pembayaran',
        template_id INT NOT NULL,
        bulan VARCHAR(50) NULL,
        inter_message_delay INT NOT NULL DEFAULT 120,
        inter_group_delay INT NOT NULL DEFAULT 900,
        allowed_days VARCHAR(100) NOT NULL DEFAULT '1,2,3,4,5,6,7',
        status ENUM('running', 'paused', 'completed', 'cancelled') DEFAULT 'running',
        total_targets INT NOT NULL DEFAULT 0,
        sent_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        started_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_campaign_status (status),
        INDEX idx_campaign_type (campaign_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS crm_reminder_campaign_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT UNSIGNED NOT NULL,
        peserta_id INT UNSIGNED NULL,
        request_id INT UNSIGNED NULL,
        nama VARCHAR(150) NOT NULL,
        nowa VARCHAR(50) NOT NULL,
        halaqoh VARCHAR(100) NOT NULL,
        group_order INT NOT NULL DEFAULT 1,
        message TEXT NOT NULL,
        scheduled_at DATETIME NOT NULL,
        status ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_queue_exec (status, scheduled_at),
        INDEX idx_queue_campaign (campaign_id, group_order),
        CONSTRAINT fk_rem_queue_campaign FOREIGN KEY (campaign_id) REFERENCES crm_reminder_campaigns (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Dynamic schema migration for existing tables
    $chk = $conn->query("SHOW COLUMNS FROM crm_reminder_campaigns LIKE 'campaign_type'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE crm_reminder_campaigns ADD COLUMN campaign_type ENUM('pembayaran', 'peserta') NOT NULL DEFAULT 'pembayaran' AFTER title");
    }
    $chk = $conn->query("SHOW COLUMNS FROM crm_reminder_campaigns LIKE 'allowed_days'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE crm_reminder_campaigns ADD COLUMN allowed_days VARCHAR(100) NOT NULL DEFAULT '1,2,3,4,5,6,7' AFTER inter_group_delay");
    }
    $chk = $conn->query("SHOW COLUMNS FROM crm_reminder_campaign_queue LIKE 'request_id'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE crm_reminder_campaign_queue ADD COLUMN request_id INT UNSIGNED NULL AFTER peserta_id");
    }
}

function crmAdjustTimestampToAllowedDays(int $timestamp, array $allowedDays, int $hour = 8, int $minute = 0): int
{
    if (!$allowedDays) return $timestamp;
    $currentDay = (int)date('N', $timestamp); // 1 = Monday ... 7 = Sunday
    if (in_array($currentDay, $allowedDays, true)) {
        return $timestamp;
    }

    $dt = new DateTime(date('Y-m-d H:i:s', $timestamp), new DateTimeZone('Asia/Jakarta'));
    for ($i = 0; $i < 7; $i++) {
        $dt->modify('+1 day');
        $dayNum = (int)$dt->format('N');
        if (in_array($dayNum, $allowedDays, true)) {
            $dt->setTime($hour, $minute, 0);
            return $dt->getTimestamp();
        }
    }
    return $timestamp;
}

function crmCreateReminderCampaign(mysqli $conn, array $params): int
{
    crmEnsureReminderScheduleTables($conn);

    $title = trim((string)($params['title'] ?? 'Reminder Tagihan'));
    $campaignType = (string)($params['campaign_type'] ?? $params['type'] ?? 'pembayaran');
    if (!in_array($campaignType, ['pembayaran', 'peserta'], true)) {
        $campaignType = 'pembayaran';
    }

    $templateId = (int)($params['template_id'] ?? 0);
    $bulan = trim((string)($params['bulan'] ?? ''));
    $interMessageDelay = max(10, (int)($params['inter_message_delay'] ?? 120)); // min 10s
    $interGroupDelay = max(30, (int)($params['inter_group_delay'] ?? 900));     // min 30s
    $startDateTimeStr = trim((string)($params['start_datetime'] ?? ''));
    $targets = (array)($params['targets'] ?? []);

    $allowedDaysRaw = $params['allowed_days'] ?? [1,2,3,4,5,6,7];
    if (is_string($allowedDaysRaw)) {
        $allowedDaysRaw = explode(',', $allowedDaysRaw);
    }
    $allowedDays = [];
    foreach ((array)$allowedDaysRaw as $d) {
        $dn = (int)$d;
        if ($dn >= 1 && $dn <= 7) $allowedDays[] = $dn;
    }
    if (!$allowedDays) $allowedDays = [1,2,3,4,5,6,7];
    $allowedDaysStr = implode(',', $allowedDays);

    if ($templateId <= 0) {
        throw new InvalidArgumentException('Template pesan wajib dipilih.');
    }
    if (!$targets) {
        throw new InvalidArgumentException('Tidak ada target peserta yang dipilih.');
    }

    // Fetch template content from appropriate table
    $rawContent = '';
    if ($campaignType === 'peserta') {
        $stmt = $conn->prepare("SELECT id, name AS title, content FROM reminder_templates WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $templateId);
            $stmt->execute();
            $tpl = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($tpl) $rawContent = (string)$tpl['content'];
        }
    }

    if ($rawContent === '') {
        $stmt = $conn->prepare("SELECT title, content FROM wa_templates WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $templateId);
            $stmt->execute();
            $tpl = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($tpl) $rawContent = (string)$tpl['content'];
        }
    }

    if ($rawContent === '') {
        throw new RuntimeException('Template pesan tidak ditemukan.');
    }

    // Group targets by halaqoh
    $grouped = [];
    foreach ($targets as $item) {
        $nowa = preg_replace('/\D+/', '', (string)($item['nowa'] ?? ''));
        if ($nowa === '') continue;
        if (str_starts_with($nowa, '0')) $nowa = '62' . substr($nowa, 1);

        $hal = trim((string)($item['halaqoh'] ?? ''));
        if ($hal === '' || $hal === '-') $hal = 'Umum / Lainnya';

        $grouped[$hal][] = [
            'id' => (int)($item['id'] ?? 0),
            'request_id' => (int)($item['request_id'] ?? 0),
            'name' => trim((string)($item['name'] ?? 'Kak')) ?: 'Kak',
            'nowa' => $nowa,
            'halaqoh' => $hal,
        ];
    }

    if (!$grouped) {
        throw new InvalidArgumentException('Semua nomor target tidak valid.');
    }

    date_default_timezone_set('Asia/Jakarta');
    $currentTs = $startDateTimeStr !== '' ? strtotime($startDateTimeStr) : time();
    if (!$currentTs || $currentTs < time() - 300) {
        $currentTs = time();
    }

    $startHour = (int)date('H', $currentTs);
    $startMinute = (int)date('i', $currentTs);
    $currentTs = crmAdjustTimestampToAllowedDays($currentTs, $allowedDays, $startHour, $startMinute);
    $startedAt = date('Y-m-d H:i:s', $currentTs);

    $totalTargets = 0;
    foreach ($grouped as $list) $totalTargets += count($list);

    // Insert campaign
    $stmt = $conn->prepare("INSERT INTO crm_reminder_campaigns 
        (title, campaign_type, template_id, bulan, inter_message_delay, inter_group_delay, allowed_days, status, total_targets, started_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'running', ?, ?)");
    if (!$stmt) throw new RuntimeException('Gagal membuat kampanye jadwal.');
    $stmt->bind_param('ssisiisis', $title, $campaignType, $templateId, $bulan, $interMessageDelay, $interGroupDelay, $allowedDaysStr, $totalTargets, $startedAt);
    $stmt->execute();
    $campaignId = (int)$stmt->insert_id;
    $stmt->close();

    // Prepare queue insert
    $queueStmt = $conn->prepare("INSERT INTO crm_reminder_campaign_queue
        (campaign_id, peserta_id, request_id, nama, nowa, halaqoh, group_order, message, scheduled_at, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    if (!$queueStmt) throw new RuntimeException('Gagal menyiapkan antrean.');

    $groupOrder = 1;
    $scheduleTime = $currentTs;
    $targetBulan = $bulan !== '' ? $bulan : 'periode ini';

    foreach ($grouped as $halName => $recipients) {
        $countInGroup = count($recipients);
        $idx = 0;
        foreach ($recipients as $recipient) {
            $idx++;
            $name = $recipient['name'];
            $nowa = $recipient['nowa'];
            $pesertaId = $recipient['id'] > 0 ? $recipient['id'] : null;
            $requestId = $recipient['request_id'] > 0 ? $recipient['request_id'] : null;

            $message = str_ireplace(
                ['{peserta_nama}', '{peserta_nowa}', '{nama}', '{NAMA}', '[nama]', '[NAMA]', '{bulan}', '{BULAN}', '[bulan]', '[BULAN]', '{halaqoh}', '{HALAQOH}', '[halaqoh]', '[HALAQOH]'],
                [$name, $nowa, $name, $name, $name, $name, $targetBulan, $targetBulan, $targetBulan, $targetBulan, $halName, $halName, $halName, $halName],
                $rawContent
            );
            $message = preg_replace('/ {2,}/', ' ', $message);

            $scheduleTime = crmAdjustTimestampToAllowedDays($scheduleTime, $allowedDays, $startHour, $startMinute);
            $scheduledAt = date('Y-m-d H:i:s', $scheduleTime);
            $queueStmt->bind_param('iiisssiss', $campaignId, $pesertaId, $requestId, $name, $nowa, $halName, $groupOrder, $message, $scheduledAt);
            $queueStmt->execute();

            // Next message in this halaqoh
            if ($idx < $countInGroup) {
                $scheduleTime += $interMessageDelay;
            }
        }

        // Delay between halaqohs
        $scheduleTime += $interGroupDelay;
        $groupOrder++;
    }
    $queueStmt->close();

    return $campaignId;
}

function crmProcessPendingReminderQueue(mysqli $conn, string $apiUrl, string $apiToken, int $limit = 10): array
{
    crmEnsureReminderScheduleTables($conn);
    date_default_timezone_set('Asia/Jakarta');

    $sql = "SELECT q.id, q.campaign_id, q.peserta_id, q.request_id, q.nama, q.nowa, q.halaqoh, q.message, c.template_id, c.campaign_type, c.title AS campaign_title
            FROM crm_reminder_campaign_queue q
            INNER JOIN crm_reminder_campaigns c ON c.id = q.campaign_id
            WHERE q.status = 'pending'
              AND q.scheduled_at <= NOW()
              AND c.status = 'running'
            ORDER BY q.scheduled_at ASC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$items) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }

    $processed = 0;
    $sent = 0;
    $failed = 0;

    $updateQueueSuccess = $conn->prepare("UPDATE crm_reminder_campaign_queue SET status = 'sent', sent_at = NOW() WHERE id = ?");
    $updateQueueFail = $conn->prepare("UPDATE crm_reminder_campaign_queue SET status = 'failed', error_message = ?, sent_at = NOW() WHERE id = ?");
    $incCampaignSent = $conn->prepare("UPDATE crm_reminder_campaigns SET sent_count = sent_count + 1 WHERE id = ?");
    $incCampaignFailed = $conn->prepare("UPDATE crm_reminder_campaigns SET failed_count = failed_count + 1 WHERE id = ?");
    $logWaStmt = $conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, NOW())");
    $updateRequestStmt = $conn->prepare("UPDATE reminder_requests SET status = 'terkirim', updated_at = NOW() WHERE id = ?");

    $crmHistoryStmt = null;
    $chk = $conn->query("SHOW TABLES LIKE 'crm_message_history'");
    if ($chk && $chk->num_rows > 0) {
        $crmHistoryStmt = $conn->prepare("INSERT INTO crm_message_history (nowa, nama, template_id, template_name, message, sent_at, status) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    }

    $affectedCampaigns = [];

    foreach ($items as $item) {
        $processed++;
        $queueId = (int)$item['id'];
        $campaignId = (int)$item['campaign_id'];
        $affectedCampaigns[$campaignId] = true;
        $name = (string)$item['nama'];
        $number = (string)$item['nowa'];
        $message = (string)$item['message'];

        $payload = json_encode([
            'recipient_type' => 'individual',
            'to' => $number,
            'type' => 'text',
            'text' => ['body' => $message]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$curlError && $httpCode >= 200 && $httpCode < 300) {
            $sent++;
            if ($updateQueueSuccess) {
                $updateQueueSuccess->bind_param('i', $queueId);
                $updateQueueSuccess->execute();
            }
            if ($incCampaignSent) {
                $incCampaignSent->bind_param('i', $campaignId);
                $incCampaignSent->execute();
            }
            $logged = '[REMINDER] [TERKIRIM] ' . $message;
            if ($logWaStmt) {
                $logWaStmt->bind_param('sss', $number, $name, $logged);
                $logWaStmt->execute();
            }
            if ($crmHistoryStmt) {
                $statusVal = 'sent';
                $tplId = (int)$item['template_id'];
                $campTitle = (string)$item['campaign_title'];
                $crmHistoryStmt->bind_param('ssisss', $number, $name, $tplId, $campTitle, $message, $statusVal);
                $crmHistoryStmt->execute();
            }
            $reqId = (int)($item['request_id'] ?? 0);
            if ($reqId > 0 && $updateRequestStmt) {
                $updateRequestStmt->bind_param('i', $reqId);
                $updateRequestStmt->execute();
            }
        } else {
            $failed++;
            $errMsg = $curlError ?: ('HTTP Error ' . $httpCode);
            if ($updateQueueFail) {
                $updateQueueFail->bind_param('si', $errMsg, $queueId);
                $updateQueueFail->execute();
            }
            if ($incCampaignFailed) {
                $incCampaignFailed->bind_param('i', $campaignId);
                $incCampaignFailed->execute();
            }
            $logged = '[REMINDER] [GAGAL] ' . $message;
            if ($logWaStmt) {
                $logWaStmt->bind_param('sss', $number, $name, $logged);
                $logWaStmt->execute();
            }
            if ($crmHistoryStmt) {
                $statusVal = 'failed';
                $tplId = (int)$item['template_id'];
                $campTitle = (string)$item['campaign_title'];
                $crmHistoryStmt->bind_param('ssisss', $number, $name, $tplId, $campTitle, $message, $statusVal);
                $crmHistoryStmt->execute();
            }
        }
        usleep(50000);
    }

    if ($updateQueueSuccess) $updateQueueSuccess->close();
    if ($updateQueueFail) $updateQueueFail->close();
    if ($incCampaignSent) $incCampaignSent->close();
    if ($incCampaignFailed) $incCampaignFailed->close();
    if ($logWaStmt) $logWaStmt->close();
    if ($crmHistoryStmt) $crmHistoryStmt->close();
    if ($updateRequestStmt) $updateRequestStmt->close();

    // Check completion for affected campaigns
    foreach (array_keys($affectedCampaigns) as $cId) {
        $cStmt = $conn->prepare("SELECT COUNT(*) AS pending_count FROM crm_reminder_campaign_queue WHERE campaign_id = ? AND status = 'pending'");
        if ($cStmt) {
            $cStmt->bind_param('i', $cId);
            $cStmt->execute();
            $pCount = (int)($cStmt->get_result()->fetch_assoc()['pending_count'] ?? 0);
            $cStmt->close();
            if ($pCount === 0) {
                $compStmt = $conn->prepare("UPDATE crm_reminder_campaigns SET status = 'completed' WHERE id = ?");
                if ($compStmt) {
                    $compStmt->bind_param('i', $cId);
                    $compStmt->execute();
                    $compStmt->close();
                }
            }
        }
    }

    return ['processed' => $processed, 'sent' => $sent, 'failed' => $failed];
}

function crmUpdateCampaignStatus(mysqli $conn, int $campaignId, string $newStatus): bool
{
    crmEnsureReminderScheduleTables($conn);
    if (!in_array($newStatus, ['running', 'paused', 'cancelled', 'completed'], true)) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE crm_reminder_campaigns SET status = ? WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('si', $newStatus, $campaignId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $newStatus === 'cancelled') {
        $cStmt = $conn->prepare("UPDATE crm_reminder_campaign_queue SET status = 'cancelled' WHERE campaign_id = ? AND status = 'pending'");
        if ($cStmt) {
            $cStmt->bind_param('i', $campaignId);
            $cStmt->execute();
            $cStmt->close();
        }
    }

    return $ok;
}

function crmGetActiveCampaigns(mysqli $conn, int $limit = 5, ?string $campaignType = null): array
{
    crmEnsureReminderScheduleTables($conn);

    $whereExtra = '';
    if ($campaignType !== null && $campaignType !== '') {
        $esc = $conn->real_escape_string($campaignType);
        $whereExtra = " AND c.campaign_type = '{$esc}'";
    }

    $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM crm_reminder_campaign_queue q WHERE q.campaign_id = c.id AND q.status = 'pending') AS pending_count,
            (SELECT q2.scheduled_at FROM crm_reminder_campaign_queue q2 WHERE q2.campaign_id = c.id AND q2.status = 'pending' ORDER BY q2.scheduled_at ASC LIMIT 1) AS next_scheduled_at,
            (SELECT q3.halaqoh FROM crm_reminder_campaign_queue q3 WHERE q3.campaign_id = c.id AND q3.status = 'pending' ORDER BY q3.scheduled_at ASC LIMIT 1) AS current_halaqoh
            FROM crm_reminder_campaigns c
            WHERE c.status IN ('running', 'paused') {$whereExtra}
            ORDER BY c.id DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
