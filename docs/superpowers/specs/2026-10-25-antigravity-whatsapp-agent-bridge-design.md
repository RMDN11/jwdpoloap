# Antigravity WhatsApp Agent Bridge Design Spec

## 1. Overview & Purpose
Fitur **Antigravity WhatsApp Agent Bridge** memungkinkan Admin mengontrol dan berkolaborasi langsung dengan AI Agent Antigravity (`agy` CLI) di MacBook melalui chat WhatsApp menggunakan trigger perintah `!agy <perintah atau pertanyaan>`.

Berbeda dengan asisten AI tanya-jawab biasa, Antigravity adalah agentic AI berkemampuan penuh:
- Membaca, mengedit, dan membuat file di workspace
- Menjalankan perintah terminal, git, build, dan testing
- Menjalankan multi-step execution secara mandiri
- Mengembalikan laporan pengerjaan atau jawaban langsung ke WhatsApp Admin.

---

## 2. Arsitektur Komponen

```
[ WhatsApp Admin (6288223053149) ]
               |
               | Chat: "!agy periksa status git dan jalankan test"
               v
      [ OneSender Gateway ]
               |
               | Webhook Inbound POST
               v
  [ https://app.reqra.my.id/jwdpoloap/webhook.php ]
               |
               | 1. Simpan ke database: `crm_antigravity_tasks` (status: pending)
               | 2. Kirim notifikasi instan ke WhatsApp: "⏳ Task diterima & sedang diproses di MacBook..."
               v
  [ Relay API: /api_antigravity.php?action=poll ]
               ^
               | Long-poll / Periodic poll (setiap 2-3 detik)
               |
 [ MacBook: Antigravity Bridge Daemon (`antigravity_wa_bridge.py`) ]
               |
               | Eksekusi Non-Interaktif CLI:
               | `agy -c -p --dangerously-skip-permissions "<prompt>"`
               v
      [ Antigravity Agent Core ]
    (Akses ke File, Git, Terminal, Shell, Tools)
               |
               | Menghasilkan output teks & laporan pengerjaan
               v
 [ MacBook: Antigravity Bridge Daemon ]
               |
               | Kirim Balasan ke WhatsApp Admin via OneSender REST API
               v
      [ OneSender Gateway ]
               |
               v
[ WhatsApp Admin Menerima Hasil Lengkap dari Antigravity! ]
```

---

## 3. Skema Database

Tabel `crm_antigravity_tasks` di MySQL:
```sql
CREATE TABLE IF NOT EXISTS crm_antigravity_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nowa VARCHAR(50) NOT NULL,
    prompt TEXT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    result LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    completed_at DATETIME NULL,
    INDEX idx_agy_status (status),
    INDEX idx_agy_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. Keamanan & Konfigurasi
- **Nomor Admin Valid**: Hanya nomor yang terdaftar di `ADMIN_BOT_ALLOWED_NUMBERS` yang dapat memicu `!agy`.
- **Secret Bridge Token**: Komunikasi antara server `app.reqra.my.id` dan Daemon di MacBook diamankan dengan header/query token `ANTIGRAVITY_BRIDGE_TOKEN`.
- **Chunking Pesan**: Output dari Antigravity yang melebihi batas panjang karakter WhatsApp (~4000 karakter) akan dipecah secara otomatis sehingga tidak terpotong.
