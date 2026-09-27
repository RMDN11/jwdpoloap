<?php
declare(strict_types=1);

$crmTitle = 'Kelola Grup';

function crmGroupManageRedirect(): never
{
    header('Location: index.php?page=manage-groups');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['manage_group_action'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!crmVerifyCsrf($csrf)) {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Sesi keamanan tidak valid. Muat ulang halaman lalu coba lagi.'];
        crmGroupManageRedirect();
    }

    if ($action === 'mass_add') {
        $data = trim((string)($_POST['data_grup'] ?? ''));
        $category = trim((string)($_POST['kategori_massal'] ?? ''));
        $category = $category !== '' ? $category : 'Tanpa Kategori';

        if ($data === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Daftar grup tidak boleh kosong.'];
            crmGroupManageRedirect();
        }

        $stmt = $conn->prepare(
            'INSERT INTO wa_grup (nama_grup, id_grup, kategori) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE nama_grup = VALUES(nama_grup), kategori = VALUES(kategori)'
        );

        if (!$stmt) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan penyimpanan grup: ' . $conn->error];
            crmGroupManageRedirect();
        }

        $added = 0;
        $updated = 0;
        $failed = 0;

        foreach (preg_split('/\R/', $data) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                $failed++;
                continue;
            }

            $name = trim($parts[0]);
            $groupId = trim($parts[1]);
            if ($name === '' || $groupId === '') {
                $failed++;
                continue;
            }

            $stmt->bind_param('sss', $name, $groupId, $category);
            if (!$stmt->execute()) {
                $failed++;
                continue;
            }

            if ($stmt->affected_rows === 1) {
                $added++;
            } else {
                $updated++;
            }
        }
        $stmt->close();

        $message = $added . ' grup ditambahkan.';
        if ($updated > 0) $message .= ' ' . $updated . ' grup diperbarui.';
        if ($failed > 0) $message .= ' ' . $failed . ' baris dilewati karena format/data tidak valid.';

        $_SESSION['crm_flash'] = [
            'type' => ($added + $updated) > 0 ? 'success' : 'error',
            'message' => $message,
        ];
        crmGroupManageRedirect();
    }

    if ($action === 'edit') {
        $id = (int)($_POST['group_id'] ?? 0);
        $name = trim((string)($_POST['nama_grup'] ?? ''));
        $groupId = trim((string)($_POST['id_grup'] ?? ''));
        $category = trim((string)($_POST['kategori'] ?? ''));
        $category = $category !== '' ? $category : 'Tanpa Kategori';

        if ($id <= 0 || $name === '' || $groupId === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Data grup tidak lengkap.'];
            crmGroupManageRedirect();
        }

        $stmt = $conn->prepare('UPDATE wa_grup SET nama_grup = ?, id_grup = ?, kategori = ? WHERE id = ?');
        if (!$stmt) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan perubahan grup: ' . $conn->error];
            crmGroupManageRedirect();
        }

        $stmt->bind_param('sssi', $name, $groupId, $category, $id);
        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        $_SESSION['crm_flash'] = [
            'type' => $ok ? 'success' : 'error',
            'message' => $ok ? 'Grup berhasil diperbarui.' : 'Gagal memperbarui grup: ' . $error,
        ];
        crmGroupManageRedirect();
    }

    if ($action === 'delete') {
        $id = (int)($_POST['group_id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'ID grup tidak valid.'];
            crmGroupManageRedirect();
        }

        $stmt = $conn->prepare('DELETE FROM wa_grup WHERE id = ?');
        if (!$stmt) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan penghapusan grup: ' . $conn->error];
            crmGroupManageRedirect();
        }

        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $error = $stmt->error;
        $stmt->close();

        $_SESSION['crm_flash'] = [
            'type' => ($ok && $affected > 0) ? 'success' : 'error',
            'message' => ($ok && $affected > 0) ? 'Grup berhasil dihapus.' : ($ok ? 'Grup tidak ditemukan.' : 'Gagal menghapus grup: ' . $error),
        ];
        crmGroupManageRedirect();
    }

    $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Aksi grup tidak dikenali.'];
    crmGroupManageRedirect();
}

$groups = [];
$result = $conn->query('SELECT id, nama_grup, id_grup, kategori FROM wa_grup ORDER BY kategori ASC, nama_grup ASC');
if ($result) {
    $groups = $result->fetch_all(MYSQLI_ASSOC);
}

$categories = [];
foreach ($groups as $group) {
    $category = trim((string)$group['kategori']) ?: 'Tanpa Kategori';
    $categories[$category] = true;
}
$categoryCount = count($categories);
?>

<div class="crm-workspace-page">
<div class="crm-workspace-back">
    <a href="index.php?page=more" title="Kembali ke More" aria-label="Kembali ke More"><i class="fa-solid fa-arrow-left"></i></a>
</div>
<section class="crm-workspace-header group-manage-page-head">
    <div>
        <span class="crm-workspace-kicker">Management</span>
        <h1>Kelola Grup</h1>
        <p>Tambah, ubah, dan rapikan daftar grup WhatsApp dari satu workspace.</p>
    </div>
    <div class="crm-workspace-stats group-manage-stats">
        <span><strong><?= count($groups) ?></strong> grup</span>
        <span><strong><?= $categoryCount ?></strong> kategori</span>
    </div>
</section>

<section class="crm-workspace-grid is-editor-left group-manage-workspace">
    <div class="crm-workspace-card group-card group-manage-import">
        <div class="crm-workspace-card-head group-card-head">
            <div>
                <span class="crm-workspace-card-kicker group-kicker">Tambah</span>
                <h2>Import Grup</h2>
            </div>
            <span class="group-manage-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
        </div>

        <div class="crm-workspace-note group-manage-note">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>Satu baris satu grup</strong>
                <span>Gunakan format <code>Nama Grup : ID Grup</code>.</span>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
            <input type="hidden" name="manage_group_action" value="mass_add">

            <label class="group-field">
                <span>Daftar Grup</span>
                <textarea name="data_grup" rows="7" required placeholder="Grup Alumni JWD : 1203630...@g.us&#10;Grup Promosi : 1203630...@g.us"></textarea>
            </label>

            <label class="group-field">
                <span>Kategori</span>
                <input type="text" name="kategori_massal" maxlength="100" placeholder="Contoh: Promosi, Internal, Alumni">
            </label>

            <button type="submit" class="group-manage-primary">
                <i class="fa-solid fa-floppy-disk"></i>
                Simpan Grup
            </button>
        </form>
    </div>

    <div class="crm-workspace-card group-card group-manage-list">
        <div class="crm-workspace-card-head group-card-head group-manage-list-head">
            <div>
                <span class="crm-workspace-card-kicker group-kicker">Library</span>
                <h2>Grup Tersimpan <small><?= count($groups) ?></small></h2>
            </div>
            <label class="group-manage-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="groupManageSearch" placeholder="Cari grup...">
            </label>
        </div>

        <?php if (!$groups): ?>
            <div class="crm-workspace-empty group-empty group-manage-empty">
                <i class="fa-solid fa-users-slash"></i>
                <strong>Belum ada grup</strong>
                <span>Tambahkan grup dari panel Import Grup.</span>
            </div>
        <?php else: ?>
            <div class="group-manage-table-wrap">
                <table class="group-manage-table">
                    <thead>
                        <tr>
                            <th>Nama Grup</th>
                            <th>ID Grup</th>
                            <th>Kategori</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="groupManageTable">
                        <?php foreach ($groups as $group): ?>
                            <?php $category = trim((string)$group['kategori']) ?: 'Tanpa Kategori'; ?>
                            <tr data-search="<?= htmlspecialchars(strtolower($group['nama_grup'] . ' ' . $group['id_grup'] . ' ' . $category), ENT_QUOTES) ?>">
                                <td>
                                    <div class="group-manage-name">
                                        <span class="group-manage-row-icon"><i class="fa-solid fa-users"></i></span>
                                        <strong><?= htmlspecialchars($group['nama_grup']) ?></strong>
                                    </div>
                                </td>
                                <td><code><?= htmlspecialchars($group['id_grup']) ?></code></td>
                                <td><span class="group-manage-category"><?= htmlspecialchars($category) ?></span></td>
                                <td>
                                    <div class="group-manage-actions">
                                        <button type="button"
                                                class="crm-icon-btn group-manage-edit"
                                                data-id="<?= (int)$group['id'] ?>"
                                                data-name="<?= htmlspecialchars($group['nama_grup'], ENT_QUOTES) ?>"
                                                data-group-id="<?= htmlspecialchars($group['id_grup'], ENT_QUOTES) ?>"
                                                data-category="<?= htmlspecialchars($category, ENT_QUOTES) ?>"
                                                title="Edit grup"
                                                aria-label="Edit grup">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <form method="post" onsubmit="return confirm('Hapus grup ini?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
                                            <input type="hidden" name="manage_group_action" value="delete">
                                            <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
                                            <button type="submit" class="crm-icon-btn crm-icon-btn-danger" title="Hapus grup" aria-label="Hapus grup">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="group-manage-no-results" id="groupManageNoResults" hidden>
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>Grup tidak ditemukan.</span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
</div>

<div class="group-manage-modal" id="groupManageModal" aria-hidden="true">
    <div class="group-manage-modal-card" role="dialog" aria-modal="true" aria-labelledby="groupManageModalTitle">
        <div class="group-card-head">
            <div>
                <span class="group-kicker">Edit</span>
                <h2 id="groupManageModalTitle">Perbarui Grup</h2>
            </div>
            <button type="button" class="crm-icon-btn" id="groupManageClose" aria-label="Tutup">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
            <input type="hidden" name="manage_group_action" value="edit">
            <input type="hidden" name="group_id" id="groupManageId">

            <label class="group-field">
                <span>Nama Grup</span>
                <input type="text" name="nama_grup" id="groupManageName" required maxlength="150">
            </label>
            <label class="group-field">
                <span>ID Grup</span>
                <input type="text" name="id_grup" id="groupManageGroupId" required maxlength="150">
            </label>
            <label class="group-field">
                <span>Kategori</span>
                <input type="text" name="kategori" id="groupManageCategory" maxlength="100">
            </label>

            <div class="group-manage-modal-actions">
                <button type="button" class="group-manage-secondary" id="groupManageCancel">Batal</button>
                <button type="submit" class="group-manage-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>



<script>
(() => {
    const search = document.getElementById('groupManageSearch');
    const rows = [...document.querySelectorAll('#groupManageTable tr')];
    const noResults = document.getElementById('groupManageNoResults');

    search?.addEventListener('input', () => {
        const query = search.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach(row => {
            const match = !query || (row.dataset.search || '').includes(query);
            row.hidden = !match;
            if (match) visible++;
        });

        if (noResults) noResults.hidden = visible > 0;
    });

    const modal = document.getElementById('groupManageModal');
    const id = document.getElementById('groupManageId');
    const name = document.getElementById('groupManageName');
    const groupId = document.getElementById('groupManageGroupId');
    const category = document.getElementById('groupManageCategory');

    function closeModal() {
        modal?.classList.remove('is-open');
        modal?.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.group-manage-edit').forEach(button => {
        button.addEventListener('click', () => {
            id.value = button.dataset.id || '';
            name.value = button.dataset.name || '';
            groupId.value = button.dataset.groupId || '';
            category.value = button.dataset.category || '';
            modal?.classList.add('is-open');
            modal?.setAttribute('aria-hidden', 'false');
            setTimeout(() => name.focus(), 30);
        });
    });

    document.getElementById('groupManageClose')?.addEventListener('click', closeModal);
    document.getElementById('groupManageCancel')?.addEventListener('click', closeModal);
    modal?.addEventListener('click', event => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeModal();
    });
})();
</script>
