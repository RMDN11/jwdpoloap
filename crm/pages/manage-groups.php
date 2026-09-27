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

<section class="page-head group-manage-page-head">
    <div>
        <span class="eyebrow">Management</span>
        <h1>Kelola Grup</h1>
        <p>Tambah, ubah, dan rapikan daftar grup WhatsApp dari satu workspace.</p>
    </div>
    <div class="group-manage-stats">
        <span><strong><?= count($groups) ?></strong> grup</span>
        <span><strong><?= $categoryCount ?></strong> kategori</span>
    </div>
</section>

<section class="group-manage-workspace">
    <div class="group-card group-manage-import">
        <div class="group-card-head">
            <div>
                <span class="group-kicker">Tambah</span>
                <h2>Import Grup</h2>
            </div>
            <span class="group-manage-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
        </div>

        <div class="group-manage-note">
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

    <div class="group-card group-manage-list">
        <div class="group-card-head group-manage-list-head">
            <div>
                <span class="group-kicker">Library</span>
                <h2>Grup Tersimpan <small><?= count($groups) ?></small></h2>
            </div>
            <label class="group-manage-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="groupManageSearch" placeholder="Cari grup...">
            </label>
        </div>

        <?php if (!$groups): ?>
            <div class="group-empty group-manage-empty">
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

<style>
.group-manage-page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px}
.group-manage-page-head h1{font-size:28px;letter-spacing:-.045em}
.group-manage-page-head p{margin:7px 0 0;color:#7a8982;font-size:12px;line-height:1.5}
.group-manage-stats{display:flex;gap:7px;flex-wrap:wrap}
.group-manage-stats span{display:inline-flex;align-items:center;gap:4px;padding:7px 10px;border:1px solid #e3ebe6;border-radius:999px;background:#f8faf9;color:#7a8982;font-size:9px;font-weight:750}
.group-manage-stats strong{color:#168044;font-size:11px}

.group-manage-workspace{display:grid;grid-template-columns:minmax(280px,350px) minmax(0,1fr);gap:12px;align-items:start}
.group-manage-workspace .group-card{background:#fff;border:1px solid #e4ebe6;border-radius:20px;box-shadow:0 8px 30px rgba(22,101,52,.05)}
.group-manage-import{padding:16px;position:sticky;top:84px}
.group-manage-list{overflow:hidden}
.group-manage-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:11px;background:#e8f7ed;color:#168044}
.group-manage-note{display:flex;gap:9px;padding:10px 11px;margin-bottom:14px;border:1px solid #dce9e1;border-radius:13px;background:#f3faf5;color:#708078;font-size:10px;line-height:1.5}
.group-manage-note>i{color:#168044;margin-top:2px}
.group-manage-note strong,.group-manage-note span{display:block}
.group-manage-note strong{color:#315044;margin-bottom:2px}
.group-manage-note code{padding:1px 5px;border-radius:5px;background:#e5f4e9;color:#176f3d;font-weight:800}

.group-manage-import .group-field{display:block;margin-bottom:12px}
.group-manage-import .group-field>span,.group-manage-modal .group-field>span{display:block;margin-bottom:5px;color:#596960;font-size:10px;font-weight:800}
.group-manage-import .group-field input,.group-manage-import .group-field textarea,
.group-manage-modal .group-field input{width:100%;box-sizing:border-box;border:1px solid #dfe8e2;border-radius:12px;background:#fbfdfc;padding:10px 11px;font:inherit;font-size:11px;color:#30473a;outline:none}
.group-manage-import .group-field textarea{min-height:150px;resize:vertical;line-height:1.55}
.group-manage-import .group-field input:focus,.group-manage-import .group-field textarea:focus,
.group-manage-modal .group-field input:focus{border-color:#70bd8e;box-shadow:0 0 0 3px rgba(36,153,86,.08);background:#fff}
.group-manage-primary,.group-manage-secondary{min-height:38px;border:0;border-radius:12px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;font:inherit;font-size:10px;font-weight:850;cursor:pointer}
.group-manage-primary{width:100%;background:#168044;color:#fff}
.group-manage-primary:hover{background:#126b39}
.group-manage-secondary{background:#edf2ee;color:#53645b}

.group-manage-list-head{padding:15px 16px;border-bottom:1px solid #edf1ee}
.group-manage-list-head h2{margin:0;color:#183d29;font-size:15px;letter-spacing:-.03em}
.group-manage-list-head h2 small{display:inline-grid;place-items:center;min-width:22px;height:20px;padding:0 6px;margin-left:4px;border-radius:999px;background:#e8f7ed;color:#168044;font-size:9px}
.group-manage-search{width:min(210px,40%);height:34px;display:flex;align-items:center;gap:7px;padding:0 10px;border:1px solid #e0e9e3;border-radius:11px;background:#f8fbf9;color:#82918a}
.group-manage-search i{font-size:10px}
.group-manage-search input{width:100%;border:0;outline:0;background:transparent;color:#30473a;font:inherit;font-size:10px}
.group-manage-search input::placeholder{color:#9aa69f}

.group-manage-table-wrap{overflow-x:auto}
.group-manage-table{width:100%;border-collapse:collapse;table-layout:fixed}
.group-manage-table th{padding:10px 14px;text-align:left;border-bottom:1px solid #edf1ee;color:#849189;font-size:8px;font-weight:850;letter-spacing:.07em;text-transform:uppercase}
.group-manage-table th:nth-child(1){width:30%}
.group-manage-table th:nth-child(2){width:31%}
.group-manage-table th:nth-child(3){width:21%}
.group-manage-table th:nth-child(4){width:18%;text-align:right}
.group-manage-table td{padding:10px 14px;border-bottom:1px solid #f0f3f1;color:#53645b;font-size:10px;vertical-align:middle}
.group-manage-table tbody tr:last-child td{border-bottom:0}
.group-manage-table tbody tr:hover{background:#fbfdfc}
.group-manage-table td code{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#718078;font:inherit;font-size:9px}
.group-manage-name{display:flex;align-items:center;gap:8px;min-width:0}
.group-manage-name strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#18352a;font-size:10px}
.group-manage-row-icon{width:30px;height:30px;flex:0 0 30px;display:grid;place-items:center;border-radius:9px;background:#e8f7ed;color:#168044}
.group-manage-category{display:inline-flex;max-width:100%;padding:4px 7px;border-radius:999px;background:#f1f6f3;color:#5d7266;font-size:8px;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.group-manage-actions{display:flex;justify-content:flex-end;gap:5px}
.group-manage-actions form{margin:0}
.group-manage-actions .crm-icon-btn{width:31px;height:31px;border:1px solid #e0e9e3;border-radius:10px;background:#fff;color:#718078}
.group-manage-actions .crm-icon-btn:hover{border-color:#b7d8c2;background:#f2faf5;color:#168044}
.group-manage-actions .crm-icon-btn-danger:hover{border-color:#f2c8c8;background:#fff3f3;color:#c65d5d}
.group-manage-no-results{min-height:180px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;color:#8b9891;font-size:10px}
.group-manage-no-results i{font-size:18px;color:#a3afa8}
.group-manage-empty{min-height:240px}

.group-manage-modal{position:fixed;inset:0;z-index:80;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,35,25,.38);backdrop-filter:blur(5px);opacity:0;visibility:hidden;pointer-events:none;transition:.18s}
.group-manage-modal.is-open{opacity:1;visibility:visible;pointer-events:auto}
.group-manage-modal-card{width:min(430px,100%);padding:18px;background:#fff;border:1px solid #dfe9e3;border-radius:20px;box-shadow:0 24px 70px rgba(15,35,25,.2)}
.group-manage-modal-card .group-card-head{margin-bottom:15px}
.group-manage-modal .group-field{display:block;margin-bottom:12px}
.group-manage-modal-actions{display:grid;grid-template-columns:110px 1fr;gap:8px;margin-top:14px}
.group-manage-modal-actions .group-manage-primary{width:auto}

@media(max-width:850px){
  .group-manage-page-head{display:block}
  .group-manage-stats{margin-top:10px}
  .group-manage-workspace{grid-template-columns:1fr}
  .group-manage-import{position:static}
}
@media(max-width:560px){
  .group-manage-page-head h1{font-size:25px}
  .group-manage-page-head{padding:10px 4px 14px}
  .group-manage-list-head{display:block}
  .group-manage-search{width:100%;margin-top:10px}
  .group-manage-table{display:block;table-layout:auto}
  .group-manage-table thead{display:none}
  .group-manage-table tbody{display:grid;gap:0}
  .group-manage-table tr{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;padding:12px 14px;border-bottom:1px solid #f0f3f1}
  .group-manage-table td{padding:0;border:0}
  .group-manage-table td:nth-child(1){grid-column:1}
  .group-manage-table td:nth-child(2){grid-column:1;margin-left:38px;margin-top:-2px}
  .group-manage-table td:nth-child(3){grid-column:1;margin-left:38px;margin-top:5px}
  .group-manage-table td:nth-child(4){grid-column:2;grid-row:1 / span 3;align-self:center}
  .group-manage-actions{align-items:center}
  .group-manage-import{padding:14px}
}
</style>

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
