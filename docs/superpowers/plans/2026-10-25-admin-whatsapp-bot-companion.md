# WhatsApp Admin Bot Companion (AI, To-Do, & Monitoring) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menghadirkan asisten WhatsApp interaktif khusus Admin Jawwada di `jwdpoloap` yang terintegrasi dengan OneSender Webhook untuk tanya-jawab AI (Gemini), pencatatan To-Do list, dan monitoring kesehatan/antrean sistem secara real-time.

**Architecture:** Webhook `webhook.php` mendeteksi apakah pengirim pesan terdaftar sebagai Admin (`6288223053149`). Jika ya, alur diarahkan ke handler khusus `crmAdminBotHandleMessage` di `crm/lib/admin-bot.php` untuk memproses perintah to-do (`crm_admin_todos`), query metrik sistem (`crm_automation_queue` & DB), atau query Gemini AI API, lalu mengirim balasan terformat kembali ke WhatsApp Admin via OneSender REST API tanpa mengganggu pipeline follow-up prospek.

**Tech Stack:** PHP 8+, MySQL (InnoDB utf8mb4), cURL (OneSender REST API & Gemini REST API), Python 3 test verification suite (`verify_suite.py`).

**Spec:** `docs/superpowers/specs/2026-10-25-admin-whatsapp-bot-companion-design.md`

## Global Constraints
- Nomor Admin (`6288223053149` dan yang terkonfigurasi) tidak boleh memicu auto-reply prospek publik atau antrean otomasi calon murid.
- Database table `crm_admin_todos` harus dibuat otomatis jika belum ada (`CREATE TABLE IF NOT EXISTS`) dengan index yang sesuai.
- Perintah bot tidak boleh case-sensitive dan harus menangani spasi berlebih dengan anggun.
- Jika API Gemini gagal atau belum diisi key, bot harus memberikan pesan balasan yang jelas dan tidak crash/fatal error.
- Balasan pesan via OneSender harus diformat rapi dengan emoji dan spasi yang nyaman dibaca di WhatsApp mobile.
- Semua pengujian harus terintegrasi dan lolos verifikasi di `crm/tests/verify_suite.py`.

## Review Focus
1. Nomor telepon admin yang masuk dengan format awalan `08...`, `628...`, `+628...`, atau diakhiri `@s.whatsapp.net` harus dinormalisasi dengan benar sehingga selalu teridentifikasi sebagai admin.
2. Perintah `!todo catat ...` dengan teks kosong atau format acak tidak boleh menghasilkan baris kosong di database.
3. Query `!status` dan `!queue` tidak boleh menyebabkan error ketika database antrean atau log masih kosong.
4. Saat query AI berjalan lama atau timeout, webhook tetap harus merespons HTTP 200 OK dengan format JSON valid agar OneSender tidak melakukan retry webhook terus-menerus.
5. Pesan dari bukan admin (prospek/calon murid) harus 100% berjalan normal seperti sebelumnya tanpa regresi.

---

### Task 1: Konfigurasi & Skema Database Admin Bot

**Files:**
- Create: `crm/config/admin-bot.php`
- Modify: `crm/config/bootstrap.php`
- Test: `crm/tests/admin-bot-test.php`

- [x] **Step 1: Buat skrip konfigurasi `crm/config/admin-bot.php`**
  - Definisikan konstanta konfigurasi:
    - `ADMIN_BOT_ALLOWED_NUMBERS` (array default `['6288223053149']`)
    - `GEMINI_API_KEY` (cek env atau default string)
    - `GEMINI_API_MODEL` (default `gemini-3.5-flash-lite`)
  - Implementasikan fungsi `crmAdminBotEnsureTables(mysqli $conn): void` untuk membuat tabel `crm_admin_todos`:
    - Kolom: `id`, `admin_phone`, `task`, `status`, `created_at`, `completed_at`.
    - Index: `(admin_phone, status)`, `(created_at)`.
  - Implementasikan fungsi helper `crmAdminBotNormalizeNumber(string $phone): string`.
  - Implementasikan fungsi `crmAdminBotIsAdmin(string $phone): bool`.

- [x] **Step 2: Panggil inisialisasi tabel di `crm/config/bootstrap.php`**
  - Sertakan `require_once __DIR__ . '/admin-bot.php';`
  - Jalankan `crmAdminBotEnsureTables($conn);`

- [x] **Step 3: Buat test verifikasi struktur dan inisialisasi di `crm/tests/admin-bot-test.php`**
  - Test validasi normalisasi nomor admin (`088223053149`, `6288223053149`, `6288223053149@s.whatsapp.net`).
  - Test verifikasi tabel `crm_admin_todos` dan struktur field.

---

### Task 2: Implementasi Engine Admin Bot (To-Do, Monitoring, & AI)

**Files:**
- Create: `crm/lib/admin-bot.php`
- Test: `crm/tests/admin-bot-test.php`

- [x] **Step 1: Implementasikan modul To-Do Manager di `crm/lib/admin-bot.php`**
  - `crmAdminBotAddTodo(mysqli $conn, string $phone, string $task): array`
  - `crmAdminBotGetTodos(mysqli $conn, string $phone, string $status = 'pending', int $limit = 10): array`
  - `crmAdminBotCompleteTodo(mysqli $conn, string $phone, int $todoId): array`
  - `crmAdminBotDeleteTodo(mysqli $conn, string $phone, int $todoId): array`

- [x] **Step 2: Implementasikan modul System Monitoring di `crm/lib/admin-bot.php`**
  - `crmAdminBotGetSystemStatus(mysqli $conn, string $baseDir): string`
    - Cek status koneksi DB & versi.
    - Cek total pesan masuk hari ini di `log_wa`.
    - Cek total antrean follow-up (`pending`, `sent`, `failed`) di `crm_automation_queue`.
    - Cek kesehatan log (`webhook.log`, `all_requests.log`, `auto_reply_log.txt`).
  - `crmAdminBotGetQueueStatus(mysqli $conn, int $limit = 5): string`
    - Tampilkan 5 antrean prospek follow-up berikutnya (nama, nowa, template, waktu jadwal).
  - `crmAdminBotGetHelpMessage(): string`
    - Menampilkan ringkasan panduan perintah: `!todo`, `!status`, `!queue`, `!ai <tanya>`.

- [x] **Step 3: Implementasikan modul Gemini AI & Pengiriman Pesan**
  - `crmAdminBotCallGemini(string $prompt, string $apiKey, string $model): string`
    - Mengirim POST ke `https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}`.
    - Menangani fallback pesan jika key belum diisi atau kuota habis.
  - `crmAdminBotSendMessage(string $to, string $message, string $apiUrl, string $apiToken): bool`
    - Mengirim HTTP POST ke OneSender API dengan format JSON `{ recipient, type: "text", content: { text } }`.

- [x] **Step 4: Implementasikan dispatcher utama `crmAdminBotProcessMessage`**
  - `crmAdminBotProcessMessage(mysqli $conn, string $senderPhone, string $messageText, string $senderName, string $apiUrl, string $apiToken, string $baseDir): array`
  - Parsing router:
    - `!todo ...` -> To-Do handler.
    - `!status` atau `!monitor` -> Status handler.
    - `!queue` -> Queue handler.
    - `!help` atau `!bantuan` -> Help handler.
    - `!ai ...` atau pesan umum -> Gemini AI handler.
  - Eksekusi pengiriman balasan ke WhatsApp admin via `crmAdminBotSendMessage`.

---

### Task 3: Integrasi ke `webhook.php` & Isolasi dari Alur Prospek

**Files:**
- Modify: `webhook.php`
- Modify: `auto_reply_engine.php`
- Test: `crm/tests/admin-bot-test.php`

- [x] **Step 1: Sambungkan Admin Bot di `webhook.php`**
  - Muat `crm/config/admin-bot.php` dan `crm/lib/admin-bot.php`.
  - Setelah pesan disimpan ke `log_wa`, periksa `if (crmAdminBotIsAdmin($senderPhone))`.
  - Jika ya:
    - Jalankan `crmAdminBotProcessMessage(...)`.
    - Catat status `admin_bot => processed` di response webhook.
    - Lewatkan / bypass pemanggilan `crmAutomationHandleIncomingWebhook` dan `AutoReplyEngine` untuk nomor admin.
  - Jika bukan admin:
    - Lanjutkan alur pendaftaran murid biasa tanpa perubahan.

- [x] **Step 2: Perbarui daftar abaikan di `auto_reply_engine.php`**
  - Sinkronkan pengecekan `crmAdminBotIsAdmin($contactId)` agar otomatis terabaikan dari engine auto-reply umum.

---

### Task 4: Verifikasi & Test Suite Python Integration

**Files:**
- Modify: `crm/tests/verify_suite.py`
- Run: `python3 crm/tests/verify_suite.py`

- [x] **Step 1: Daftarkan `admin-bot-test` ke `crm/tests/verify_suite.py`**
  - Tambahkan runner fungsi `run_admin_bot_test(base_dir)` ke dalam `verify_suite.py`.
  - Jalankan test suite dan verifikasi seluruh assertion lolos dengan status OK.
