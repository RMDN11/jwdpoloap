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

<section class="page-head auto-reply-page-head">
    <div>
        <span class="eyebrow">Automation</span>
        <h1>Auto Reply</h1>
        <p>Atur keyword dan balasan otomatis untuk percakapan WhatsApp.</p>
    </div>
    <div class="auto-reply-head-stats">
        <span><strong><?= $activeRules ?></strong> aktif</span>
        <span><strong><?= $totalRules ?></strong> total rule</span>
    </div>
</section>

<section class="auto-reply-workspace">
    <div class="group-card auto-reply-editor" id="autoReplyEditor">
        <div class="group-card-head">
            <div>
                <span class="group-kicker">Rule</span>
                <h2 id="autoReplyFormTitle">Tambah Rule</h2>
            </div>
            <button type="button" class="crm-icon-btn" id="autoReplyReset" title="Reset form" aria-label="Reset form">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>

        <div class="auto-reply-note">
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

            <label class="group-field">
                <span>Keyword</span>
                <input type="text" name="keyword" id="autoReplyKeyword" maxlength="120" required placeholder="Contoh: harga">
            </label>

            <label class="crm-field">
                <span>Balasan Otomatis</span>
                <textarea name="reply" id="autoReplyReply" required placeholder="Assalamu'alaikum, kak. Untuk informasi harga program..."></textarea>
            </label>

            <label class="crm-field">
                <span>Prioritas</span>
                <input type="number" name="priority" id="autoReplyPriority" min="1" max="999" value="1" required>
            </label>

            <div class="auto-reply-form-actions">
                <button type="submit" class="crm-btn crm-btn-primary" id="autoReplySubmit">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Simpan Rule</span>
                </button>
                <button type="button" class="crm-btn crm-btn-secondary" id="autoReplyCancel">Batal</button>
            </div>
        </form>
    </div>

    <div class="group-card auto-reply-list-card">
        <div class="group-card-head">
            <div>
                <span class="group-kicker">Library</span>
                <h2>Rule Tersimpan <small><?= $totalRules ?></small></h2>
            </div>
            <button type="button" class="crm-btn crm-btn-primary auto-reply-new" id="autoReplyNew">
                <i class="fa-solid fa-plus"></i>
                Rule Baru
            </button>
        </div>

        <?php if (!$rules): ?>
            <div class="auto-reply-empty">
                <div class="auto-reply-empty-icon"><i class="fa-solid fa-robot"></i></div>
                <strong>Belum ada rule auto reply</strong>
                <span>Buat rule pertama untuk merespons keyword secara otomatis.</span>
            </div>
        <?php else: ?>
            <div class="auto-reply-list">
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

<div class="auto-reply-modal" id="autoReplyTestModal" aria-hidden="true">
    <div class="auto-reply-modal-card" role="dialog" aria-modal="true" aria-labelledby="autoReplyTestTitle">
        <div class="auto-reply-modal-head">
            <div>
                <span class="group-kicker">Test Rule</span>
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
            <label class="crm-field">
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

<style>
.auto-reply-page-head{align-items:flex-end}
.auto-reply-head-stats{display:flex;gap:7px;flex-wrap:wrap}
.auto-reply-head-stats span{display:inline-flex;align-items:center;gap:4px;padding:7px 10px;border:1px solid #dfe9e2;border-radius:999px;background:#f8fbf9;color:#7a8a82;font-size:9px;font-weight:750}
.auto-reply-head-stats strong{color:#168044;font-size:11px}
.auto-reply-workspace{display:grid;grid-template-columns:minmax(280px,350px) minmax(0,1fr);gap:16px;align-items:start}
.auto-reply-editor,.auto-reply-list-card{background:#fff;border:1px solid #e2ebe5;border-radius:16px;box-shadow:0 2px 8px rgba(22,55,38,.035)}
.auto-reply-editor{padding:16px;position:sticky;top:16px}
.auto-reply-card-head,.auto-reply-list-head,.auto-reply-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.auto-reply-card-head{padding-bottom:13px;margin-bottom:13px;border-bottom:1px solid #edf2ee}
.auto-reply-kicker{display:block;color:#7b8c83;font-size:8px;font-weight:850;letter-spacing:.09em;text-transform:uppercase;margin-bottom:3px}
.auto-reply-card-head h2,.auto-reply-list-head h2,.auto-reply-modal-head h2{margin:0;color:#17251d;font-size:14px;font-weight:850;letter-spacing:-.02em}
.auto-reply-list-head h2 small{display:inline-grid;place-items:center;min-width:22px;height:20px;padding:0 6px;margin-left:4px;border-radius:999px;background:#eaf7ef;color:#168044;font-size:9px}
.auto-reply-note{display:flex;gap:9px;padding:10px 11px;margin-bottom:14px;border:1px solid #dceee3;border-radius:11px;background:#f5fbf7;color:#66776e;font-size:9px;line-height:1.5}
.auto-reply-note>i{color:#168044;margin-top:2px}.auto-reply-note strong,.auto-reply-note span{display:block}.auto-reply-note strong{color:#315044;margin-bottom:2px}
.crm-field{display:block;margin-bottom:12px}.crm-field>span{display:block;margin-bottom:6px;color:#56675e;font-size:9px;font-weight:850}
.crm-field input,.crm-field textarea{width:100%;box-sizing:border-box;border:1px solid #dfe8e2;border-radius:9px;background:#f9fbfa;color:#304238;padding:10px 11px;font:inherit;font-size:11px;outline:none;transition:.18s}
.crm-field textarea{min-height:130px;resize:vertical;line-height:1.55}.crm-field input:focus,.crm-field textarea:focus{border-color:#8bc6a2;background:#fff;box-shadow:0 0 0 3px rgba(22,128,68,.08)}
.crm-btn{border:0;border-radius:9px;min-height:36px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:10px;font-weight:850;cursor:pointer;transition:.18s}
.crm-btn-primary{background:#168044;color:#fff}.crm-btn-primary:hover{background:#126b39}.crm-btn-secondary{background:#edf2ee;color:#53645b}.crm-btn-secondary:hover{background:#e2e9e4}
.auto-reply-form-actions{display:flex;gap:7px}.auto-reply-form-actions .crm-btn-primary{flex:1}
.auto-reply-list-head{padding:14px 16px;border-bottom:1px solid #e7eee9}
.auto-reply-new{min-height:32px}
.auto-reply-list{padding:2px 16px 8px}
.auto-reply-item{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;padding:14px 0;border-bottom:1px solid #edf2ee}
.auto-reply-item:last-child{border-bottom:0}.auto-reply-item-main{min-width:0;flex:1}
.auto-reply-item-title{display:flex;gap:9px;align-items:center}.auto-reply-icon{width:33px;height:33px;flex:0 0 33px;display:grid;place-items:center;border-radius:10px;background:#eaf7ef;color:#168044}
.auto-reply-item.is-inactive .auto-reply-icon{background:#f1f3f2;color:#8c9891}
.auto-reply-item-title h3{margin:0 0 2px;color:#17251d;font-size:11px;font-weight:850}
.auto-reply-item-title div>span{color:#93a098;font-size:8px}
.auto-reply-message{margin:9px 0 0 42px;padding:9px 10px;max-height:115px;overflow:auto;border:1px solid #edf2ee;border-radius:9px;background:#fafcfb;color:#63746b;font-size:10px;line-height:1.5;white-space:normal}
.auto-reply-item-actions{display:flex;gap:5px;flex-shrink:0}.auto-reply-inline-form{margin:0}
.crm-icon-btn{width:32px;height:32px;flex:0 0 32px;border:1px solid #e0e9e3;border-radius:8px;background:#fff;color:#718078;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:.18s}
.crm-icon-btn:hover{border-color:#b7d8c2;background:#f2faf5;color:#168044}.crm-icon-btn-danger:hover{border-color:#f2c8c8;background:#fff3f3;color:#c65d5d}.auto-reply-toggle-active{color:#168044}
.auto-reply-empty{min-height:260px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:26px;text-align:center;color:#95a29b}.auto-reply-empty-icon{width:48px;height:48px;display:grid;place-items:center;margin-bottom:11px;border-radius:14px;background:#f5f8f6;color:#9aaa9f;font-size:18px}.auto-reply-empty strong{color:#4d6257;font-size:11px;margin-bottom:4px}.auto-reply-empty span{max-width:300px;font-size:9px;line-height:1.5}
.auto-reply-modal{position:fixed;inset:0;z-index:80;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,35,25,.38);opacity:0;visibility:hidden;pointer-events:none;transition:.18s}.auto-reply-modal.is-open{opacity:1;visibility:visible;pointer-events:auto}
.auto-reply-modal-card{width:min(430px,100%);padding:16px;background:#fff;border:1px solid #dfe9e2;border-radius:18px;box-shadow:0 22px 60px rgba(15,35,25,.2)}
.auto-reply-modal-head{margin-bottom:15px}.auto-reply-modal-head p{margin:3px 0 0;color:#89968f;font-size:9px}.auto-reply-test-hint{margin:-4px 0 12px;color:#8a9790;font-size:8px;line-height:1.5}
@media(max-width:820px){.auto-reply-page-head{align-items:flex-start}.auto-reply-workspace{grid-template-columns:1fr}.auto-reply-editor{position:static}.auto-reply-head-stats{margin-top:2px}}
@media(max-width:560px){.auto-reply-page-head{gap:9px}.auto-reply-head-stats span{font-size:8px}.auto-reply-item{display:block}.auto-reply-item-actions{margin:10px 0 0 42px}.auto-reply-message{margin-left:0}.auto-reply-list{padding-inline:12px}.auto-reply-list-head{padding-inline:12px}.auto-reply-editor{padding:13px}}
</style>

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


<style>
/* Auto Reply follows the shared CRM visual language used by More, Chat and Group. */
.auto-reply-page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px}
.auto-reply-page-head h1{font-size:28px;letter-spacing:-.045em}
.auto-reply-page-head p{margin:7px 0 0;color:#7a8982;font-size:12px;line-height:1.5}
.auto-reply-head-stats{display:flex;gap:7px;flex-wrap:wrap}
.auto-reply-head-stats span{display:inline-flex;align-items:center;gap:4px;padding:7px 10px;border:1px solid #e3ebe6;border-radius:999px;background:#f8faf9;color:#7a8982;font-size:9px;font-weight:750}
.auto-reply-head-stats strong{color:#168044;font-size:11px}

.auto-reply-workspace{display:grid;grid-template-columns:minmax(280px,.85fr) minmax(360px,1.15fr);gap:12px;align-items:start}
.auto-reply-editor,.auto-reply-list-card{background:#fff;border:1px solid #e4ebe6;border-radius:20px;box-shadow:0 8px 30px rgba(22,101,52,.05)}
.auto-reply-editor{padding:16px;position:sticky;top:84px}
.auto-reply-list-card{overflow:hidden}
.auto-reply-card-head{padding-bottom:14px;border-bottom:1px solid #edf1ee;margin-bottom:13px}
.auto-reply-card-head h2,.auto-reply-list-head h2,.auto-reply-modal-head h2{margin:0;color:#183d29;font-size:15px;letter-spacing:-.03em}
.auto-reply-list-head{padding:15px 16px;border-bottom:1px solid #edf1ee}
.auto-reply-list-head h2 small{display:inline-grid;place-items:center;min-width:22px;height:20px;padding:0 6px;margin-left:4px;border-radius:999px;background:#e8f7ed;color:#168044;font-size:9px}
.auto-reply-note{display:flex;gap:9px;padding:10px 11px;margin-bottom:14px;border:1px solid #dce9e1;border-radius:13px;background:#f3faf5;color:#708078;font-size:10px;line-height:1.5}
.auto-reply-note>i{color:#168044;margin-top:2px}
.auto-reply-note strong{display:block;color:#315044;margin-bottom:2px}
.auto-reply-note span{display:block}

.auto-reply-editor .group-field{display:block;margin-bottom:12px}
.auto-reply-editor .group-field>span{display:block;margin-bottom:5px;color:#596960;font-size:10px;font-weight:800}
.auto-reply-editor .group-field input,
.auto-reply-editor .group-field textarea{width:100%;box-sizing:border-box;border:1px solid #dfe8e2;border-radius:12px;background:#fbfdfc;padding:10px 11px;font:inherit;font-size:11px;color:#30473a;outline:none}
.auto-reply-editor .group-field textarea{min-height:135px;resize:vertical;line-height:1.55}
.auto-reply-editor .group-field input:focus,
.auto-reply-editor .group-field textarea:focus{border-color:#70bd8e;box-shadow:0 0 0 3px rgba(36,153,86,.08);background:#fff}

.auto-reply-form-actions{display:flex;gap:8px}
.auto-reply-form-actions .crm-btn-primary{flex:1}
.auto-reply-editor .crm-btn,
.auto-reply-list-card .crm-btn{border:0;border-radius:12px;min-height:38px;padding:0 13px;font:inherit;font-size:10px;font-weight:800;cursor:pointer}
.auto-reply-editor .crm-btn-primary,
.auto-reply-list-card .crm-btn-primary{background:#168044;color:#fff}
.auto-reply-editor .crm-btn-primary:hover,
.auto-reply-list-card .crm-btn-primary:hover{background:#126b39}
.auto-reply-editor .crm-btn-secondary{background:#edf2ee;color:#53645b}

.auto-reply-list{padding:2px 16px 8px}
.auto-reply-item{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;padding:14px 0;border-bottom:1px solid #edf1ee}
.auto-reply-item:last-child{border-bottom:0}
.auto-reply-item-main{min-width:0;flex:1}
.auto-reply-item-title{display:flex;gap:10px;align-items:center}
.auto-reply-icon{width:38px;height:38px;flex:0 0 38px;border-radius:12px;display:grid;place-items:center;background:#e8f7ed;color:#168044}
.auto-reply-item.is-inactive .auto-reply-icon{background:#f1f3f2;color:#8d9a93}
.auto-reply-item-title h3{margin:0 0 3px;color:#18352a;font-size:12px;font-weight:800}
.auto-reply-item-title div>span{color:#87958e;font-size:9px}
.auto-reply-message{margin:9px 0 0 48px;padding:11px 12px;max-height:120px;overflow:auto;border:1px solid #e7eee9;border-radius:13px;background:#f7faf8;color:#526259;font-size:11px;line-height:1.55}
.auto-reply-item-actions{display:flex;gap:5px;flex-shrink:0}
.auto-reply-inline-form{margin:0}
.auto-reply-item-actions .crm-icon-btn{width:34px;height:34px;border:1px solid #e0e9e3;border-radius:11px;background:#fff;color:#718078}
.auto-reply-item-actions .crm-icon-btn:hover{border-color:#b7d8c2;background:#f2faf5;color:#168044}
.auto-reply-item-actions .crm-icon-btn-danger:hover{border-color:#f2c8c8;background:#fff3f3;color:#c65d5d}

.auto-reply-empty{min-height:280px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px;text-align:center;color:#8a9891}
.auto-reply-empty-icon{width:48px;height:48px;display:grid;place-items:center;margin-bottom:12px;border-radius:14px;background:#edf8f1;color:#80a08d;font-size:19px}
.auto-reply-empty strong{color:#365046;font-size:13px;margin-bottom:5px}
.auto-reply-empty span{max-width:320px;font-size:11px;line-height:1.6}

.auto-reply-modal{position:fixed;inset:0;z-index:80;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,35,25,.38);backdrop-filter:blur(5px);opacity:0;visibility:hidden;pointer-events:none;transition:.18s}
.auto-reply-modal.is-open{opacity:1;visibility:visible;pointer-events:auto}
.auto-reply-modal-card{width:min(430px,100%);padding:20px;background:#fff;border:1px solid #dfe9e3;border-radius:20px;box-shadow:0 24px 70px rgba(15,35,25,.2)}
.auto-reply-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}
.auto-reply-modal-head p{margin:4px 0 0;color:#849189;font-size:10px}
.auto-reply-test-hint{margin:-4px 0 12px;color:#8a9790;font-size:9px;line-height:1.5}

@media(max-width:850px){
  .auto-reply-page-head{display:block}
  .auto-reply-head-stats{margin-top:10px}
  .auto-reply-workspace{grid-template-columns:1fr}
  .auto-reply-editor{position:static}
}
@media(max-width:560px){
  .auto-reply-page-head h1{font-size:25px}
  .auto-reply-page-head{padding:10px 4px 14px}
  .auto-reply-item{display:block}
  .auto-reply-item-actions{margin:10px 0 0 48px}
  .auto-reply-message{margin-left:0}
  .auto-reply-list{padding-inline:12px}
  .auto-reply-list-head{padding-inline:12px}
  .auto-reply-editor{padding:14px}
}
</style>