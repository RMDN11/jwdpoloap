<?php
declare(strict_types=1);

$crmTitle = 'Auto Reply';

require_once __DIR__ . '/../../auto_reply_engine.php';

$apiUrl = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : '';
$apiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : '';
$logFile = __DIR__ . '/../../auto_reply_log.txt';
$autoReply = new AutoReplyEngine($conn, $apiUrl, $apiToken, $logFile);

function crmAutoReplyRedirect(): never
{
    header('Location: index.php?page=auto-reply');
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
        $priority = max(1, (int)($_POST['priority'] ?? 1));
        $ruleId = (int)($_POST['rule_id'] ?? 0);

        if ($keyword === '' || $reply === '' || ($action === 'edit' && $ruleId <= 0)) {
            crmAutoReplyFlash('error', 'Keyword dan balasan wajib diisi.');
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

            crmAutoReplyFlash(
                $ok ? 'success' : 'error',
                $ok ? 'Rule auto reply berhasil ditambahkan.' : 'Gagal menambahkan rule: ' . $error
            );
        } else {
            $stmt = $conn->prepare(
                'UPDATE auto_reply_rules
                 SET keyword = ?, reply = ?, priority = ?
                 WHERE id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ssii', $keyword, $reply, $priority, $ruleId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $error = $stmt->error;
                $stmt->close();
            } else {
                $ok = false;
                $affected = 0;
                $error = $conn->error;
            }

            crmAutoReplyFlash(
                $ok ? ($affected > 0 ? 'success' : 'warning') : 'error',
                $ok
                    ? ($affected > 0 ? 'Rule auto reply berhasil diperbarui.' : 'Tidak ada perubahan data.')
                    : 'Gagal memperbarui rule: ' . $error
            );
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

        crmAutoReplyFlash(
            $ok ? 'success' : 'error',
            $ok
                ? ($affected > 0 ? 'Status rule diperbarui.' : 'Rule tidak ditemukan atau status sudah sama.')
                : 'Gagal memperbarui status: ' . $error
        );
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

        crmAutoReplyFlash(
            ($ok && $affected > 0) ? 'success' : 'error',
            ($ok && $affected > 0)
                ? 'Rule auto reply berhasil dihapus.'
                : ($ok ? 'Rule tidak ditemukan.' : 'Gagal menghapus rule: ' . $error)
        );
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
        crmAutoReplyFlash(
            $sent ? 'success' : 'error',
            $sent ? 'Test message berhasil dikirim.' : 'Gagal mengirim test message.'
        );
        crmAutoReplyRedirect();
    }
}

$rules = [];
$result = $conn->query(
    'SELECT id, keyword, reply, priority, is_active, created_at
     FROM auto_reply_rules
     ORDER BY priority DESC, id DESC'
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rules[] = $row;
    }
    $result->free();
}

$totalRules = count($rules);
$activeRules = 0;
foreach ($rules as $rule) {
    if ((int)$rule['is_active'] === 1) {
        $activeRules++;
    }
}
?>

<div class="crm-workspace-page">
<div class="crm-workspace-back">
    <a href="index.php?page=more" title="Kembali ke More" aria-label="Kembali ke More"><i class="fa-solid fa-arrow-left"></i></a>
</div>

<section class="crm-workspace-header auto-reply-peserta-head">
    <div class="crm-workspace-header-main">
        <span class="crm-workspace-kicker">Automation</span>
        <h1>Auto Reply</h1>
        <p>Atur keyword dan balasan otomatis untuk percakapan WhatsApp.</p>
    </div>
    <div class="crm-workspace-stats auto-reply-head-stats">
        <span class="crm-workspace-stat"><strong><?= $activeRules ?></strong> aktif</span>
        <span class="crm-workspace-stat"><strong><?= $totalRules ?></strong> total rule</span>
    </div>
</section>

<section class="crm-workspace-grid is-editor-left auto-reply-workspace">
    <div class="crm-workspace-card auto-reply-editor is-sticky" id="autoReplyEditor">
        <div class="crm-workspace-card-head">
            <div>
                <span class="crm-workspace-card-kicker">Rule</span>
                <h2 id="autoReplyFormTitle">Tambah Rule</h2>
            </div>
            <button type="button" class="crm-icon-btn" id="autoReplyReset" title="Reset form" aria-label="Reset form">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>

        <div class="crm-workspace-note auto-reply-note">
            <i class="fa-solid fa-robot"></i>
            <div>
                <strong>Keyword → Balasan</strong>
                <span>Gunakan keyword yang mudah dikenali dan prioritaskan rule yang lebih spesifik.</span>
            </div>
        </div>

        <form method="post" id="autoReplyForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
            <input type="hidden" name="auto_reply_action" id="autoReplyAction" value="add">
            <input type="hidden" name="rule_id" id="autoReplyRuleId" value="">

            <label class="crm-workspace-field">
                <span>Keyword</span>
                <input type="text" name="keyword" id="autoReplyKeyword" maxlength="120" required placeholder="Contoh: harga">
            </label>

            <label class="crm-workspace-field">
                <span>Balasan Otomatis</span>
                <textarea name="reply" id="autoReplyReply" required placeholder="Assalamu'alaikum, kak. Untuk informasi harga program..."></textarea>
            </label>

            <label class="crm-workspace-field">
                <span>Prioritas</span>
                <input type="number" name="priority" id="autoReplyPriority" min="1" max="999" value="1" required>
            </label>

            <div class="crm-workspace-actions auto-reply-form-actions">
                <button type="submit" class="crm-btn crm-btn-primary" id="autoReplySubmit">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Simpan Rule</span>
                </button>
                <button type="button" class="crm-btn crm-btn-secondary" id="autoReplyCancel">Batal</button>
            </div>
        </form>
    </div>

    <div class="crm-workspace-card auto-reply-list-card">
        <div class="crm-workspace-card-head">
            <div>
                <span class="crm-workspace-card-kicker">Library</span>
                <h2>Rule Tersimpan <small><?= $totalRules ?></small></h2>
            </div>
            <button type="button" class="crm-btn crm-btn-primary auto-reply-new" id="autoReplyNew">
                <i class="fa-solid fa-plus"></i>
                Rule Baru
            </button>
        </div>

        <?php if (!$rules): ?>
            <div class="crm-workspace-empty auto-reply-empty">
                <div class="crm-workspace-empty-icon auto-reply-empty-icon"><i class="fa-solid fa-robot"></i></div>
                <strong>Belum ada rule auto reply</strong>
                <span>Buat rule pertama untuk merespons keyword secara otomatis.</span>
            </div>
        <?php else: ?>
            <div class="crm-workspace-list auto-reply-list">
                <?php foreach ($rules as $rule): ?>
                    <?php $active = (int)$rule['is_active'] === 1; ?>
                    <article class="auto-reply-item <?= $active ? 'is-active' : 'is-inactive' ?>">
                        <div class="auto-reply-item-main">
                            <div class="auto-reply-item-title">
                                <span class="auto-reply-icon"><i class="fa-solid fa-robot"></i></span>
                                <div>
                                    <h3><?= htmlspecialchars((string)$rule['keyword']) ?></h3>
                                    <span>
                                        Prioritas <?= (int)$rule['priority'] ?>
                                        · <?= $active ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </div>
                            </div>
                            <div class="auto-reply-message"><?= nl2br(htmlspecialchars((string)$rule['reply'])) ?></div>
                        </div>

                        <div class="auto-reply-item-actions">
                            <button type="button"
                                    class="crm-icon-btn auto-reply-test"
                                    data-id="<?= (int)$rule['id'] ?>"
                                    data-keyword="<?= htmlspecialchars((string)$rule['keyword'], ENT_QUOTES) ?>"
                                    title="Test rule"
                                    aria-label="Test rule">
                                <i class="fa-solid fa-paper-plane"></i>
                            </button>

                            <button type="button"
                                    class="crm-icon-btn auto-reply-edit"
                                    data-id="<?= (int)$rule['id'] ?>"
                                    data-keyword="<?= htmlspecialchars((string)$rule['keyword'], ENT_QUOTES) ?>"
                                    data-reply="<?= htmlspecialchars((string)$rule['reply'], ENT_QUOTES) ?>"
                                    data-priority="<?= (int)$rule['priority'] ?>"
                                    title="Edit rule"
                                    aria-label="Edit rule">
                                <i class="fa-solid fa-pen"></i>
                            </button>

                            <form method="post" class="auto-reply-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
                                <input type="hidden" name="auto_reply_action" value="toggle">
                                <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                <input type="hidden" name="status" value="<?= $active ? '0' : '1' ?>">
                                <button type="submit" class="crm-icon-btn <?= $active ? 'auto-reply-toggle-active' : '' ?>"
                                        title="<?= $active ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                        aria-label="<?= $active ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                    <i class="fa-solid <?= $active ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                </button>
                            </form>

                            <form method="post" class="auto-reply-inline-form" onsubmit="return confirm('Hapus rule auto reply ini?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
                                <input type="hidden" name="auto_reply_action" value="delete">
                                <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                <button type="submit" class="crm-icon-btn crm-icon-btn-danger" title="Hapus rule" aria-label="Hapus rule">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
</div>

<div class="auto-reply-modal" id="autoReplyTestModal" aria-hidden="true">
    <div class="auto-reply-modal-card" role="dialog" aria-modal="true" aria-labelledby="autoReplyTestTitle">
        <div class="auto-reply-modal-head">
            <div>
                <span class="crm-workspace-card-kicker">Test Rule</span>
                <h2 id="autoReplyTestTitle">Kirim test message</h2>
                <p id="autoReplyTestKeyword"></p>
            </div>
            <button type="button" class="crm-icon-btn" id="autoReplyTestClose" aria-label="Tutup">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
            <input type="hidden" name="auto_reply_action" value="test">
            <input type="hidden" name="rule_id" id="autoReplyTestRuleId" value="">
            <label class="crm-workspace-field">
                <span>Nomor WhatsApp</span>
                <input type="tel" name="test_phone" id="autoReplyTestPhone" inputmode="numeric" required placeholder="08xxxxxxxxxx">
            </label>
            <p class="auto-reply-test-hint">Nomor akan dinormalisasi ke format WhatsApp Indonesia sebelum dikirim.</p>
            <div class="auto-reply-form-actions">
                <button type="submit" class="crm-btn crm-btn-primary">
                    <i class="fa-solid fa-paper-plane"></i>
                    Kirim Test
                </button>
                <button type="button" class="crm-btn crm-btn-secondary" id="autoReplyTestCancel">Batal</button>
            </div>
        </form>
    </div>
</div>



<script>
(() => {
    const form = document.getElementById('autoReplyForm');
    const title = document.getElementById('autoReplyFormTitle');
    const action = document.getElementById('autoReplyAction');
    const id = document.getElementById('autoReplyRuleId');
    const keyword = document.getElementById('autoReplyKeyword');
    const reply = document.getElementById('autoReplyReply');
    const priority = document.getElementById('autoReplyPriority');
    const submitText = document.querySelector('#autoReplySubmit span');
    const editor = document.getElementById('autoReplyEditor');

    function resetForm() {
        form.reset();
        action.value = 'add';
        id.value = '';
        priority.value = '1';
        title.textContent = 'Tambah Rule';
        submitText.textContent = 'Simpan Rule';
    }

    document.getElementById('autoReplyNew')?.addEventListener('click', () => {
        resetForm();
        editor.scrollIntoView({behavior:'smooth', block:'start'});
        keyword.focus();
    });
    document.getElementById('autoReplyReset')?.addEventListener('click', resetForm);
    document.getElementById('autoReplyCancel')?.addEventListener('click', resetForm);

    document.querySelectorAll('.auto-reply-edit').forEach(button => {
        button.addEventListener('click', () => {
            action.value = 'edit';
            id.value = button.dataset.id || '';
            keyword.value = button.dataset.keyword || '';
            reply.value = button.dataset.reply || '';
            priority.value = button.dataset.priority || '1';
            title.textContent = 'Edit Rule';
            submitText.textContent = 'Update Rule';
            editor.scrollIntoView({behavior:'smooth', block:'start'});
            keyword.focus();
        });
    });

    const modal = document.getElementById('autoReplyTestModal');
    const modalId = document.getElementById('autoReplyTestRuleId');
    const modalPhone = document.getElementById('autoReplyTestPhone');
    const modalKeyword = document.getElementById('autoReplyTestKeyword');

    function closeTestModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.auto-reply-test').forEach(button => {
        button.addEventListener('click', () => {
            modalId.value = button.dataset.id || '';
            modalKeyword.textContent = 'Keyword: ' + (button.dataset.keyword || '');
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(() => modalPhone.focus(), 40);
        });
    });

    document.getElementById('autoReplyTestClose')?.addEventListener('click', closeTestModal);
    document.getElementById('autoReplyTestCancel')?.addEventListener('click', closeTestModal);
    modal?.addEventListener('click', event => {
        if (event.target === modal) closeTestModal();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeTestModal();
    });
})();
</script>


