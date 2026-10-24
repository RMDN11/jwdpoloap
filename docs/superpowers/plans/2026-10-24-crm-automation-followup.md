# CRM Automation Follow-Up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun modul dan halaman baru di CRM Jawwada (`?page=automation-followup`) untuk otomatisasi follow-up berjenjang bagi calon peserta yang telah menerima balasan awal webhook, lengkap dengan engine antrean, pembatalan otomatis saat user merespons, manajemen template mandiri, dan monitoring antrean aktif.

**Architecture:** Modul mengadopsi arsitektur event-driven terintegrasi: saat webhook/auto-reply mengirimkan balasan instan pemicu program, kontak otomatis didaftarkan ke antrean terjadwal (`crm_automation_queue`) dengan jeda waktu terkonfigurasi. Runner berkala memeriksa antrean jatuh tempo, mengevaluasi *stop conditions* (apakah calon peserta sudah membalas di `log_wa` atau terdaftar di `blocked_peserta`), lalu merender template khusus dan mengeksekusi pengiriman pesan via gateway WhatsApp.

**Tech Stack:** PHP 8.x (strict types, MySQLi prepared statements), Vanilla CSS3 (CRM App-Shell), cURL HTTP API client, Font Awesome 6.6.0.

**Spec:** Dokumen spesifikasi fungsional automasi follow-up program Tahfidz Private Jawwada pada prompt diskusi 2026-10-24.

## Global Constraints

- Wajib menggunakan `declare(strict_types=1);` di seluruh file PHP baru.
- Tidak boleh melakukan *hardcode* token API, password, maupun kredensial rahasia apa pun.
- Setiap mutasi data HTTP POST wajib memvalidasi token CSRF via `crmVerifyCsrf($_POST['csrf'] ?? null)`.
- Seluruh kueri basis data interaktif wajib menggunakan MySQLi *prepared statements*.
- Zona waktu sistem wajib diseragamkan ke `Asia/Jakarta` (`date_default_timezone_set('Asia/Jakarta')`).
- Kompatibel penuh dengan struktur app-shell dan tema warna CRM Jawwada (`#166534`, mobile-first responsive).

## Review Focus

1. **Kontak Membalas Sebelum Jadwal**: Jika ada pesan masuk baru dari nomor kontak di `log_wa` setelah waktu pendaftaran antrean, antrean harus ditandai `cancelled_replied` dan pesan tidak boleh dikirim.
2. **Deduplikasi Pemicu Berulang**: Jika kontak mengirim pesan pemicu yang sama beberapa kali dalam jendela waktu tunggu, sistem tidak boleh menduplikasi antrean aktif (antrean aktif sebelumnya diperbarui atau dipertahankan).
3. **Kontak Masuk Daftar Blokir (`blocked_peserta`)**: Jika kontak diblokir sebelum antrean terkirim, engine harus menandai `cancelled_blocked` dan mengabaikan pengiriman.
4. **Normalisasi Nomor Telepon Konsisten**: Nomor telepon input (`08...`, `628...`, `+628...`) wajib dinormalisasi secara seragam via `crmProspectNormalizeNumber()`.
5. **Penanganan Kegagalan API Gateway WhatsApp**: Error HTTP / timeout cURL tidak boleh menyebabkan crash; status antrean harus diubah menjadi `failed` beserta ringkasan log error.

---

### Task 1: Database Schema & Configuration Helpers (`crm/config/automation-followup.php`)

**Files:**
- Create: `/Users/macbookair/jwdpoloap/crm/config/automation-followup.php`
- Test: `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-db-test.php`

**Interfaces:**
- Consumes: `$conn` (mysqli) dari `crm/config/bootstrap.php`, `crmProspectNormalizeNumber()` dari `crm/config/prospect.php`.
- Produces: 
  - `crmAutomationEnsureTables(mysqli $conn): bool`
  - `crmAutomationRegisterQueue(mysqli $conn, string $nowa, ?string $nama, int $ruleId, int $delayMinutes): int`
  - `crmAutomationHasUserReplied(mysqli $conn, string $nowa, string $registeredAt): bool`
  - `crmAutomationIsBlocked(mysqli $conn, string $nowa): bool`

- [ ] **Step 1: Write the failing test**

Buat file `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-db-test.php` yang menguji:
1. Normalisasi nomor kontak sebelum masuk antrean.
2. Fungsi evaluasi pesan balasan pelanggan (`crmAutomationHasUserReplied`).
3. Perhitungan waktu `scheduled_at` berdasarkan `delayMinutes`.

- [ ] **Step 2: Run test to verify it fails**

Jalankan test helper untuk memastikan fungsi belum terdefinisi.

- [ ] **Step 3: Implement `crm/config/automation-followup.php`**

Implementasikan pembuatan tabel `crm_automation_rules`, `crm_automation_templates`, dan `crm_automation_queue` jika belum ada (CREATE TABLE IF NOT EXISTS), serta fungsi-fungsi query helper dengan prepared statements.

- [ ] **Step 4: Run test to verify it passes**

Pastikan seluruh logika kueri lolos verifikasi tanpa error sintaks.

- [ ] **Step 5: Commit**

```bash
git add crm/config/automation-followup.php crm/tests/automation-followup-db-test.php
git commit -m "feat(crm): add automation follow-up database schema and helper functions"
```

---

### Task 2: Automation Execution Engine (`crm/lib/automation-engine.php`)

**Files:**
- Create: `/Users/macbookair/jwdpoloap/crm/lib/automation-engine.php`
- Test: `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-engine-test.php`

**Interfaces:**
- Consumes: Fungsi dari `crm/config/automation-followup.php` dan skema template pesan.
- Produces:
  - `crmAutomationRenderMessage(string $template, array $data): string`
  - `crmAutomationProcessPendingQueue(mysqli $conn, string $apiUrl, string $apiToken): array`
  - `crmAutomationCancelQueueItem(mysqli $conn, int $queueId, string $reason): bool`

- [ ] **Step 1: Write the failing test**

Buat `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-engine-test.php` yang memverifikasi:
1. Substitusi variabel template (`{nama}`, `{sapaan}`, `{program}`).
2. Deteksi status pembatalan saat pelanggan sudah membalas chat.
3. Struktur payload JSON untuk pengiriman API WhatsApp.

- [ ] **Step 2: Run test to verify it fails**

Pastikan test mendeteksi fungsi belum dibuat.

- [ ] **Step 3: Implement `crm/lib/automation-engine.php`**

Buat implementasi engine pemrosesan:
- Menarik antrean berstatus `pending` dengan `scheduled_at <= NOW()`.
- Melakukan verifikasi *stop conditions* (chat baru masuk di `log_wa` atau nomor diblokir).
- Merender pesan template dengan sanitasi.
- Mengirim pesan via cURL ke API Gateway OneSender/WA Gateway dengan timeout 10 detik.
- Mengupdate status antrean menjadi `sent`, `cancelled_replied`, atau `failed`.

- [ ] **Step 4: Run test to verify it passes**

Jalankan file test untuk memastikan substitusi template dan logika filter antrean bekerja 100%.

- [ ] **Step 5: Commit**

```bash
git add crm/lib/automation-engine.php crm/tests/automation-followup-engine-test.php
git commit -m "feat(crm): implement automation follow-up processing engine"
```

---

### Task 3: Backend Action Handlers (`crm/actions/automation-followup.php`)

**Files:**
- Create: `/Users/macbookair/jwdpoloap/crm/actions/automation-followup.php`
- Test: `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-actions-test.php`

**Interfaces:**
- Consumes: `$_POST` form data, `crmVerifyCsrf()`, session flash messages `$_SESSION['crm_flash']`.
- Produces: Menangani aksi HTTP POST: `save_rule`, `toggle_rule`, `save_template`, `delete_template`, `cancel_queue`, dan `send_now`.

- [ ] **Step 1: Write the failing test**

Buat file test `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-actions-test.php` yang menguji validasi parameter POST, proteksi CSRF token palsu, dan struktur return redirect URL.

- [ ] **Step 2: Run test to verify it fails**

Verifikasi file action belum ada.

- [ ] **Step 3: Implement `crm/actions/automation-followup.php`**

Bangun penangan aksi:
- Validasi metode POST dan CSRF token.
- Sanitasi input nama template, isi pesan, kata kunci pemicu, jeda waktu (menit/jam).
- Menyimpan aturan ke `crm_automation_rules` dan template ke `crm_automation_templates`.
- Aksi tombol antrean: `cancel_queue` (membatalkan secara manual) dan `send_now` (memaksa kirim instan saat itu juga).
- Mengatur pesan notifikasi flash (`$_SESSION['crm_flash']`) dan redirect kembali ke halaman `?page=automation-followup`.

- [ ] **Step 4: Run test to verify it passes**

Pastikan seluruh skenario aksi berhasil lolos pengujian regresi.

- [ ] **Step 5: Commit**

```bash
git add crm/actions/automation-followup.php crm/tests/automation-followup-actions-test.php
git commit -m "feat(crm): add action handlers for automation follow-up management"
```

---

### Task 4: Webhook & Trigger Hooking (`crm/config/automation-followup.php` integration)

**Files:**
- Modify: `/Users/macbookair/jwdpoloap/auto_reply_engine.php` (menghubungkan pemicu ke antrean follow-up otomatis)
- Test: `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-trigger-test.php`

**Interfaces:**
- Consumes: Event pengiriman auto-reply pertama pada `AutoReplyEngine::processIncomingMessage()`.
- Produces: Pendaftaran otomatis nomor calon peserta ke antrean follow-up jika pesan cocok dengan aturan aktif.

- [ ] **Step 1: Write the failing test**

Buat `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-trigger-test.php` untuk menguji skenario:
Ketika pesan masuk cocok dengan aturan "Tahfidz Private", selain mengirim pesan balasan instan, fungsi pendaftaran antrean follow-up terpicu secara otomatis.

- [ ] **Step 2: Run test to verify it fails**

Verifikasi test mendeteksi belum adanya integrasi.

- [ ] **Step 3: Wire trigger registration in auto-reply flow**

Tambahkan pemanggilan aman ke `crmAutomationRegisterQueue()` di dalam alur pesan masuk:
- Pengecekan aman (`function_exists` / fail-closed jika modul CRM belum aktif).
- Memastikan tidak ada duplikasi antrean aktif untuk nomor yang sama dalam siklus tunggu berjalan.

- [ ] **Step 4: Run test to verify it passes**

Pastikan test integrasi pemicu sukses.

- [ ] **Step 5: Commit**

```bash
git add auto_reply_engine.php crm/tests/automation-followup-trigger-test.php
git commit -m "feat(crm): connect incoming auto-reply trigger to follow-up automation queue"
```

---

### Task 5: CRM Frontend UI & Styling (`crm/pages/automation-followup.php`)

**Files:**
- Create: `/Users/macbookair/jwdpoloap/crm/pages/automation-followup.php`
- Create: `/Users/macbookair/jwdpoloap/crm/assets/css/automation-followup.css`
- Modify: `/Users/macbookair/jwdpoloap/crm/index.php` (tambahkan `'automation-followup'` ke `$allowedPages` dan include CSS)
- Modify: `/Users/macbookair/jwdpoloap/crm/pages/more.php` (tambahkan kartu tautan ke Automation Follow Up)
- Test: `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-ui-test.php`

**Interfaces:**
- Consumes: Sesi login CRM, data dari `crm_automation_rules`, `crm_automation_templates`, dan `crm_automation_queue`.
- Produces: Antarmuka berbasis tab responsif:
  - **Tab 1: Aturan Alur (Sequence Rules)**: Form pemicu pesan, jeda waktu, pilihan template.
  - **Tab 2: Template Follow-Up**: Editor teks, variabel dinamis, live bubble preview.
  - **Tab 3: Antrean & Log Aktif**: Tabel interaktif calon peserta pending, status terkirim/batal, tombol aksi cepat.

- [ ] **Step 1: Write the failing test**

Buat `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-ui-test.php` yang memverifikasi bahwa `index.php?page=automation-followup` terdaftar di `$allowedPages`, file halaman dapat dimuat tanpa fatal error, dan komponen tab terdefinisi.

- [ ] **Step 2: Run test to verify it fails**

Pastikan test gagal sebelum rute dan file halaman dibuat.

- [ ] **Step 3: Implement page view, styling, and navigation integration**

- Daftarkan halaman di `crm/index.php` dan `crm/pages/more.php`.
- Tulis file `crm/pages/automation-followup.php` lengkap dengan render tab, kartu bento metrik antrean, form modal, dan badge status warna.
- Tulis stylesheet `crm/assets/css/automation-followup.css` yang serasi dengan desain workspace CRM Jawwada.

- [ ] **Step 4: Run test to verify it passes**

Pastikan test routing dan render UI lolos validasi.

- [ ] **Step 5: Commit**

```bash
git add crm/index.php crm/pages/more.php crm/pages/automation-followup.php crm/assets/css/automation-followup.css crm/tests/automation-followup-ui-test.php
git commit -m "feat(crm): add automation follow-up workspace page and styling"
```

---

### Task 6: Cron/Worker Runner & End-to-End Verification

**Files:**
- Create: `/Users/macbookair/jwdpoloap/crm/actions/automation-followup-cron.php`
- Modify: `.git/info/exclude` (memastikan file log sementara terisolasi)
- Test: `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-e2e-test.php`

**Interfaces:**
- Consumes: Skrip CLI / Webhook Cron yang dipanggil secara berkala via crontab cPanel (misal setiap 5 atau 10 menit).
- Produces: Output ringkasan eksekusi: `[CRM_AUTOMATION] Processed: X sent, Y cancelled, Z failed`.

- [ ] **Step 1: Write the failing test**

Buat `/Users/macbookair/jwdpoloap/crm/tests/automation-followup-e2e-test.php` yang menjalankan simulasi ujung-ke-ujung (E2E): pendaftaran antrean -> verifikasi waktu jatuh tempo -> pembatalan jika user membalas -> eksekusi pengiriman.

- [ ] **Step 2: Run test to verify it fails**

Pastikan skrip runner belum dibuat.

- [ ] **Step 3: Implement cron runner `automation-followup-cron.php`**

Bangun skrip runner:
- Mengamankan pemanggilan via CLI atau secret token query param jika dipanggil via URL cPanel.
- Menjalankan `crmAutomationProcessPendingQueue()`.
- Menulis log diagnostik ringkas untuk audit cPanel.

- [ ] **Step 4: Run test to verify it passes**

Pastikan simulasi E2E selesai dengan sukses dan seluruh pengujian lolos.

- [ ] **Step 5: Update Graphify Knowledge Graph**

Jalankan pembaruan Graphify agar modul dan simbol baru langsung terpetakan:
```bash
graphify update .
```

- [ ] **Step 6: Commit**

```bash
git add crm/actions/automation-followup-cron.php crm/tests/automation-followup-e2e-test.php
git commit -m "feat(crm): add cron runner and comprehensive e2e test for automation follow-up"
```
