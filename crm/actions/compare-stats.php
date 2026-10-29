<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

$cronToken = (string)($_GET['cron_token'] ?? $_GET['token'] ?? '');
$expectedToken = 'jwd_auto_cron';

if ($cronToken !== $expectedToken && (php_sapi_name() !== 'cli')) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'message' => 'Token tidak valid.']);
    exit;
}

require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi DB gagal']);
    exit;
}

function normalizePhone(string $num): string {
    $num = preg_replace('/\D+/', '', $num);
    if (str_starts_with($num, '0')) {
        $num = '62' . substr($num, 1);
    }
    return $num;
}

// 1. Ambil semua kontak yang PERNAH menerima pesan Finance
// Pola: Wajib segera diisi ... pendataan Finance kami
$paidNumbers = [];

// Cek di crm_message_history
$hasHistory = $conn->query("SHOW TABLES LIKE 'crm_message_history'");
if ($hasHistory && $hasHistory->num_rows > 0) {
    $qHist = $conn->query("
        SELECT DISTINCT nowa, message, sent_at 
        FROM crm_message_history 
        WHERE LOWER(message) LIKE '%wajib%segera%diisi%' 
           OR LOWER(message) LIKE '%pendataan%finance%kami%'
    ");
    if ($qHist) {
        while ($r = $qHist->fetch_assoc()) {
            $msg = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$r['message']));
            if (str_contains($msg, 'wajibsegeradiisi') && str_contains($msg, 'mohondiisiuntukpendataanfinancekami')) {
                $norm = normalizePhone((string)$r['nowa']);
                if ($norm !== '') {
                    $paidNumbers[$norm] = [
                        'source' => 'crm_message_history',
                        'sent_at' => $r['sent_at']
                    ];
                }
            }
        }
    }
}

// Cek di log_wa
$qLog = $conn->query("
    SELECT DISTINCT nowa, message, created_at 
    FROM log_wa 
    WHERE LOWER(message) LIKE '%wajib%segera%diisi%' 
       OR LOWER(message) LIKE '%pendataan%finance%kami%'
");
if ($qLog) {
    while ($r = $qLog->fetch_assoc()) {
        $msg = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$r['message']));
        if (str_contains($msg, 'wajibsegeradiisi') && str_contains($msg, 'mohondiisiuntukpendataanfinancekami')) {
            $norm = normalizePhone((string)$r['nowa']);
            if ($norm !== '' && !isset($paidNumbers[$norm])) {
                $paidNumbers[$norm] = [
                    'source' => 'log_wa',
                    'sent_at' => $r['created_at']
                ];
            }
        }
    }
}

// Cek di crm_messages jika ada
$hasCrmMsg = $conn->query("SHOW TABLES LIKE 'crm_messages'");
if ($hasCrmMsg && $hasCrmMsg->num_rows > 0) {
    $qCrm = $conn->query("
        SELECT DISTINCT nowa, message, created_at 
        FROM crm_messages 
        WHERE direction = 'out' 
          AND (LOWER(message) LIKE '%wajib%segera%diisi%' OR LOWER(message) LIKE '%pendataan%finance%kami%')
    ");
    if ($qCrm) {
        while ($r = $qCrm->fetch_assoc()) {
            $msg = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$r['message']));
            if (str_contains($msg, 'wajibsegeradiisi') && str_contains($msg, 'mohondiisiuntukpendataanfinancekami')) {
                $norm = normalizePhone((string)$r['nowa']);
                if ($norm !== '' && !isset($paidNumbers[$norm])) {
                    $paidNumbers[$norm] = [
                        'source' => 'crm_messages',
                        'sent_at' => $r['created_at']
                    ];
                }
            }
        }
    }
}

// Cek di crm_automation_finance jika ada
$hasAutFin = $conn->query("SHOW TABLES LIKE 'crm_automation_finance'");
if ($hasAutFin && $hasAutFin->num_rows > 0) {
    $qFin = $conn->query("SELECT nowa, updated_at FROM crm_automation_finance WHERE status = 'paid'");
    if ($qFin) {
        while ($r = $qFin->fetch_assoc()) {
            $norm = normalizePhone((string)$r['nowa']);
            if ($norm !== '' && !isset($paidNumbers[$norm])) {
                $paidNumbers[$norm] = [
                    'source' => 'crm_automation_finance',
                    'sent_at' => $r['updated_at']
                ];
            }
        }
    }
}

// Fungsi analitik per periode
function analyzePeriodData(mysqli $conn, string $start, string $end, array $paidNumbers): array {
    // Cari chat masuk di log_wa yang bukan broadcast grup (@g.us) dan bukan admin outbound
    $stmt = $conn->prepare("
        SELECT id, nowa, nama, message, created_at 
        FROM log_wa 
        WHERE created_at >= ? AND created_at <= ?
          AND nowa NOT LIKE '%@g.us%'
          AND nowa NOT LIKE '%-%'
          AND nama NOT LIKE '%Admin%'
          AND message NOT LIKE '[GRUP]%'
        ORDER BY created_at ASC
    ");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $totalChatMasuk = 0;
    $uniqueLeads = [];

    while ($row = $res->fetch_assoc()) {
        $totalChatMasuk++;
        $norm = normalizePhone((string)$row['nowa']);
        if ($norm === '') continue;

        if (!isset($uniqueLeads[$norm])) {
            $uniqueLeads[$norm] = [
                'nowa' => $norm,
                'nama' => (string)$row['nama'],
                'first_chat' => (string)$row['created_at'],
                'first_message' => mb_substr((string)$row['message'], 0, 80),
                'chat_count' => 1
            ];
        } else {
            $uniqueLeads[$norm]['chat_count']++;
        }
    }
    $stmt->close();

    $totalUnique = count($uniqueLeads);
    $paidInPeriod = [];
    $unpaidInPeriod = [];

    foreach ($uniqueLeads as $norm => $lead) {
        if (isset($paidNumbers[$norm])) {
            $paidInfo = $paidNumbers[$norm];
            $lead['paid_source'] = $paidInfo['source'];
            $lead['paid_sent_at'] = $paidInfo['sent_at'];
            $paidInPeriod[] = $lead;
        } else {
            $unpaidInPeriod[] = $lead;
        }
    }

    $conversionRate = ($totalUnique > 0) ? round((count($paidInPeriod) / $totalUnique) * 100, 2) : 0;

    return [
        'start_date' => $start,
        'end_date' => $end,
        'total_chat_masuk' => $totalChatMasuk,
        'total_unique_kontak' => $totalUnique,
        'total_paid' => count($paidInPeriod),
        'total_unpaid' => count($unpaidInPeriod),
        'conversion_rate_percent' => $conversionRate,
        'list_paid' => $paidInPeriod
    ];
}

$sept = analyzePeriodData($conn, '2026-09-01 00:00:00', '2026-09-24 23:59:59', $paidNumbers);
$oct  = analyzePeriodData($conn, '2026-10-01 00:00:00', '2026-10-29 23:59:59', $paidNumbers);

echo json_encode([
    'status' => 'success',
    'total_all_paid_numbers_in_system' => count($paidNumbers),
    'september' => $sept,
    'october' => $oct,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
