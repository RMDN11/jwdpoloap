# Antigravity WhatsApp Agent Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menghubungkan WhatsApp Admin secara langsung ke AI Agent Antigravity di MacBook melalui perintah `!agy <task>` dengan arsitektur antrean real-time dan daemon eksekusi lokal.

**Architecture:** Server `jwdpoloap` menerima webhook WhatsApp, menyimpan task `!agy` ke tabel antrean `crm_antigravity_tasks` dan mengirimkan feedback instan ke admin. Daemon Python lokal di MacBook secara berkala mengambil task pending melalui `api_antigravity.php`, mengeksekusinya menggunakan Antigravity CLI (`agy -c -p --dangerously-skip-permissions`), lalu mengirimkan hasil pengerjaan lengkap ke WhatsApp Admin melalui OneSender REST API.

**Tech Stack:** PHP 8+, MySQL (InnoDB), Python 3.9 (standard library: `urllib`, `subprocess`, `json`), Antigravity CLI (`agy`), OneSender REST API.

**Spec:** `docs/superpowers/specs/2026-10-25-antigravity-whatsapp-agent-bridge-design.md`

## Global Constraints
- Hanya nomor Admin terdaftar (`6288223053149`) yang dapat memicu `!agy`.
- Endpoint relay `api_antigravity.php` wajib diverifikasi menggunakan token otentikasi `ANTIGRAVITY_BRIDGE_TOKEN`.
- Daemon lokal di MacBook harus menangani timeout jika proses `agy` berjalan lebih dari 180 detik.
- Pesan balasan WhatsApp yang panjang harus dipecah per 3500 karakter agar tidak ditolak oleh WhatsApp API.
- Seluruh skrip harus teruji dan lolos verifikasi di `crm/tests/verify_suite.py`.

## Review Focus
1. Pesan `!agy` tanpa argumen/prompt harus memberikan panduan contoh perintah yang jelas.
2. Status antrean task harus berpindah dari `pending` -> `processing` -> `completed` (atau `failed`) tanpa race condition.
3. Token keamanan di `api_antigravity.php` harus menolak request tanpa token atau token salah dengan HTTP 403.
4. Output dari `agy` baik stdout maupun stderr harus ditangkap dengan baik dan dilaporkan ke WhatsApp.
5. Pembaruan kode harus siap di-push ke branch `main` GitHub untuk dideploy ke server.

---

### Task 1: Konfigurasi, Database & Handler `!agy` di Server

**Files:**
- Modify: `crm/config/admin-bot.php`
- Modify: `crm/lib/admin-bot.php`
- Create: `api_antigravity.php`
- Test: `crm/tests/antigravity-bridge-test.php`

- [x] **Step 1: Definisikan skema tabel dan token di `crm/config/admin-bot.php`**
  - Definisikan `ANTIGRAVITY_BRIDGE_TOKEN`.
  - Buat tabel `crm_antigravity_tasks` dalam fungsi `crmAdminBotEnsureTables`.

- [x] **Step 2: Tambahkan router perintah `!agy` di `crm/lib/admin-bot.php`**
  - Tangkap awalan `!agy`.
  - Jika argumen kosong, kirim pesan bantuan.
  - Jika ada task: insert ke `crm_antigravity_tasks`, kirimkan pesan konfirmasi awal:
    `⏳ *[Antigravity Agent]*: Perintah diterima & sedang diproses di MacBook...`

- [x] **Step 3: Buat endpoint relay antrean `api_antigravity.php`**
  - Validasi token otentikasi.
  - Aksi `poll`: Ambil 1 task pending terlama, ubah status ke `processing`.
  - Aksi `complete`: Terima hasil eksekusi, ubah status ke `completed`.

- [x] **Step 4: Buat test verifikasi server di `crm/tests/antigravity-bridge-test.php`**
  - Uji token verifikasi, handler `!agy`, skema database, dan endpoint relay.

---

### Task 2: Implementasi Antigravity WA Bridge Daemon di MacBook

**Files:**
- Create: `/Users/macbookair/bin/antigravity_wa_bridge.py`
- Test: Manual execution & verification test

- [x] **Step 1: Buat skrip daemon `antigravity_wa_bridge.py`**
  - Konfigurasi URL server, secret token, OneSender API URL & token.
  - Fungsi polling ke `api_antigravity.php`.
  - Fungsi eksekusi `agy -c -p --dangerously-skip-permissions "<prompt>"`.
  - Fungsi pengiriman pesan balik ke WhatsApp admin via OneSender REST API.
  - Dukungan pemecahan pesan panjang (chunking).

- [x] **Step 2: Buat unit runner test untuk daemon**
  - Verifikasi bahwa skrip Python valid secara sintaks dan fungsi eksekusi siap dijalankan.

---

### Task 3: Verifikasi Suite, Git Commit, Push & Deployment

**Files:**
- Modify: `crm/tests/verify_suite.py`
- Run: `python3 crm/tests/verify_suite.py`

- [x] **Step 1: Daftarkan test ke `verify_suite.py` dan jalankan verifikasi penuh**
- [x] **Step 2: Commit dan push perubahan ke GitHub `main`**
- [x] **Step 3: Jalankan daemon di background di MacBook**
