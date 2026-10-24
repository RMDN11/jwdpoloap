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

function crmAutomationFormatDelay(int $minutes): string
{
    if ($minutes < 60) {
        return "{$minutes} menit";
    }
    if ($minutes < 1440) {
        $hours = round($minutes / 60, 1);
        return number_format($minutes, 0, ',', '.') . " menit ({$hours} jam)";
    }
    $days = round($minutes / 1440, 1);
    return number_format($minutes, 0, ',', '.') . " menit (H+{$days} / {$days} hari)";
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
        [
            'name' => 'Follow Up 4 (H+1 / 24 Jam - Solusi Ragu & Pengalaman Santri)',
            'desc' => 'Dikirimkan 1 hari setelah chat untuk meyakinkan calon santri yang ragu dengan kemampuan atau kesibukan',
            'content' => "Assalamu'alaikum kak {nama} ✨\nSemoga hari ini penuh berkah dan kemudahan ya kak.\n\nSekadar berbagi cerita kak, banyak santri di Tahfidz Private Jawwada yang awalnya sempat ragu: *\"Apakah bisa menghafal di tengah kesibukan kerja/kuliah?\"* atau *\"Apakah bisa ikut kalau bacaan tajwid masih terbata-bata?\"*\n\nAlhamdulillah karena bimbingannya 1-on-1 (privat), ustadz/ustadzah akan menyesuaikan ritme belajar sepenuhnya dengan kemampuan kak {nama}. Belajar dimulai dari perbaikan makhraj & tahsin santai tanpa beban.\n\nKira-kira apa target hafalan atau harapan yang paling ingin kak {nama} capai saat ini? Jika ingin ngobrol santai dulu, kami siap membantu ya kak. 😊🙏",
            'delay' => 1440,
        ],
        [
            'name' => 'Follow Up 5 (H+3 / 3 Hari - Konsultasi & Free Placement Test)',
            'desc' => 'Dikirimkan 3 hari kemudian untuk menawarkan tes penempatan level bacaan gratis tanpa komitmen',
            'content' => "Ahlan wa sahlan kak {nama} 🙏\nSemoga dalam keadaan sehat wal'afiat.\n\nSupaya kak {nama} memiliki gambaran yang jelas dan tidak bingung menentukan langkah awal, tim Jawwada menyediakan sesi *Free Placement & Consultation*.\n\nDi sesi santai ini, kak {nama} bisa:\n1. Mengetahui level bacaan Al-Qur'an & tajwid saat ini\n2. Menentukan target juz yang realistis sesuai jadwal harian\n3. Mencocokkan kriteria ustadz/ustadzah yang paling cocok\n\nSesi konsultasi ini gratis dan tidak mengikat ya kak. Apakah kak {nama} berkenan jika kami bantu jadwalkan pekan ini? 😊✨",
            'delay' => 4320,
        ],
        [
            'name' => 'Follow Up 6 (H+7 / 7 Hari - Konfirmasi Penutupan Data & Doa)',
            'desc' => 'Dikirimkan 7 hari kemudian sebagai penutupan sopan (breakup sequence) agar tidak mengganggu prospek',
            'content' => "Bismillah, Assalamu'alaikum kak {nama}. 🙏\n\nMohon maaf jika pesan kami sebelumnya sempat menyela kesibukan kak {nama}.\n\nKarena belum ada kabar kelanjutan mengenai rencana bimbingan Tahfidz Private-nya, izin kami rapikan dan arsipkan data konsultasi kak {nama} sementara waktu agar tidak mengganggu ya kak.\n\nNamun jika sewaktu-waktu kak {nama} sudah memiliki kelonggaran waktu atau ingin memulai kembali, kontak ini selalu terbuka untuk kak {nama}.\n\nJazaakallahu khayran sudah menghubungi Jawwada. Semoga Allah Ta'ala senantiasa memudahkan niat mulia kak {nama} untuk dekat dengan Al-Qur'an. Aamiin ya Rabbal 'Alamin. 🤲✨",
            'delay' => 10080,
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
