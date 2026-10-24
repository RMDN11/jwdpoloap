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

function crmAutomationIsOperationalNumber(string $nowa): bool
{
    $clean = preg_replace('/\D+/', '', $nowa);
    if ($clean === '') {
        return false;
    }
    // Nomor operasional / CS utama Jawwada (088223053149 / 6288223053149)
    if (str_ends_with($clean, '88223053149')) {
        return true;
    }
    // Nomor internal / bot sistem lainnya
    if (str_contains($clean, '248232064090227')) {
        return true;
    }
    return false;
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
    if (str_contains($m, 'perkenalkan nama saya') || str_contains($m, 'nama saya')) {
        return [
            'key' => 'form_lead',
            'label' => 'Bismillah Nama LP',
            'color' => '#0284c7',
            'bg' => '#e0f2fe',
            'icon' => 'fa-user-pen',
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

function crmAutomationRelativeTime(string $datetime): string
{
    $diff = strtotime($datetime) - time();
    if ($diff <= 60) {
        return 'Segera / < 1 mnt';
    }
    $minutes = (int)round($diff / 60);
    if ($minutes < 60) {
        return "Dalam {$minutes} mnt";
    }
    $hours = (int)round($minutes / 60);
    if ($hours < 24) {
        return "Dalam {$hours} jam";
    }
    $days = (int)round($hours / 24);
    return "Dalam {$days} hari";
}

function crmAutomationGetStepNumber(string $tplName): string
{
    if (preg_match('/Follow Up (\d+)/i', $tplName, $m)) {
        return "Tahap {$m[1]} / 6";
    }
    return "Pesan Tunggal";
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
            'name' => 'Follow Up 4 (H+1 / 24 Jam - Solusi Ragu & Pengalaman Peserta)',
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
        [
            'name' => 'Menghafal Pemula - Follow Up 1 (Tahsin dari Nol)',
            'desc' => 'Follow-up calon peserta baru yang ingin belajar dari nol',
            'content' => "Bismillah, ahlan kak {nama}! 😊\n\nTerima kasih sudah memilih Kelas Menghafal Pemula Jawwada. Tidak perlu khawatir jika bacaan belum lancar ya kak, karena di kelas privat ini kita akan benahi tahsin & makhrajnya terlebih dahulu secara santai (10 menit/hari via Telepon WA).\n\nKak {nama} rencananya ingin ambil Paket Normal (Rp135rb/bln) atau Paket Intensif (Rp245rb/bln) kak? 🙏",
            'delay' => 30,
        ],
        [
            'name' => 'Lanjutan & Muroja\'ah - Follow Up 1 (Mutqin & Target Juz)',
            'desc' => 'Follow-up peserta yang sudah punya tabungan hafalan',
            'content' => "Bismillah, ahlan kak {nama}! ✨\n\nAlhamdulillah atas ikhtiar menjaga hafalan Al-Qur'annya. Di kelas ini kak {nama} akan disimak langsung oleh Hafidz/Hafidzoh 30 Juz bersanad agar hafalan lama semakin mutqin dan hafalan baru bertambah lancar.\n\nSaat ini kak {nama} sudah ada tabungan juz berapa saja dan target selanjutnya ingin fokus di juz berapa kak? 🙏",
            'delay' => 30,
        ],
        [
            'name' => 'Tahfidz Cilik - Follow Up 1 (Pendekatan Bunda/Ayah)',
            'desc' => 'Follow-up ramah untuk orang tua yang mendaftarkan anak',
            'content' => "Bismillah, ahlan Bunda/Ayah {nama}! 😊\n\nSenang sekali Bunda/Ayah berniat membimbing ananda dekat dengan Al-Qur'an sejak dini. Di Tahfidz Cilik, belajarnya 1-on-1 via Private Video Call WA (Rp155rb/bln) dengan metode sabar & menyenangkan.\n\nBoleh tahu ananda saat ini usia berapa tahun dan apakah sudah mulai iqro atau juz 'amma?",
            'delay' => 30,
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

                    // Hanya buat aturan sequence otomatis untuk template umum Follow Up 1 s/d 6
                    if ($tplId > 0 && str_starts_with($tpl['name'], 'Follow Up ')) {
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
            'tpl' => 'Follow Up 1 (30 Menit - Cek Akses & Pertanyaan)',
            'delay' => 60,
        ],
        [
            'name' => 'Tahfidz Private - Kelas Menghafal Pemula',
            'kw' => 'Kelas Menghafal Pemula',
            'tpl' => 'Menghafal Pemula - Follow Up 1 (Tahsin dari Nol)',
            'delay' => 60,
        ],
        [
            'name' => 'Tahfidz Private - Lanjutan & Penguatan Muroja\'ah',
            'kw' => 'Kelas Menghafal Lanjutan',
            'tpl' => 'Lanjutan & Muroja\'ah - Follow Up 1 (Mutqin & Target Juz)',
            'delay' => 60,
        ],
        [
            'name' => 'Tahfidz Cilik - Bimbingan Anak (Video Call)',
            'kw' => 'Tahfidz Cilik',
            'tpl' => 'Tahfidz Cilik - Follow Up 1 (Pendekatan Bunda/Ayah)',
            'delay' => 60,
        ],
        [
            'name' => 'Tahfidz Private - Form Nama (Bismillah perkenalkan)',
            'kw' => 'Bismillah, perkenalkan nama saya',
            'tpl' => 'Follow Up 1 (30 Menit - Cek Akses & Pertanyaan)',
            'delay' => 30,
        ],
    ];

    foreach ($lpVariations as $var) {
        $targetTplId = 1;
        $tplLookup = $conn->prepare("SELECT id FROM crm_automation_templates WHERE name = ? LIMIT 1");
        if ($tplLookup) {
            $tplLookup->bind_param('s', $var['tpl']);
            $tplLookup->execute();
            $tplRow = $tplLookup->get_result()->fetch_assoc();
            $tplLookup->close();
            if ($tplRow) {
                $targetTplId = (int)$tplRow['id'];
            }
        }

        $checkVar = $conn->prepare("SELECT id FROM crm_automation_rules WHERE trigger_keyword = ? LIMIT 1");
        if ($checkVar) {
            $checkVar->bind_param('s', $var['kw']);
            $checkVar->execute();
            $existsVar = $checkVar->get_result()->fetch_assoc();
            $checkVar->close();

            if (!$existsVar) {
                $insVar = $conn->prepare("INSERT INTO crm_automation_rules (name, trigger_keyword, trigger_condition, template_id, delay_minutes, is_active) VALUES (?, ?, 'contains', ?, ?, 0)");
                if ($insVar) {
                    $insVar->bind_param('ssii', $var['name'], $var['kw'], $targetTplId, $var['delay']);
                    $insVar->execute();
                    $insVar->close();
                }
            } else {
                $updVar = $conn->prepare("UPDATE crm_automation_rules SET template_id = ?, name = ? WHERE id = ?");
                if ($updVar) {
                    $rId = (int)$existsVar['id'];
                    $updVar->bind_param('isi', $targetTplId, $var['name'], $rId);
                    $updVar->execute();
                    $updVar->close();
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

    // Cleanup 1: Hapus aturan rules yang salah mengaitkan template spesifik minat dengan keyword umum
    $conn->query("
        DELETE r FROM crm_automation_rules r
        JOIN crm_automation_templates t ON t.id = r.template_id
        WHERE (t.name LIKE '%Tahfidz Cilik%' 
            OR t.name LIKE '%Menghafal Pemula%' 
            OR t.name LIKE '%Lanjutan%')
          AND r.trigger_keyword LIKE '%tanya detail%'
    ");

    // Cleanup 2: Sinkronkan jeda menit pada aturan rules follow up 1 s/d 6
    $conn->query("
        UPDATE crm_automation_rules r
        JOIN crm_automation_templates t ON t.id = r.template_id
        SET r.delay_minutes = CASE
            WHEN t.name LIKE 'Follow Up 1 (%' THEN 30
            WHEN t.name LIKE 'Follow Up 2 (%' THEN 60
            WHEN t.name LIKE 'Follow Up 3 (%' THEN 300
            WHEN t.name LIKE 'Follow Up 4 (%' THEN 1440
            WHEN t.name LIKE 'Follow Up 5 (%' THEN 4320
            WHEN t.name LIKE 'Follow Up 6 (%' THEN 10080
            ELSE r.delay_minutes
        END
        WHERE t.name LIKE 'Follow Up %'
    ");

    // Cleanup 3: Batalkan antrean pending duplikat untuk template minat spesifik
    $conn->query("
        UPDATE crm_automation_queue q
        LEFT JOIN crm_automation_rules r ON r.id = q.rule_id
        LEFT JOIN crm_automation_templates t ON t.id = q.template_id
        SET q.status = 'cancelled_duplicate_specific',
            q.error_message = 'Dibatalkan: hanya Follow Up 1 General yang dijalankan dalam alur sequence'
        WHERE q.status = 'pending'
          AND (
            r.name LIKE '%Cilik%' OR r.name LIKE '%Pemula%' OR r.name LIKE '%Lanjutan%'
            OR t.name LIKE '%Cilik%' OR t.name LIKE '%Pemula%' OR t.name LIKE '%Lanjutan%'
          )
    ");

    // Cleanup 4: Batalkan antrean pending yang terjadwal di jam dan menit yang sama persis untuk nomor yang sama
    $conn->query("
        UPDATE crm_automation_queue q
        JOIN (
            SELECT MIN(id) AS keep_id, nowa, DATE_FORMAT(scheduled_at, '%Y-%m-%d %H:%i') AS sched_slot
            FROM crm_automation_queue
            WHERE status = 'pending'
            GROUP BY nowa, DATE_FORMAT(scheduled_at, '%Y-%m-%d %H:%i')
            HAVING COUNT(*) > 1
        ) dup ON dup.nowa = q.nowa 
             AND DATE_FORMAT(q.scheduled_at, '%Y-%m-%d %H:%i') = dup.sched_slot 
             AND q.id > dup.keep_id
        SET q.status = 'cancelled_same_time_dup',
            q.error_message = 'Dibatalkan otomatis: jadwal bentrok/bersamaan dengan antrean lain'
        WHERE q.status = 'pending'
    ");

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

function crmAutomationCancelAllPendingForContact(mysqli $conn, string $nowa, string $reason = 'cancelled_payment'): int
{
    $normalized = crmProspectNormalizeNumber($nowa);
    $variants = array_values(array_unique(array_filter([
        $normalized,
        $nowa,
        str_starts_with($normalized, '62') ? '0' . substr($normalized, 2) : '',
        '+' . $normalized,
    ])));

    if (empty($variants)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $stmt = $conn->prepare("UPDATE crm_automation_queue SET status = ?, error_message = ? WHERE nowa IN ({$placeholders}) AND status = 'pending'");
    if (!$stmt) {
        return 0;
    }

    $types = 'ss' . str_repeat('s', count($variants));
    $params = array_merge([$reason, $reason], $variants);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $affected = (int)$stmt->affected_rows;
    $stmt->close();
    return $affected;
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

    // 1. Cek di tabel peserta (apakah peserta sudah berstatus aktif/terdaftar)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'peserta'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $stmtPeserta = $conn->prepare("SELECT 1 FROM peserta WHERE nowa IN ({$placeholders}) LIMIT 1");
        if ($stmtPeserta) {
            $stmtPeserta->bind_param(str_repeat('s', count($variants)), ...$variants);
            $stmtPeserta->execute();
            if ($stmtPeserta->get_result()->num_rows > 0) {
                $stmtPeserta->close();
                return true;
            }
            $stmtPeserta->close();
        }
    }

    // 2. Cek di log_wa apakah sudah pernah menerima chat form finance
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
        '+' . $normalized,
    ])));

    if (empty($variants)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($variants), '?'));

    // 1. Cek di crm_messages jika tabel ada (arah 'in' adalah balasan customer)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'crm_messages'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $stmtMsg = $conn->prepare("SELECT id FROM crm_messages WHERE nowa IN ({$placeholders}) AND direction = 'in' AND created_at > ? LIMIT 1");
        if ($stmtMsg) {
            $types = str_repeat('s', count($variants)) . 's';
            $params = array_merge($variants, [$registeredAt]);
            $stmtMsg->bind_param($types, ...$params);
            $stmtMsg->execute();
            if ($stmtMsg->get_result()->num_rows > 0) {
                $stmtMsg->close();
                return true;
            }
            $stmtMsg->close();
        }
    }

    // 2. Cek di log_wa (bukan pesan siaran grup dan bukan pesan keluar yang dikirim oleh sistem/CRM)
    $sql = "SELECT id FROM log_wa 
            WHERE (nowa IN ({$placeholders})) 
              AND created_at > ? 
              AND message NOT LIKE '[GRUP]%' 
              AND message NOT LIKE '[PROMOSI]%'
              AND message NOT LIKE 'Ahlan kak%' 
              AND message NOT LIKE 'Bismillah, ahlan kak%'
              AND message NOT LIKE 'Sekadar info kak%'
              AND message NOT LIKE 'Assalamu%alaikum kak%'
              AND message != 'Data CSV/Manual'
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

    if (crmAutomationIsOperationalNumber($nowa)) {
        return 0;
    }

    $normalized = crmProspectNormalizeNumber($nowa);
    if ($normalized === '' || crmAutomationIsOperationalNumber($normalized) || crmAutomationIsBlocked($conn, $normalized)) {
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

    // Deduplikasi: Jika masih ada antrean berstatus pending untuk nomor dan template/rule ini, perbarui jadwalnya
    $checkStmt = $conn->prepare("SELECT id FROM crm_automation_queue WHERE nowa = ? AND (template_id = ? OR (rule_id = ? AND rule_id > 0)) AND status = 'pending' LIMIT 1");
    if ($checkStmt) {
        $checkStmt->bind_param('sii', $normalized, $templateId, $ruleId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $existingId = (int)$existing['id'];
            $updateStmt = $conn->prepare("UPDATE crm_automation_queue SET scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), nama = COALESCE(?, nama), template_id = ?, rule_id = ? WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param('isiii', $delayMinutes, $nama, $templateId, $ruleId, $existingId);
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

/**
 * Menjadwalkan seluruh urutan follow-up untuk suatu nomor prospek.
 *
 * @return array{success: bool, count: int, reason: string, message: string}
 */
function crmAutomationScheduleSequence(
    mysqli $conn,
    string $nowa,
    ?string $nama,
    int $startTemplateId = 0,
    string $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada'
): array {
    crmAutomationEnsureTables($conn);

    if (crmAutomationIsOperationalNumber($nowa)) {
        return [
            'success' => false,
            'count' => 0,
            'reason' => 'operational_number',
            'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dijadwalkan follow-up.",
        ];
    }

    $normalized = crmProspectNormalizeNumber($nowa);
    if ($normalized === '') {
        return [
            'success' => false,
            'count' => 0,
            'reason' => 'invalid_number',
            'message' => 'Nomor WhatsApp tidak valid atau kosong.',
        ];
    }

    if (crmAutomationIsOperationalNumber($normalized)) {
        return [
            'success' => false,
            'count' => 0,
            'reason' => 'operational_number',
            'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dijadwalkan follow-up.",
        ];
    }

    if (crmAutomationIsBlocked($conn, $normalized)) {
        return [
            'success' => false,
            'count' => 0,
            'reason' => 'blocked',
            'message' => "Nomor {$nowa} terdaftar dalam daftar blokir peserta (blocked_peserta).",
        ];
    }

    if (crmAutomationHasReceivedPayment($conn, $normalized)) {
        return [
            'success' => false,
            'count' => 0,
            'reason' => 'already_paid',
            'message' => "Kontak {$nowa} sudah tercatat sebagai peserta aktif atau sudah menerima pesan Finance (pembayaran).",
        ];
    }

    // 1. Batalkan semua antrean pending lama untuk nomor ini agar tidak terjadi jadwal ganda/tumpang tindih
    crmAutomationCancelAllPendingForContact($conn, $normalized, 'cancelled_rescheduled');

    // 2. Definisi 6 tahap follow up standar general dengan jeda waktu pasti dan berurutan
    $sequenceSteps = [
        1 => ['pattern' => 'Follow Up 1 (%', 'delay_from_now' => 10],     // 10 menit dari sekarang
        2 => ['pattern' => 'Follow Up 2 (%', 'delay_from_now' => 60],     // 1 jam (60 mnt) dari sekarang
        3 => ['pattern' => 'Follow Up 3 (%', 'delay_from_now' => 300],    // 5 jam (300 mnt) dari sekarang
        4 => ['pattern' => 'Follow Up 4 (%', 'delay_from_now' => 1440],   // 24 jam / H+1 dari sekarang
        5 => ['pattern' => 'Follow Up 5 (%', 'delay_from_now' => 4320],   // 3 hari / H+3 dari sekarang
        6 => ['pattern' => 'Follow Up 6 (%', 'delay_from_now' => 10080],  // 7 hari / H+7 dari sekarang
    ];

    $resolvedSteps = [];
    foreach ($sequenceSteps as $num => $stepInfo) {
        $stmtTpl = $conn->prepare("
            SELECT t.id AS template_id, COALESCE(r.id, 1) AS rule_id
            FROM crm_automation_templates t
            LEFT JOIN crm_automation_rules r ON r.template_id = t.id
            WHERE t.name LIKE ?
              AND t.name NOT LIKE '%Menghafal Pemula%'
              AND t.name NOT LIKE '%Lanjutan%'
              AND t.name NOT LIKE '%Tahfidz Cilik%'
            ORDER BY t.id ASC
            LIMIT 1
        ");
        if ($stmtTpl) {
            $stmtTpl->bind_param('s', $stepInfo['pattern']);
            $stmtTpl->execute();
            $rowTpl = $stmtTpl->get_result()->fetch_assoc();
            $stmtTpl->close();
            if ($rowTpl) {
                $resolvedSteps[$num] = [
                    'step' => $num,
                    'template_id' => (int)$rowTpl['template_id'],
                    'rule_id' => (int)$rowTpl['rule_id'],
                    'base_delay' => (int)$stepInfo['delay_from_now'],
                ];
            }
        }
    }

    if (empty($resolvedSteps)) {
        return [
            'success' => false,
            'count' => 0,
            'reason' => 'no_rules',
            'message' => 'Template sequence Follow Up 1 s/d 6 belum tersedia di database.',
        ];
    }

    // Tentukan tahap mulai jika startTemplateId dipilih
    $startStepNum = 1;
    if ($startTemplateId > 0) {
        foreach ($resolvedSteps as $num => $stepData) {
            if ($stepData['template_id'] === $startTemplateId) {
                $startStepNum = $num;
                break;
            }
        }
    }

    $baseOffset = $resolvedSteps[$startStepNum]['base_delay'] ?? 10;

    $count = 0;
    foreach ($resolvedSteps as $num => $stepData) {
        if ($num < $startStepNum) {
            continue; // Lewati tahap sebelum tahap mulai yang dipilih
        }

        // Hitung jeda relatif: tahap pertama selalu mulai dalam 10 menit, tahap selanjutnya berjarak sesuai interval
        if ($num === $startStepNum) {
            $relDelay = 10;
        } else {
            $relDelay = ($stepData['base_delay'] - $baseOffset) + 10;
        }

        $res = crmAutomationRegisterQueue(
            $conn,
            $normalized,
            $nama,
            $stepData['rule_id'],
            $relDelay,
            true,
            $stepData['template_id']
        );
        if ($res > 0) {
            $count++;
        }
    }

    if ($count > 0) {
        return [
            'success' => true,
            'count' => $count,
            'reason' => 'ok',
            'message' => "Alur follow-up berhasil dijadwalkan secara berurutan untuk kontak {$nowa} ({$count} tahap pesan). Pesan pertama akan mulai dikirim dalam ±10 menit.",
        ];
    }

    return [
        'success' => false,
        'count' => 0,
        'reason' => 'queue_failed',
        'message' => "Gagal memasukkan jadwal ke antrean database untuk kontak {$nowa}.",
    ];
}

function crmAutomationRegisterSequence(
    mysqli $conn,
    string $nowa,
    ?string $nama,
    string $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada'
): int {
    $res = crmAutomationScheduleSequence($conn, $nowa, $nama, 0, $keyword);
    return (int)($res['count'] ?? 0);
}
