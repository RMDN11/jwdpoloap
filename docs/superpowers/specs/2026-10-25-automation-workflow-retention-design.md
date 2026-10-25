# Desain Spesifikasi: Retensi Baris & Scroll Saat Aksi, Optimasi Workflow, dan UI/UX Automation Follow-Up

## 1. Konteks & Kebutuhan Pengguna
Setelah fitur automasi follow-up dan pembersihan tabel diterapkan, Admin membutuhkan stabilitas antarmuka dan alur kerja (workflow) yang seamless:
1. **Retensi Posisi Baris & Scroll (Row & Scroll Retention):**
   - Saat Admin melakukan tindakan apa pun (misal: "Jadwalkan Alur (6 Tahap)", "Stop", "Kirim", "Antre 1", toggle status rule, hapus rule/template, batal antrean), halaman tidak boleh melompat kembali ke halaman 1 paling atas `(0, 0)`.
   - Halaman harus tetap berada pada nomor halaman pagination (`p`), filter kategori (`cfilter`), filter finance (`pfilter`), status antrean (`qstatus`), dan fokus otomatis pada baris kontak yang baru ditindaklanjuti.
2. **Penyempurnaan Workflow & UI/UX Tuntas (Agar Tidak Perlu Update Berulang):**
   - **Pencarian Cepat (Search Bar):** Fitur cari nomor WhatsApp atau nama prospek di Tab Prospek & Tab Antrean.
   - **Penghapusan Hardcoded `LIMIT 100` pada Queue:** Agar seluruh riwayat antrean masa lalu (>100 data) tetap dapat diakses via pagination tanpa terpotong.
   - **Debounce / Cegah Double Submit:** Mencegah klik ganda pada tombol aksi yang mengirim 2 kali request ke server.
   - **Konfirmasi Aksi Jadwal Massal:** Dialog konfirmasi sebelum mengeksekusi "Jadwalkan Alur (6 Tahap)" untuk mencegah salah klik.
   - **Tombol Satu-Klik "Reset Filter":** Saat filter menghasilkan 0 data, sediakan tombol cepat untuk mereset filter.
   - **Tombol "Refresh Data":** Memungkinkan admin menyinkronkan data secara berkala tanpa kehilangan posisi filter.

---

## 2. Sasaran (Goals), Non-Sasaran (Non-Goals), & Anti-Sasaran (Anti-Goals)
- **Goals:**
  - Admin tetap berada di halaman pagination, filter, dan posisi scroll yang sama persis setelah submit aksi.
  - Baris yang baru saja dimodifikasi mendapatkan indikator visual highlight (`:target` pulse) selama 2 detik.
  - Pencarian nomor dan nama dapat dilakukan langsung di Tab Prospek dan Tab Antrean.
  - Tab Antrean dapat memuat seluruh data riwayat secara akurat tanpa batasan 100 baris.
  - Form aksi terlindungi dari multiple rapid clicks.
- **Non-Goals:**
  - Mengubah logika inti matching trigger auto-reply atau struktur alur 6 tahap yang sudah berjalan sukses.
- **Anti-Goals:**
  - Mengharuskan instalasi library JavaScript berat / SPA framework. Semua tetap menggunakan Vanilla JS dan native CSS yang ringan dan cepat.

---

## 3. Arsitektur Teknis

### A. Mekanisme Retensi Baris & URL (`return_url` & `#anchor`)
1. **Hidden Input `return_url`:**
   Setiap form aksi di `crm/pages/automation-followup.php` memuat input hidden:
   ```html
   <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
   ```
2. **Anchor ID Unik Setiap Baris:**
   - Tab Prospek: `id="row-p-<?= preg_replace('/\D/', '', $item['nowa']) ?>"`
   - Tab Rules: `id="row-rule-<?= (int)$r['id'] ?>"`
   - Tab Template: `id="row-tpl-<?= (int)$t['id'] ?>"`
   - Tab Antrean: `id="row-queue-<?= (int)$q['id'] ?>"`
3. **Controller Redirect (`crmAutoRedirect` di `crm/actions/automation-followup.php`):**
   ```php
   function crmAutoRedirect(string $tab = 'rules', ?string $returnUrl = null, ?string $anchor = null): void
   {
       $target = 'index.php?page=automation-followup&tab=' . urlencode($tab);
       if (!empty($returnUrl)) {
           // Validasi internal path agar aman dari open redirect
           $parsed = parse_url($returnUrl);
           $path = $parsed['path'] ?? '';
           $query = !empty($parsed['query']) ? '?' . $parsed['query'] : '';
           if (str_contains($path, 'index.php') || $path === '' || str_starts_with($path, '?')) {
               $target = 'index.php' . $query;
           }
       }
       if (!empty($anchor)) {
           $target .= '#' . ltrim($anchor, '#');
       }
       header('Location: ' . $target);
       exit;
   }
   ```
4. **Visual Highlight Animasi CSS (`automation-followup.css`):**
   ```css
   @keyframes crmRowPulse {
       0% { background-color: rgba(22, 101, 52, 0.2); }
       50% { background-color: rgba(34, 197, 94, 0.15); }
       100% { background-color: transparent; }
   }
   tr:target, .crm-card:target {
       animation: crmRowPulse 2.5s ease-out;
   }
   ```
5. **Scroll Restoration Memory (`sessionStorage`):**
   - Saat form dikirim, catat `sessionStorage.setItem('crm_scroll_y', window.scrollY)`.
   - Saat `DOMContentLoaded`, jika ada `sessionStorage.getItem('crm_scroll_y')`, lakukan `window.scrollTo(0, scrollY)` dan bersihkan storage jika tidak ada hash `:target`.

### B. Pencarian Nomor / Nama (Tab Prospek & Antrean)
- Parameter query string: `search` (misal: `&search=081234`).
- Pada Tab Prospek: Filter array prospek dengan pencocokan case-insensitive pada `nowa` atau `nama`.
- Pada Tab Antrean: Filter query SQL dengan `AND (q.nowa LIKE '%...%' OR q.nama LIKE '%...')`.
- Parameter `search` disertakan dalam link pagination dan filter tab.

### C. Pembongkaran Hardcode `LIMIT 100` pada Tab Antrean
- Hapus batasan statis `LIMIT 100` pada query Tab Queue.
- Hitung total antrean dinamis sesuai status & pencarian (`SELECT COUNT(*) FROM crm_automation_queue WHERE ...`).
- Terapkan pagination yang sama dengan batas item yang dinamis dan terstruktur.

### D. Debounce & Loading Spinner Form
- Pada setiap submit form aksi follow-up, secara otomatis disable tombol submit dan tampilkan animasi spinner FontAwesome `<i class="fa-solid fa-spinner fa-spin"></i>` untuk mencegah double click.
- Konfirmasi `confirm('Jadwalkan 6 tahap follow-up untuk kontak ini?')` pada tombol utama sequence.

### E. Tombol "Reset Filter" & "Refresh Data"
- Ketika hasil pencarian atau filter menghasilkan 0 data:
  Sediakan link tombol aksi langsung: `Reset Semua Filter` ke `?page=automation-followup&tab=prospects&pfilter=all&cfilter=all`.
- Di header tabel: Sediakan tombol `Refresh Data` yang mempertahankan query string aktif.
