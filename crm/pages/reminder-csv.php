<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/reminder-csv.php';

$crmTitle = 'Import Peserta CSV';
$imports = crmReminderCsvList();
?>
<div class="reminder-payment-page">
 <div class="reminder-payment-topbar"><a class="reminder-back-btn" href="?page=reminder-pembayaran" title="Kembali"><i class="fa-solid fa-arrow-left"></i></a></div>
 <section class="reminder-card">
  <div class="reminder-card-head"><div><span class="reminder-kicker">Sumber peserta</span><h2>Import CSV</h2></div></div>
  <div class="reminder-empty" style="text-align:left;margin-bottom:14px">
   <strong>CSV hanya disimpan di folder server.</strong>
   <span>Tidak ada isi CSV yang masuk ke tabel peserta atau pembayaran. Saat dipakai sebagai sumber reminder, file dibaca langsung dari server lalu dicocokkan ke peserta CRM berdasarkan nomor WhatsApp wali.</span>
  </div>
  <form method="post" action="actions/reminder-csv-import.php" enctype="multipart/form-data" class="reminder-filter-box" style="grid-template-columns:1fr 1.5fr auto">
   <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
   <label><span>Label sumber</span><input name="label" required maxlength="100" placeholder="BATCH 54"></label>
   <label><span>File CSV</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
   <button class="reminder-filter-btn" type="submit"><i class="fa-solid fa-upload"></i> Simpan</button>
  </form>
 </section>
 <section class="reminder-card" style="margin-top:16px">
  <div class="reminder-card-head"><div><span class="reminder-kicker">Folder server</span><h2>CSV tersimpan</h2></div></div>
  <div class="reminder-list">
   <?php if (!$imports): ?><div class="reminder-empty">Belum ada CSV yang disimpan di server.</div>
   <?php else: foreach ($imports as $item): ?>
    <div class="reminder-person">
     <span class="reminder-avatar"><i class="fa-solid fa-file-csv"></i></span>
     <span class="reminder-person-body">
      <strong><?= htmlspecialchars((string)$item['label']) ?></strong>
      <small><?= htmlspecialchars((string)$item['source_filename']) ?> · <?= htmlspecialchars((string)$item['imported_at']) ?></small>
      <em class="reminder-history"><?= (int)$item['matched_count'] ?> cocok · <?= (int)$item['unmatched_count'] ?> belum cocok · <?= (int)$item['duplicate_count'] ?> duplikat · <?= (int)$item['row_count'] ?> baris</em>
     </span>
     <a class="reminder-secondary-link" href="?page=reminder-pembayaran&csv_file=<?= urlencode((string)$item['file']) ?>&status_bayar=belum_lunas&status_peserta=semua">Gunakan</a>
    </div>
   <?php endforeach; endif; ?>
  </div>
 </section>
</div>