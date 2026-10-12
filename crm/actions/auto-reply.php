<?php
declare(strict_types=1);

/**
 * Process Auto Reply mutations before crm/index.php emits HTML.
 * This preserves Post/Redirect/Get and prevents a partial blank page.
 */
require_once __DIR__ . '/../../auto_reply_engine.php';

$apiUrl = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : '';
$apiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : '';
$logFile = __DIR__ . '/../../auto_reply_log.txt';
$autoReply = new AutoReplyEngine($conn, $apiUrl, $apiToken, $logFile);

function crmAutoReplyRedirect(): never
{
    header('Location: index.php?page=auto-reply', true, 303);
    exit;
}

function crmAutoReplyFlash(string $type, string $message): void
{
    $_SESSION['crm_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_reply_action'])) {
    if (!crmVerifyCsrf($_POST['csrf_token'] ?? null)) {
        crmAutoReplyFlash('error', 'Sesi formulir tidak valid. Silakan coba lagi.');
        crmAutoReplyRedirect();
    }

    $action = (string)$_POST['auto_reply_action'];

    if (in_array($action, ['add', 'edit'], true)) {
        $keyword = trim((string)($_POST['keyword'] ?? ''));
        $reply = trim((string)($_POST['reply'] ?? ''));
        $priorityRaw = trim((string)($_POST['priority'] ?? '1'));
        $priority = filter_var($priorityRaw, FILTER_VALIDATE_INT);
        $ruleId = (int)($_POST['rule_id'] ?? 0);

        if ($keyword === '' || $reply === '') {
            crmAutoReplyFlash('error', 'Keyword dan balasan wajib diisi.');
            crmAutoReplyRedirect();
        }
        if (mb_strlen($keyword) > 120) {
            crmAutoReplyFlash('error', 'Keyword maksimal 120 karakter.');
            crmAutoReplyRedirect();
        }
        if ($priority === false || $priority < 1 || $priority > 999) {
            crmAutoReplyFlash('error', 'Prioritas harus berupa angka 1 sampai 999.');
            crmAutoReplyRedirect();
        }
        if ($action === 'edit' && $ruleId <= 0) {
            crmAutoReplyFlash('error', 'ID rule tidak valid untuk diedit.');
            crmAutoReplyRedirect();
        }

        if ($action === 'add') {
            $stmt = $conn->prepare(
                'INSERT INTO auto_reply_rules (keyword, reply, priority, is_active, created_at)
                 VALUES (?, ?, ?, 1, NOW())'
            );
            if ($stmt) {
                $stmt->bind_param('ssi', $keyword, $reply, $priority);
                $ok = $stmt->execute();
                $error = $stmt->error;
                $stmt->close();
            } else {
                $ok = false;
                $error = $conn->error;
            }
            crmAutoReplyFlash($ok ? 'success' : 'error',
                $ok ? 'Rule auto reply berhasil ditambahkan.' : 'Gagal menambahkan rule: ' . $error);
        } else {
            $stmt = $conn->prepare(
                'UPDATE auto_reply_rules SET keyword = ?, reply = ?, priority = ? WHERE id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ssii', $keyword, $reply, $priority, $ruleId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $error = $stmt->error;
                $stmt->close();

                if ($ok && $affected === 0) {
                    $check = $conn->prepare('SELECT id FROM auto_reply_rules WHERE id = ? LIMIT 1');
                    $exists = false;
                    if ($check) {
                        $check->bind_param('i', $ruleId);
                        $check->execute();
                        $check->store_result();
                        $exists = $check->num_rows > 0;
                        $check->close();
                    }
                    if (!$exists) {
                        $ok = false;
                        $error = 'Rule tidak ditemukan. Mungkin sudah dihapus oleh pengguna lain.';
                    }
                }
            } else {
                $ok = false;
                $affected = 0;
                $error = $conn->error;
            }
            crmAutoReplyFlash($ok ? 'success' : 'error',
                $ok ? ($affected > 0 ? 'Rule auto reply berhasil diperbarui.' : 'Data sama, tidak ada perubahan yang diperlukan.')
                    : 'Gagal memperbarui rule: ' . $error);
        }
        crmAutoReplyRedirect();
    }

    if ($action === 'toggle') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
        if ($ruleId <= 0) {
            crmAutoReplyFlash('error', 'ID rule tidak valid.');
            crmAutoReplyRedirect();
        }
        $stmt = $conn->prepare('UPDATE auto_reply_rules SET is_active = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $status, $ruleId);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $error = $stmt->error;
            $stmt->close();
        } else {
            $ok = false;
            $affected = 0;
            $error = $conn->error;
        }
        crmAutoReplyFlash($ok ? 'success' : 'error',
            $ok ? ($affected > 0 ? 'Status rule diperbarui.' : 'Status sudah sesuai, tidak ada perubahan yang diperlukan.')
                : 'Gagal memperbarui status: ' . $error);
        crmAutoReplyRedirect();
    }

    if ($action === 'delete') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId <= 0) {
            crmAutoReplyFlash('error', 'ID rule tidak valid.');
            crmAutoReplyRedirect();
        }
        $stmt = $conn->prepare('DELETE FROM auto_reply_rules WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $ruleId);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $error = $stmt->error;
            $stmt->close();
        } else {
            $ok = false;
            $affected = 0;
            $error = $conn->error;
        }
        crmAutoReplyFlash(($ok && $affected > 0) ? 'success' : 'error',
            ($ok && $affected > 0) ? 'Rule auto reply berhasil dihapus.'
                : ($ok ? 'Rule tidak ditemukan.' : 'Gagal menghapus rule: ' . $error));
        crmAutoReplyRedirect();
    }

    if ($action === 'test') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $phone = crmNormalizeNumber((string)($_POST['test_phone'] ?? ''));
        if ($ruleId <= 0 || $phone === '') {
            crmAutoReplyFlash('error', 'Rule dan nomor WhatsApp wajib diisi.');
            crmAutoReplyRedirect();
        }
        $stmt = $conn->prepare('SELECT reply FROM auto_reply_rules WHERE id = ? LIMIT 1');
        $replyText = '';
        $found = false;
        if ($stmt) {
            $stmt->bind_param('i', $ruleId);
            $stmt->execute();
            $stmt->bind_result($replyText);
            $found = (bool)$stmt->fetch();
            $stmt->close();
        }
        if (!$found || $replyText === '') {
            crmAutoReplyFlash('error', 'Rule tidak ditemukan.');
            crmAutoReplyRedirect();
        }
        $sent = $autoReply->sendTestMessage($phone, $replyText);
        crmAutoReplyFlash($sent ? 'success' : 'error',
            $sent ? 'Test message berhasil dikirim.' : 'Gagal mengirim test message.');
        crmAutoReplyRedirect();
    }

    crmAutoReplyFlash('error', 'Aksi Auto Reply tidak dikenal.');
    crmAutoReplyRedirect();
}
