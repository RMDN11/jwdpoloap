<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/automation-followup.php';
require_once __DIR__ . '/../lib/automation-engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

crmAutomationEnsureTables($conn);

$action = trim((string)($_POST['action'] ?? ''));
$tab = trim((string)($_POST['tab'] ?? 'rules'));

function crmAutoRedirect(string $tab = 'rules'): void
{
    header('Location: index.php?page=automation-followup&tab=' . urlencode($tab));
    exit;
}

switch ($action) {
    case 'save_rule':
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $keyword = trim((string)($_POST['trigger_keyword'] ?? ''));
        $templateId = (int)($_POST['template_id'] ?? 0);
        $delayMinutes = max(1, (int)($_POST['delay_minutes'] ?? 1440));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $keyword === '' || $templateId <= 0) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nama aturan, kata kunci pemicu, dan template wajib diisi.'];
            crmAutoRedirect('rules');
        }

        if ($ruleId > 0) {
            $stmt = $conn->prepare("UPDATE crm_automation_rules SET name = ?, trigger_keyword = ?, template_id = ?, delay_minutes = ?, is_active = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ssiiii', $name, $keyword, $templateId, $delayMinutes, $isActive, $ruleId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Aturan automasi follow-up berhasil diperbarui.'];
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO crm_automation_rules (name, trigger_keyword, trigger_condition, template_id, delay_minutes, is_active) VALUES (?, ?, 'contains', ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssiii', $name, $keyword, $templateId, $delayMinutes, $isActive);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Aturan automasi follow-up baru berhasil ditambahkan.'];
            }
        }
        crmAutoRedirect('rules');
        break;

    case 'toggle_rule':
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $stmt = $conn->prepare("UPDATE crm_automation_rules SET is_active = (1 - is_active) WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $ruleId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Status aktif aturan berhasil diubah.'];
            }
        }
        crmAutoRedirect('rules');
        break;

    case 'save_template':
        $templateId = (int)($_POST['template_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($name === '' || $content === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nama dan konten template wajib diisi.'];
            crmAutoRedirect('templates');
        }

        if ($templateId > 0) {
            $stmt = $conn->prepare("UPDATE crm_automation_templates SET name = ?, content = ?, description = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('sssi', $name, $content, $description, $templateId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template follow-up berhasil diperbarui.'];
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO crm_automation_templates (name, content, description) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sss', $name, $content, $description);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template follow-up baru berhasil disimpan.'];
            }
        }
        crmAutoRedirect('templates');
        break;

    case 'delete_template':
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId > 0) {
            // Cek apakah template sedang digunakan di rules
            $check = $conn->prepare("SELECT id FROM crm_automation_rules WHERE template_id = ? LIMIT 1");
            if ($check) {
                $check->bind_param('i', $templateId);
                $check->execute();
                $inUse = $check->get_result()->num_rows > 0;
                $check->close();

                if ($inUse) {
                    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template tidak dapat dihapus karena masih digunakan dalam aturan aktif.'];
                    crmAutoRedirect('templates');
                }
            }

            $stmt = $conn->prepare("DELETE FROM crm_automation_templates WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $templateId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template follow-up berhasil dihapus.'];
            }
        }
        crmAutoRedirect('templates');
        break;

    case 'cancel_queue':
        $queueId = (int)($_POST['queue_id'] ?? 0);
        if ($queueId > 0) {
            crmAutomationCancelQueueItem($conn, $queueId, 'cancelled_manual');
            $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Antrean berhasil dibatalkan secara manual.'];
        }
        crmAutoRedirect('queue');
        break;

    case 'queue_prospect':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? 'Kak'));
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);
        $delayMinutes = max(0, (int)($_POST['delay_minutes'] ?? 0));

        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects');
        }

        if (crmAutomationIsOperationalNumber($nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dimasukkan ke antrean follow-up."];
            crmAutoRedirect('prospects');
        }

        if (crmAutomationHasReceivedPayment($conn, $nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak ini sudah menerima pesan payment Finance, tidak dapat dimasukkan antrean.'];
            crmAutoRedirect('prospects');
        }

        $queueId = crmAutomationRegisterQueue($conn, $nowa, $nama, $ruleId, $delayMinutes, true, $templateId);
        if ($queueId > 0) {
            $_SESSION['crm_flash'] = ['type' => 'success', 'message' => "Kontak {$nowa} berhasil dijadwalkan ke antrean follow-up."];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menjadwalkan kontak (nomor diblokir atau tidak valid).'];
        }
        crmAutoRedirect('prospects');
        break;

    case 'schedule_sequence':
    case 'queue_sequence':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? 'Kak'));
        $templateId = (int)($_POST['template_id'] ?? 0);

        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects');
        }

        if (crmAutomationIsOperationalNumber($nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dijadwalkan follow-up."];
            crmAutoRedirect('prospects');
        }

        if (crmAutomationHasReceivedPayment($conn, $nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak ini sudah menerima pesan payment Finance / terdaftar sebagai peserta, tidak dapat dimasukkan alur follow-up.'];
            crmAutoRedirect('prospects');
        }

        $keyword = trim((string)($_POST['keyword'] ?? ''));
        if ($keyword === '') {
            $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada';
        }

        $res = crmAutomationScheduleSequence($conn, $nowa, $nama, $templateId, $keyword);
        if (!empty($res['success'])) {
            $_SESSION['crm_flash'] = ['type' => 'success', 'message' => $res['message']];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menjadwalkan sequence: ' . ($res['message'] ?? 'Nomor diblokir atau aturan belum tersedia.')];
        }
        crmAutoRedirect('prospects');
        break;

    case 'stop_prospect':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects');
        }

        $cancelledCount = crmAutomationCancelAllPendingForContact($conn, $nowa, 'cancelled_manual');
        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => "Follow-up kontak {$nowa} berhasil dihentikan ({$cancelledCount} antrean dibatalkan)."];
        crmAutoRedirect('prospects');
        break;

    case 'send_prospect_now':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? 'Kak'));
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);

        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects');
        }

        if (crmAutomationIsOperationalNumber($nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dikirim follow-up."];
            crmAutoRedirect('prospects');
        }

        if (crmAutomationHasReceivedPayment($conn, $nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak ini sudah menerima pesan payment Finance.'];
            crmAutoRedirect('prospects');
        }

        $queueId = crmAutomationRegisterQueue($conn, $nowa, $nama, $ruleId, 0, true, $templateId);
        if ($queueId > 0) {
            [$apiUrl, $apiToken] = crmAutomationGetApiCredentials();
            $res = crmAutomationDispatchSingle($conn, $queueId, $apiUrl, $apiToken);
            if ($res['status'] === 'sent') {
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => "Pesan follow-up berhasil langsung dikirim ke {$nowa}."];
            } else {
                $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pengiriman gagal: ' . ($res['reason'] ?? '')];
            }
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal membuat antrean untuk kontak (nomor diblokir atau nomor operasional).'];
        }
        crmAutoRedirect('prospects');
        break;

    case 'send_now':
        $queueId = (int)($_POST['queue_id'] ?? 0);
        if ($queueId > 0) {
            [$apiUrl, $apiToken] = crmAutomationGetApiCredentials();
            $res = crmAutomationDispatchSingle($conn, $queueId, $apiUrl, $apiToken);
            if ($res['status'] === 'sent') {
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Pesan follow-up otomatis berhasil dikirimkan ke kontak.'];
            } elseif ($res['status'] === 'cancelled') {
                $_SESSION['crm_flash'] = ['type' => 'info', 'message' => 'Pengiriman dibatalkan otomatis karena: ' . ($res['reason'] ?? 'kondisi henti terpenuhi')];
            } else {
                $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pengiriman gagal: ' . ($res['reason'] ?? 'terjadi kendala jaringan/API.')];
            }
        }
        crmAutoRedirect('queue');
        break;

    case 'clean_duplicate_queues':
        // 1. Batalkan semua antrean pending yang memuat template minat spesifik
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

        // 2. Batalkan antrean pending yang bentrok pada jam dan menit yang sama
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

        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Pembersihan selesai: antrean spesifik dan antrean yang bentrok pada jam yang sama berhasil dibatalkan.'];
        crmAutoRedirect('queue');
        break;

    default:
        crmAutoRedirect('rules');
        break;
}
