<?php
declare(strict_types=1);

$crmTitle = 'Kelola Grup';

if (isset($conn) && $conn instanceof mysqli) {
    $colCheck = $conn->query("SHOW COLUMNS FROM wa_grup LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE wa_grup ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
}

if (!function_exists('crmGroupManageRedirect')) {
    function crmGroupManageRedirect(): never
    {
        header('Location: index.php?page=manage-groups');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['manage_group_action'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!crmVerifyCsrf($csrf)) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Sesi keamanan tidak valid. Muat ulang halaman lalu coba lagi.']);
            exit;
        }
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Sesi keamanan tidak valid. Muat ulang halaman lalu coba lagi.'];
        crmGroupManageRedirect();
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['group_id'] ?? 0);
        $status = isset($_POST['is_active']) ? (int)$_POST['is_active'] : null;

        if ($id <= 0) {
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'ID grup tidak valid.']);
                exit;
            }
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'ID grup tidak valid.'];
            crmGroupManageRedirect();
        }

        if ($status !== null) {
            $newActive = $status === 1 ? 1 : 0;
            $stmt = $conn->prepare('UPDATE wa_grup SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $newActive, $id);
        } else {
            $stmt = $conn->prepare('UPDATE wa_grup SET is_active = IF(COALESCE(is_active, 1) = 1, 0, 1) WHERE id = ?');
            $stmt->bind_param('i', $id);
        }

        $ok = $stmt ? $stmt->execute() : false;
        if ($stmt) $stmt->close();

        $newStatus = 1;
        $chk = $conn->prepare('SELECT COALESCE(is_active, 1) AS is_active FROM wa_grup WHERE id = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('i', $id);
            $chk->execute();
            $r = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($r) $newStatus = (int)$r['is_active'];
        }

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => $ok ? 'success' : 'error',
                'is_active' => $newStatus,
                'message' => $newStatus === 1 ? 'Grup berhasil diaktifkan kembali.' : 'Grup dinonaktifkan sementara.'
            ]);
            exit;
        }

        $_SESSION['crm_flash'] = [
            'type' => $ok ? 'success' : 'error',
            'message' => $ok ? ($newStatus === 1 ? 'Grup berhasil diaktifkan kembali.' : 'Grup berhasil dinonaktifkan.') : 'Gagal memperbarui status grup.',
        ];
        crmGroupManageRedirect();
    }

    if ($action === 'toggle_category') {
        $category = trim((string)($_POST['category'] ?? ''));
        $targetStatus = isset($_POST['is_active']) ? (int)$_POST['is_active'] : null;

        if ($category === '') {
            $category = 'Tanpa Kategori';
        }

        if ($targetStatus === null) {
            if ($category === 'Tanpa Kategori') {
                $chk = $conn->query("SELECT SUM(CASE WHEN COALESCE(is_active, 1) = 1 THEN 1 ELSE 0 END) AS active_cnt FROM wa_grup WHERE kategori IS NULL OR TRIM(kategori) = '' OR kategori = 'Tanpa Kategori'");
            } else {
                $stmtChk = $conn->prepare("SELECT SUM(CASE WHEN COALESCE(is_active, 1) = 1 THEN 1 ELSE 0 END) AS active_cnt FROM wa_grup WHERE kategori = ?");
                $stmtChk->bind_param('s', $category);
                $stmtChk->execute();
                $chk = $stmtChk->get_result();
            }
            $activeCnt = 0;
            if ($chk) {
                $r = $chk->fetch_assoc();
                $activeCnt = (int)($r['active_cnt'] ?? 0);
            }
            $targetStatus = ($activeCnt > 0) ? 0 : 1;
        } else {
            $targetStatus = ($targetStatus === 1) ? 1 : 0;
        }

        if ($category === 'Tanpa Kategori') {
            $stmt = $conn->prepare("UPDATE wa_grup SET is_active = ? WHERE kategori IS NULL OR TRIM(kategori) = '' OR kategori = 'Tanpa Kategori'");
            $stmt->bind_param('i', $targetStatus);
        } else {
            $stmt = $conn->prepare("UPDATE wa_grup SET is_active = ? WHERE kategori = ?");
            $stmt->bind_param('is', $targetStatus, $category);
        }

        $ok = $stmt ? $stmt->execute() : false;
        $affected = $stmt ? $stmt->affected_rows : 0;
        if ($stmt) $stmt->close();

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => $ok ? 'success' : 'error',
                'category' => $category,
                'is_active' => $targetStatus,
                'affected' => $affected,
                'message' => $ok
                    ? ($targetStatus === 1 ? "Semua grup di kategori '{$category}' berhasil diaktifkan." : "Semua grup di kategori '{$category}' berhasil dinonaktifkan.")
                    : 'Gagal memperbarui status kategori.'
            ]);
            exit;
        }

        $_SESSION['crm_flash'] = [
            'type' => $ok ? 'success' : 'error',
            'message' => $ok
                ? ($targetStatus === 1 ? "Semua grup di kategori '{$category}' berhasil diaktifkan." : "Semua grup di kategori '{$category}' berhasil dinonaktifkan.")
                : 'Gagal memperbarui status kategori.',
        ];
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
            'INSERT INTO wa_grup (nama_grup, id_grup, kategori, is_active) VALUES (?, ?, ?, 1)
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
        $isActive = isset($_POST['is_active']) ? ((int)$_POST['is_active'] === 1 ? 1 : 0) : 1;

        if ($id <= 0 || $name === '' || $groupId === '') {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Data grup tidak lengkap.'];
            crmGroupManageRedirect();
        }

        $stmt = $conn->prepare('UPDATE wa_grup SET nama_grup = ?, id_grup = ?, kategori = ?, is_active = ? WHERE id = ?');
        if (!$stmt) {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan perubahan grup: ' . $conn->error];
            crmGroupManageRedirect();
        }

        $stmt->bind_param('sssii', $name, $groupId, $category, $isActive, $id);
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
$result = $conn->query('SELECT id, nama_grup, id_grup, kategori, COALESCE(is_active, 1) AS is_active FROM wa_grup ORDER BY is_active DESC, kategori ASC, nama_grup ASC');
if ($result) {
    $groups = $result->fetch_all(MYSQLI_ASSOC);
}

$categories = [];
$categoryStats = [];
$activeCount = 0;
$inactiveCount = 0;
foreach ($groups as $group) {
    $category = trim((string)$group['kategori']) ?: 'Tanpa Kategori';
    $categories[$category] = true;
    if (!isset($categoryStats[$category])) {
        $categoryStats[$category] = [
            'name' => $category,
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
        ];
    }
    $categoryStats[$category]['total']++;
    if ((int)$group['is_active'] === 1) {
        $activeCount++;
        $categoryStats[$category]['active']++;
    } else {
        $inactiveCount++;
        $categoryStats[$category]['inactive']++;
    }
}
$categoryCount = count($categoryStats);
?>

<div class="crm-workspace-page">
<div class="crm-workspace-back">
    <a href="index.php?page=more" title="Kembali ke More" aria-label="Kembali ke More"><i class="fa-solid fa-arrow-left"></i></a>
</div>
<section class="crm-workspace-header group-manage-page-head">
    <div class="crm-workspace-header-main">
        <span class="crm-workspace-kicker">Management</span>
        <h1>Kelola Grup</h1>
        <p>Tambah, ubah, atur status aktif/nonaktif, dan rapikan daftar grup WhatsApp.</p>
    </div>
    <div class="crm-workspace-stats group-manage-stats">
        <span class="crm-workspace-stat"><strong><?= count($groups) ?></strong> grup</span>
        <span class="crm-workspace-stat" id="statActiveCount"><strong><?= $activeCount ?></strong> aktif</span>
        <span class="crm-workspace-stat" id="statInactiveCount"><strong><?= $inactiveCount ?></strong> nonaktif</span>
        <span class="crm-workspace-stat"><strong><?= $categoryCount ?></strong> kategori</span>
    </div>
</section>

<section class="crm-workspace-grid is-editor-left group-manage-workspace">
    <div class="crm-workspace-card group-manage-import">
        <div class="crm-workspace-card-head">
            <div>
                <span class="crm-workspace-card-kicker">Tambah</span>
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

            <label class="crm-workspace-field">
                <span>Daftar Grup</span>
                <textarea name="data_grup" rows="7" required placeholder="Grup Alumni JWD : 1203630...@g.us&#10;Grup Promosi : 1203630...@g.us"></textarea>
            </label>

            <label class="crm-workspace-field">
                <span>Kategori</span>
                <input type="text" name="kategori_massal" maxlength="100" placeholder="Contoh: Promosi, Internal, Alumni">
            </label>

            <button type="submit" class="group-manage-primary">
                <i class="fa-solid fa-floppy-disk"></i>
                Simpan Grup
            </button>
        </form>
    </div>

    <div class="crm-workspace-card group-manage-list">
        <div class="crm-workspace-card-head group-manage-list-head">
            <div>
                <span class="crm-workspace-card-kicker">Library</span>
                <h2>Grup Tersimpan <small id="totalGroupCountBadge"><?= count($groups) ?></small></h2>
            </div>
            <div class="group-manage-filter-row">
                <div class="group-filter-tabs" role="tablist">
                    <button type="button" class="group-filter-btn is-active" data-filter="all">Semua (<span id="tabCountAll"><?= count($groups) ?></span>)</button>
                    <button type="button" class="group-filter-btn" data-filter="active">Aktif (<span id="tabCountActive"><?= $activeCount ?></span>)</button>
                    <button type="button" class="group-filter-btn" data-filter="inactive">Nonaktif (<span id="tabCountInactive"><?= $inactiveCount ?></span>)</button>
                </div>
                <select id="groupCategorySelect" class="group-category-select" title="Pilih kategori grup">
                    <option value="all">Semua Kategori (<?= count($groups) ?>)</option>
                    <?php foreach ($categoryStats as $c): ?>
                        <option value="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['active'] ?>/<?= $c['total'] ?> aktif)</option>
                    <?php endforeach; ?>
                </select>
                <label class="group-manage-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" id="groupManageSearch" placeholder="Cari nama / ID / kategori...">
                </label>
            </div>
        </div>

        <?php if ($categoryCount > 0): ?>
            <div class="group-category-strip" id="groupCategoryStrip">
                <span class="group-category-strip-label"><i class="fa-solid fa-layer-group"></i> Kategori:</span>
                <button type="button" class="group-cat-chip is-all is-selected" data-category="all" id="catChipAll">
                    Semua Kategori (<span id="catStripTotalAll"><?= count($groups) ?></span>)
                </button>
                <?php foreach ($categoryStats as $c): ?>
                    <?php $hasActive = $c['active'] > 0; ?>
                    <div class="group-cat-chip" data-category="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>" data-active="<?= $c['active'] ?>" data-total="<?= $c['total'] ?>">
                        <button type="button" class="group-cat-filter-btn" data-category="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>" title="Filter kategori <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>">
                            <i class="fa-regular fa-folder"></i>
                            <span class="cat-name"><?= htmlspecialchars($c['name']) ?></span>
                            <span class="cat-stat"><?= $c['active'] ?>/<?= $c['total'] ?></span>
                        </button>
                        <button type="button"
                                class="group-cat-power-btn <?= $hasActive ? 'is-on' : 'is-off' ?>"
                                data-category="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>"
                                data-action-target="<?= $hasActive ? '0' : '1' ?>"
                                title="<?= $hasActive ? 'Nonaktifkan semua grup di kategori ' . htmlspecialchars($c['name'], ENT_QUOTES) : 'Aktifkan semua grup di kategori ' . htmlspecialchars($c['name'], ENT_QUOTES) ?>"
                                aria-label="Toggle status kategori <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>">
                            <i class="fa-solid <?= $hasActive ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="group-category-action-bar" id="groupCategoryActionBar" hidden>
                <div>
                    <i class="fa-solid fa-folder-open"></i> Kategori: <strong id="catBarName">-</strong> · <span id="catBarStats">0 aktif, 0 nonaktif</span>
                </div>
                <div class="group-category-action-tools">
                    <button type="button" class="cat-action-btn cat-action-off" id="btnCatTurnOff">
                        <i class="fa-solid fa-circle-pause"></i> Nonaktifkan Kategori
                    </button>
                    <button type="button" class="cat-action-btn cat-action-on" id="btnCatTurnOn">
                        <i class="fa-solid fa-circle-check"></i> Aktifkan Kategori
                    </button>
                    <button type="button" class="cat-action-btn cat-action-reset" id="btnCatReset" title="Tampilkan semua kategori">
                        <i class="fa-solid fa-xmark"></i> Tutup Filter
                    </button>
                </div>
            </div>
        <?php endif; ?>

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
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="groupManageTable">
                        <?php foreach ($groups as $group): ?>
                            <?php
                            $category = trim((string)$group['kategori']) ?: 'Tanpa Kategori';
                            $isActive = (int)$group['is_active'] === 1;
                            ?>
                            <tr class="<?= $isActive ? '' : 'is-inactive-row' ?>"
                                data-search="<?= htmlspecialchars(strtolower($group['nama_grup'] . ' ' . $group['id_grup'] . ' ' . $category . ' ' . ($isActive ? 'aktif' : 'nonaktif')), ENT_QUOTES) ?>"
                                data-status="<?= $isActive ? 'active' : 'inactive' ?>"
                                data-category="<?= htmlspecialchars($category, ENT_QUOTES) ?>"
                                data-id="<?= (int)$group['id'] ?>">
                                <td>
                                    <div class="group-manage-name">
                                        <span class="group-manage-row-icon"><i class="fa-solid <?= $isActive ? 'fa-users' : 'fa-users-slash' ?>"></i></span>
                                        <strong><?= htmlspecialchars($group['nama_grup']) ?></strong>
                                    </div>
                                </td>
                                <td><code><?= htmlspecialchars($group['id_grup']) ?></code></td>
                                <td>
                                    <button type="button" class="group-manage-category" data-category="<?= htmlspecialchars($category, ENT_QUOTES) ?>" title="Filter kategori <?= htmlspecialchars($category, ENT_QUOTES) ?>">
                                        <i class="fa-regular fa-folder"></i>
                                        <span><?= htmlspecialchars($category) ?></span>
                                    </button>
                                </td>
                                <td>
                                    <button type="button"
                                            class="group-status-toggle <?= $isActive ? 'is-active' : 'is-inactive' ?>"
                                            data-id="<?= (int)$group['id'] ?>"
                                            data-status="<?= $isActive ? '1' : '0' ?>"
                                            title="<?= $isActive ? 'Klik untuk nonaktifkan grup' : 'Klik untuk aktifkan kembali' ?>"
                                            aria-label="<?= $isActive ? 'Status aktif, klik untuk nonaktifkan' : 'Status nonaktif, klik untuk aktifkan' ?>">
                                        <i class="fa-solid <?= $isActive ? 'fa-circle-check' : 'fa-circle-pause' ?>"></i>
                                        <span><?= $isActive ? 'Aktif' : 'Nonaktif' ?></span>
                                    </button>
                                </td>
                                <td>
                                    <div class="group-manage-actions">
                                        <button type="button"
                                                class="crm-icon-btn group-manage-edit"
                                                data-id="<?= (int)$group['id'] ?>"
                                                data-name="<?= htmlspecialchars($group['nama_grup'], ENT_QUOTES) ?>"
                                                data-group-id="<?= htmlspecialchars($group['id_grup'], ENT_QUOTES) ?>"
                                                data-category="<?= htmlspecialchars($category, ENT_QUOTES) ?>"
                                                data-is-active="<?= $isActive ? '1' : '0' ?>"
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
    <div class="group-manage-modal-card crm-workspace-card" role="dialog" aria-modal="true" aria-labelledby="groupManageModalTitle">
        <div class="crm-workspace-card-head">
            <div>
                <span class="crm-workspace-card-kicker">Edit</span>
                <h2 id="groupManageModalTitle">Perbarui Grup</h2>
            </div>
            <button type="button" class="crm-icon-btn" id="groupManageClose" aria-label="Tutup">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" id="groupManageCsrf" value="<?= htmlspecialchars(crmCsrfToken(), ENT_QUOTES) ?>">
            <input type="hidden" name="manage_group_action" value="edit">
            <input type="hidden" name="group_id" id="groupManageId">

            <label class="crm-workspace-field">
                <span>Nama Grup</span>
                <input type="text" name="nama_grup" id="groupManageName" required maxlength="150">
            </label>
            <label class="crm-workspace-field">
                <span>ID Grup</span>
                <input type="text" name="id_grup" id="groupManageGroupId" required maxlength="150">
            </label>
            <label class="crm-workspace-field">
                <span>Kategori</span>
                <input type="text" name="kategori" id="groupManageCategory" maxlength="100">
            </label>
            <label class="crm-workspace-field">
                <span>Status Grup</span>
                <select name="is_active" id="groupManageIsActive">
                    <option value="1">Aktif (Dapat menerima broadcast)</option>
                    <option value="0">Nonaktif (Diarsipkan sementara)</option>
                </select>
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
    const tableBody = document.getElementById('groupManageTable');
    const noResults = document.getElementById('groupManageNoResults');
    const filterButtons = document.querySelectorAll('.group-filter-btn');
    const categorySelect = document.getElementById('groupCategorySelect');
    const categoryStrip = document.getElementById('groupCategoryStrip');
    const catActionBar = document.getElementById('groupCategoryActionBar');
    const catBarName = document.getElementById('catBarName');
    const catBarStats = document.getElementById('catBarStats');
    const btnCatTurnOff = document.getElementById('btnCatTurnOff');
    const btnCatTurnOn = document.getElementById('btnCatTurnOn');
    const btnCatReset = document.getElementById('btnCatReset');
    const csrfToken = document.getElementById('groupManageCsrf')?.value || '';

    let currentFilter = 'all';
    let currentCategory = 'all';

    function getRows() {
        return [...document.querySelectorAll('#groupManageTable tr')];
    }

    function applyFilterAndSearch() {
        const query = search ? search.value.trim().toLowerCase() : '';
        const rows = getRows();
        let visible = 0;

        rows.forEach(row => {
            const matchesSearch = !query || (row.dataset.search || '').includes(query);
            const status = row.dataset.status || 'active';
            const matchesStatus = (currentFilter === 'all') || (currentFilter === status);
            const rowCat = (row.dataset.category || 'Tanpa Kategori').trim().toLowerCase();
            const matchesCat = (currentCategory === 'all') || (rowCat === currentCategory.trim().toLowerCase());

            const isShown = matchesSearch && matchesStatus && matchesCat;
            row.hidden = !isShown;
            if (isShown) visible++;
        });

        if (noResults) noResults.hidden = visible > 0;
        updateCategoryBarStats();
    }

    function updateCategoryBarStats() {
        if (!catActionBar) return;
        if (currentCategory === 'all') {
            catActionBar.hidden = true;
            return;
        }

        catActionBar.hidden = false;
        if (catBarName) catBarName.textContent = currentCategory;

        const rows = getRows().filter(r => (r.dataset.category || 'Tanpa Kategori').trim().toLowerCase() === currentCategory.trim().toLowerCase());
        let act = 0;
        let inact = 0;
        rows.forEach(r => {
            if (r.dataset.status === 'active') act++;
            else inact++;
        });

        if (catBarStats) {
            catBarStats.textContent = `${act} aktif, ${inact} nonaktif (${rows.length} total)`;
        }
    }

    function setCategory(catName) {
        currentCategory = (catName || 'all').trim();

        // Sync dropdown
        if (categorySelect) {
            categorySelect.value = currentCategory;
            // Fallback if value not exact match (case insensitive)
            if (categorySelect.value !== currentCategory && currentCategory !== 'all') {
                for (let i = 0; i < categorySelect.options.length; i++) {
                    if (categorySelect.options[i].value.toLowerCase() === currentCategory.toLowerCase()) {
                        categorySelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        // Sync chip highlights
        const chips = document.querySelectorAll('.group-cat-chip');
        chips.forEach(chip => {
            const chipCat = chip.dataset.category || '';
            const isMatch = (currentCategory === 'all' && chipCat === 'all') ||
                            (currentCategory.toLowerCase() === chipCat.toLowerCase());
            chip.classList.toggle('is-selected', isMatch);
        });

        applyFilterAndSearch();
    }

    function recalculateCounts() {
        const rows = getRows();
        const total = rows.length;
        let active = 0;
        let inactive = 0;

        const catMap = {};

        rows.forEach(r => {
            const st = r.dataset.status || 'active';
            const cat = (r.dataset.category || 'Tanpa Kategori').trim();

            if (st === 'active') active++;
            else inactive++;

            if (!catMap[cat]) catMap[cat] = { total: 0, active: 0, inactive: 0 };
            catMap[cat].total++;
            if (st === 'active') catMap[cat].active++;
            else catMap[cat].inactive++;
        });

        const tabAll = document.getElementById('tabCountAll');
        const tabAct = document.getElementById('tabCountActive');
        const tabInact = document.getElementById('tabCountInactive');
        const statAct = document.querySelector('#statActiveCount strong');
        const statInact = document.querySelector('#statInactiveCount strong');
        const totalBadge = document.getElementById('totalGroupCountBadge');
        const stripTotalAll = document.getElementById('catStripTotalAll');

        if (tabAll) tabAll.textContent = String(total);
        if (tabAct) tabAct.textContent = String(active);
        if (tabInact) tabInact.textContent = String(inactive);
        if (statAct) statAct.textContent = String(active);
        if (statInact) statInact.textContent = String(inactive);
        if (totalBadge) totalBadge.textContent = String(total);
        if (stripTotalAll) stripTotalAll.textContent = String(total);

        // Update category chips
        document.querySelectorAll('.group-cat-chip[data-category]').forEach(chip => {
            const cat = chip.dataset.category;
            if (!cat || cat === 'all') return;
            const data = catMap[cat] || { total: 0, active: 0, inactive: 0 };

            const statSpan = chip.querySelector('.cat-stat');
            if (statSpan) statSpan.textContent = `${data.active}/${data.total}`;

            const pwr = chip.querySelector('.group-cat-power-btn');
            if (pwr) {
                const hasActive = data.active > 0;
                pwr.className = `group-cat-power-btn ${hasActive ? 'is-on' : 'is-off'}`;
                pwr.dataset.actionTarget = hasActive ? '0' : '1';
                pwr.title = hasActive ? `Nonaktifkan semua grup di kategori ${cat}` : `Aktifkan semua grup di kategori ${cat}`;
                pwr.innerHTML = `<i class="fa-solid ${hasActive ? 'fa-toggle-on' : 'fa-toggle-off'}"></i>`;
            }
        });

        // Update select options text
        if (categorySelect) {
            for (let i = 0; i < categorySelect.options.length; i++) {
                const opt = categorySelect.options[i];
                if (opt.value === 'all') {
                    opt.textContent = `Semua Kategori (${total})`;
                } else if (catMap[opt.value]) {
                    const d = catMap[opt.value];
                    opt.textContent = `${opt.value} (${d.active}/${d.total} aktif)`;
                }
            }
        }

        updateCategoryBarStats();
    }

    // Toggle Category Status (AJAX)
    async function toggleCategory(categoryName, targetStatus, triggerButton = null) {
        if (!categoryName || categoryName === 'all') return;

        let origHtml = '';
        if (triggerButton) {
            triggerButton.disabled = true;
            origHtml = triggerButton.innerHTML;
            triggerButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        }

        const formData = new FormData();
        formData.append('manage_group_action', 'toggle_category');
        formData.append('csrf_token', csrfToken);
        formData.append('category', categoryName);
        formData.append('is_active', String(targetStatus));
        formData.append('ajax', '1');

        try {
            const res = await fetch('index.php?page=manage-groups', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!res.ok) {
                const text = await res.text();
                try {
                    const errJson = JSON.parse(text);
                    alert(errJson.message || ('Error ' + res.status));
                } catch {
                    alert('Gagal memproses (Status ' + res.status + '). Coba muat ulang halaman.');
                }
                return;
            }

            const json = await res.json();

            if (json && json.status === 'success') {
                const nowActive = Number(json.is_active) === 1;
                const rows = getRows().filter(r => (r.dataset.category || 'Tanpa Kategori').trim().toLowerCase() === categoryName.trim().toLowerCase());

                rows.forEach(row => {
                    row.dataset.status = nowActive ? 'active' : 'inactive';
                    row.classList.toggle('is-inactive-row', !nowActive);

                    const nameIcon = row.querySelector('.group-manage-row-icon i');
                    if (nameIcon) {
                        nameIcon.className = `fa-solid ${nowActive ? 'fa-users' : 'fa-users-slash'}`;
                    }

                    const toggleBtn = row.querySelector('.group-status-toggle');
                    if (toggleBtn) {
                        toggleBtn.dataset.status = nowActive ? '1' : '0';
                        toggleBtn.className = 'group-status-toggle ' + (nowActive ? 'is-active' : 'is-inactive');
                        toggleBtn.title = nowActive ? 'Klik untuk nonaktifkan grup' : 'Klik untuk aktifkan kembali';
                        toggleBtn.setAttribute('aria-label', nowActive ? 'Status aktif, klik untuk nonaktifkan' : 'Status nonaktif, klik untuk aktifkan');
                        toggleBtn.innerHTML = `<i class="fa-solid ${nowActive ? 'fa-circle-check' : 'fa-circle-pause'}"></i> <span>${nowActive ? 'Aktif' : 'Nonaktif'}</span>`;
                    }

                    const editBtn = row.querySelector('.group-manage-edit');
                    if (editBtn) editBtn.dataset.isActive = nowActive ? '1' : '0';

                    const name = row.querySelector('.group-manage-name strong')?.textContent || '';
                    const gid = row.querySelector('code')?.textContent || '';
                    const cat = row.querySelector('.group-manage-category span')?.textContent || '';
                    row.dataset.search = (name + ' ' + gid + ' ' + cat + ' ' + (nowActive ? 'aktif' : 'nonaktif')).toLowerCase();
                });

                recalculateCounts();
                applyFilterAndSearch();
            } else {
                alert(json?.message || 'Gagal memperbarui status kategori.');
            }
        } catch (err) {
            console.error('Error toggling category status:', err);
            alert('Koneksi terganggu atau sesi berakhir. Silakan muat ulang halaman.');
        } finally {
            if (triggerButton) {
                triggerButton.disabled = false;
                triggerButton.innerHTML = origHtml;
            }
        }
    }

    // Category Strip Interactions
    categoryStrip?.addEventListener('click', (event) => {
        const powerBtn = event.target.closest('.group-cat-power-btn');
        if (powerBtn) {
            event.stopPropagation();
            const cat = powerBtn.dataset.category;
            const target = Number(powerBtn.dataset.actionTarget ?? '0');
            toggleCategory(cat, target, powerBtn);
            return;
        }

        const filterBtn = event.target.closest('.group-cat-filter-btn') || event.target.closest('.group-cat-chip.is-all');
        if (filterBtn) {
            const cat = filterBtn.dataset.category || 'all';
            setCategory(cat);
        }
    });

    // Category Select Dropdown
    categorySelect?.addEventListener('change', () => {
        setCategory(categorySelect.value);
    });

    // Category Action Bar Buttons
    btnCatTurnOff?.addEventListener('click', () => {
        if (currentCategory && currentCategory !== 'all') {
            toggleCategory(currentCategory, 0, btnCatTurnOff);
        }
    });

    btnCatTurnOn?.addEventListener('click', () => {
        if (currentCategory && currentCategory !== 'all') {
            toggleCategory(currentCategory, 1, btnCatTurnOn);
        }
    });

    btnCatReset?.addEventListener('click', () => {
        setCategory('all');
    });

    // Click on table row category badge filters by that category
    tableBody?.addEventListener('click', (event) => {
        const catBtn = event.target.closest('.group-manage-category');
        if (catBtn) {
            const cat = catBtn.dataset.category;
            if (cat) {
                setCategory(cat);
            }
        }
    });

    // Search and Status Tabs
    search?.addEventListener('input', applyFilterAndSearch);

    filterButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            filterButtons.forEach(b => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            currentFilter = btn.dataset.filter || 'all';
            applyFilterAndSearch();
        });
    });

    // Toggle individual group status handler
    tableBody?.addEventListener('click', async (event) => {
        const btn = event.target.closest('.group-status-toggle');
        if (!btn || btn.disabled) return;

        const row = btn.closest('tr');
        const groupId = btn.dataset.id;
        if (!groupId) return;

        btn.disabled = true;
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        const formData = new FormData();
        formData.append('manage_group_action', 'toggle_status');
        formData.append('csrf_token', csrfToken);
        formData.append('group_id', groupId);
        formData.append('ajax', '1');

        try {
            const res = await fetch('index.php?page=manage-groups', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!res.ok) {
                const text = await res.text();
                try {
                    const errJson = JSON.parse(text);
                    alert(errJson.message || ('Error ' + res.status));
                } catch {
                    alert('Gagal memproses (Status ' + res.status + '). Coba muat ulang halaman.');
                }
                btn.innerHTML = origHtml;
                return;
            }

            const json = await res.json();
            if (json && json.status === 'success') {
                const nowActive = json.is_active === 1;
                btn.dataset.status = nowActive ? '1' : '0';
                btn.className = 'group-status-toggle ' + (nowActive ? 'is-active' : 'is-inactive');
                btn.title = nowActive ? 'Klik untuk nonaktifkan grup' : 'Klik untuk aktifkan kembali';
                btn.setAttribute('aria-label', nowActive ? 'Status aktif, klik untuk nonaktifkan' : 'Status nonaktif, klik untuk aktifkan');
                btn.innerHTML = `<i class="fa-solid ${nowActive ? 'fa-circle-check' : 'fa-circle-pause'}"></i> <span>${nowActive ? 'Aktif' : 'Nonaktif'}</span>`;

                if (row) {
                    row.dataset.status = nowActive ? 'active' : 'inactive';
                    row.classList.toggle('is-inactive-row', !nowActive);

                    const nameIcon = row.querySelector('.group-manage-row-icon i');
                    if (nameIcon) {
                        nameIcon.className = `fa-solid ${nowActive ? 'fa-users' : 'fa-users-slash'}`;
                    }

                    const editBtn = row.querySelector('.group-manage-edit');
                    if (editBtn) {
                        editBtn.dataset.isActive = nowActive ? '1' : '0';
                    }

                    const name = row.querySelector('.group-manage-name strong')?.textContent || '';
                    const gid = row.querySelector('code')?.textContent || '';
                    const cat = row.querySelector('.group-manage-category span')?.textContent || '';
                    row.dataset.search = (name + ' ' + gid + ' ' + cat + ' ' + (nowActive ? 'aktif' : 'nonaktif')).toLowerCase();
                }

                recalculateCounts();
                applyFilterAndSearch();
            } else {
                alert(json?.message || 'Gagal mengubah status grup.');
                btn.innerHTML = origHtml;
            }
        } catch (err) {
            console.error('Error toggling group status:', err);
            btn.innerHTML = origHtml;
            alert('Koneksi terganggu atau sesi berakhir. Silakan muat ulang halaman.');
        } finally {
            btn.disabled = false;
        }
    });

    // Modal Edit handler
    const modal = document.getElementById('groupManageModal');
    const id = document.getElementById('groupManageId');
    const name = document.getElementById('groupManageName');
    const groupId = document.getElementById('groupManageGroupId');
    const category = document.getElementById('groupManageCategory');
    const isActiveSelect = document.getElementById('groupManageIsActive');

    function closeModal() {
        modal?.classList.remove('is-open');
        modal?.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.group-manage-edit').forEach(button => {
        button.addEventListener('click', () => {
            if (id) id.value = button.dataset.id || '';
            if (name) name.value = button.dataset.name || '';
            if (groupId) groupId.value = button.dataset.groupId || '';
            if (category) category.value = button.dataset.category || '';
            if (isActiveSelect) isActiveSelect.value = button.dataset.isActive || '1';
            modal?.classList.add('is-open');
            modal?.setAttribute('aria-hidden', 'false');
            setTimeout(() => name?.focus(), 30);
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
