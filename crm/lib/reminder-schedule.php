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
    $chk = $conn->query("SHOW COLUMNS FROM crm_reminder_campaign_queue LIKE 'template_num'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE crm_reminder_campaign_queue ADD COLUMN template_num INT UNSIGNED NULL AFTER group_order");
    }

    // Pastikan kampanye peserta aktif di hari kerja Senin-Jumat
    $conn->query("UPDATE crm_reminder_campaigns SET allowed_days = '1,2,3,4,5' WHERE campaign_type = 'peserta' AND (allowed_days = '1,2,3,4,5,6,7' OR allowed_days IS NULL)");
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

/**
 * Memajukan waktu 1 hari ke hari aktif berikutnya pada jam dan menit awal yang sama.
 */
function crmAdvanceToNextAllowedDay(int $currentTs, array $allowedDays, int $targetHour, int $targetMinute): int
{
    $nextTs = strtotime('+1 day', $currentTs);
    $nextTs = mktime($targetHour, $targetMinute, 0, (int)date('n', $nextTs), (int)date('j', $nextTs), (int)date('Y', $nextTs));
    return crmAdjustTimestampToAllowedDays($nextTs, $allowedDays, $targetHour, $targetMinute);
}

/**
 * Mengambil nomor template rotasi terakhir (1-6) yang pernah dikirim/dijadwalkan untuk peserta ini.
 */
function crmReminderGetLastTemplateNum(mysqli $conn, string $nowa, ?int $pesertaId = null): int
{
    $norm = preg_replace('/\D+/', '', $nowa);
    if (str_starts_with($norm, '0')) {
        $norm = '62' . substr($norm, 1);
    }

    $pId = $pesertaId ?: 0;

    // 1. Cek kolom template_num terlebih dahulu
    $hasCol = false;
    $chk = $conn->query("SHOW COLUMNS FROM crm_reminder_campaign_queue LIKE 'template_num'");
    if ($chk && $chk->num_rows > 0) {
        $hasCol = true;
    }

    if ($hasCol) {
        $stmt = $conn->prepare("
            SELECT template_num 
            FROM crm_reminder_campaign_queue 
            WHERE (nowa = ? OR (peserta_id > 0 AND peserta_id = ?))
              AND template_num IS NOT NULL 
              AND template_num >= 1 AND template_num <= 6
            ORDER BY scheduled_at DESC, id DESC 
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('si', $norm, $pId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['template_num'])) {
                return (int)$row['template_num'];
            }
        }
    }

    // 2. Fallback deteksi dari isi pesan jika template_num belum terisi
    $stmt2 = $conn->prepare("
        SELECT message 
        FROM crm_reminder_campaign_queue 
        WHERE (nowa = ? OR (peserta_id > 0 AND peserta_id = ?))
        ORDER BY scheduled_at DESC, id DESC 
        LIMIT 1
    ");
    if ($stmt2) {
        $stmt2->bind_param('si', $norm, $pId);
        $stmt2->execute();
        $row2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        if ($row2 && !empty($row2['message'])) {
            $msg = $row2['message'];
            if (stripos($msg, 'tidak terlihat setorannya') !== false) return 1;
            if (stripos($msg, 'tertinggal beberapa hari') !== false) return 2;
            if (stripos($msg, 'absen halqoh') !== false) return 3;
            if (stripos($msg, 'setorannya menumpuk') !== false) return 4;
            if (stripos($msg, 'tidak ada kabar setoran') !== false) return 5;
            if (stripos($msg, 'Ke mana saja beberapa hari ini') !== false) return 6;
        }
    }

    return 0;
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
    $isRotating = !empty($params['is_rotating']) || ((string)($params['template_id'] ?? '') === 'rotate_1_6') || ($templateId === -1);
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

    $rotationTemplates = [];
    if ($isRotating) {
        $res = $conn->query("SELECT id, name, content FROM reminder_templates WHERE name IN ('1','2','3','4','5','6') ORDER BY CAST(name AS UNSIGNED) ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $rotationTemplates[] = $row;
        }
        if (!$rotationTemplates) {
            $res = $conn->query("SELECT id, name, content FROM reminder_templates ORDER BY id ASC LIMIT 6");
            if ($res) while ($row = $res->fetch_assoc()) $rotationTemplates[] = $row;
        }
        if ($rotationTemplates) {
            $templateId = (int)$rotationTemplates[0]['id'];
        }
    }

    if ($templateId <= 0 && !$isRotating) {
        throw new InvalidArgumentException('Template pesan wajib dipilih.');
    }
    if (!$targets) {
        throw new InvalidArgumentException('Tidak ada target peserta yang dipilih.');
    }

    // Fetch template content from appropriate table
    $rawContent = '';
    if ($isRotating && $rotationTemplates) {
        $rawContent = (string)$rotationTemplates[0]['content'];
    } elseif ($campaignType === 'peserta') {
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

    if ($rawContent === '' && !$isRotating) {
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

    $scheduleDays = max(1, min(14, (int)($params['schedule_days'] ?? 1)));

    $startHour = (int)date('H', $currentTs);
    $startMinute = (int)date('i', $currentTs);
    $currentTs = crmAdjustTimestampToAllowedDays($currentTs, $allowedDays, $startHour, $startMinute);
    $startedAt = date('Y-m-d H:i:s', $currentTs);

    $baseTargets = 0;
    foreach ($grouped as $list) $baseTargets += count($list);
    $totalTargets = $baseTargets * $scheduleDays;

    // Insert campaign
    $stmt = $conn->prepare("INSERT INTO crm_reminder_campaigns 
        (title, campaign_type, template_id, bulan, inter_message_delay, inter_group_delay, allowed_days, status, total_targets, started_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'running', ?, ?)");
    if (!$stmt) throw new RuntimeException('Gagal membuat kampanye jadwal.');
    $stmt->bind_param('ssisiisis', $title, $campaignType, $templateId, $bulan, $interMessageDelay, $interGroupDelay, $allowedDaysStr, $totalTargets, $startedAt);
    $stmt->execute();
    $campaignId = (int)$stmt->insert_id;
    $stmt->close();

    // Inisialisasi nomor template awal untuk tiap peserta
    // Jika peserta sudah pernah dapat template X di hari sebelumnya, otomatis mulai dari X+1
    // Jika belum pernah, beri template bergantian (1, 2, 3, 4, 5, 6, 1...) agar antar peserta beda pesan
    $participantBaseTpl = [];
    $pIdx = 0;
    foreach ($grouped as $halName => $recipients) {
        foreach ($recipients as $recipient) {
            $key = $recipient['nowa'];
            if (!isset($participantBaseTpl[$key])) {
                $lastNum = crmReminderGetLastTemplateNum($conn, $recipient['nowa'], $recipient['id']);
                if ($lastNum > 0 && !empty($rotationTemplates)) {
                    $participantBaseTpl[$key] = ($lastNum % count($rotationTemplates)) + 1;
                } else {
                    $participantBaseTpl[$key] = (!empty($rotationTemplates)) ? (($pIdx % count($rotationTemplates)) + 1) : 1;
                }
                $pIdx++;
            }
        }
    }

    // Prepare queue insert
    $queueStmt = $conn->prepare("INSERT INTO crm_reminder_campaign_queue
        (campaign_id, peserta_id, request_id, nama, nowa, halaqoh, group_order, template_num, message, scheduled_at, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    if (!$queueStmt) {
        throw new RuntimeException('Gagal menyiapkan antrean: ' . $conn->error);
    }

    $groupOrder = 1;
    $targetBulan = $bulan !== '' ? $bulan : 'periode ini';
    $dayBaseTs = $currentTs;

    for ($dayIdx = 0; $dayIdx < $scheduleDays; $dayIdx++) {
        if ($dayIdx > 0) {
            $dayBaseTs = crmAdvanceToNextAllowedDay($dayBaseTs, $allowedDays, $startHour, $startMinute);
        }

        $scheduleTime = $dayBaseTs;

        foreach ($grouped as $halName => $recipients) {
            $countInGroup = count($recipients);
            $idx = 0;
            foreach ($recipients as $recipient) {
                $idx++;
                $name = $recipient['name'];
                $nowa = $recipient['nowa'];
                $pesertaId = $recipient['id'] > 0 ? $recipient['id'] : null;
                $requestId = $recipient['request_id'] > 0 ? $recipient['request_id'] : null;

                $activeContent = $rawContent;
                $assignedTplNum = null;

                if ($isRotating && !empty($rotationTemplates)) {
                    $baseTpl = $participantBaseTpl[$nowa] ?? 1;
                    $assignedTplNum = (($baseTpl - 1 + $dayIdx) % count($rotationTemplates)) + 1;
                    $chosen = $rotationTemplates[$assignedTplNum - 1];
                    $activeContent = (string)$chosen['content'];
                }

                $message = str_ireplace(
                    ['{peserta_nama}', '{peserta_nowa}', '{nama}', '{NAMA}', '[nama]', '[NAMA]', '{bulan}', '{BULAN}', '[bulan]', '[BULAN]', '{halaqoh}', '{HALAQOH}', '[halaqoh]', '[HALAQOH]'],
                    [$name, $nowa, $name, $name, $name, $name, $targetBulan, $targetBulan, $targetBulan, $targetBulan, $halName, $halName, $halName, $halName],
                    $activeContent
                );
                $message = preg_replace('/ {2,}/', ' ', $message);

                $itemScheduleTime = crmAdjustTimestampToAllowedDays($scheduleTime, $allowedDays, $startHour, $startMinute);
                $scheduledAt = date('Y-m-d H:i:s', $itemScheduleTime);

                $queueStmt->bind_param('iiisssiiss', $campaignId, $pesertaId, $requestId, $name, $nowa, $halName, $groupOrder, $assignedTplNum, $message, $scheduledAt);
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
    }
    $queueStmt->close();

    return $campaignId;
}

function crmProcessPendingReminderQueue(mysqli $conn, string $apiUrl, string $apiToken, int $limit = 10): array
{
    crmEnsureReminderScheduleTables($conn);
    date_default_timezone_set('Asia/Jakarta');
    @$conn->query("SET time_zone = '+07:00'");

    $nowStr = date('Y-m-d H:i:s');
    $sql = "SELECT q.id, q.campaign_id, q.peserta_id, q.request_id, q.nama, q.nowa, q.halaqoh, q.message, 
                   c.template_id, c.campaign_type, c.title AS campaign_title, c.allowed_days
            FROM crm_reminder_campaign_queue q
            INNER JOIN crm_reminder_campaigns c ON c.id = q.campaign_id
            WHERE q.status = 'pending'
              AND (q.scheduled_at <= ? OR q.scheduled_at <= NOW())
              AND c.status = 'running'
            ORDER BY q.scheduled_at ASC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }
    $stmt->bind_param('si', $nowStr, $limit);
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
        $queueId = (int)$item['id'];
        $campaignId = (int)$item['campaign_id'];

        $campAllowedStr = (string)($item['allowed_days'] ?? '1,2,3,4,5');
        $campAllowedList = array_map('intval', explode(',', $campAllowedStr));
        $todayN = (int)date('N'); // 1 = Senin ... 7 = Ahad
        if ($campAllowedList && !in_array($todayN, $campAllowedList, true)) {
            // Hari ini bukan hari aktif (misal Sabtu atau Ahad), tunda ke hari aktif berikutnya
            $rescheduledTs = crmAdjustTimestampToAllowedDays(time(), $campAllowedList, 8, 15);
            $newScheduledAt = date('Y-m-d H:i:s', $rescheduledTs);
            $reschedStmt = $conn->prepare("UPDATE crm_reminder_campaign_queue SET scheduled_at = ? WHERE id = ?");
            if ($reschedStmt) {
                $reschedStmt->bind_param('si', $newScheduledAt, $queueId);
                $reschedStmt->execute();
                $reschedStmt->close();
            }
            continue;
        }

        $processed++;
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

    // Otomatis batalkan kampanye hantu tanpa antrean akibat bug prepared statement sebelumnya
    @$conn->query("UPDATE crm_reminder_campaigns c 
                  SET c.status = 'cancelled' 
                  WHERE c.status IN ('running', 'paused') 
                    AND (SELECT COUNT(*) FROM crm_reminder_campaign_queue q WHERE q.campaign_id = c.id) = 0");

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
