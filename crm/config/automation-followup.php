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
    if (str_contains($m, 'saya mau daftar') || str_contains($m, 'saya ingin daftar') || str_contains($m, 'daftar sekarang')) {
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

/**
 * Pengaturan Jam Istirahat Malam (Sleep / Quiet Hours)
 * Pesan yang jatuh pada jam ini otomatis ditahan hingga pagi hari.
 */
function crmAutomationGetSleepSettings(mysqli $conn): array
{
    $settings = [
        'enabled' => true,
        'start' => '21:30',
        'end' => '07:30',
    ];

    $res = $conn->query("SELECT meta_key, meta_value FROM crm_automation_meta WHERE meta_key IN ('sleep_hours_enabled', 'sleep_start', 'sleep_end')");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['meta_key'] === 'sleep_hours_enabled') {
                $settings['enabled'] = ($row['meta_value'] === '1');
            } elseif ($row['meta_key'] === 'sleep_start') {
                $settings['start'] = (string)$row['meta_value'];
            } elseif ($row['meta_key'] === 'sleep_end') {
                $settings['end'] = (string)$row['meta_value'];
            }
        }
    }
    return $settings;
}

function crmAutomationIsSleepTime(mysqli $conn, ?int $timestamp = null): bool
{
    date_default_timezone_set('Asia/Jakarta');
    $ts = $timestamp ?? time();
    $settings = crmAutomationGetSleepSettings($conn);
    if (!$settings['enabled']) {
        return false;
    }

    $timeStr = date('H:i', $ts);
    $start = $settings['start'];
    $end = $settings['end'];

    if ($start > $end) {
        return ($timeStr >= $start || $timeStr < $end);
    }
    return ($timeStr >= $start && $timeStr < $end);
}

function crmAutomationAdjustForSleep(mysqli $conn, int $timestamp): int
{
    date_default_timezone_set('Asia/Jakarta');
    if (!crmAutomationIsSleepTime($conn, $timestamp)) {
        return $timestamp;
    }

    $settings = crmAutomationGetSleepSettings($conn);
    $parts = explode(':', (string)$settings['end']);
    $endHour = isset($parts[0]) ? (int)$parts[0] : 7;
    $endMin = isset($parts[1]) ? (int)$parts[1] : 30;

    $dt = new DateTime(date('Y-m-d H:i:s', $timestamp), new DateTimeZone('Asia/Jakarta'));
    $timeStr = $dt->format('H:i');

    if ($timeStr >= $settings['start']) {
        $dt->modify('+1 day');
    }

    $dt->setTime($endHour, $endMin, 0);
    return $dt->getTimestamp();
}

/**
 * Ekstrak nama calon peserta HANYA jika secara eksplisit dituliskan dalam pesan chat masuk.
 * Jika calon peserta tidak menyebutkan namanya, kembalikan null agar tidak menggunakan pushName WA yang sering tidak sesuai.
 */
function crmAutomationExtractExplicitName(string $message): ?string
{
    $patterns = [
        '/(?:perkenalkan\s+nama\s+saya|nama\s+saya|nama\s+sy|nama\s*:|panggil\s+saya)\s+([A-Za-z\p{L}\'\-]+(?:\s+[A-Za-z\p{L}\'\-]+)?)/iu',
        '/^(?:saya|sy)\s+([A-Za-z\p{L}\'\-]+(?:\s+[A-Za-z\p{L}\'\-]+)?)[,\.]?\s+(?:mau|ingin|daftar)/iu',
    ];

    $forbidden = [
        'kak', 'kakak', 'saya', 'sy', 'admin', 'bismillah', 'assalamualaikum', 'assalamu\'alaikum',
        'halo', 'tanya', 'mau', 'ingin', 'daftar', 'peserta', 'tahfidz', 'private', 'online', 'jawwada',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) {
            $extracted = trim((string)$m[1]);
            $lower = strtolower($extracted);
            if (!in_array($lower, $forbidden, true) && mb_strlen($extracted) >= 2) {
                return mb_convert_case($extracted, MB_CASE_TITLE, 'UTF-8');
            }
        }
    }

    return null;
}

if (!function_exists('crmAutomationRenderMessage')) {
    /**
     * Render template pesan follow-up Jawwada.
     * Mengabaikan penyebutan nama jika nama tidak secara eksplisit disebutkan di pesan.
     */
    function crmAutomationRenderMessage(string $template, array $data): string
    {
        $rawNama = trim((string)($data['nama'] ?? ''));
        $hasExplicitName = false;
        $nama = '';

        $isGeneric = (
            $rawNama === '' ||
            in_array(strtolower($rawNama), ['kak', 'unknown', 'tanpa nama', 'null', 'calon peserta', 'peserta'], true)
        );

        if (!$isGeneric) {
            $hasExplicitName = true;
            $nama = $rawNama;
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
        $rendered = $template;

        if ($hasExplicitName) {
            // Jika nama peserta memang diketahui secara eksplisit:
            $search = ['{nama}', '{NAMA}', '[nama]', '[NAMA]'];
            $rendered = str_ireplace($search, $nama, $rendered);
        } else {
            // Jika nama TIDAK disebut oleh prospek: hilangkan penyebutan nama secara rapi & alami
            $replacements = [
                '/(?:Bismillah,\s+)?ahlan\s+Bunda\/Ayah\s+\{nama\}!/i' => 'Bismillah, ahlan Bunda/Ayah! 😊',
                '/Bunda\/Ayah\s+\{nama\}/i' => 'Bunda/Ayah',
                '/terbuka\s+untuk\s+kak\s+\{nama\}/i' => 'terbuka untuk kakak',
                '/Untuk\s+kak\s+\{nama\}/i' => 'Untuk kakak',
                '/untuk\s+kak\s+\{nama\}/i' => 'untuk kakak',
                '/(?:kak|Kak)\s+\{nama\}\s+rencananya/i' => 'Kakak rencananya',
                '/(?:jika|Jika)\s+kak\s+\{nama\}\s+berminat/i' => 'Jika kakak berminat',
                '/(?:apakah|Apakah)\s+kak\s+\{nama\}\s+berkenan/i' => 'Apakah kakak berkenan',
                '/kak\s+\{nama\}\s+akan\s+disimak/i' => 'kakak akan disimak',
                '/kak\s+\{nama\}\s+sudah\s+ada/i' => 'kakak sudah ada',
                '/kak\s+\{nama\}\s+paling\s+ingin/i' => 'kakak paling ingin',
                '/kak\s+\{nama\}\s+memiliki/i' => 'kakak memiliki',
                '/kak\s+\{nama\}/i' => 'kak',
                '/Kak\s+\{nama\}/i' => 'Kak',
                '/\{nama\}/i' => '',
                '/\[nama\]/i' => '',
            ];

            foreach ($replacements as $pattern => $replacement) {
                $rendered = preg_replace($pattern, $replacement, $rendered);
            }

            // Rapikan spasi berlebih dan spasi sebelum tanda baca
            $rendered = preg_replace('/[ \t]{2,}/', ' ', $rendered);
            $rendered = preg_replace('/\s+([,\.!\?])/', '$1', $rendered);
        }

        $rendered = str_replace(['{sapaan}', '{program}'], [$sapaan, $program], $rendered);

        return trim($rendered);
    }
}

function crmAutomationSeedDefaultTemplates(mysqli $conn): void
{
    $defaultTemplates = [
        [
            'name' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'desc' => 'Sapaan ramah 2 menit pertama, hangat dan santai menanyakan kebutuhan belajar',
            'content' => "Bismillah, ahlan kak {nama}! 😊\n\nTerima kasih ya sudah mampir ke Tahfidz Private Jawwada.\n\nBiar kami bantu infokan detail kelas dan waktu yang paling pas, rencananya program ini untuk kakak sendiri atau ananda nih kak? 🙏",
            'delay' => 2,
        ],
        [
            'name' => 'Follow Up 2 (45 Menit - Fleksibilitas & Biaya Terjangkau)',
            'desc' => 'Edukasi privat 1-on-1 via telepon WA 10 menit & biaya mulai Rp135rb/bln',
            'content' => "Sekadar info kak {nama}, di Jawwada belajarnya private 1-on-1 via Telepon WA (10 menit/hari) jadi tidak menyita waktu. Biayanya sangat terjangkau mulai Rp135rb/bulan.\n\nKakak rencananya ingin belajar di waktu luang pagi, sore, atau malam kak? ✨",
            'delay' => 45,
        ],
        [
            'name' => 'Follow Up 3 (4 Jam - Reservasi Slot Terbatas)',
            'desc' => 'Soft urgency kuota guru hafidz 30 juz pekan ini terbatas',
            'content' => "Assalamu'alaikum kak {nama}. 🙏\n\nIzin mengabarkan, kuota pembimbing Hafidz/Hafidzoh 30 Juz untuk pekan ini tersisa sedikit karena jadwal privat yang terbatas.\n\nJika kakak berminat, mau kami bantu simpankan slot belajarnya terlebih dahulu? 😊",
            'delay' => 240,
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

    // Seed pola pemicu pesan masuk untuk alur automasi pendaftaran landing page (is_active = 1)
    $lpVariations = [
        [
            'name' => 'Tahfidz Cilik (Tombol LP)',
            'kw' => 'Kak, saya ingin daftar Tahfidz Cilik',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'cilik',
        ],
        [
            'name' => 'Tahfidz Cilik (Keyword)',
            'kw' => 'Tahfidz Cilik',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'cilik',
        ],
        [
            'name' => 'Menghafal Pemula (Tombol LP)',
            'kw' => 'Kak, saya ingin daftar Kelas Menghafal Pemula',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'pemula',
        ],
        [
            'name' => 'Menghafal Pemula (Keyword)',
            'kw' => 'Menghafal Pemula',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'pemula',
        ],
        [
            'name' => 'Menghafal Lanjutan (Tombol LP)',
            'kw' => 'Kak, saya ingin daftar Kelas Menghafal Lanjutan',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'lanjutan',
        ],
        [
            'name' => 'Menghafal Lanjutan (Keyword)',
            'kw' => 'Menghafal Lanjutan',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'lanjutan',
        ],
        [
            'name' => 'Penguatan Hafalan (Tombol LP)',
            'kw' => 'Kak, saya ingin daftar Kelas Penguatan Hafalan',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'lanjutan',
        ],
        [
            'name' => 'Penguatan Hafalan (Keyword)',
            'kw' => 'Penguatan Hafalan',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'lanjutan',
        ],
        [
            'name' => 'Tanya Detail Hero (LP Utama)',
            'kw' => 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'general',
        ],
        [
            'name' => 'Form Nama LP (Bismillah)',
            'kw' => 'Bismillah, perkenalkan nama saya',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'form_lead',
        ],
        [
            'name' => 'Pendaftaran Kelas Umum (CTA)',
            'kw' => 'Kak, saya ingin daftar Kelas',
            'tpl' => 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            'delay' => 2,
            'cat' => 'cta',
        ],
    ];

    // Cek apakah pola variasi LP sudah pernah di-seed agar rule yang dihapus admin tidak muncul kembali
    $metaCheck = $conn->query("SELECT meta_value FROM crm_automation_meta WHERE meta_key = 'seed_rules_v1' LIMIT 1");
    $alreadySeeded = ($metaCheck && $metaCheck->num_rows > 0);

    if (!$alreadySeeded) {
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
                    $insVar = $conn->prepare("INSERT INTO crm_automation_rules (name, trigger_keyword, trigger_condition, category, template_id, delay_minutes, is_active) VALUES (?, ?, 'contains', ?, ?, ?, 1)");
                    if ($insVar) {
                        $insVar->bind_param('sssii', $var['name'], $var['kw'], $var['cat'], $targetTplId, $var['delay']);
                        $insVar->execute();
                        $insVar->close();
                    }
                } else {
                    $updVar = $conn->prepare("UPDATE crm_automation_rules SET category = ?, name = ? WHERE id = ?");
                    if ($updVar) {
                        $rId = (int)$existsVar['id'];
                        $updVar->bind_param('ssi', $var['cat'], $var['name'], $rId);
                        $updVar->execute();
                        $updVar->close();
                    }
                }
            }
        }
        $conn->query("INSERT IGNORE INTO crm_automation_meta (meta_key, meta_value) VALUES ('seed_rules_v1', '1')");
    }
}

function crmAutomationEnsureTables(mysqli $conn): bool
{
    static $ensured = false;
    if ($ensured) {
        return true;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS crm_automation_meta (
        meta_key VARCHAR(64) PRIMARY KEY,
        meta_value VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration: Hapus baris aturan legacy (Tahfidz Private - Follow Up 1..6) yang tidak lagi relevan
    $hasRulesTable = $conn->query("SHOW TABLES LIKE 'crm_automation_rules'");
    if ($hasRulesTable && $hasRulesTable->num_rows > 0) {
        $cleanedCheck = $conn->query("SELECT meta_value FROM crm_automation_meta WHERE meta_key = 'legacy_fu_rules_cleaned_v1' LIMIT 1");
        if (!$cleanedCheck || $cleanedCheck->num_rows === 0) {
            $conn->query("DELETE FROM crm_automation_rules WHERE name LIKE 'Tahfidz Private - Follow Up %'");
            $conn->query("INSERT IGNORE INTO crm_automation_meta (meta_key, meta_value) VALUES ('legacy_fu_rules_cleaned_v1', '1')");
        }
    }

    // Pastikan seluruh template alur follow up 1..6 selalu ada di database
    $hasTemplatesTable = $conn->query("SHOW TABLES LIKE 'crm_automation_templates'");
    if ($hasTemplatesTable && $hasTemplatesTable->num_rows > 0) {
        $tplCountRes = $conn->query("SELECT COUNT(*) AS total FROM crm_automation_templates WHERE name LIKE '%Follow Up %'");
        $tplCount = $tplCountRes ? (int)($tplCountRes->fetch_assoc()['total'] ?? 0) : 0;
        if ($tplCount < 6) {
            crmAutomationSeedDefaultTemplates($conn);
        }
    }

    // Pastikan tabel override finance manual selalu tersedia
    $conn->query("CREATE TABLE IF NOT EXISTS crm_automation_finance (
        nowa VARCHAR(50) PRIMARY KEY,
        status VARCHAR(20) NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_auto_fin_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration v2: Instant Welcome & Jam Tidur Malam
    $metaV2 = $conn->query("SELECT meta_value FROM crm_automation_meta WHERE meta_key = 'schema_v2_instant_welcome' LIMIT 1");
    if (!$metaV2 || $metaV2->num_rows === 0) {
        $conn->query("UPDATE crm_automation_templates 
                      SET name = 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
                          description = 'Sapaan ramah 2 menit pertama, hangat dan santai menanyakan kebutuhan belajar',
                          content = 'Bismillah, ahlan kak {nama}! 😊\\n\\nTerima kasih ya sudah mampir ke Tahfidz Private Jawwada.\\n\\nBiar kami bantu infokan detail kelas dan waktu yang paling pas, rencananya program ini untuk kakak sendiri atau ananda nih kak? 🙏'
                      WHERE name LIKE 'Follow Up 1%'");

        $conn->query("UPDATE crm_automation_rules SET delay_minutes = 2 WHERE delay_minutes IN (10, 30) AND (name LIKE '%LP%' OR name LIKE '%Hero%' OR name LIKE '%CTA%' OR name LIKE '%Follow Up 1%')");
        $conn->query("INSERT INTO crm_automation_meta (meta_key, meta_value) VALUES ('schema_v2_instant_welcome', '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    }

    // Fast path: jika skema dan seed sudah lengkap, hindari DDL dan query berulang pada webhook
    $metaEnsured = $conn->query("SELECT meta_value FROM crm_automation_meta WHERE meta_key = 'schema_v2_instant_welcome' LIMIT 1");
    if ($metaEnsured && $metaEnsured->num_rows > 0) {
        $ensured = true;
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
        category VARCHAR(50) NOT NULL DEFAULT 'general',
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
        trigger_source VARCHAR(30) NOT NULL DEFAULT 'manual',
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        scheduled_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_auto_queue_status_sched (status, scheduled_at),
        INDEX idx_auto_queue_nowa (nowa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pastikan kolom category ada di crm_automation_rules jika tabel sudah ada sebelumnya
    $colRuleCheck = $conn->query("SHOW COLUMNS FROM crm_automation_rules LIKE 'category'");
    if ($colRuleCheck && $colRuleCheck->num_rows === 0) {
        $conn->query("ALTER TABLE crm_automation_rules ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'general' AFTER trigger_condition");
    }

    // Pastikan kolom trigger_source ada di crm_automation_queue jika tabel sudah ada sebelumnya
    $colQueueCheck = $conn->query("SHOW COLUMNS FROM crm_automation_queue LIKE 'trigger_source'");
    if ($colQueueCheck && $colQueueCheck->num_rows === 0) {
        $conn->query("ALTER TABLE crm_automation_queue ADD COLUMN trigger_source VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER nama");
    }

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

    // Cleanup 2: Sinkronkan jeda menit pada aturan rules follow up 1 s/d 6 (Instant Welcome: 2 menit)
    $conn->query("
        UPDATE crm_automation_rules r
        JOIN crm_automation_templates t ON t.id = r.template_id
        SET r.delay_minutes = CASE
            WHEN t.name LIKE 'Follow Up 1 (%' THEN 2
            WHEN t.name LIKE 'Follow Up 2 (%' THEN 45
            WHEN t.name LIKE 'Follow Up 3 (%' THEN 240
            WHEN t.name LIKE 'Follow Up 4 (%' THEN 1440
            WHEN t.name LIKE 'Follow Up 5 (%' THEN 4320
            WHEN t.name LIKE 'Follow Up 6 (%' THEN 10080
            ELSE r.delay_minutes
        END
        WHERE t.name LIKE 'Follow Up %'
    ");

    $conn->query("UPDATE crm_automation_rules SET delay_minutes = 2 WHERE delay_minutes IN (10, 30) AND (name LIKE '%LP%' OR name LIKE '%Hero%' OR name LIKE '%CTA%' OR name LIKE '%Follow Up 1%')");

    // Migration template Follow Up 1 lama ke copy Instant Welcome baru yang interaktif
    $conn->query("
        UPDATE crm_automation_templates 
        SET name = 'Follow Up 1 (Instant Welcome - Sapaan Akrab & Tanya Kebutuhan)',
            description = 'Sapaan ramah 2 menit pertama, hangat dan santai menanyakan kebutuhan belajar',
            content = 'Bismillah, ahlan kak {nama}! 😊\\n\\nTerima kasih ya sudah mampir ke Tahfidz Private Jawwada.\\n\\nBiar kami bantu infokan detail kelas dan waktu yang paling pas, rencananya program ini untuk kakak sendiri atau ananda nih kak? 🙏'
        WHERE name LIKE 'Follow Up 1%'
    ");

    // Cleanup 3: Batalkan antrean pending duplikat untuk template minat spesifik lama
    $conn->query("
        UPDATE crm_automation_queue q
        LEFT JOIN crm_automation_templates t ON t.id = q.template_id
        SET q.status = 'cancelled_duplicate_specific',
            q.error_message = 'Dibatalkan: hanya Follow Up 1 General yang dijalankan dalam alur sequence'
        WHERE q.status = 'pending'
          AND (
            t.name LIKE '%- Follow Up 1 (%'
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

    $conn->query("INSERT IGNORE INTO crm_automation_meta (meta_key, meta_value) VALUES ('schema_v1_ensured', '1')");

    $ensured = true;
    return true;
}

/**
 * Mengambil seluruh pola aturan pemicu aktif dari basis data.
 *
 * @return array<int, array{id: int, name: string, trigger_keyword: string, category: string, is_active: int}>
 */
function crmAutomationGetActiveTriggers(mysqli $conn, bool $forceRefresh = false): array
{
    static $cachedTriggers = null;
    if ($cachedTriggers !== null && !$forceRefresh) {
        return $cachedTriggers;
    }

    crmAutomationEnsureTables($conn);
    $triggers = [];
    $res = $conn->query("
        SELECT id, name, trigger_keyword, COALESCE(category, 'general') AS category, is_active
        FROM crm_automation_rules
        WHERE is_active = 1
        ORDER BY id ASC
    ");
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $triggers[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'trigger_keyword' => (string)$row['trigger_keyword'],
                'category' => (string)$row['category'],
                'is_active' => (int)$row['is_active'],
            ];
        }
    }
    $cachedTriggers = $triggers;
    return $cachedTriggers;
}

/**
 * Mencocokkan isi pesan chat masuk dengan daftar aturan pemicu aktif.
 *
 * @return array{id: int, name: string, trigger_keyword: string, category: string}|null
 */
function crmAutomationFindMatchingTrigger(mysqli $conn, string $message): ?array
{
    $cleanMsg = trim($message);
    if ($cleanMsg === '') {
        return null;
    }
    $lowerMsg = mb_strtolower($cleanMsg, 'UTF-8');
    $activeTriggers = crmAutomationGetActiveTriggers($conn);

    // Prioritaskan kata kunci pemicu yang lebih spesifik/panjang terlebih dahulu
    usort($activeTriggers, function ($a, $b) {
        return mb_strlen($b['trigger_keyword']) <=> mb_strlen($a['trigger_keyword']);
    });

    foreach ($activeTriggers as $trig) {
        $kw = mb_strtolower(trim($trig['trigger_keyword']), 'UTF-8');
        if ($kw !== '' && str_contains($lowerMsg, $kw)) {
            return $trig;
        }
    }

    return null;
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

/**
 * Mengubah status finance kontak secara manual (paid = Sudah Isi Finance, unpaid = Belum Diisi Finance).
 * Jika status diubah menjadi 'paid', antrean follow-up pending untuk kontak ini otomatis dibatalkan.
 */
function crmAutomationSetFinanceStatus(mysqli $conn, string $nowa, string $status = 'paid'): bool
{
    crmAutomationEnsureTables($conn);
    $normalized = crmProspectNormalizeNumber($nowa);
    if ($normalized === '') {
        return false;
    }

    $status = strtolower(trim($status)) === 'unpaid' ? 'unpaid' : 'paid';

    $stmt = $conn->prepare("
        INSERT INTO crm_automation_finance (nowa, status, updated_at) 
        VALUES (?, ?, NOW()) 
        ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $normalized, $status);
    $stmt->execute();
    $stmt->close();

    // Jika ditandai 'paid' (Sudah), otomatis batalkan antrean pending kontak ini
    if ($status === 'paid') {
        crmAutomationCancelAllPendingForContact($conn, $normalized, 'cancelled_payment');
    }

    return true;
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

    // 0. Cek tabel override finance manual
    $tableFin = $conn->query("SHOW TABLES LIKE 'crm_automation_finance'");
    if ($tableFin && $tableFin->num_rows > 0) {
        $stmtFin = $conn->prepare("SELECT status FROM crm_automation_finance WHERE nowa IN ({$placeholders}) LIMIT 1");
        if ($stmtFin) {
            $stmtFin->bind_param(str_repeat('s', count($variants)), ...$variants);
            $stmtFin->execute();
            $resFin = $stmtFin->get_result()->fetch_assoc();
            $stmtFin->close();
            if ($resFin) {
                return ($resFin['status'] === 'paid');
            }
        }
    }

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
    int $templateIdOverride = 0,
    string $triggerSource = 'manual'
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

    // Hitung waktu jadwal dengan memperhitungkan jam tidur malam (sleep hours)
    date_default_timezone_set('Asia/Jakarta');
    $scheduledTs = time() + ($delayMinutes * 60);
    $scheduledTs = crmAutomationAdjustForSleep($conn, $scheduledTs);
    $scheduledAtStr = date('Y-m-d H:i:s', $scheduledTs);

    // Deduplikasi: Jika masih ada antrean berstatus pending untuk nomor dan template ini, perbarui jadwalnya
    $checkStmt = $conn->prepare("SELECT id FROM crm_automation_queue WHERE nowa = ? AND template_id = ? AND status = 'pending' LIMIT 1");
    if ($checkStmt) {
        $checkStmt->bind_param('si', $normalized, $templateId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $existingId = (int)$existing['id'];
            $updateStmt = $conn->prepare("UPDATE crm_automation_queue SET scheduled_at = ?, nama = COALESCE(?, nama), template_id = ?, rule_id = ?, trigger_source = ? WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param('ssissi', $scheduledAtStr, $nama, $templateId, $ruleId, $triggerSource, $existingId);
                $updateStmt->execute();
                $updateStmt->close();
            }
            return $existingId;
        }
    }

    // Buat antrean baru
    $insertStmt = $conn->prepare("INSERT INTO crm_automation_queue (rule_id, template_id, nowa, nama, trigger_source, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())");
    if (!$insertStmt) {
        return 0;
    }

    $insertStmt->bind_param('iissss', $ruleId, $templateId, $normalized, $nama, $triggerSource, $scheduledAtStr);
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
    string $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada',
    string $triggerSource = 'manual'
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
        1 => ['delay_from_now' => 2],      // 2 menit dari sekarang (Instant Welcome)
        2 => ['delay_from_now' => 45],     // 45 menit dari sekarang
        3 => ['delay_from_now' => 240],    // 4 jam (240 mnt) dari sekarang
        4 => ['delay_from_now' => 1440],   // 24 jam / H+1 dari sekarang
        5 => ['delay_from_now' => 4320],   // 3 hari / H+3 dari sekarang
        6 => ['delay_from_now' => 10080],  // 7 hari / H+7 dari sekarang
    ];

    $resolvedSteps = [];
    foreach ($sequenceSteps as $num => $stepInfo) {
        $patExact = "Follow Up {$num} (%";
        $patPrefixed = "%Follow Up {$num} (%";
        $patAny = "%Follow Up {$num}%";

        $stmtTpl = $conn->prepare("
            SELECT t.id AS template_id, COALESCE(r.id, 0) AS rule_id, t.name AS template_name
            FROM crm_automation_templates t
            LEFT JOIN crm_automation_rules r ON r.template_id = t.id AND r.is_active = 1
            WHERE (t.name LIKE ? OR t.name LIKE ? OR t.name LIKE ?)
              AND t.name NOT LIKE '%Menghafal Pemula%'
              AND t.name NOT LIKE '%Lanjutan%'
              AND t.name NOT LIKE '%Tahfidz Cilik%'
            ORDER BY 
              CASE WHEN t.name LIKE 'Follow Up {$num} (%' THEN 1 ELSE 0 END DESC,
              CASE WHEN t.name LIKE '%Tahfidz Private - Follow Up {$num}%' THEN 1 ELSE 0 END DESC,
              t.id ASC
            LIMIT 1
        ");
        if ($stmtTpl) {
            $stmtTpl->bind_param('sss', $patExact, $patPrefixed, $patAny);
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

    // Jika ada tahap yang belum teresolusi (misal template belum lengkap di DB), seed template dan coba lagi
    if (count($resolvedSteps) < 6) {
        crmAutomationSeedDefaultTemplates($conn);
        foreach ($sequenceSteps as $num => $stepInfo) {
            if (isset($resolvedSteps[$num])) {
                continue;
            }
            $patExact = "Follow Up {$num} (%";
            $patPrefixed = "%Follow Up {$num} (%";
            $patAny = "%Follow Up {$num}%";

            $stmtRetry = $conn->prepare("
                SELECT t.id AS template_id, COALESCE(r.id, 0) AS rule_id, t.name AS template_name
                FROM crm_automation_templates t
                LEFT JOIN crm_automation_rules r ON r.template_id = t.id AND r.is_active = 1
                WHERE (t.name LIKE ? OR t.name LIKE ? OR t.name LIKE ?)
                  AND t.name NOT LIKE '%Menghafal Pemula%'
                  AND t.name NOT LIKE '%Lanjutan%'
                  AND t.name NOT LIKE '%Tahfidz Cilik%'
                ORDER BY 
                  CASE WHEN t.name LIKE 'Follow Up {$num} (%' THEN 1 ELSE 0 END DESC,
                  t.id ASC
                LIMIT 1
            ");
            if ($stmtRetry) {
                $stmtRetry->bind_param('sss', $patExact, $patPrefixed, $patAny);
                $stmtRetry->execute();
                $rowR = $stmtRetry->get_result()->fetch_assoc();
                $stmtRetry->close();
                if ($rowR) {
                    $resolvedSteps[$num] = [
                        'step' => $num,
                        'template_id' => (int)$rowR['template_id'],
                        'rule_id' => (int)$rowR['rule_id'],
                        'base_delay' => (int)$stepInfo['delay_from_now'],
                    ];
                }
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

    $baseOffset = $resolvedSteps[$startStepNum]['base_delay'] ?? 2;

    $count = 0;
    foreach ($resolvedSteps as $num => $stepData) {
        if ($num < $startStepNum) {
            continue; // Lewati tahap sebelum tahap mulai yang dipilih
        }

        // Hitung jeda relatif: tahap pertama selalu mulai dalam 2 menit (Instant Welcome)
        if ($num === $startStepNum) {
            $relDelay = 2;
        } else {
            $relDelay = ($stepData['base_delay'] - $baseOffset) + 2;
        }

        $res = crmAutomationRegisterQueue(
            $conn,
            $normalized,
            $nama,
            $stepData['rule_id'],
            $relDelay,
            true,
            $stepData['template_id'],
            $triggerSource
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
            'message' => "Alur follow-up berhasil dijadwalkan secara berurutan untuk kontak {$nowa} ({$count} tahap pesan). Pesan pertama (Instant Welcome) akan mulai dikirim dalam ±2 menit.",
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
    string $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada',
    string $triggerSource = 'manual'
): int {
    $res = crmAutomationScheduleSequence($conn, $nowa, $nama, 0, $keyword, $triggerSource);
    return (int)($res['count'] ?? 0);
}

/**
 * Menangani chat masuk real-time dari webhook untuk secara otomatis menjadwalkan
 * urutan alur follow-up 6 tahap jika pesan cocok dengan pola pemicu pendaftaran aktif.
 *
 * @return array{handled: bool, status: string, reason: string, count: int, trigger?: array}
 */
function crmAutomationHandleIncomingWebhook(
    mysqli $conn,
    string $nowa,
    string $message,
    ?string $nama = null
): array {
    crmAutomationEnsureTables($conn);

    if (crmAutomationIsOperationalNumber($nowa)) {
        return [
            'handled' => false,
            'status' => 'skipped',
            'reason' => 'operational_number',
            'count' => 0,
        ];
    }

    $normalized = crmProspectNormalizeNumber($nowa);
    if ($normalized === '' || crmAutomationIsOperationalNumber($normalized)) {
        return [
            'handled' => false,
            'status' => 'skipped',
            'reason' => 'invalid_number',
            'count' => 0,
        ];
    }

    // Periksa apakah kontak adalah nomor guru/pengajar
    if (function_exists('crmChatIsTeacherNumber') && crmChatIsTeacherNumber($conn, $normalized)) {
        return [
            'handled' => false,
            'status' => 'skipped',
            'reason' => 'teacher_contact',
            'count' => 0,
        ];
    }

    // Periksa apakah nomor diblokir
    if (crmAutomationIsBlocked($conn, $normalized)) {
        return [
            'handled' => false,
            'status' => 'skipped',
            'reason' => 'blocked',
            'count' => 0,
        ];
    }

    // Periksa apakah kontak sudah pernah isi form payment finance / jadi peserta aktif
    if (crmAutomationHasReceivedPayment($conn, $normalized)) {
        return [
            'handled' => false,
            'status' => 'skipped',
            'reason' => 'already_paid',
            'count' => 0,
        ];
    }

    // Periksa apakah isi pesan cocok dengan salah satu pola pemicu pendaftaran aktif
    $matchedTrigger = crmAutomationFindMatchingTrigger($conn, $message);
    if (!$matchedTrigger) {
        return [
            'handled' => false,
            'status' => 'skipped',
            'reason' => 'no_matching_trigger',
            'count' => 0,
        ];
    }

    // Ekstrak nama calon peserta HANYA jika secara eksplisit ditulis dalam isi chat.
    // Tidak mengambil dari pushName WhatsApp agar pesan follow-up tidak memuat nama aneh/tidak sesuai.
    $leadName = crmAutomationExtractExplicitName($message);

    // Jadwalkan 6 tahap alur follow-up standar dengan penanda 'auto_webhook'
    $res = crmAutomationScheduleSequence(
        $conn,
        $normalized,
        $leadName,
        0,
        $message,
        'auto_webhook'
    );

    return [
        'handled' => !empty($res['success']),
        'status' => !empty($res['success']) ? 'scheduled' : 'failed',
        'reason' => (string)($res['reason'] ?? ''),
        'count' => (int)($res['count'] ?? 0),
        'trigger' => $matchedTrigger,
    ];
}
