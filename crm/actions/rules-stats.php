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

// 1. Ambil semua Pola Pemicu dari crm_automation_rules
$rules = [];
$resRules = $conn->query("SELECT id, name, trigger_keyword, trigger_condition, category, is_active FROM crm_automation_rules ORDER BY id ASC");
if ($resRules) {
    while ($r = $resRules->fetch_assoc()) {
        $rules[] = [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'keyword' => trim((string)$r['trigger_keyword']),
            'condition' => (string)$r['trigger_condition'],
            'category' => (string)$r['category'],
            'is_active' => (int)$r['is_active']
        ];
    }
}

// Fungsi mencocokkan apakah pesan masuk cocok dengan salah satu Pola Pemicu
function matchTriggerRule(string $message, array $rules): ?array {
    $cleanMsg = strtolower(trim($message));
    if ($cleanMsg === '') return null;

    foreach ($rules as $rule) {
        $kw = strtolower(trim($rule['keyword']));
        if ($kw === '') continue;

        if ($rule['condition'] === 'exact') {
            if ($cleanMsg === $kw) return $rule;
        } else {
            // 'contains' (default)
            if (str_contains($cleanMsg, $kw)) return $rule;
        }
    }
    return null;
}

// 2. Ambil kontak yang berstatus PAID (menerima chat pendataan finance)
$paidNumbers = [];

// A. Dari crm_message_history
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

// B. Dari log_wa
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

// C. Dari crm_automation_finance
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

// 3. Analisis berdasarkan filter Pola Pemicu
function analyzeByRules(mysqli $conn, string $start, string $end, array $rules, array $paidNumbers): array {
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

    $matchedChats = 0;
    $uniqueCustomerBaru = [];
    $ruleBreakdown = [];

    while ($row = $res->fetch_assoc()) {
        $msg = (string)$row['message'];
        $matched = matchTriggerRule($msg, $rules);
        if (!$matched) {
            continue; // Bukan chat customer baru pemicu LP
        }

        $matchedChats++;
        $norm = normalizePhone((string)$row['nowa']);
        if ($norm === '') continue;

        $ruleName = $matched['name'];
        if (!isset($ruleBreakdown[$ruleName])) {
            $ruleBreakdown[$ruleName] = 0;
        }
        $ruleBreakdown[$ruleName]++;

        if (!isset($uniqueCustomerBaru[$norm])) {
            $uniqueCustomerBaru[$norm] = [
                'nowa' => $norm,
                'nama' => (string)$row['nama'],
                'first_chat' => (string)$row['created_at'],
                'matched_rule' => $ruleName,
                'first_message' => mb_substr($msg, 0, 100),
                'chat_count' => 1
            ];
        } else {
            $uniqueCustomerBaru[$norm]['chat_count']++;
        }
    }
    $stmt->close();

    $totalUnique = count($uniqueCustomerBaru);
    $paidList = [];
    $unpaidList = [];

    foreach ($uniqueCustomerBaru as $norm => $customer) {
        if (isset($paidNumbers[$norm])) {
            $customer['paid_source'] = $paidNumbers[$norm]['source'];
            $customer['paid_sent_at'] = $paidNumbers[$norm]['sent_at'];
            $paidList[] = $customer;
        } else {
            $unpaidList[] = $customer;
        }
    }

    $conversionRate = ($totalUnique > 0) ? round((count($paidList) / $totalUnique) * 100, 2) : 0;

    return [
        'start_date' => $start,
        'end_date' => $end,
        'total_chat_masuk_pola_pemicu' => $matchedChats,
        'total_unique_customer_baru' => $totalUnique,
        'total_paid' => count($paidList),
        'total_unpaid' => count($unpaidList),
        'conversion_rate_percent' => $conversionRate,
        'rule_breakdown' => $ruleBreakdown,
        'list_paid' => $paidList
    ];
}

$september = analyzeByRules($conn, '2026-09-01 00:00:00', '2026-09-24 23:59:59', $rules, $paidNumbers);
$october   = analyzeByRules($conn, '2026-10-01 00:00:00', '2026-10-29 23:59:59', $rules, $paidNumbers);

echo json_encode([
    'status' => 'success',
    'total_rules' => count($rules),
    'rules' => $rules,
    'total_paid_in_system' => count($paidNumbers),
    'september' => $september,
    'october' => $october,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
