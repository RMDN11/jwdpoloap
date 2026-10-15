<?php
declare(strict_types=1);
$crmTitle = 'Kelola Trigger';
require_once __DIR__ . '/../config/prospect.php';
$triggers = [];
$result = $conn->query("SELECT id, keyword, category, active, created_at FROM crm_prospect_triggers ORDER BY category ASC, keyword ASC");
if ($result) while ($row = $result->fetch_assoc()) $triggers[] = $row;
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$testMessage = trim((string)($_POST['test_message'] ?? ''));
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dry_run') {
    $testResult = crmProspectClassifyMessage($testMessage, $conn);
}
?>
<section class="page-head trigger-page">
 <div><span class="eyebrow">Customer Baru · Otomasi</span><h1>Kelola Trigger</h1><p>Atur kata pemicu klasifikasi Customer Baru. Pengujian hanya membaca aturan, tidak mengirim pesan WhatsApp.</p></div>
 <div class="trigger-toolbar"><a class="trigger-btn secondary" href="?page=chat"><i class="fa-solid fa-arrow-left"></i> Kembali ke Chat</a></div>
</section>
<section class="trigger-panel">
 <h2>Tambah trigger</h2>
 <form class="trigger-form" method="post" action="actions/triggers.php">
  <input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>"><input type="hidden" name="action" value="add">
  <label>Kata/frasa pemicu<input name="keyword" maxlength="120" required placeholder="Contoh: mau daftar program"></label>
  <label>Kategori<select name="category" required>
   <?php foreach (['Bingung','Ziyadah Pemula','Ziyadah Lanjutan',"Muroja'ah",'Tahfidz Cilik','Mode Intensif','Mode Normal','Ekspresi Minat','Lainnya'] as $category): ?><option value="<?= $h($category) ?>"><?= $h($category) ?></option><?php endforeach; ?>
  </select></label>
  <button class="trigger-btn" type="submit"><i class="fa-solid fa-plus"></i> Tambah</button>
 </form>
 <p class="trigger-note">Trigger aktif dipakai untuk klasifikasi pesan. Kata yang terlalu umum dapat menghasilkan kategori yang keliru, jadi uji sebelum mengaktifkan.</p>
</section>
<section class="trigger-panel">
 <h2>Uji pesan tanpa mengirim</h2>
 <form method="post" class="trigger-test">
  <input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>"><input type="hidden" name="action" value="dry_run">
  <label>Contoh pesan<textarea name="test_message" maxlength="4000" placeholder="Masukkan pesan calon customer..." required><?= $h($testMessage) ?></textarea></label>
  <button class="trigger-btn secondary" type="submit"><i class="fa-solid fa-flask"></i> Uji klasifikasi</button>
 </form>
 <?php if ($testResult !== null): ?><div class="trigger-result"><strong>Hasil dry-run: <?= $h($testResult) ?></strong><div class="trigger-note">Simulasi selesai. Tidak ada pesan yang dikirim dan tidak ada data kontak yang diubah.</div></div><?php endif; ?>
</section>
<section class="trigger-panel">
 <div class="trigger-toolbar"><div><h2>Daftar trigger</h2><p class="trigger-note"><?= count($triggers) ?> aturan tersimpan</p></div></div>
 <?php if (!$triggers): ?><div class="trigger-empty">Belum ada trigger.</div><?php else: ?>
 <div class="trigger-table-wrap"><table class="trigger-table"><thead><tr><th>Kata pemicu</th><th>Kategori</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
 <?php foreach ($triggers as $trigger): ?><tr>
  <td><?= $h($trigger['keyword']) ?></td><td><?= $h($trigger['category']) ?></td><td><span class="trigger-state <?= (int)$trigger['active'] ? '' : 'off' ?>"><?= (int)$trigger['active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
  <td><div class="trigger-row-actions">
   <form method="post" action="actions/triggers.php"><input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$trigger['id'] ?>"><button class="trigger-btn secondary" type="submit"><?= (int)$trigger['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
   <form method="post" action="actions/triggers.php" onsubmit="return confirm('Hapus trigger ini?');"><input type="hidden" name="csrf" value="<?= $h(crmCsrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$trigger['id'] ?>"><button class="trigger-btn danger" type="submit">Hapus</button></form>
  </div></td>
 </tr><?php endforeach; ?>
 </tbody></table></div><?php endif; ?>
</section>