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

// 1. Ambil kontak yang berstatus PAID
$paidNumbers = [];

// A. crm_message_history
$qHist = $conn->query("
    SELECT DISTINCT nowa, message, sent_at 
    FROM crm_message_history 
    WHERE LOWER(message) LIKE '%wajib%segera%diisi%' 
       OR LOWER(message) LIKE '%pendataan%finance%kami%'
");
if ($qHist) {
    while ($r = $qHist->fetch_assoc()) {
        $clean = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$r['message']));
        if (str_contains($clean, 'wajibsegeradiisi') && str_contains($clean, 'mohondiisiuntukpendataanfinancekami')) {
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

// B. log_wa
$qLog = $conn->query("
    SELECT DISTINCT nowa, message, created_at 
    FROM log_wa 
    WHERE LOWER(message) LIKE '%wajib%segera%diisi%' 
       OR LOWER(message) LIKE '%pendataan%finance%kami%'
");
if ($qLog) {
    while ($r = $qLog->fetch_assoc()) {
        $clean = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$r['message']));
        if (str_contains($clean, 'wajibsegeradiisi') && str_contains($clean, 'mohondiisiuntukpendataanfinancekami')) {
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

// C. crm_automation_finance
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

// 2. Fungsi analisis khusus teks Hero LP: 'Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada'
function analyzeHeroKeyword(mysqli $conn, string $start, string $end, array $paidNumbers): array {
    $stmt = $conn->prepare("
        SELECT id, nowa, nama, message, created_at 
        FROM log_wa 
        WHERE created_at >= ? AND created_at <= ?
          AND LOWER(message) LIKE '%tanya detail program tahfidz private online jawwada%'
          AND nowa NOT LIKE '%@g.us%'
          AND nowa NOT LIKE '%-%'
          AND nama NOT LIKE '%Admin%'
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
                'message' => (string)$row['message'],
                'chat_count' => 1
            ];
        } else {
            $uniqueLeads[$norm]['chat_count']++;
        }
    }
    $stmt->close();

    $totalUnique = count($uniqueLeads);
    $paidList = [];
    $unpaidList = [];

    foreach ($uniqueLeads as $norm => $lead) {
        if (isset($paidNumbers[$norm])) {
            $lead['paid_source'] = $paidNumbers[$norm]['source'];
            $lead['paid_sent_at'] = $paidNumbers[$norm]['sent_at'];
            $paidList[] = $lead;
        } else {
            $unpaidList[] = $lead;
        }
    }

    $conversionRate = ($totalUnique > 0) ? round((count($paidList) / $totalUnique) * 100, 2) : 0;

    return [
        'start_date' => $start,
        'end_date' => $end,
        'total_chat_masuk' => $totalChatMasuk,
        'total_unique_kontak' => $totalUnique,
        'total_paid' => count($paidList),
        'total_unpaid' => count($unpaidList),
        'conversion_rate_percent' => $conversionRate,
        'list_paid' => $paidList,
        'sample_unpaid' => array_slice($unpaidList, 0, 5)
    ];
}

$sept = analyzeHeroKeyword($conn, '2026-09-01 00:00:00', '2026-09-24 23:59:59', $paidNumbers);
$oct  = analyzeHeroKeyword($conn, '2026-10-01 00:00:00', '2026-10-29 23:59:59', $paidNumbers);

echo json_encode([
    'status' => 'success',
    'september' => $sept,
    'october' => $oct,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
