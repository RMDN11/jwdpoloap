# Pembersihan Baris Usang, Pagination, Filter Pemicu Rules, dan Salin Nomor WhatsApp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membersihkan baris aturan lama yang tidak relevan, menerapkan sistem pagination responsif pada tabel, memfilter daftar prospek agar hanya menampilkan pemicu yang sesuai rules aktif, dan menambahkan fitur salin nomor serta tombol pintas WhatsApp langsung.

**Architecture:**
1. Modifikasi seeder di `crm/config/automation-followup.php` untuk menghapus generasi baris `Tahfidz Private - Follow Up %`, serta menambahkan eksekusi pembersihan otomatis ke database via migration flag di `crm_automation_meta`.
2. Penyempurnaan filter daftar prospek di `crm/pages/automation-followup.php` menggunakan `crmAutomationFindMatchingTrigger` sehingga hanya memunculkan chat masuk yang cocok dengan rules aktif (atau yang memiliki antrean aktif).
3. Penambahan fungsi pembantu pagination di PHP dan komponen UI pagination di Tab Prospek, Rules, dan Queue.
4. Penambahan tombol Copy Nomor WhatsApp interaktif (dengan visual badge `Disalin!`) dan tombol direct link `https://wa.me/{nowa}`.
5. Pembaruan styling di `crm/assets/css/automation-followup.css` dan pembuatan regression test suites di `crm/tests/`.

**Tech Stack:** PHP 8+, JavaScript Native, MySQLi, FontAwesome 6, CSS3.

**Spec:** `docs/superpowers/specs/2026-10-25-cleanup-pagination-whatsapp-actions-design.md`

## Global Constraints
- Jangan mengubah template 6 tahap umum Follow Up 1 s/d 6 yang sudah berjalan.
- Jeda waktu alur (10m, 1j, 5j, 24j, 3d, 7d) harus tetap dipertahankan.
- Pagination harus mempertahankan query parameter yang sedang aktif (`tab`, `cat`, `pfilter`, `qstatus`, `search`).
- Tombol salin nomor harus bekerja tanpa dependensi library eksternal (menggunakan `navigator.clipboard` dengan fallback).

## Review Focus
1. Baris `Tahfidz Private - Follow Up 1..6` terhapus dari tabel dan tidak muncul lagi setelah halaman di-refresh.
2. Calon prospek dengan chat acak yang tidak memuat pola kata kunci aktif tidak muncul di Tab Prospek.
3. Pagination membagi data secara akurat (15 item per halaman) dan navigasi halaman berfungsi mulus.
4. Tombol copy nomor menyalin nomor telepon WhatsApp yang bersih dan memberikan umpan balik visual instan.
5. Tombol icon WhatsApp membuka obrolan ke `https://wa.me/{nowa}` di tab baru.

---

### Task 1: Clean Up Legacy Follow-Up Rules from Seeder & Database

**Files:**
- Modify: `crm/config/automation-followup.php:200-230`
- Modify: `crm/config/automation-followup.php:400-470`
- Test: `crm/tests/automation-followup-cleanup-test.php`

**Interfaces:**
- `crmAutomationEnsureTables(mysqli $conn): bool`
- Migration flag: `legacy_fu_rules_cleaned_v1` in `crm_automation_meta`

- [ ] **Step 1: Buat failing test untuk verifikasi pembersihan baris usang**
Verifikasi bahwa `Tahfidz Private - Follow Up %` dihapus dari `crm_automation_rules` dan tidak ada lagi kode seeding yang memasukkannya.

- [ ] **Step 2: Jalankan test untuk memastikan test gagal sebelum implementasi**

- [ ] **Step 3: Implementasi pembersihan di `crm/config/automation-followup.php`**
- Hapus blok `if ($tplId > 0 && str_starts_with($tpl['name'], 'Follow Up '))` pada `crmAutomationSeedDefaultTemplates()`.
- Tambahkan query pembersihan pada `crmAutomationEnsureTables()`:
  ```php
  $conn->query("DELETE FROM crm_automation_rules WHERE name LIKE 'Tahfidz Private - Follow Up %'");
  ```
- Tandai pada `crm_automation_meta` dengan kunci `legacy_fu_rules_cleaned_v1`.

- [ ] **Step 4: Jalankan test dan verifikasi lulus**

- [ ] **Step 5: Commit perubahan Task 1**
```bash
git add crm/config/automation-followup.php crm/tests/automation-followup-cleanup-test.php
git commit -m "fix(crm): purge legacy follow-up rules from seeder and database"
```

---

### Task 2: Filter Prospects by Active Rules & Aligned Categories

**Files:**
- Modify: `crm/pages/automation-followup.php:140-195`
- Test: `crm/tests/automation-followup-prospect-filter-test.php`

- [ ] **Step 1: Buat failing test untuk filter prospek**
Test memverifikasi bahwa prospek tanpa antrean aktif dan tanpa kecocokan dengan `crmAutomationFindMatchingTrigger` otomatis di-skip.

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

- [ ] **Step 3: Implementasi filter prospek di `crm/pages/automation-followup.php`**
Cocokkan setiap baris pesan dengan `crmAutomationFindMatchingTrigger($conn, $firstTriggerMsg ?: $lastMsg)`.
Jika tidak ada antrean dan tidak cocok dengan rule aktif, abaikan baris tersebut.
Gunakan kategori dari rule yang cocok untuk memastikan keselarasan badge.

- [ ] **Step 4: Jalankan test dan verifikasi lulus**

- [ ] **Step 5: Commit perubahan Task 2**
```bash
git add crm/pages/automation-followup.php crm/tests/automation-followup-prospect-filter-test.php
git commit -m "feat(crm): filter prospects strictly to active matching automation rules"
```

---

### Task 3: Implement Reusable Table Pagination (15 Items/Page)

**Files:**
- Modify: `crm/pages/automation-followup.php:190-250`
- Modify: `crm/pages/automation-followup.php:500-600` (render pagination)
- Modify: `crm/assets/css/automation-followup.css`
- Test: `crm/tests/automation-followup-pagination-test.php`

- [ ] **Step 1: Buat failing test untuk pagination**
Test memverifikasi perhitungan halaman (`$page`, `$totalPages`, `$perPage = 15`), pemotongan array (`array_slice`), dan keberadaan komponen UI `.crm-pagination`.

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

- [ ] **Step 3: Implementasi logika dan UI pagination di `crm/pages/automation-followup.php`**
- Tambahkan parser `$currentPage = max(1, (int)($_GET['p'] ?? 1))` dan `$perPage = 15`.
- Potong data `$prospects`, `$rules`, atau `$queueItems` sesuai halaman aktif.
- Buat fungsi render navigasi pagination yang mempertahankan parameter URL lainnya.

- [ ] **Step 4: Tambahkan styling pagination di `crm/assets/css/automation-followup.css`**

- [ ] **Step 5: Jalankan test dan verifikasi lulus**

- [ ] **Step 6: Commit perubahan Task 3**
```bash
git add crm/pages/automation-followup.php crm/assets/css/automation-followup.css crm/tests/automation-followup-pagination-test.php
git commit -m "feat(crm): add responsive pagination for prospects, rules, and queue tables"
```

---

### Task 4: Interactive WhatsApp Copy Button & Direct WhatsApp Link

**Files:**
- Modify: `crm/pages/automation-followup.php:400-500` (kolom kontak)
- Modify: `crm/assets/css/automation-followup.css`
- Test: `crm/tests/automation-followup-copy-wa-test.php`

- [ ] **Step 1: Buat failing test untuk copy nomor dan link WhatsApp**
Test memverifikasi keberadaan tombol `btn-copy-wa`, link `https://wa.me/`, dan fungsi JavaScript `crmCopyWaNumber`.

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

- [ ] **Step 3: Implementasi tombol salin nomor dan link WhatsApp di `crm/pages/automation-followup.php`**
- Tambahkan tombol salin nomor dengan icon `<i class="fa-regular fa-copy"></i>`.
- Tambahkan link WA direct `<a href="https://wa.me/{clean_nowa}" target="_blank">`.
- Tambahkan fungsi JavaScript `crmCopyWaNumber(number, btnElement)` dengan efek animasi icon centang dan tooltip "Disalin!".

- [ ] **Step 4: Tambahkan styling tombol dan tooltip di `crm/assets/css/automation-followup.css`**

- [ ] **Step 5: Jalankan test dan verifikasi lulus**

- [ ] **Step 6: Commit perubahan Task 4**
```bash
git add crm/pages/automation-followup.php crm/assets/css/automation-followup.css crm/tests/automation-followup-copy-wa-test.php
git commit -m "feat(crm): add 1-click whatsapp copy button and direct chat link"
```

---

### Task 5: End-to-End Regression, Test Suite Updates, & Server Push

**Files:**
- Modify: `crm/tests/verify_suite.py`
- Run all test suites
- Update knowledge graph (`graphify update .`)
- Push to server (`git push origin main`)

- [ ] **Step 1: Hubungkan semua test baru ke `crm/tests/verify_suite.py`**
- [ ] **Step 2: Jalankan seluruh test suite (`python3 crm/tests/verify_suite.py all`)**
- [ ] **Step 3: Update basis pengetahuan graphify (`graphify update .`)**
- [ ] **Step 4: Push perubahan ke GitHub repository server (`git push origin main`)**
