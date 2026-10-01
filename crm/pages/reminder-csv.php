<?php
declare(strict_types=1);
$crmTitle = 'Import Peserta CSV';
$imports = [];
$r = $conn->query("SELECT id,label,source_filename,row_count,matched_count,unmatched_count,duplicate_count,imported_at FROM crm_csv_imports ORDER BY imported_at DESC LIMIT 30");
if ($r) $imports = $r->fetch_all(MYSQLI_ASSOC);
?>
<div class="reminder-payment-page">
 <div class="reminder-payment-topbar"><a class="reminder-back-btn" href="?page=reminder-pembayaran" title="Kembali"><i class="fa-solid fa-arrow-left"></i></a></div>
 <section class="reminder-card">
  <div class="reminder-card-head"><div><span class="reminder-kicker">Sumber peserta</span><h2>Import CSV</h2></div></div>
  <div class="reminder-empty" style="text-align:left;margin-bottom:14px">
   <strong>CSV tidak mengubah tabel peserta atau pembayaran.</strong>
   <span>Data disimpan sebagai snapshot sumber peserta dan dicocokkan ke peserta CRM berdasarkan nomor WhatsApp wali. Nomor yang tidak unik tidak dipasangkan otomatis.</span>
  </div>
  <form method="post" action="actions/reminder-csv-import.php" enctype="multipart/form-data" class="reminder-filter-box" style="grid-template-columns:1fr 1.5fr auto">
   <input type="hidden" name="csrf" value="<?= htmlspecialchars(crmCsrfToken()) ?>">
   <label><span>Label sumber</span><input name="label" required maxlength="100" placeholder="BATCH 54"></label>
   <label><span>File CSV</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
   <button class="reminder-filter-btn" type="submit"><i class="fa-solid fa-upload"></i> Import</button>
  </form>
 </section>
 <section class="reminder-card" style="margin-top:16px">
  <div class="reminder-card-head"><div><span class="reminder-kicker">Riwayat</span><h2>Import sebelumnya</h2></div></div>
  <div class="reminder-list">
   <?php if (!$imports): ?><div class="reminder-empty">Belum ada CSV yang diimport.</div>
   <?php else: foreach ($imports as $item): ?>
    <div class="reminder-person">
     <span class="reminder-avatar"><i class="fa-solid fa-file-csv"></i></span>
     <span class="reminder-person-body">
      <strong><?= htmlspecialchars($item['label']) ?></strong>
      <small><?= htmlspecialchars($item['source_filename']) ?> · <?= htmlspecialchars($item['imported_at']) ?></small>
      <em class="reminder-history"><?= (int)$item['matched_count'] ?> cocok · <?= (int)$item['unmatched_count'] ?> belum cocok · <?= (int)$item['duplicate_count'] ?> duplikat · <?= (int)$item['row_count'] ?> baris</em>
     </span>
     <a class="reminder-secondary-link" href="?page=reminder-pembayaran&csv_import_id=<?= (int)$item['id'] ?>&status_bayar=belum_lunas&status_peserta=semua">Gunakan</a>
    </div>
   <?php endforeach; endif; ?>
  </div>
 </section>
</div>