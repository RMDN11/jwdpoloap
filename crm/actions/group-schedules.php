<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$action = trim((string)($_POST['action'] ?? ''));
$returnPage = trim((string)($_POST['return_page'] ?? 'group-schedules'));
if (!in_array($returnPage, ['group', 'group-schedules'], true)) {
    $returnPage = 'group-schedules';
}

$statusParam = trim((string)($_POST['status'] ?? 'aktif'));
$jenisParam = trim((string)($_POST['jenis'] ?? 'kategori'));

$redirectUrl = ($returnPage === 'group')
    ? '../index.php?page=group'
    : '../index.php?page=group-schedules&status=' . urlencode($statusParam) . '&jenis=' . urlencode($jenisParam);

if ($action === 'cancel') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM jadwal_pesan_grup WHERE id = ? AND status = 'pending'");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            $_SESSION['crm_flash'] = [
                'type' => ($ok && $deleted === 1) ? 'success' : 'error',
                'message' => ($ok && $deleted === 1) ? 'Jadwal pending berhasil dibatalkan.' : 'Jadwal tidak ditemukan, sudah diproses, atau gagal dibatalkan.'
            ];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Tidak dapat menyiapkan pembatalan jadwal.'];
        }
    } else {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'ID jadwal tidak valid.'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

if ($action === 'cancel_batch') {
    $rawList = trim((string)($_POST['id_list'] ?? ''));
    $ids = array_values(array_filter(array_map('intval', explode(',', $rawList)), static fn(int $id): bool => $id > 0));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("DELETE FROM jadwal_pesan_grup WHERE id IN ({$placeholders}) AND status = 'pending'");
        if ($stmt) {
            $refs = [];
            foreach ($ids as $k => $v) $refs[$k] = &$ids[$k];
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            $ok = $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            $_SESSION['crm_flash'] = [
                'type' => ($ok && $deleted > 0) ? 'success' : 'error',
                'message' => ($ok && $deleted > 0)
                    ? "Berhasil membatalkan {$deleted} jadwal halaqoh."
                    : 'Tidak ada jadwal pending yang dibatalkan (mungkin sudah terkirim atau dibatalkan).'
            ];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan pembatalan jadwal halaqoh.'];
        }
    } else {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Daftar ID jadwal tidak valid atau kosong.'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

if ($action === 'edit_message' || $action === 'edit_schedule') {
    $rawList = trim((string)($_POST['id_list'] ?? ''));
    $newPesan = trim((string)($_POST['pesan'] ?? ''));
    $ids = array_values(array_filter(array_map('intval', explode(',', $rawList)), static fn(int $id): bool => $id > 0));

    $tipeJadwal = trim((string)($_POST['tipe_jadwal'] ?? ''));

    if ($newPesan === '') {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Isi pesan tidak boleh kosong.'];
    } elseif (empty($ids)) {
        $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Daftar jadwal yang akan diedit tidak valid.'];
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($tipeJadwal === 'sekali') {
            $rawOnce = trim((string)($_POST['waktu_jadwal'] ?? $_POST['jadwal_kirim'] ?? ''));
            $ts = strtotime($rawOnce);
            if (!$ts) {
                $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Waktu jadwal sekali kirim tidak valid.'];
                header('Location: ' . $redirectUrl);
                exit;
            }
            $jadwalKirim = date('Y-m-d H:i:s', $ts);
            $sql = "UPDATE jadwal_pesan_grup SET pesan = ?, tipe_jadwal = 'sekali', jadwal_kirim = ?, jam_harian = NULL, hari_rutin = NULL WHERE id IN ({$placeholders}) AND status = 'pending'";
            $types = 'ss' . str_repeat('i', count($ids));
            $params = array_merge([$newPesan, $jadwalKirim], $ids);
        } elseif ($tipeJadwal === 'harian') {
            $jamHarian = trim((string)($_POST['jam_harian'] ?? ''));
            if ($jamHarian === '') {
                $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Jam kirim harian wajib diisi.'];
                header('Location: ' . $redirectUrl);
                exit;
            }
            $rawDays = $_POST['edit_hari_rutin'] ?? $_POST['hari_rutin'] ?? [];
            if (is_string($rawDays)) $rawDays = explode(',', $rawDays);
            $days = array_values(array_filter(array_map('intval', (array)$rawDays), static fn(int $d): bool => $d >= 1 && $d <= 7));
            sort($days);
            if (empty($days)) {
                $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Pilih minimal satu hari pengiriman rutin.'];
                header('Location: ' . $redirectUrl);
                exit;
            }
            $hariRutin = implode(',', $days);
            $sql = "UPDATE jadwal_pesan_grup SET pesan = ?, tipe_jadwal = 'harian', jadwal_kirim = NULL, jam_harian = ?, hari_rutin = ? WHERE id IN ({$placeholders}) AND status = 'pending'";
            $types = 'sss' . str_repeat('i', count($ids));
            $params = array_merge([$newPesan, $jamHarian, $hariRutin], $ids);
        } else {
            $sql = "UPDATE jadwal_pesan_grup SET pesan = ? WHERE id IN ({$placeholders}) AND status = 'pending'";
            $types = 's' . str_repeat('i', count($ids));
            $params = array_merge([$newPesan], $ids);
        }

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $refs = [];
            foreach ($params as $k => $v) $refs[$k] = &$params[$k];
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            $ok = $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            $_SESSION['crm_flash'] = [
                'type' => $ok ? 'success' : 'error',
                'message' => $ok
                    ? "Isi pesan dan jadwal untuk {$updated} halaqoh berhasil diperbarui."
                    : 'Gagal memperbarui jadwal halaqoh.'
            ];
        } else {
            $_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Gagal menyiapkan pembaruan jadwal.'];
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$_SESSION['crm_flash'] = ['type' => 'error', 'message' => 'Aksi jadwal tidak dikenali.'];
header('Location: ' . $redirectUrl);
exit;
