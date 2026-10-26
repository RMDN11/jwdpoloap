<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/reminder-schedule.php';

@set_time_limit(300);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$action = (string)($_POST['action'] ?? 'create');

$redirectParams = ['page' => 'reminder-pembayaran'];
foreach (['q', 'bulan', 'csv_file', 'halaqoh', 'status_peserta', 'status_bayar', 'p'] as $filterKey) {
    if (isset($_POST[$filterKey]) && trim((string)$_POST[$filterKey]) !== '') {
        $redirectParams[$filterKey] = trim((string)$_POST[$filterKey]);
    }
}
$redirectUrl = '../index.php?' . http_build_query($redirectParams);

if ($action === 'pause') {
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if ($campaignId > 0 && crmUpdateCampaignStatus($conn, $campaignId, 'paused')) {
        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Jadwal otomasi berhasil dijeda.'];
    } else {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menjeda jadwal otomasi.'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

if ($action === 'resume') {
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if ($campaignId > 0 && crmUpdateCampaignStatus($conn, $campaignId, 'running')) {
        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Jadwal otomasi dilanjutkan kembali.'];
    } else {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal melanjutkan jadwal otomasi.'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

if ($action === 'cancel') {
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if ($campaignId > 0 && crmUpdateCampaignStatus($conn, $campaignId, 'cancelled')) {
        $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Jadwal otomasi telah dibatalkan.'];
    } else {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal membatalkan jadwal otomasi.'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Default: create / create_campaign
$templateId = (int)($_POST['template_id'] ?? 0);
if ($templateId <= 0) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Template reminder belum dipilih.'];
    header('Location: ' . $redirectUrl);
    exit;
}

$title = trim((string)($_POST['title'] ?? ''));
if ($title === '') {
    $title = 'Reminder Tagihan ' . date('d M Y H:i');
}

$bulan = trim((string)($_POST['bulan'] ?? ''));
$delayMsgMin = max(1, (int)($_POST['delay_message'] ?? 2));
$interMessageDelay = $delayMsgMin * 60;

$delayHalMin = max(1, (int)($_POST['delay_halaqoh'] ?? 15));
$interGroupDelay = $delayHalMin * 60;

$startType = (string)($_POST['start_type'] ?? 'now');
date_default_timezone_set('Asia/Jakarta');
$startDateTime = date('Y-m-d H:i:s');
if ($startType === 'custom' && !empty($_POST['start_datetime'])) {
    $ts = strtotime((string)$_POST['start_datetime']);
    if ($ts) {
        $startDateTime = date('Y-m-d H:i:s', $ts);
    }
}

$targets = [];
$raw = json_decode((string)($_POST['selected'] ?? '[]'), true);

if (is_array($raw)) {
    $selectedIds = [];
    foreach ($raw as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $selectedIds[$id] = true;
    }

    $csvFile = basename(trim((string)($_POST['csv_file'] ?? '')));

    if ($csvFile !== '' && $selectedIds) {
        require_once __DIR__ . '/../lib/reminder-csv.php';
        try {
            $csvRows = crmReminderCsvRead($csvFile);
            $waMap = [];
            $res = $conn->query("SELECT id, nowa FROM peserta WHERE nowa IS NOT NULL AND nowa <> ''");
            if ($res) {
                while ($p = $res->fetch_assoc()) {
                    $norm = crmReminderCsvNormalizeWa((string)$p['nowa']);
                    if ($norm === '') continue;
                    if (array_key_exists($norm, $waMap)) $waMap[$norm] = 0;
                    else $waMap[$norm] = (int)$p['id'];
                }
            }

            $csvTargetByPesertaId = [];
            foreach ($csvRows as $csvRow) {
                if (crmReminderCsvNormalizeStatus((string)$csvRow['status_siswa']) !== 'ON') continue;
                $norm = (string)$csvRow['normalized_wa'];
                if ($norm === '' || !array_key_exists($norm, $waMap) || $waMap[$norm] === 0) continue;
                $csvTargetByPesertaId[(int)$waMap[$norm]] = [
                    'wa' => (string)$csvRow['whatsapp_wali'],
                    'halaqoh' => (string)($csvRow['kelas_grup'] ?? $csvRow['program'] ?? '')
                ];
            }

            $ids = array_map('intval', array_keys($selectedIds));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $stmt = $conn->prepare("SELECT id, nama_lengkap, nowa, halaqoh FROM peserta WHERE id IN ({$placeholders})");
            if ($stmt) {
                $refs = [];
                foreach ($ids as $k => $v) $refs[$k] = &$ids[$k];
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $pId = (int)$row['id'];
                    $tWa = isset($csvTargetByPesertaId[$pId]) ? $csvTargetByPesertaId[$pId]['wa'] : (string)$row['nowa'];
                    $targets[] = [
                        'id' => $pId,
                        'name' => (string)$row['nama_lengkap'],
                        'nowa' => $tWa,
                        'halaqoh' => (string)($row['halaqoh'] ?: ($csvTargetByPesertaId[$pId]['halaqoh'] ?? 'Umum')),
                    ];
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('CRM Schedule CSV read failed: ' . $e->getMessage());
        }
    } elseif ($selectedIds) {
        $ids = array_map('intval', array_keys($selectedIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT id, nama_lengkap, nowa, halaqoh FROM peserta WHERE id IN ({$placeholders})");
        if ($stmt) {
            $refs = [];
            foreach ($ids as $k => $v) $refs[$k] = &$ids[$k];
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $targets[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['nama_lengkap'],
                    'nowa' => (string)$row['nowa'],
                    'halaqoh' => (string)($row['halaqoh'] ?: 'Umum'),
                ];
            }
            $stmt->close();
        }
    }

    if (!$targets && !empty($raw)) {
        foreach ($raw as $item) {
            if (!empty($item['nowa'])) {
                $targets[] = [
                    'id' => (int)($item['id'] ?? 0),
                    'name' => (string)($item['name'] ?? 'Kak'),
                    'nowa' => (string)$item['nowa'],
                    'halaqoh' => (string)($item['halaqoh'] ?? 'Umum'),
                ];
            }
        }
    }
}

if (!$targets) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Tidak ada target peserta yang valid untuk dijadwalkan.'];
    header('Location: ' . $redirectUrl);
    exit;
}

try {
    $campaignId = crmCreateReminderCampaign($conn, [
        'title' => $title,
        'template_id' => $templateId,
        'bulan' => $bulan,
        'inter_message_delay' => $interMessageDelay,
        'inter_group_delay' => $interGroupDelay,
        'start_datetime' => $startDateTime,
        'targets' => $targets
    ]);

    $_SESSION['crm_flash'] = [
        'type' => 'success',
        'message' => 'Jadwal otomasi "' . htmlspecialchars($title) . '" berhasil dibuat untuk ' . count($targets) . ' target peserta.'
    ];
} catch (Throwable $e) {
    $_SESSION['crm_flash'] = [
        'type' => 'error',
        'message' => 'Gagal membuat jadwal otomasi: ' . $e->getMessage()
    ];
}

header('Location: ' . $redirectUrl);
exit;
