# Real-Time Webhook Follow-Up Automation & Simplified Trigger Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mengotomatiskan penjadwalan alur follow-up 6 tahap begitu chat pemicu pendaftaran WhatsApp baru masuk via webhook, serta menghadirkan antarmuka pengelolaan pola pemicu yang sederhana dan fleksibel di CRM.

**Architecture:** 
1. Penambahan kolom `category` pada `crm_automation_rules` dan `trigger_source` pada `crm_automation_queue`.
2. Pengayaan daftar pola pemicu pendaftaran paket bawaan (`Tahfidz Cilik`, `Menghafal Pemula`, `Menghafal Lanjutan`, `Penguatan Hafalan`, `Hero`, `Form Nama`).
3. Pembuatan fungsi handler `crmAutomationHandleIncomingWebhook` yang dipanggil langsung oleh `webhook.php`.
4. Penyederhanaan form dan tabel di Tab Pola Pemicu (`page=automation-followup&tab=rules`) untuk tambah, edit, hapus, dan toggle pola chat masuk secara instan.
5. Pembaruan indikator badge prospek (Otomatis vs Manual) di Tab Prospek.

**Tech Stack:** PHP 8+, MySQL/MariaDB (MySQLi), HTML5/CSS, FontAwesome, JavaScript.

**Spec:** `docs/superpowers/specs/2026-10-25-automation-followup-realtime-triggers-design.md`

## Global Constraints
- Isolasi nomor CS internal Jawwada (`88223053149`, `248232064090227`) agar tidak pernah dijadwalkan follow-up.
- Kontak terdaftar sebagai pengajar (`crmChatIsTeacherNumber`) wajib dilewati dari automasi.
- Kontak yang sudah menerima pesan Payment Finance (`crmAutomationHasReceivedPayment`) tidak boleh dijadwalkan.
- Alur sequence standar wajib menggunakan 6 tahap pesan umum (`Follow Up 1` s/d `Follow Up 6`) dengan jeda terstandar (10m, 1j, 5j, 24j, 3h, 7h).
- Tombol manual *"Jadwalkan Alur (6 Tahap)"* tetap dipertahankan untuk prospek lama.

## Review Focus
1. Chat masuk pemicu variasi huruf besar/kecil (misal: "kak, saya ingin daftar tahfidz cilik") tetap terdeteksi dengan tepat.
2. Kontak yang mengirim pesan pemicu ganda berturut-turut tidak membuat antrean terduplikasi/tumpang tindih.
3. Chat masuk dari pengajar atau nomor admin internal sama sekali tidak membuat antrean follow-up.
4. Perubahan atau penambahan kata kunci di form web langsung efektif secara real-time pada webhook berikutnya tanpa deploy/restart.
5. Pembatalan otomatis (`cancelled_replied` atau `cancelled_payment`) tetap berjalan saat calon peserta membalas chat atau membayar.

---

### Task 1: Schema Updates & Trigger Pattern Engine

**Files:**
- Modify: `crm/config/automation-followup.php`
- Test: `crm/tests/automation-followup-db-test.php`

**Interfaces:**
- Produces: 
  - `crmAutomationGetActiveTriggers(mysqli $conn): array`
  - `crmAutomationFindMatchingTrigger(mysqli $conn, string $message): ?array`
  - Parameter `string $source = 'manual'` pada `crmAutomationScheduleSequence(...)`

- [ ] **Step 1: Write database & trigger helper tests in `crm/tests/automation-followup-db-test.php`**
Tambahkan pengujian ketersediaan kolom `category`, fungsi `crmAutomationGetActiveTriggers`, dan pencocokan pola chat `Kak, saya ingin daftar Tahfidz Cilik` serta `Kak, saya ingin daftar Kelas Menghafal Pemula`.

- [ ] **Step 2: Jalankan test dan verifikasi kegagalan awal**
Verifikasi bahwa fungsi baru belum ada sebelum implementasi.

- [ ] **Step 3: Implementasikan migrasi schema & helper di `crm/config/automation-followup.php`**
- Pastikan kolom `category VARCHAR(50) DEFAULT 'general'` ditambahkan ke `crm_automation_rules`.
- Pastikan kolom `trigger_source VARCHAR(30) DEFAULT 'manual'` ditambahkan ke `crm_automation_queue`.
- Seed 11 pola pemicu bawaan termasuk variasi tombol LP pendaftaran (`Tahfidz Cilik`, `Menghafal Pemula`, `Menghafal Lanjutan`, `Penguatan Hafalan`, `Hero`, `Form Nama`).
- Buat fungsi `crmAutomationGetActiveTriggers($conn)` dan `crmAutomationFindMatchingTrigger($conn, $message)`.
- Perbarui `crmAutomationScheduleSequence` agar menyimpan nilai `trigger_source`.

- [ ] **Step 4: Jalankan verifikasi test**
Pastikan test database dan helper trigger lulus.

- [ ] **Step 5: Commit perubahan Task 1**
```bash
git add crm/config/automation-followup.php crm/tests/automation-followup-db-test.php
git commit -m "feat(crm): enhance automation triggers schema and keyword matching engine"
```

---

### Task 2: Webhook Real-Time Handler Integration

**Files:**
- Modify: `crm/config/automation-followup.php`
- Modify: `webhook.php`
- Test: `crm/tests/automation-followup-trigger-test.php`

**Interfaces:**
- Produces: `crmAutomationHandleIncomingWebhook(mysqli $conn, string $nowa, string $message, ?string $nama): array`
- Consumes: `crmChatIsTeacherNumber()`, `crmAutomationIsOperationalNumber()`, `crmAutomationFindMatchingTrigger()`, `crmAutomationScheduleSequence()`

- [ ] **Step 1: Buat skenario pengujian webhook trigger di `crm/tests/automation-followup-trigger-test.php`**
Uji pemanggilan `crmAutomationHandleIncomingWebhook` dengan nomor prospek baru, nomor pengajar (harus di-skip), nomor CS (harus di-skip), dan pesan non-pemicu (harus di-skip).

- [ ] **Step 2: Jalankan test dan verifikasi kegagalan**
Verifikasi fungsi handler belum didefinisikan.

- [ ] **Step 3: Implementasikan `crmAutomationHandleIncomingWebhook` di `crm/config/automation-followup.php`**
Implementasikan alur lengkap:
1. Normalisasi nomor & filter nomor internal / guru / blokir / pembayaran.
2. Cari aturan aktif via `crmAutomationFindMatchingTrigger`.
3. Jika cocok, panggil `crmAutomationScheduleSequence($conn, $nowa, $nama, 0, $message, 'auto_webhook')`.
4. Kembalikan array status status eksekusi.

- [ ] **Step 4: Sambungkan pemanggilan di `webhook.php`**
Panggil `crmAutomationHandleIncomingWebhook` tepat setelah pesan chat masuk berhasil disimpan ke database dan lolos cek bukan nomor pengajar.

- [ ] **Step 5: Verifikasi test integrasi webhook**
Jalankan test dan pastikan seluruh skenario lulus.

- [ ] **Step 6: Commit perubahan Task 2**
```bash
git add crm/config/automation-followup.php webhook.php crm/tests/automation-followup-trigger-test.php
git commit -m "feat(crm): trigger automated 6-step follow-up on incoming webhook chat"
```

---

### Task 3: Simplified Trigger Management UI & Actions

**Files:**
- Modify: `crm/actions/automation-followup.php`
- Modify: `crm/pages/automation-followup.php`
- Modify: `crm/assets/css/automation-followup.css`
- Test: `crm/tests/automation-followup-actions-test.php`

**Interfaces:**
- Actions: `save_rule`, `toggle_rule`, `delete_rule`
- UI: Tab `rules` dengan formulir 3-input dan tabel interaktif daftar pola pemicu.

- [ ] **Step 1: Tulis test actions di `crm/tests/automation-followup-actions-test.php`**
Uji penambahan pola pemicu baru (`save_rule`), toggle status aktif (`toggle_rule`), dan penghapusan pola (`delete_rule`).

- [ ] **Step 2: Jalankan test dan verifikasi kegagalan awal**

- [ ] **Step 3: Perbarui logic action di `crm/actions/automation-followup.php`**
- Sederhanakan `save_rule` agar hanya membutuhkan `trigger_keyword`, `name`, `category`, dan `is_active`. Template ID dan delay otomatis dialokasikan default.
- Tambahkan handler `delete_rule` untuk menghapus pola dari tabel `crm_automation_rules`.

- [ ] **Step 4: Rombak antarmuka Tab Pola Pemicu di `crm/pages/automation-followup.php`**
- Ganti form rumit lama dengan form 3 input yang bersih dan ringkas.
- Hadirkan tabel daftar pola pemicu lengkap dengan badge kategori warna-warni, toggle aktif/nonaktif, tombol Edit instan, dan tombol Hapus.
- Tambahkan styling pendukung di `crm/assets/css/automation-followup.css`.

- [ ] **Step 5: Verifikasi test actions dan render HTML**
Pastikan aksi CRUD pola pemicu berjalan mulus.

- [ ] **Step 6: Commit perubahan Task 3**
```bash
git add crm/actions/automation-followup.php crm/pages/automation-followup.php crm/assets/css/automation-followup.css crm/tests/automation-followup-actions-test.php
git commit -m "feat(crm): simplify trigger patterns management UI and CRUD actions"
```

---

### Task 4: Prospects Tab Display & Distinction

**Files:**
- Modify: `crm/pages/automation-followup.php`
- Test: `crm/tests/automation-followup-ui-test.php`

**Interfaces:**
- UI: Menampilkan badge `Terjadwal (Otomatis)` vs `Terjadwal (Manual)`, serta memastikan tombol `Jadwalkan Alur (6 Tahap)` tetap ada untuk prospek lama.

- [ ] **Step 1: Tambahkan assertion tampilan di `crm/tests/automation-followup-ui-test.php`**
Verifikasi token UI seperti badge `Otomatis`, penanganan kategori LP, dan tombol manual.

- [ ] **Step 2: Update logika render baris prospek di `crm/pages/automation-followup.php`**
- Jika prospek memiliki antrean dengan `trigger_source = 'auto_webhook'`, tampilkan badge hijau `Terjadwal (Otomatis)`.
- Jika belum terjadwal, tampilkan tombol primer `Jadwalkan Alur (6 Tahap)`.
- Jika sudah terjadwal (baik otomatis maupun manual), sediakan tombol `Stop` untuk menghentikan sewaktu-waktu.

- [ ] **Step 3: Jalankan verifikasi test UI**
Pastikan semua assertion UI lulus.

- [ ] **Step 4: Commit perubahan Task 4**
```bash
git add crm/pages/automation-followup.php crm/tests/automation-followup-ui-test.php
git commit -m "feat(crm): distinguish auto-scheduled vs manual prospects on UI"
```

---

### Task 5: End-to-End Regression & Verification

**Files:**
- Create: `crm/tests/automation-followup-webhook-e2e-test.php`
- Run all test suites in `crm/tests/`

- [ ] **Step 1: Buat skrip pengujian E2E simulasi webhook chat masuk**
Simulasikan skenario:
1. Calon peserta mengirim chat *"Kak, saya ingin daftar Tahfidz Cilik"*.
2. Webhook mendeteksi pesan, menyimpan pesan, dan otomatis membuat 6 antrean berurutan (+10m, +1j, +5j, +24j, +3d, +7d).
3. Halaman CRM menampilkan kontak dengan status `Terjadwal (Otomatis)` kategori `Tahfidz Cilik`.
4. Simulasi calon peserta membalas pesan, antrean pending otomatis dibatalkan (`cancelled_replied`).

- [ ] **Step 2: Jalankan semua regression test**
Jalankan seluruh test di direktori `crm/tests/`.

- [ ] **Step 3: Update knowledge graphify**
Jalankan `graphify update .` untuk memperbarui graph basis kode.

- [ ] **Step 4: Commit pengujian final & dokumentasi**
```bash
git add crm/tests/
git commit -m "test(crm): add end-to-end regression tests for realtime webhook follow-up automation"
```
