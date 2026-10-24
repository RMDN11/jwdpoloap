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

    case 'send_now':
        $queueId = (int)($_POST['queue_id'] ?? 0);
        if ($queueId > 0) {
            $apiUrl = defined('WA_API_URL') ? WA_API_URL : (getenv('WA_API_URL') ?: 'https://api.onesender.net/api/v1/messages');
            $apiToken = defined('WA_API_TOKEN') ? WA_API_TOKEN : (getenv('WA_API_TOKEN') ?: '');

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

    default:
        crmAutoRedirect('rules');
        break;
}
