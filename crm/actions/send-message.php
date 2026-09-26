<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/prospect.php';\nrequire_once __DIR__ . '/../config/chat.php';
$disqualified = crmGetDisqualifiedNumbers($conn);
$blocked = crmGetBlockedNumbers($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$contactId = trim((string)($_POST['contact_id'] ?? ''));
$templateId = (int)($_POST['template_id'] ?? 0);
$customMessage = trim((string)($_POST['custom_message'] ?? ''));

if ($contactId === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak tidak ditemukan.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$normalizedContact = crmProspectNormalizeNumber($contactId);
$contact = crmFindEligibleProspectByNumber($conn, $contactId, $disqualified, $blocked);

if (!$contact) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Kontak tidak ditemukan atau sudah tidak eligible untuk follow-up.'];
    header('Location: ../index.php?page=chat');
    exit;
}

$templateName = '';
$messageTemplate = '';

if ($customMessage !== '') {
    $messageTemplate = $customMessage;
    $templateName = 'Pesan Custom Langsung';

    
} elseif ($templateId > 0) {
    $templateStmt = $conn->prepare("SELECT name, content FROM poloap_templates WHERE id = ? LIMIT 1");
    $templateStmt->bind_param('i', $templateId);
    $templateStmt->execute();
    $template = $templateStmt->get_result()->fetch_assoc();

    $messageTemplate = (string)($template['content'] ?? '');
    $templateName = (string)($template['name'] ?? '');
}

if ($messageTemplate === '') {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pilih template atau isi pesan custom.'];
    header('Location: ../index.php?page=chat&contact=' . urlencode($contactId));
    exit;
}

$name = trim((string)($contact['nama'] ?? 'Kak'));
$incomingMessage = (string)($contact['message'] ?? '');

if (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?\s*\(/i', $incomingMessage, $m)) {
    $name = trim($m[1]);
} elseif (preg_match('/nama saya\s+\*?([^\*\(\n]+)\*?/i', $incomingMessage, $m)) {
    $name = trim($m[1]);
}
if ($name === '' || strtolower($name) === 'kak') $name = 'Kak';

$message = str_ireplace(['[nama]', '[NAMA]', '{nama}', '{NAMA}'], $name, $messageTemplate);
$message = preg_replace('/ {2,}/', ' ', $message);

$number = preg_replace('/\D+/', '', $contactId);
if (str_starts_with($number, '0')) $number = '62' . substr($number, 1);

$payload = json_encode([
    'recipient_type' => 'individual',
    'to' => $number,
    'type' => 'text',
    'text' => ['body' => $message],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode < 200 || $httpCode >= 300) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => $curlError ?: 'Pesan gagal dikirim. API mengembalikan kode ' . $httpCode . '.'];
    header('Location: ../index.php?page=chat&contact=' . urlencode($contactId));
    exit;
}

$historyStmtLegacy = $conn->prepare("SELECT template_history FROM log_wa WHERE nowa = ? OR nowa = ? ORDER BY id DESC LIMIT 1");
$historyStmtLegacy->bind_param('ss', $contact['nowa'], $normalizedContact);
$historyStmtLegacy->execute();
$legacyRow = $historyStmtLegacy->get_result()->fetch_assoc();
$historyStmtLegacy->close();

$oldHistory = (string)($legacyRow['template_history'] ?? '');
$historyEntry = date('d/m/Y H:i') . ' - ' . $templateName;
$newHistory = $oldHistory !== '' ? $oldHistory . '|||' . $historyEntry : $historyEntry;
$isForm = (stripos($messageTemplate, 'penempatan halaqoh') !== false || stripos($messageTemplate, 'silahkan isi link form berikut') !== false || stripos($messageTemplate, 'silakan isi link form berikut') !== false) ? 1 : 0;

$updateOk = false;
$update = $conn->prepare("UPDATE log_wa SET last_followup_at = NOW(), is_form_sent = GREATEST(is_form_sent, ?), last_template_name = ?, template_history = ? WHERE nowa = ? OR nowa = ?");
if ($update) {
    $update->bind_param('issss', $isForm, $templateName, $newHistory, $contact['nowa'], $normalizedContact);
    $updateOk = $update->execute();
    $update->close();
}

$historyOk = false;
$historyStmt = $conn->prepare("INSERT INTO crm_message_history (nowa, nama, template_id, template_name, message, sent_at, status) VALUES (?, ?, NULLIF(?, 0), ?, ?, NOW(), 'sent')");
if ($historyStmt) {
    $historyStmt->bind_param('ssiss', $contact['nowa'], $name, $templateId, $templateName, $message);
    $historyOk = $historyStmt->execute();
    $historyStmt->close();
}

// Phase 2: simpan pesan outbound ke conversation layer tanpa memutus flow legacy.
$chatMessageId = null;
try {
    $chatMessageId = crmChatStoreMessage(
        $conn,
        $contact['nowa'],
        $name,
        $message,
        'out',
        'admin',
        'crm',
        date('Y-m-d H:i:s'),
        null,
        $templateId > 0 ? $templateId : null,
        $templateName
    );

    if ($chatMessageId !== null && $chatMessageId !== 0 && crmChatTablesReady($conn)) {
        $conversationIdStmt = $conn->prepare("SELECT id FROM crm_conversations WHERE nowa = ? LIMIT 1");
        if ($conversationIdStmt) {
            $conversationIdStmt->bind_param('s', $normalizedContact);
            $conversationIdStmt->execute();
            $conversation = $conversationIdStmt->get_result()->fetch_assoc();
            $conversationIdStmt->close();

            if ($conversation) {
                $followupStmt = $conn->prepare(
                    "INSERT INTO crm_followups (conversation_id, message_id, template_id, template_name, sent_at, status)
                     VALUES (?, ?, NULLIF(?, 0), ?, NOW(), 'sent')"
                );
                if ($followupStmt) {
                    $followupTemplateId = $templateId > 0 ? $templateId : 0;
                    $followupStmt->bind_param(
                        'iiis',
                        $conversation['id'],
                        $chatMessageId,
                        $followupTemplateId,
                        $templateName
                    );
                    $followupStmt->execute();
                    $followupStmt->close();
                    crmChatRefreshFollowupCount($conn, (int)$conversation['id']);
                }
            }
        }
    }
} catch (Throwable $e) {
    // Conversation layer is additive during migration. Legacy send/history remain authoritative.
}

if (!$historyOk) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pesan terkirim ke ' . $name . ', tetapi riwayat CRM gagal disimpan.'];
} elseif (!$updateOk) {
    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pesan terkirim ke ' . $name . ', tetapi status follow-up gagal diperbarui.'];
} else {
    $_SESSION['crm_flash'] = ['type' => 'success', 'message' => 'Pesan berhasil dikirim ke ' . $name . '.'];
}
header('Location: ../index.php?page=chat&contact=' . urlencode($contactId));
exit;
