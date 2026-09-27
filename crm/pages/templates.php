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

<div class="crm-workspace-page">
<div class="crm-workspace-back">
    <a href="index.php?page=more" title="Kembali ke More" aria-label="Kembali ke More"><i class="fa-solid fa-arrow-left"></i></a>
</div>
<section class="crm-workspace-header">
    <div class="crm-workspace-header-main">
        <span class="crm-workspace-kicker">Workspace</span>
        <h1>Template Pesan</h1>
        <p>Kelola pesan siap pakai untuk komunikasi WhatsApp.</p>
    </div>
    <div class="crm-workspace-stats">
        <button type="button" class="crm-btn crm-btn-primary" id="newTemplateButton">
            <i class="fa-solid fa-plus"></i>
            Template Baru
        </button>
    </div>
</section>

<section class="crm-workspace-grid is-editor-left template-workspace">
    <div class="crm-workspace-card crm-workspace-card-body is-sticky template-form-card" id="templateFormCard">
        <div class="crm-workspace-card-head template-card-head">
            <div>
                <span class="crm-workspace-card-kicker template-card-kicker">Editor</span>
                <h2 id="templateFormTitle">Tambah Template</h2>
            </div>
            <button type="button" class="crm-icon-btn" id="resetTemplateButton" aria-label="Reset form" title="Reset">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>

        <div class="crm-workspace-note template-placeholder-note">
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

    <div class="crm-workspace-card template-list-card">
        <div class="crm-workspace-card-head template-list-head">
            <div>
                <span class="crm-workspace-card-kicker template-card-kicker">Library</span>
                <h2>Template Tersimpan <small><?= count($templates) ?></small></h2>
            </div>
            <div class="template-list-hint">Klik salin untuk menggunakan isi pesan.</div>
        </div>

        <?php if (!$templates): ?>
            <div class="crm-workspace-empty template-empty">
                <div class="crm-workspace-empty-icon template-empty-icon"><i class="fa-regular fa-file-lines"></i></div>
                <strong>Belum ada template</strong>
                <span>Buat template pertama untuk mempercepat komunikasi WhatsApp.</span>
            </div>
        <?php else: ?>
            <div class="crm-workspace-list template-list">
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
