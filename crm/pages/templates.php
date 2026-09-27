<?php
declare(strict_types=1);

$crmTitle = 'Template Pesan';

$notification = '';
$notificationType = '';
if (isset($_SESSION['crm_template_flash'])) {
    $flash = $_SESSION['crm_template_flash'];
    $notification = (string)($flash['message'] ?? '');
    $notificationType = (string)($flash['type'] ?? 'success');
    unset($_SESSION['crm_template_flash']);
}

function crmTemplateRedirect(): never
{
    header('Location: index.php?page=templates');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_templates_action'])) {
    $action = (string)$_POST['manage_templates_action'];

    if ($action === 'add' || $action === 'edit') {
        $name = trim((string)($_POST['new_template_name'] ?? ''));
        $content = trim((string)($_POST['new_template_content'] ?? ''));
        $templateId = (int)($_POST['template_id'] ?? 0);

        if ($name === '' || $content === '' || ($action === 'edit' && $templateId <= 0)) {
            $_SESSION['crm_template_flash'] = [
                'type' => 'error',
                'message' => $action === 'edit' ? 'Data template tidak valid.' : 'Nama dan isi template tidak boleh kosong.',
            ];
            crmTemplateRedirect();
        }

        if ($action === 'add') {
            $stmt = $conn->prepare('INSERT INTO poloap_templates (name, content) VALUES (?, ?)');
            if ($stmt) {
                $stmt->bind_param('ss', $name, $content);
                $ok = $stmt->execute();
                $error = $stmt->error;
                $stmt->close();
            } else {
                $ok = false;
                $error = $conn->error;
            }

            $_SESSION['crm_template_flash'] = [
                'type' => $ok ? 'success' : 'error',
                'message' => $ok ? 'Template baru berhasil ditambahkan.' : 'Gagal menambahkan template: ' . $error,
            ];
        } else {
            $stmt = $conn->prepare('UPDATE poloap_templates SET name = ?, content = ? WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('ssi', $name, $content, $templateId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $error = $stmt->error;
                $stmt->close();
            } else {
                $ok = false;
                $affected = 0;
                $error = $conn->error;
            }

            $_SESSION['crm_template_flash'] = [
                'type' => $ok ? ($affected > 0 ? 'success' : 'warning') : 'error',
                'message' => $ok
                    ? ($affected > 0 ? 'Template berhasil diperbarui.' : 'Tidak ada perubahan data.')
                    : 'Gagal memperbarui template: ' . $error,
            ];
        }

        crmTemplateRedirect();
    }

    if ($action === 'delete') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId <= 0) {
            $_SESSION['crm_template_flash'] = ['type' => 'error', 'message' => 'ID template tidak valid.'];
            crmTemplateRedirect();
        }

        $stmt = $conn->prepare('DELETE FROM poloap_templates WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $templateId);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $error = $stmt->error;
            $stmt->close();
        } else {
            $ok = false;
            $affected = 0;
            $error = $conn->error;
        }

        $_SESSION['crm_template_flash'] = [
            'type' => ($ok && $affected > 0) ? 'success' : 'error',
            'message' => ($ok && $affected > 0) ? 'Template berhasil dihapus.' : ($ok ? 'Template tidak ditemukan.' : 'Gagal menghapus template: ' . $error),
        ];
        crmTemplateRedirect();
    }
}

$templates = [];
$stmt = $conn->prepare('SELECT id, name, content FROM poloap_templates ORDER BY name ASC');
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $templates[] = $row;
    }
    $stmt->close();
}
?>

<section class="crm-page-head">
    <div>
        <span class="crm-eyebrow">Workspace</span>
        <h1>Template Pesan</h1>
        <p>Kelola pesan siap pakai untuk komunikasi WhatsApp.</p>
    </div>
    <div class="crm-page-head-actions">
        <button type="button" class="crm-btn crm-btn-primary" id="newTemplateButton">
            <i class="fa-solid fa-plus"></i>
            Template Baru
        </button>
    </div>
</section>

<section class="template-workspace">
    <div class="template-form-card" id="templateFormCard">
        <div class="template-card-head">
            <div>
                <span class="template-card-kicker">Editor</span>
                <h2 id="templateFormTitle">Tambah Template</h2>
            </div>
            <button type="button" class="crm-icon-btn" id="resetTemplateButton" aria-label="Reset form" title="Reset">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>

        <div class="template-placeholder-note">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>Gunakan placeholder</strong>
                <span><code>{nama}</code> akan diganti dengan nama kontak saat digunakan.</span>
            </div>
        </div>

        <form method="post" id="templateForm">
            <input type="hidden" name="manage_templates_action" id="templateAction" value="add">
            <input type="hidden" name="template_id" id="templateId" value="">

            <label class="crm-field">
                <span>Nama Template</span>
                <input type="text" name="new_template_name" id="templateName" required maxlength="150" placeholder="Contoh: Follow Up Prospek">
            </label>

            <label class="crm-field">
                <span>Isi Pesan</span>
                <textarea name="new_template_content" id="templateContent" required placeholder="Assalamu'alaikum {nama}, ..."></textarea>
            </label>

            <div class="template-form-actions">
                <button type="submit" class="crm-btn crm-btn-primary" id="templateSubmit">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Simpan Template</span>
                </button>
                <button type="button" class="crm-btn crm-btn-secondary" id="cancelTemplateButton">Batal</button>
            </div>
        </form>
    </div>

    <div class="template-list-card">
        <div class="template-list-head">
            <div>
                <span class="template-card-kicker">Library</span>
                <h2>Template Tersimpan <small><?= count($templates) ?></small></h2>
            </div>
            <div class="template-list-hint">Klik salin untuk menggunakan isi pesan.</div>
        </div>

        <?php if (!$templates): ?>
            <div class="template-empty">
                <div class="template-empty-icon"><i class="fa-regular fa-file-lines"></i></div>
                <strong>Belum ada template</strong>
                <span>Buat template pertama untuk mempercepat komunikasi WhatsApp.</span>
            </div>
        <?php else: ?>
            <div class="template-list">
                <?php foreach ($templates as $template): ?>
                    <article class="template-item">
                        <div class="template-item-main">
                            <div class="template-item-title">
                                <span class="template-item-icon"><i class="fa-regular fa-file-lines"></i></span>
                                <div>
                                    <h3><?= htmlspecialchars((string)$template['name']) ?></h3>
                                    <span>ID #<?= (int)$template['id'] ?></span>
                                </div>
                            </div>
                            <div class="template-content"><?= nl2br(htmlspecialchars((string)$template['content'])) ?></div>
                        </div>

                        <div class="template-item-actions">
                            <button type="button"
                                    class="crm-icon-btn template-copy"
                                    data-content="<?= htmlspecialchars((string)$template['content'], ENT_QUOTES) ?>"
                                    title="Salin template"
                                    aria-label="Salin template">
                                <i class="fa-regular fa-copy"></i>
                            </button>

                            <button type="button"
                                    class="crm-icon-btn template-edit"
                                    data-id="<?= (int)$template['id'] ?>"
                                    data-name="<?= htmlspecialchars((string)$template['name'], ENT_QUOTES) ?>"
                                    data-content="<?= htmlspecialchars((string)$template['content'], ENT_QUOTES) ?>"
                                    title="Edit template"
                                    aria-label="Edit template">
                                <i class="fa-solid fa-pen"></i>
                            </button>

                            <form method="post" class="template-delete-form" onsubmit="return confirm('Hapus template ini?');">
                                <input type="hidden" name="manage_templates_action" value="delete">
                                <input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>">
                                <button type="submit" class="crm-icon-btn crm-icon-btn-danger" title="Hapus template" aria-label="Hapus template">
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

<style>
.template-workspace {
    display: grid;
    grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
    gap: 18px;
    align-items: start;
}
.template-form-card,
.template-list-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .05);
}
.template-form-card {
    padding: 18px;
    position: sticky;
    top: 18px;
}
.template-card-head,
.template-list-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.template-card-head {
    padding-bottom: 14px;
    border-bottom: 1px solid #f1f5f9;
    margin-bottom: 14px;
}
.template-card-kicker {
    display: block;
    color: #64748b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 3px;
}
.template-card-head h2,
.template-list-head h2 {
    margin: 0;
    color: #0f172a;
    font-size: 15px;
    font-weight: 800;
}
.template-list-head h2 small {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 22px;
    padding: 0 7px;
    margin-left: 5px;
    border-radius: 999px;
    background: #eff6ff;
    color: #2563eb;
    font-size: 10px;
}
.template-placeholder-note {
    display: flex;
    gap: 10px;
    padding: 11px 12px;
    margin-bottom: 16px;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    background: #eff6ff;
    color: #475569;
    font-size: 11px;
    line-height: 1.5;
}
.template-placeholder-note > i {
    color: #2563eb;
    margin-top: 2px;
}
.template-placeholder-note strong,
.template-placeholder-note span {
    display: block;
}
.template-placeholder-note strong {
    color: #1e3a8a;
    margin-bottom: 2px;
}
.template-placeholder-note code {
    padding: 1px 5px;
    border-radius: 5px;
    background: #dbeafe;
    color: #1d4ed8;
    font-weight: 800;
}
.crm-field {
    display: block;
    margin-bottom: 14px;
}
.crm-field > span {
    display: block;
    margin-bottom: 6px;
    color: #475569;
    font-size: 11px;
    font-weight: 800;
}
.crm-field input,
.crm-field textarea {
    width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    background: #f8fafc;
    color: #334155;
    padding: 10px 11px;
    font: inherit;
    font-size: 12px;
    outline: none;
    transition: .2s;
    box-sizing: border-box;
}
.crm-field textarea {
    min-height: 150px;
    resize: vertical;
    line-height: 1.55;
}
.crm-field input:focus,
.crm-field textarea:focus {
    border-color: #3b82f6;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, .1);
}
.crm-btn {
    border: 0;
    border-radius: 9px;
    min-height: 38px;
    padding: 0 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
    transition: .2s;
}
.crm-btn-primary {
    background: #2563eb;
    color: #fff;
}
.crm-btn-primary:hover {
    background: #1d4ed8;
}
.crm-btn-secondary {
    background: #f1f5f9;
    color: #475569;
}
.crm-btn-secondary:hover {
    background: #e2e8f0;
}
.template-form-actions {
    display: flex;
    gap: 8px;
}
.template-form-actions .crm-btn-primary {
    flex: 1;
}
.crm-icon-btn {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: .2s;
}
.crm-icon-btn:hover {
    border-color: #bfdbfe;
    background: #eff6ff;
    color: #2563eb;
}
.crm-icon-btn-danger:hover {
    border-color: #fecdd3;
    background: #fff1f2;
    color: #e11d48;
}
.template-list-head {
    padding: 16px 18px;
    border-bottom: 1px solid #e2e8f0;
}
.template-list-hint {
    color: #94a3b8;
    font-size: 10px;
}
.template-list {
    padding: 4px 18px 8px;
}
.template-item {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    justify-content: space-between;
    padding: 16px 0;
    border-bottom: 1px solid #f1f5f9;
}
.template-item:last-child {
    border-bottom: 0;
}
.template-item-main {
    min-width: 0;
    flex: 1;
}
.template-item-title {
    display: flex;
    gap: 10px;
    align-items: center;
}
.template-item-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: #eff6ff;
    color: #3b82f6;
}
.template-item-title h3 {
    margin: 0 0 2px;
    color: #0f172a;
    font-size: 12px;
    font-weight: 800;
}
.template-item-title span:not(.template-item-icon) {
    color: #94a3b8;
    font-size: 9px;
}
.template-content {
    margin: 10px 0 0 44px;
    padding: 10px 11px;
    max-height: 120px;
    overflow: auto;
    border: 1px solid #f1f5f9;
    border-radius: 8px;
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    line-height: 1.55;
    white-space: normal;
}
.template-item-actions {
    display: flex;
    gap: 5px;
    flex-shrink: 0;
}
.template-delete-form {
    margin: 0;
}
.template-empty {
    min-height: 260px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px;
    text-align: center;
    color: #94a3b8;
}
.template-empty-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 12px;
    border-radius: 14px;
    background: #f8fafc;
    color: #94a3b8;
    font-size: 19px;
}
.template-empty strong {
    color: #475569;
    font-size: 12px;
    margin-bottom: 4px;
}
.template-empty span {
    max-width: 300px;
    font-size: 10px;
    line-height: 1.5;
}
@media (max-width: 820px) {
    .template-workspace {
        grid-template-columns: 1fr;
    }
    .template-form-card {
        position: static;
    }
    .template-list-hint {
        display: none;
    }
}
@media (max-width: 560px) {
    .template-item {
        display: block;
    }
    .template-item-actions {
        margin: 10px 0 0 44px;
    }
    .template-content {
        margin-left: 0;
    }
    .template-item-title {
        align-items: flex-start;
    }
}
</style>

<script>
(() => {
    const form = document.getElementById('templateForm');
    const formTitle = document.getElementById('templateFormTitle');
    const action = document.getElementById('templateAction');
    const id = document.getElementById('templateId');
    const name = document.getElementById('templateName');
    const content = document.getElementById('templateContent');
    const submit = document.getElementById('templateSubmit');
    const formCard = document.getElementById('templateFormCard');

    function resetTemplateForm() {
        form.reset();
        action.value = 'add';
        id.value = '';
        formTitle.textContent = 'Tambah Template';
        submit.querySelector('span').textContent = 'Simpan Template';
    }

    document.getElementById('newTemplateButton')?.addEventListener('click', () => {
        resetTemplateForm();
        formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        name.focus();
    });

    document.getElementById('resetTemplateButton')?.addEventListener('click', resetTemplateForm);
    document.getElementById('cancelTemplateButton')?.addEventListener('click', resetTemplateForm);

    document.querySelectorAll('.template-edit').forEach(button => {
        button.addEventListener('click', () => {
            action.value = 'edit';
            id.value = button.dataset.id || '';
            name.value = button.dataset.name || '';
            content.value = button.dataset.content || '';
            formTitle.textContent = 'Edit Template';
            submit.querySelector('span').textContent = 'Update Template';
            formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            name.focus();
        });
    });

    document.querySelectorAll('.template-copy').forEach(button => {
        button.addEventListener('click', async () => {
            const value = button.dataset.content || '';
            try {
                await navigator.clipboard.writeText(value);
                const original = button.innerHTML;
                button.innerHTML = '<i class="fa-solid fa-check"></i>';
                button.title = 'Tersalin';
                setTimeout(() => {
                    button.innerHTML = original;
                    button.title = 'Salin template';
                }, 1400);
            } catch (error) {
                window.prompt('Salin template secara manual:', value);
            }
        });
    });
})();
</script>
