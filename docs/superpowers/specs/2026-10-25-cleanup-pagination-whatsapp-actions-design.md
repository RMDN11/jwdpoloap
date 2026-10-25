# Desain Spesifikasi: Pembersihan Baris Lama, Pagination Tabel, Filter Pemicu Sesuai Rules, dan Fitur Salin/Cek WhatsApp

## 1. Konteks & Kebutuhan Pengguna
Setelah pengujian alur automasi follow-up chat real-time berjalan sukses, Admin menemukan beberapa hal yang perlu dioptimasi pada antarmuka `page=automation-followup`:
1. **Pembersihan Baris Lama (Legacy Rows):**
   - Menghapus aturan bawaan usang `Tahfidz Private - Follow Up 1 s/d 6` dari tabel `crm_automation_rules` yang sebelumnya di-seed secara terpisah untuk setiap template follow-up.
   - Memastikan aturan tersebut tidak lagi di-seed ulang di masa mendatang.
2. **Pagination Tabel:**
   - Mencegah tabel (khususnya Tab Prospek, Tab Pola Pemicu/Rules, dan Tab Antrean) memanjang ke bawah tanpa batas.
   - Menerapkan pagination bersih dan responsif (15 baris per halaman) lengkap dengan navigasi halaman (*Sebelumnya*, nomor halaman, *Berikutnya*) serta informasi jumlah data (contoh: *Menampilkan 1-15 dari 45 data*).
3. **Filter Pemicu & Kategori Sesuai Rules Aktif:**
   - Prospek chat masuk yang pemicu atau kategorinya tidak cocok dengan daftar aturan pemicu (`crm_automation_rules`) yang ada, tidak perlu dimunculkan di Tab Prospek agar data yang tampil benar-benar relevan dan terarah.
4. **Opsi Salin Nomor & Cek Langsung ke WhatsApp:**
   - Menambahkan tombol interaktif **Salin Nomor** (1-klik copy ke clipboard dengan indikator visual *Disalin!*).
   - Menambahkan tombol pintas **Buka WhatsApp** (`https://wa.me/...`) agar Admin dapat langsung membuka dan mengecek chat prospek di WhatsApp Web/App secara instan.

---

## 2. Rincian Teknis & Arsitektur

### A. Pembersihan Baris Usang di Backend (`crm/config/automation-followup.php`)
- Menghapus blok perulangan pembuatan aturan individual `Tahfidz Private - Follow Up 1..6` pada fungsi `crmAutomationSeedDefaultTemplates()`.
- Menambahkan migration query pada `crmAutomationEnsureTables()`:
  ```sql
  DELETE FROM crm_automation_rules WHERE name LIKE 'Tahfidz Private - Follow Up %';
  ```
  Dicatat pada `crm_automation_meta` dengan kunci `legacy_fu_rules_cleaned_v1` sehingga eksekusi bersih dan efisien.

### B. Filter Prospek Berdasarkan Rules Aktif (`crm/pages/automation-followup.php`)
- Setiap pesan prospek dicocokkan dengan aturan pemicu aktif menggunakan `crmAutomationFindMatchingTrigger($conn, $msg)`.
- Jika prospek tidak memiliki antrean aktif dan pesan masuknya tidak cocok dengan salah satu aturan pemicu aktif di `crm_automation_rules`, maka prospek tersebut diabaikan (*skip*) dan tidak ditampilkan di daftar prospek follow-up.
- Kategori badge prospek dipetakan secara akurat dari `category` aturan yang cocok (misal: `cilik`, `pemula`, `lanjutan`, `form_lead`, `hero`, `cta`, dll).

### C. Komponen Pagination Ringan & Reusable
- Parameter query string: `&p=1`, `&p=2`, dst. (mempertahankan parameter aktif lainnya seperti `tab`, `cat`, `pfilter`, `qstatus`).
- Ukuran halaman terstandar: 15 item per halaman.
- Fungsi pembantu pemotong array (*slice*) dan render tombol pagination HTML.

### D. Fitur Salin Nomor & Buka WhatsApp Langsung (UI)
- Komponen kolom nomor telepon menyertakan:
  - Tampilan nomor yang rapi.
  - Tombol icon salin `<button type="button" class="btn-copy-wa" onclick="crmCopyWaNumber('...', this)"><i class="fa-regular fa-copy"></i></button>` dengan tooltip feedback.
  - Tombol icon WhatsApp `<a href="https://wa.me/..." target="_blank" class="btn-open-wa"><i class="fa-brands fa-whatsapp"></i></a>`.
- Skrip JavaScript native untuk clipboard handling (`navigator.clipboard.writeText`) dengan fallback `document.execCommand`.
