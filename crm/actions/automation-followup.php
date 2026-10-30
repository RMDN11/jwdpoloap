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
$returnUrl = trim((string)($_POST['return_url'] ?? ''));

function crmAutoRedirect(string $tab = 'rules', ?string $returnUrl = null, ?string $anchor = null): void
{
    // Location: index.php?page=automation-followup
    $target = 'index.php?page=automation-followup&tab=' . urlencode($tab);
    if (!empty($returnUrl)) {
        $parsed = parse_url($returnUrl);
        $path = $parsed['path'] ?? '';
        $query = !empty($parsed['query']) ? '?' . $parsed['query'] : '';
        if (str_contains($path, 'index.php') || $path === '' || str_starts_with($path, '?')) {
            if (empty($query) && str_starts_with($path, '?')) {
                $query = $path;
            }
            if (!empty($query) && str_contains($query, 'page=automation-followup')) {
                $target = 'index.php' . $query;
            }
        }
    }

    if (!empty($anchor)) {
        $cleanAnchor = ltrim($anchor, '#');
        $base = explode('#', $target)[0];
        $target = $base . '#' . $cleanAnchor;
    }

    header('Location: ' . $target);
    exit;
}

switch ($action) {
    case 'save_rule':
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $keyword = trim((string)($_POST['trigger_keyword'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'general'));
        $templateId = (int)($_POST['template_id'] ?? 0);
        $delayMinutes = max(1, (int)($_POST['delay_minutes'] ?? 10));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($templateId <= 0) {
            // Default ke template pertama yang tersedia
            $resFirstTpl = $conn->query("SELECT id FROM crm_automation_templates ORDER BY id ASC LIMIT 1");
            if ($resFirstTpl && ($rowFt = $resFirstTpl->fetch_assoc())) {
                $templateId = (int)$rowFt['id'];
            } else {
                $templateId = 1;
            }
        }

        if ($name === '' || $keyword === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nama program/label dan kata kunci pemicu wajib diisi.'];
            crmAutoRedirect('rules', $returnUrl);
        }

        if ($category === '') {
            $category = 'general';
        }

        $savedRuleId = $ruleId;
        if ($ruleId > 0) {
            $stmt = $conn->prepare("UPDATE crm_automation_rules SET name = ?, trigger_keyword = ?, category = ?, template_id = ?, delay_minutes = ?, is_active = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('sssiiii', $name, $keyword, $category, $templateId, $delayMinutes, $isActive, $ruleId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Pola pemicu automasi follow-up berhasil diperbarui.'];
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO crm_automation_rules (name, trigger_keyword, trigger_condition, category, template_id, delay_minutes, is_active) VALUES (?, ?, 'contains', ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sssiii', $name, $keyword, $category, $templateId, $delayMinutes, $isActive);
                $stmt->execute();
                $savedRuleId = (int)$conn->insert_id;
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Pola pemicu automasi baru berhasil ditambahkan.'];
            }
        }
        $anchor = $savedRuleId > 0 ? "row-rule-{$savedRuleId}" : null;
        crmAutoRedirect('rules', $returnUrl, $anchor);
        break;

    case 'delete_rule':
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $stmt = $conn->prepare("DELETE FROM crm_automation_rules WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $ruleId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Pola pemicu berhasil dihapus.'];
            }
        }
        crmAutoRedirect('rules', $returnUrl);
        break;

    case 'toggle_rule':
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $stmt = $conn->prepare("UPDATE crm_automation_rules SET is_active = (1 - is_active) WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $ruleId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Status aktif pola pemicu berhasil diubah.'];
            }
        }
        crmAutoRedirect('rules', $returnUrl, $ruleId > 0 ? "row-rule-{$ruleId}" : null);
        break;

    case 'save_template':
        $templateId = (int)($_POST['template_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($name === '' || $content === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nama dan konten template wajib diisi.'];
            crmAutoRedirect('templates', $returnUrl);
        }

        $savedTplId = $templateId;
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
                $savedTplId = (int)$conn->insert_id;
                $stmt->close();
                $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Template follow-up baru berhasil disimpan.'];
            }
        }
        $anchor = $savedTplId > 0 ? "row-tpl-{$savedTplId}" : null;
        crmAutoRedirect('templates', $returnUrl, $anchor);
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
                    crmAutoRedirect('templates', $returnUrl, "row-tpl-{$templateId}");
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
        crmAutoRedirect('templates', $returnUrl);
        break;

    case 'cancel_queue':
        $queueId = (int)($_POST['queue_id'] ?? 0);
        if ($queueId > 0) {
            crmAutomationCancelQueueItem($conn, $queueId, 'cancelled_manual');
            $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Antrean berhasil dibatalkan secara manual.'];
        }
        crmAutoRedirect('queue', $returnUrl, $queueId > 0 ? "row-queue-{$queueId}" : null);
        break;

    case 'queue_prospect':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        if (in_array(strtolower($nama), ['kak', 'unknown', 'tanpa nama', 'null', 'calon peserta', 'peserta'], true)) {
            $nama = null;
        }
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);
        $delayMinutes = max(0, (int)($_POST['delay_minutes'] ?? 0));
        $prospectAnchor = $nowa !== '' ? 'row-p-' . preg_replace('/\D/', '', $nowa) : null;

        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects', $returnUrl);
        }

        if (crmAutomationIsOperationalNumber($nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dimasukkan ke antrean follow-up."];
            crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        }

        if (crmAutomationHasReceivedPayment($conn, $nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak ini sudah menerima pesan payment Finance, tidak dapat dimasukkan antrean.'];
            crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        }

        $queueId = crmAutomationRegisterQueue($conn, $nowa, $nama, $ruleId, $delayMinutes, true, $templateId);
        if ($queueId > 0) {
            $_SESSION['crm_flash'] = ['type' => 'success', 'message' => "Kontak {$nowa} berhasil dijadwalkan ke antrean follow-up."];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menjadwalkan kontak (nomor diblokir atau tidak valid).'];
        }
        crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        break;

    case 'schedule_sequence':
    case 'queue_sequence':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        if (in_array(strtolower($nama), ['kak', 'unknown', 'tanpa nama', 'null', 'calon peserta', 'peserta'], true)) {
            $nama = null;
        }
        $templateId = (int)($_POST['template_id'] ?? 0);
        $startStep = max(1, min(6, (int)($_POST['start_step'] ?? 1)));
        $prospectAnchor = $nowa !== '' ? 'row-p-' . preg_replace('/\D/', '', $nowa) : null;

        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects', $returnUrl);
        }

        if (crmAutomationIsOperationalNumber($nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dijadwalkan follow-up."];
            crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        }

        if (crmAutomationHasReceivedPayment($conn, $nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak ini sudah menerima pesan payment Finance / terdaftar sebagai peserta, tidak dapat dimasukkan alur follow-up.'];
            crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        }

        $keyword = trim((string)($_POST['keyword'] ?? ''));
        if ($keyword === '') {
            $keyword = 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada';
        }

        $res = crmAutomationScheduleSequence($conn, $nowa, $nama, $templateId, $keyword, 'manual', $startStep);
        if (!empty($res['success'])) {
            $_SESSION['crm_flash'] = ['type' => 'success', 'message' => $res['message']];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menjadwalkan sequence: ' . ($res['message'] ?? 'Nomor diblokir atau aturan belum tersedia.')];
        }
        crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        break;

    case 'stop_prospect':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $prospectAnchor = $nowa !== '' ? 'row-p-' . preg_replace('/\D/', '', $nowa) : null;
        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects', $returnUrl);
        }

        $cancelledCount = crmAutomationCancelAllPendingForContact($conn, $nowa, 'cancelled_manual');
        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => "Follow-up kontak {$nowa} berhasil dihentikan ({$cancelledCount} antrean dibatalkan)."];
        crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        break;

    case 'send_prospect_now':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        if (in_array(strtolower($nama), ['kak', 'unknown', 'tanpa nama', 'null', 'calon peserta', 'peserta'], true)) {
            $nama = null;
        }
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);
        $prospectAnchor = $nowa !== '' ? 'row-p-' . preg_replace('/\D/', '', $nowa) : null;

        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects', $returnUrl);
        }

        if (crmAutomationIsOperationalNumber($nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => "Nomor {$nowa} adalah nomor operasional internal Jawwada, tidak dapat dikirim follow-up."];
            crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        }

        if (crmAutomationHasReceivedPayment($conn, $nowa)) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak ini sudah menerima pesan payment Finance.'];
            crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
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
        crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
        break;

    case 'toggle_finance_status':
    case 'set_finance_status':
        $nowa = trim((string)($_POST['nowa'] ?? ''));
        $targetStatus = trim((string)($_POST['status'] ?? 'paid'));
        $prospectAnchor = $nowa !== '' ? 'row-p-' . preg_replace('/\D/', '', $nowa) : null;

        if ($nowa === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Nomor WhatsApp tidak valid.'];
            crmAutoRedirect('prospects', $returnUrl);
        }

        if ($targetStatus !== 'unpaid') {
            $targetStatus = 'paid';
        }

        $ok = crmAutomationSetFinanceStatus($conn, $nowa, $targetStatus);
        if ($ok) {
            $label = ($targetStatus === 'paid') ? 'Sudah Diisi Finance' : 'Belum Diisi Finance';
            $_SESSION['crm_flash'] = ['type' => 'success', 'message' => "Status finance kontak {$nowa} berhasil diubah menjadi: {$label}."];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal mengubah status finance kontak.'];
        }

        crmAutoRedirect('prospects', $returnUrl, $prospectAnchor);
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
        crmAutoRedirect('queue', $returnUrl, $queueId > 0 ? "row-queue-{$queueId}" : null);
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
        crmAutoRedirect('queue', $returnUrl);
        break;

    case 'save_sleep_settings':
        $enabled = isset($_POST['sleep_hours_enabled']) ? '1' : '0';
        $start = trim((string)($_POST['sleep_start'] ?? '21:30'));
        $end = trim((string)($_POST['sleep_end'] ?? '07:30'));

        $stmtMeta = $conn->prepare("INSERT INTO crm_automation_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        if ($stmtMeta) {
            $k1 = 'sleep_hours_enabled'; $stmtMeta->bind_param('ss', $k1, $enabled); $stmtMeta->execute();
            $k2 = 'sleep_start'; $stmtMeta->bind_param('ss', $k2, $start); $stmtMeta->execute();
            $k3 = 'sleep_end'; $stmtMeta->bind_param('ss', $k3, $end); $stmtMeta->execute();
            $stmtMeta->close();
        }
        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Pengaturan jam istirahat malam berhasil disimpan.'];
        crmAutoRedirect($tab, $returnUrl);
        break;

    default:
        crmAutoRedirect('rules', $returnUrl);
        break;
}
