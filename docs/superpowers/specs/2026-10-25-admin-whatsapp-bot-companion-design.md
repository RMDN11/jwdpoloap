# WhatsApp Admin Bot Companion (AI Q&A, To-Do List, & System Monitor) Design

## 1. Executive Summary
Sistem `jwdpoloap` saat ini menerima pesan masuk dari WhatsApp via OneSender webhook (`webhook.php`) untuk CRM pesan pendaftaran dan automasi follow-up. 
Fitur **WhatsApp Admin Bot Companion** memperluas arsitektur ini agar nomor WhatsApp Admin (`6288223053149` dan nomor yang didaftarkan di config) dapat berinteraksi langsung sebagai asisten pribadi melalui WhatsApp:
1. **Asisten AI Tanya-Jawab**: Integrasi API Gemini untuk menjawab pertanyaan, konsultasi konten/copywriting, atau tanya jawab umum.
2. **Pencatatan To-Do List**: Menambah, melihat, menandai selesai, dan menghapus catatan tugas langsung via chat WA (`!todo tambah ...`, `!todo list`, `!todo selesai <id>`).
3. **Monitoring Sistem `jwdpoloap`**: Memantau kesehatan sistem, database, antrean automasi follow-up aktif (`crm_automation_queue`), dan error logs secara instan (`!status`, `!queue`, `!logs`).

---

## 2. Arsitektur & Alur Data

```
+-----------------------------------------------------------+
|               WhatsApp Admin (6288223053149)              |
+-----------------------------+-----------------------------+
                              | Kirim Chat (e.g. !status, !todo, tanya AI)
                              v
                +----------------------------+
                |    OneSender WA Gateway    |
                +-------------+--------------+
                              | Webhook POST
                              v
+-----------------------------+-----------------------------+
|                      webhook.php                          |
|  - Cek apakah pengirim adalah Admin terdaftar?            |
+-----------------------------+-----------------------------+
                              |
               +--------------+--------------+
       [Admin] |                             | [Bukan Admin]
               v                             v
+-----------------------------+    +-----------------------------+
|    crmAdminBotHandler()     |    |   Alur CRM & Prospek Biasa  |
|  (crm/lib/admin-bot.php)    |    |  (auto_reply_engine.php &   |
+--------------+--------------+    |   automation-followup.php)  |
               |                   +-----------------------------+
   +-----------+-----------+
   |                       |                       |
   v                       v                       v
[To-Do Manager]     [System Monitor]         [Gemini AI]
`crm_admin_todos`   DB, Queue, Logs, Cron    Google AI Studio API
   |                       |                       |
   +-----------+-----------+                       |
               |                                   |
               v                                   v
         +-----------------------------------------------+
         | Kirim Balasan ke WhatsApp Admin               |
         | via OneSender REST API (ONESENDER_API_URL)    |
         +-----------------------------------------------+
```

---

## 3. Komponen & Perintah Chat WhatsApp

### 3.1. Keamanan & Identifikasi Admin
- Hanya nomor yang terdaftar di konfigurasi (`ADMIN_BOT_NUMBERS`) yang dapat mengakses fitur ini.
- Nomor default: `6288223053149`.
- Nomor admin tidak akan pernah dikirimi template follow-up prospek atau pesan auto-reply publik.

### 3.2. Fitur Pencatatan To-Do List
- `!todo` atau `!todo list` : Menampilkan daftar to-do yang masih berstatus `pending`.
- `!todo catat <teks>` / `!todo tambah <teks>` : Menambahkan to-do baru ke tabel `crm_admin_todos`.
- `!todo selesai <id>` : Mengubah status task menjadi `completed` dengan timestamp penyelesaian.
- `!todo hapus <id>` : Menghapus to-do dari database.
- `!todo all` : Menampilkan 10 to-do terakhir baik pending maupun selesai.

### 3.3. Fitur Monitoring Sistem (`jwdpoloap`)
- `!status` atau `!monitor` :
  - **Database Status**: Koneksi MySQL aktif & latency ping.
  - **Statistik Pesan Hari Ini**: Total pesan masuk di `log_wa`.
  - **Antrean Otomasi Follow-Up**: Total pending, terkirim hari ini, dan gagal di `crm_automation_queue`.
  - **Log Kesehatan**: Status file log dan cuplikan jika ada error terbaru di `webhook.log` / `debug.log`.
- `!queue` : Menampilkan 5 antrean follow-up terjadwal berikutnya beserta nama dan nomor prospek.
- `!help` atau `!bantuan` : Menampilkan panduan format perintah bot.

### 3.4. Fitur Asisten AI Tanya-Jawab (Gemini)
- `!ai <pertanyaan>` atau `? <pertanyaan>` : Mengirim prompt ke Gemini API dan mengembalikan jawaban langsung ke WhatsApp.
- Jika chat yang dikirim oleh Admin adalah kalimat percakapan biasa (bukan command berawalan `!`), bot otomatis merespons dengan AI Assistant.
- Menggunakan endpoint Gemini REST API (v1beta) dengan model `gemini-1.5-flash` atau `gemini-2.0-flash`.

---

## 4. Skema Database

Tabel `crm_admin_todos`:
```sql
CREATE TABLE IF NOT EXISTS crm_admin_todos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_phone VARCHAR(50) NOT NULL,
    task TEXT NOT NULL,
    status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_admin_todo_phone_status (admin_phone, status),
    INDEX idx_admin_todo_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 5. Strategi Verifikasi & Testing
1. **Unit Tests**:
   - `crmAdminBotIsAdmin()` validasi nomor dengan berbagai format (`08...`, `628...`, `@s.whatsapp.net`).
   - Parsing perintah (`!todo`, `!status`, `!ai`, pesan bebas).
   - CRUD to-do item logic.
   - Peringkasan status monitoring query logic.
2. **Integration & E2E Tests**:
   - Simulasi webhook payload dari nomor admin ke `webhook.php`.
   - Memastikan nomor admin tidak memicu `crmAutomationHandleIncomingWebhook` atau auto-reply murid.
   - Memastikan balasan diarahkan ke pengirim via fungsi pengirim OneSender.
