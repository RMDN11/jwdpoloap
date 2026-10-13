<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Permintaan tidak valid.'); }
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (($_POST['action'] ?? '') === 'cancel' && $id && $id > 0) {
 $stmt = $conn->prepare("DELETE FROM jadwal_pesan_grup WHERE id = ? AND status = 'pending'");
 if ($stmt) { $stmt->bind_param('i',$id); $ok=$stmt->execute(); $deleted=$stmt->affected_rows; $stmt->close(); $_SESSION['crm_flash']=['type'=>$ok && $deleted===1?'success':'error','message'=>$ok && $deleted===1?'Jadwal pending berhasil dibatalkan.':'Jadwal tidak ditemukan, sudah diproses, atau gagal dibatalkan.']; }
 else $_SESSION['crm_flash']=['type'=>'error','message'=>'Tidak dapat menyiapkan pembatalan jadwal.'];
} else $_SESSION['crm_flash']=['type'=>'error','message'=>'Aksi jadwal tidak valid.'];
header('Location: ../index.php?page=group-schedules&status=aktif'); exit;
