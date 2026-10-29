<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$cronToken = (string)($_GET['cron_token'] ?? $_GET['token'] ?? '');
$expectedToken = defined('CRM_CRON_TOKEN') ? CRM_CRON_TOKEN : 'jwd_auto_cron';

if ($cronToken !== $expectedToken && (php_sapi_name() !== 'cli')) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'message' => 'Token tidak valid.']);
    exit;
}

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/config/prospect.php';
require_once dirname(__DIR__) . '/config/automation-followup.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB unavailable']);
    exit;
}

// Function to check if a text matches the finance payment pattern
function isFinanceText(string $text): bool {
    $clean = strtolower(preg_replace('/[^a-z0-9]/', '', $text));
    return str_contains($clean, 'wajibsegeradiisi') && str_contains($clean, 'mohondiisiuntukpendataanfinancekami');
}

// Helper to analyze a period
function analyzePeriod(mysqli $conn, string $start, string $end, string $label): array {
    // 1. Log_wa incoming chats
    // Check disqualification & blocked
    $disqualified = crmGetDisqualifiedNumbers($conn);
    $blocked = crmGetBlockedNumbers($conn);

    $stmt = $conn->prepare("
        SELECT id, nowa, nama, message, created_at 
        FROM log_wa 
        WHERE created_at >= ? AND created_at <= ? 
        ORDER BY created_at ASC
    ");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $totalIncomingChats = 0;
    $uniqueNumbers = [];
    $contacts = [];

    while ($row = $res->fetch_assoc()) {
        $rawNo = (string)$row['nowa'];
        $normNo = crmProspectNormalizeNumber($rawNo);
        if ($normNo === '' || str_contains($rawNo, '@g') || str_contains($rawNo, '-')) {
            continue;
        }

        $totalIncomingChats++;
        if (!isset($uniqueNumbers[$normNo])) {
            $uniqueNumbers[$normNo] = [
                'nowa' => $normNo,
                'raw_nowa' => $rawNo,
                'nama' => (string)($row['nama'] ?? ''),
                'first_chat' => (string)$row['created_at'],
                'first_message' => (string)$row['message'],
                'is_eligible_prospect' => crmIsEligibleProspect($row, $disqualified, $blocked, $conn),
            ];
        }
    }
    $stmt->close();

    $uniqueCount = count($uniqueNumbers);

    // 2. Determine which unique leads are PAID
    // Search across crm_message_history, crm_messages, log_wa, crm_automation_finance, and peserta
    $paidContacts = [];
    $nonPaidContacts = [];

    foreach ($uniqueNumbers as $normNo => $info) {
        $variants = array_values(array_unique(array_filter([
            $normNo,
            $info['raw_nowa'],
            str_starts_with($normNo, '62') ? '0' . substr($normNo, 2) : '',
            '+' . $normNo,
        ])));
        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $types = str_repeat('s', count($variants));

        $isPaid = false;
        $paidReason = '';
        $paidAt = null;

        // Check 1: crm_automation_finance status
        $qFin = $conn->prepare("SELECT status, updated_at FROM crm_automation_finance WHERE nowa IN ({$placeholders}) LIMIT 1");
        if ($qFin) {
            $qFin->bind_param($types, ...$variants);
            $qFin->execute();
            $rFin = $qFin->get_result()->fetch_assoc();
            $qFin->close();
            if ($rFin && $rFin['status'] === 'paid') {
                $isPaid = true;
                $paidReason = 'crm_automation_finance (Status Paid)';
                $paidAt = $rFin['updated_at'];
            }
        }

        // Check 2: crm_message_history containing finance message
        if (!$isPaid) {
            $qHist = $conn->prepare("
                SELECT message, sent_at 
                FROM crm_message_history 
                WHERE nowa IN ({$placeholders})
                ORDER BY id DESC
            ");
            if ($qHist) {
                $qHist->bind_param($types, ...$variants);
                $qHist->execute();
                $rHistRes = $qHist->get_result();
                while ($rHist = $rHistRes->fetch_assoc()) {
                    if (isFinanceText((string)$rHist['message'])) {
                        $isPaid = true;
                        $paidReason = 'crm_message_history (Pesan Finance Terkirim)';
                        $paidAt = $rHist['sent_at'];
                        break;
                    }
                }
                $qHist->close();
            }
        }

        // Check 3: crm_messages containing finance message
        if (!$isPaid) {
            $tCrmMsg = $conn->query("SHOW TABLES LIKE 'crm_messages'");
            if ($tCrmMsg && $tCrmMsg->num_rows > 0) {
                $qCrmMsg = $conn->prepare("
                    SELECT message, created_at 
                    FROM crm_messages 
                    WHERE nowa IN ({$placeholders}) AND direction = 'out'
                    ORDER BY id DESC
                ");
                if ($qCrmMsg) {
                    $qCrmMsg->bind_param($types, ...$variants);
                    $qCrmMsg->execute();
                    $rCrmRes = $qCrmMsg->get_result();
                    while ($rCrm = $rCrmRes->fetch_assoc()) {
                        if (isFinanceText((string)$rCrm['message'])) {
                            $isPaid = true;
                            $paidReason = 'crm_messages (Outbound Finance)';
                            $paidAt = $rCrm['created_at'];
                            break;
                        }
                    }
                    $qCrmMsg->close();
                }
            }
        }

        // Check 4: log_wa containing finance message
        if (!$isPaid) {
            $qLog = $conn->prepare("
                SELECT message, created_at 
                FROM log_wa 
                WHERE nowa IN ({$placeholders})
                ORDER BY id DESC
            ");
            if ($qLog) {
                $qLog->bind_param($types, ...$variants);
                $qLog->execute();
                $rLogRes = $qLog->get_result();
                while ($rLog = $rLogRes->fetch_assoc()) {
                    if (isFinanceText((string)$rLog['message'])) {
                        $isPaid = true;
                        $paidReason = 'log_wa (Pesan Finance)';
                        $paidAt = $rLog['created_at'];
                        break;
                    }
                }
                $qLog->close();
            }
        }

        // Check 5: peserta table
        if (!$isPaid) {
            $qPes = $conn->prepare("SELECT id, nama_lengkap FROM peserta WHERE nowa IN ({$placeholders}) LIMIT 1");
            if ($qPes) {
                $qPes->bind_param($types, ...$variants);
                $qPes->execute();
                $rPes = $qPes->get_result()->fetch_assoc();
                $qPes->close();
                if ($rPes) {
                    $isPaid = true;
                    $paidReason = 'Tabel Peserta (Terdaftar: ' . ($rPes['nama_lengkap'] ?? '') . ')';
                }
            }
        }

        if ($isPaid) {
            $paidContacts[] = [
                'nowa' => $normNo,
                'nama' => $info['nama'],
                'first_chat' => $info['first_chat'],
                'paid_reason' => $paidReason,
                'paid_at' => $paidAt
            ];
        } else {
            $nonPaidContacts[] = [
                'nowa' => $normNo,
                'nama' => $info['nama'],
                'first_chat' => $info['first_chat']
            ];
        }
    }

    // 3. Activity-based: Count total finance messages sent strictly within this date range
    $financeSentInRange = 0;
    $financeSentContacts = [];
    $qFinSent = $conn->prepare("
        SELECT nowa, message, sent_at 
        FROM crm_message_history 
        WHERE sent_at >= ? AND sent_at <= ?
    ");
    if ($qFinSent) {
        $qFinSent->bind_param('ss', $start, $end);
        $qFinSent->execute();
        $rSentRes = $qFinSent->get_result();
        while ($rSent = $rSentRes->fetch_assoc()) {
            if (isFinanceText((string)$rSent['message'])) {
                $financeSentInRange++;
                $financeSentContacts[] = $rSent['nowa'];
            }
        }
        $qFinSent->close();
    }

    $conversionRate = ($uniqueCount > 0) ? round((count($paidContacts) / $uniqueCount) * 100, 2) : 0;

    return [
        'period_label' => $label,
        'start_date' => $start,
        'end_date' => $end,
        'total_incoming_chat_events' => $totalIncomingChats,
        'unique_incoming_contacts' => $uniqueCount,
        'paid_contacts_count' => count($paidContacts),
        'unpaid_contacts_count' => count($nonPaidContacts),
        'conversion_rate_percent' => $conversionRate,
        'finance_sent_in_date_range' => $financeSentInRange,
        'unique_finance_sent_in_date_range' => count(array_unique($financeSentContacts)),
        'sample_paid_contacts' => array_slice($paidContacts, 0, 15),
    ];
}

$september = analyzePeriod($conn, '2026-09-01 00:00:00', '2026-09-24 23:59:59', '1 - 24 September 2026');
$october = analyzePeriod($conn, '2026-10-01 00:00:00', '2026-10-29 23:59:59', '1 - 29 Oktober 2026');

echo json_encode([
    'status' => 'success',
    'comparison' => [
        'september' => $september,
        'october' => $october,
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
