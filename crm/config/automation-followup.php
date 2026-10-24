<?php
declare(strict_types=1);

require_once __DIR__ . '/prospect.php';

function crmAutomationGetApiCredentials(): array
{
    global $apiUrl, $apiToken;

    $url = defined('ONESENDER_API_URL')
        ? ONESENDER_API_URL
        : (!empty($apiUrl) ? $apiUrl : (defined('WA_API_URL') ? WA_API_URL : 'https://wa51024.oneapi.my.id/api/v1/messages'));

    $token = defined('ONESENDER_API_TOKEN')
        ? ONESENDER_API_TOKEN
        : (!empty($apiToken) ? $apiToken : (defined('WA_API_TOKEN') ? WA_API_TOKEN : ''));

    return [(string)$url, (string)$token];
}

function crmAutomationSeedDefaultTemplates(mysqli $conn): void
{
    $defaultTemplates = [
        [
            'name' => 'Follow Up 1 (30 Menit - Cek Akses & Pertanyaan)',
            'desc' => 'Dikirimkan 30 menit setelah chat awal untuk mengecek apakah link website berhasil dibuka',
            'content' => "Ahlan kak {nama}! 😊\n\nMau memastikan, apakah link rincian program Tahfidz Private-nya tadi sudah berhasil dibuka kak?\n\nBarangkali ada bagian dari pilihan waktu, kurikulum, atau biaya yang ingin kami bantu jelaskan lebih detail? Silakan langsung tanyakan di sini ya kak, kami siap membantu! 🙏",
            'delay' => 30,
        ],
        [
            'name' => 'Follow Up 2 (1 Jam - Fleksibilitas Jadwal & Target)',
            'desc' => 'Dikirimkan 1 jam kemudian untuk menawarkan konsultasi jadwal santai',
            'content' => "Bismillah, kak {nama}. Semoga kabarnya senantiasa diberkahi. ✨\n\nSekadar info kak, di program Tahfidz Private Jawwada jadwal belajarnya sangat fleksibel (bisa pagi, sore, atau malam) dengan sistem 1 guru 1 santri sehingga bimbingannya intensif.\n\nApakah kak {nama} ingin berkonsultasi santai dahulu mengenai target hafalan atau perbaikan bacaan Al-Qur'annya?",
            'delay' => 60,
        ],
        [
            'name' => 'Follow Up 3 (5 Jam - Reservasi Slot Terbatas)',
            'desc' => 'Dikirimkan 5 jam kemudian untuk mengabarkan kuota pengajar pekan ini',
            'content' => "Assalamu'alaikum kak {nama}. 🙏\n\nUntuk pembukaan halaqoh Tahfidz Private pekan ini kuotanya cukup terbatas karena menyesuaikan ketersediaan jadwal ustadz/ustadzah pembimbing.\n\nJika kak {nama} berminat, kami bisa bantu simpankan slot jadwal terbaiknya terlebih dahulu. Bagaimana kak, apakah berkenan untuk kami jadwalkan?",
            'delay' => 300,
        ],
    ];

    foreach ($defaultTemplates as $tpl) {
        $check = $conn->prepare("SELECT id FROM crm_automation_templates WHERE name = ? LIMIT 1");
        if ($check) {
            $check->bind_param('s', $tpl['name']);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$exists) {
                $ins = $conn->prepare("INSERT INTO crm_automation_templates (name, content, description) VALUES (?, ?, ?)");
                if ($ins) {
                    $ins->bind_param('sss', $tpl['name'], $tpl['content'], $tpl['desc']);
                    $ins->execute();
                    $tplId = (int)$ins->insert_id;
                    $ins->close();

                    if ($tplId > 0) {
                        $ruleName = 'Tahfidz Private - ' . $tpl['name'];
                        $kw = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada';
                        $insRule = $conn->prepare("INSERT INTO crm_automation_rules (name, trigger_keyword, trigger_condition, template_id, delay_minutes, is_active) VALUES (?, ?, 'contains', ?, ?, 0)");
                        if ($insRule) {
                            $insRule->bind_param('ssii', $ruleName, $kw, $tplId, $tpl['delay']);
                            $insRule->execute();
                            $insRule->close();
                        }
                    }
                }
            }
        }
    }
}

function crmAutomationEnsureTables(mysqli $conn): bool
{
    static $ensured = false;
    if ($ensured) {
        return true;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS crm_automation_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        content TEXT NOT NULL,
        description VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS crm_automation_rules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        trigger_keyword VARCHAR(255) NOT NULL,
        trigger_condition VARCHAR(30) NOT NULL DEFAULT 'contains',
        template_id INT UNSIGNED NOT NULL,
        delay_minutes INT UNSIGNED NOT NULL DEFAULT 120,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_auto_rule_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS crm_automation_queue (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rule_id INT UNSIGNED NOT NULL,
        template_id INT UNSIGNED NOT NULL,
        nowa VARCHAR(50) NOT NULL,
        nama VARCHAR(150) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        scheduled_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_auto_queue_status_sched (status, scheduled_at),
        INDEX idx_auto_queue_nowa (nowa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crmAutomationSeedDefaultTemplates($conn);

    $ensured = true;
    return true;
}

function crmAutomationIsBlocked(mysqli $conn, string $nowa): bool
{
    $normalized = crmProspectNormalizeNumber($nowa);
    if ($normalized === '') {
        return false;
    }

    $variants = array_values(array_unique(array_filter([
        $normalized,
        $nowa,
        str_starts_with($normalized, '62') ? '0' . substr($normalized, 2) : '',
        '+' . $normalized,
    ])));

    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $stmt = $conn->prepare("SELECT 1 FROM blocked_peserta WHERE nowa IN ({$placeholders}) LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $types = str_repeat('s', count($variants));
    $stmt->bind_param($types, ...$variants);
    $stmt->execute();
    $isBlocked = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $isBlocked;
}

function crmAutomationCancelQueueItem(mysqli $conn, int $queueId, string $reason = 'cancelled_replied'): bool
{
    $stmt = $conn->prepare("UPDATE crm_automation_queue SET status = ?, error_message = ? WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssi', $reason, $reason, $queueId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function crmAutomationHasReceivedPayment(mysqli $conn, string $nowa): bool
{
    $normalized = crmProspectNormalizeNumber($nowa);
    $variants = array_values(array_unique(array_filter([
        $normalized,
        $nowa,
        str_starts_with($normalized, '62') ? '0' . substr($normalized, 2) : '',
        '+' . $normalized,
    ])));

    if (empty($variants)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $sql = "SELECT 1 FROM log_wa 
            WHERE nowa IN ({$placeholders})
              AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(COALESCE(message, '')), CHAR(13), ''), CHAR(10), ''), CHAR(9), ''), ' ', ''), CHAR(160), '') LIKE '%wajibsegeradiisi%'
              AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(COALESCE(message, '')), CHAR(13), ''), CHAR(10), ''), CHAR(9), ''), ' ', ''), CHAR(160), '') LIKE '%mohondiisiuntukpendataanfinancekami%'
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $types = str_repeat('s', count($variants));
    $stmt->bind_param($types, ...$variants);
    $stmt->execute();
    $hasPayment = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $hasPayment;
}

function crmAutomationHasUserReplied(mysqli $conn, string $nowa, string $registeredAt): bool
{
    $normalized = crmProspectNormalizeNumber($nowa);
    $variants = array_values(array_unique(array_filter([
        $normalized,
        $nowa,
        str_starts_with($normalized, '62') ? '0' . substr($normalized, 2) : '',
    ])));

    if (empty($variants)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    // Pesan masuk dari user: bukan pesan siaran grup dan bukan pesan keluar yang dikirim oleh sistem/CRM
    $sql = "SELECT id FROM log_wa 
            WHERE (nowa IN ({$placeholders})) 
              AND created_at > ? 
              AND message NOT LIKE '[GRUP]%' 
              AND message NOT LIKE 'Ahlan kak%' 
            ORDER BY id DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $types = str_repeat('s', count($variants)) . 's';
    $params = array_merge($variants, [$registeredAt]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $replied = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $replied;
}

function crmAutomationRegisterQueue(
    mysqli $conn,
    string $nowa,
    ?string $nama,
    int $ruleId,
    int $delayMinutes,
    bool $allowInactiveRule = false,
    int $templateIdOverride = 0
): int {
    crmAutomationEnsureTables($conn);

    $normalized = crmProspectNormalizeNumber($nowa);
    if ($normalized === '' || crmAutomationIsBlocked($conn, $normalized)) {
        return 0;
    }

    $templateId = $templateIdOverride;
    if ($templateId <= 0) {
        $activeClause = $allowInactiveRule ? "" : " AND is_active = 1";
        $ruleStmt = $conn->prepare("SELECT template_id FROM crm_automation_rules WHERE id = ? {$activeClause} LIMIT 1");
        if (!$ruleStmt) {
            return 0;
        }
        $ruleStmt->bind_param('i', $ruleId);
        $ruleStmt->execute();
        $ruleRow = $ruleStmt->get_result()->fetch_assoc();
        $ruleStmt->close();

        if (!$ruleRow) {
            return 0;
        }
        $templateId = (int)$ruleRow['template_id'];
    }

    // Deduplikasi: Jika masih ada antrean berstatus pending untuk nomor dan rule ini, perbarui jadwalnya
    $checkStmt = $conn->prepare("SELECT id FROM crm_automation_queue WHERE nowa = ? AND rule_id = ? AND status = 'pending' LIMIT 1");
    if ($checkStmt) {
        $checkStmt->bind_param('si', $normalized, $ruleId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $existingId = (int)$existing['id'];
            $updateStmt = $conn->prepare("UPDATE crm_automation_queue SET scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), nama = COALESCE(?, nama) WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param('isi', $delayMinutes, $nama, $existingId);
                $updateStmt->execute();
                $updateStmt->close();
            }
            return $existingId;
        }
    }

    // Buat antrean baru
    $insertStmt = $conn->prepare("INSERT INTO crm_automation_queue (rule_id, template_id, nowa, nama, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())");
    if (!$insertStmt) {
        return 0;
    }

    $insertStmt->bind_param('iissi', $ruleId, $templateId, $normalized, $nama, $delayMinutes);
    $insertStmt->execute();
    $queueId = (int)$insertStmt->insert_id;
    $insertStmt->close();

    return $queueId;
}
