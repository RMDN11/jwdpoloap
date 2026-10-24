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

function crmAutomationClassifyLpLead(string $message): array
{
    $m = strtolower($message);
    if (str_contains($m, 'tahfidz cilik')) {
        return [
            'key' => 'cilik',
            'label' => 'Tahfidz Cilik',
            'color' => '#be185d',
            'bg' => '#fce7f3',
            'icon' => 'fa-child-reaching',
        ];
    }
    if (str_contains($m, 'menghafal pemula')) {
        return [
            'key' => 'pemula',
            'label' => 'Menghafal Pemula',
            'color' => '#1d4ed8',
            'bg' => '#dbeafe',
            'icon' => 'fa-book-quran',
        ];
    }
    if (str_contains($m, 'menghafal lanjutan') || str_contains($m, 'penguatan hafalan')) {
        return [
            'key' => 'lanjutan',
            'label' => 'Lanjutan / Muroja\'ah',
            'color' => '#6d28d9',
            'bg' => '#ede9fe',
            'icon' => 'fa-award',
        ];
    }
    if (str_contains($m, 'saya mau daftar program') || str_contains($m, 'daftar sekarang')) {
        return [
            'key' => 'cta',
            'label' => 'Pendaftaran CTA',
            'color' => '#047857',
            'bg' => '#d1fae5',
            'icon' => 'fa-bolt',
        ];
    }
    return [
        'key' => 'general',
        'label' => 'Tanya Detail (Hero)',
        'color' => '#334155',
        'bg' => '#f1f5f9',
        'icon' => 'fa-comment-dots',
    ];
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
            'desc' => 'Check-in ramah apakah info program sudah berhasil dibuka',
            'content' => "Bismillah, ahlan kak {nama}! 😊\n\nMau memastikan, apakah info program Tahfidz Private tadi sudah berhasil dibuka kak?\n\nJika ada pertanyaan soal pilihan waktu atau biaya belajarnya, silakan tanyakan ya kak, kami siap membantu. 🙏",
            'delay' => 30,
        ],
        [
            'name' => 'Follow Up 2 (1 Jam - Fleksibilitas & Biaya Terjangkau)',
            'desc' => 'Edukasi privat 1-on-1 via telepon WA 10 menit & biaya mulai Rp135rb/bln',
            'content' => "Sekadar info kak {nama}, di Jawwada belajarnya private 1-on-1 via Telepon WA (10 menit/hari) jadi tidak menyita waktu. Biayanya sangat terjangkau mulai Rp135rb/bulan.\n\nKak {nama} rencananya ingin belajar di waktu luang pagi, sore, atau malam kak? ✨",
            'delay' => 60,
        ],
        [
            'name' => 'Follow Up 3 (5 Jam - Reservasi Slot Terbatas)',
            'desc' => 'Soft urgency kuota guru hafidz 30 juz pekan ini terbatas',
            'content' => "Assalamu'alaikum kak {nama}. 🙏\n\nIzin mengabarkan, kuota pembimbing Hafidz/Hafidzoh 30 Juz untuk pekan ini tersisa sedikit karena jadwal privat yang terbatas.\n\nJika kak {nama} berminat, mau kami bantu simpankan slot belajarnya terlebih dahulu? 😊",
            'delay' => 300,
        ],
        [
            'name' => 'Follow Up 4 (H+1 / 24 Jam - Solusi Ragu & Pengalaman Santri)',
            'desc' => 'Menghilangkan rasa minder jika bacaan belum lancar, bimbingan dari nol',
            'content' => "Assalamu'alaikum kak {nama} ✨\n\nTidak perlu khawatir jika bacaan belum lancar ya kak. Di kelas privat ini ustadz/ustadzah akan membimbing dari nol (tahsin santai) tanpa beban minimal setoran.\n\nKira-kira apa target Al-Qur'an yang paling ingin kak {nama} capai saat ini? 🙏",
            'delay' => 1440,
        ],
        [
            'name' => 'Follow Up 5 (H+3 / 3 Hari - Konsultasi & Free Placement Test)',
            'desc' => 'Tawaran gratis tes penempatan & pemetaan target hafalan via VN/chat santai',
            'content' => "Ahlan kak {nama} 🙏\n\nAgar ada gambaran, tim kurikulum kami menyediakan sesi tes penempatan level bacaan & pemetaan target hafalan secara gratis via voice note / chat santai.\n\nApakah berkenan jika kami bantu jadwalkan pekan ini kak? 😊",
            'delay' => 4320,
        ],
        [
            'name' => 'Follow Up 6 (H+7 / 7 Hari - Konfirmasi Penutupan Data & Doa)',
            'desc' => 'Penutupan sopan (breakup sequence) dan doa kebaikan tanpa terkesan memaksa',
            'content' => "Bismillah, kak {nama}. 🙏\n\nIzin kami arsipkan data konsultasinya sementara waktu ya kak, agar tidak mengganggu kesibukan kakak.\n\nJika sewaktu-waktu ingin memulai kembali, nomor ini selalu terbuka untuk kak {nama}. Semoga Allah Ta'ala mudahkan niat mulianya. Barakallahu fiik. 🤲✨",
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
            } else {
                // Update template agar otomatis ringkas & persuasif
                $upd = $conn->prepare("UPDATE crm_automation_templates SET content = ?, description = ? WHERE id = ?");
                if ($upd) {
                    $tplId = (int)$exists['id'];
                    $upd->bind_param('ssi', $tpl['content'], $tpl['desc'], $tplId);
                    $upd->execute();
                    $upd->close();
                }
            }
        }
    }

    // Seed variasi aturan khusus untuk tombol pendaftaran landing page (default is_active = 0)
    $lpVariations = [
        [
            'name' => 'Tahfidz Private - Pendaftaran Langsung (CTA)',
            'kw' => 'Kak, saya mau daftar program Tahfidz Private Online',
            'delay' => 60,
        ],
        [
            'name' => 'Tahfidz Private - Kelas Menghafal Pemula',
            'kw' => 'Kelas Menghafal Pemula',
            'delay' => 60,
        ],
        [
            'name' => 'Tahfidz Private - Lanjutan & Penguatan Muroja\'ah',
            'kw' => 'Kelas Menghafal Lanjutan',
            'delay' => 60,
        ],
        [
            'name' => 'Tahfidz Cilik - Bimbingan Anak (Video Call)',
            'kw' => 'Tahfidz Cilik',
            'delay' => 60,
        ],
    ];

    foreach ($lpVariations as $var) {
        $checkVar = $conn->prepare("SELECT id FROM crm_automation_rules WHERE trigger_keyword = ? LIMIT 1");
        if ($checkVar) {
            $checkVar->bind_param('s', $var['kw']);
            $checkVar->execute();
            $existsVar = $checkVar->get_result()->fetch_assoc();
            $checkVar->close();

            if (!$existsVar) {
                $insVar = $conn->prepare("INSERT INTO crm_automation_rules (name, trigger_keyword, trigger_condition, template_id, delay_minutes, is_active) VALUES (?, ?, 'contains', 1, ?, 0)");
                if ($insVar) {
                    $insVar->bind_param('ssi', $var['name'], $var['kw'], $var['delay']);
                    $insVar->execute();
                    $insVar->close();
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

function crmAutomationScheduleSequence(
    mysqli $conn,
    string $nowa,
    ?string $nama,
    int $startTemplateId = 0,
    string $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada'
): int {
    crmAutomationEnsureTables($conn);

    $normalized = crmProspectNormalizeNumber($nowa);
    if ($normalized === '' || crmAutomationIsBlocked($conn, $normalized)) {
        return 0;
    }

    if (crmAutomationHasReceivedPayment($conn, $normalized)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT id, template_id, delay_minutes FROM crm_automation_rules WHERE ? LIKE CONCAT('%', trigger_keyword, '%') OR trigger_keyword LIKE CONCAT('%', ?, '%') ORDER BY delay_minutes ASC");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('ss', $keyword, $keyword);
    $stmt->execute();
    $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rules)) {
        return 0;
    }

    // Tentukan delay awal jika startTemplateId dipilih
    $startDelay = 0;
    if ($startTemplateId > 0) {
        foreach ($rules as $r) {
            if ((int)$r['template_id'] === $startTemplateId) {
                $startDelay = (int)$r['delay_minutes'];
                break;
            }
        }
    }

    $filteredRules = [];
    foreach ($rules as $r) {
        if ((int)$r['delay_minutes'] >= $startDelay) {
            $filteredRules[] = $r;
        }
    }
    if (empty($filteredRules)) {
        $filteredRules = $rules;
        $startDelay = 0;
    }

    $count = 0;
    foreach ($filteredRules as $r) {
        $ruleId = (int)$r['id'];
        $tplId = (int)$r['template_id'];
        $ruleDelay = (int)$r['delay_minutes'];

        // Jadwalkan berurutan mulai dari tombol diklik:
        // Pesan pertama mulai dalam 10 menit, pesan berikutnya berjarak sesuai selisih jeda
        if ($startDelay > 0) {
            $relativeDelay = ($ruleDelay - $startDelay) + 10;
        } else {
            $relativeDelay = $ruleDelay;
        }

        $res = crmAutomationRegisterQueue($conn, $normalized, $nama, $ruleId, $relativeDelay, true, $tplId);
        if ($res > 0) {
            $count++;
        }
    }

    return $count;
}

function crmAutomationRegisterSequence(
    mysqli $conn,
    string $nowa,
    ?string $nama,
    string $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada'
): int {
    return crmAutomationScheduleSequence($conn, $nowa, $nama, 0, $keyword);
}
