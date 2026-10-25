# Desain Automasi Follow-Up Real-Time & Pengelolaan Sederhana Pola Pemicu Chat Masuk

## 1. Konteks & Latar Belakang
Pada implementasi sebelumnya di CRM (`page=automation-followup`), penjadwalan alur follow-up 6 tahap (10m, 1j, 5j, 24j, 3h, 7h) dilakukan secara manual oleh admin melalui tombol *"Jadwalkan Alur (6 Tahap)"* pada tab Prospek.

Pengguna membutuhkan sistem di mana:
1. **Chat Baru Masuk (Real-time):** Begitu calon peserta mengirim pesan dari Landing Page WhatsApp, sistem langsung secara otomatis memasukkannya ke jadwal alur 6 tahap tanpa perlu admin mengklik tombol manual.
2. **Chat Lama (Historis):** Tombol manual tetap dipertahankan khusus untuk memproses prospek lama yang masuk sebelum automasi real-time ini aktif.
3. **Pengelolaan Pola Sederhana (Simplified Trigger Management):** Mempermudah admin dalam menambah, mengubah, dan mengaktifkan/menonaktifkan kata kunci atau teks pesan pemicu (misalnya tombol pendaftaran *"Kak, saya ingin daftar Kelas..."* atau *"Kak, saya ingin daftar Tahfidz Cilik"*) langsung melalui tampilan antarmuka web tanpa menyentuh kode PHP.

---

## 2. Tujuan, Non-Goals & Batasan

### Goals
- Mengintegrasikan deteksi pemicu chat masuk real-time pada `webhook.php`.
- Menjadwalkan otomatis 6 tahap urutan follow-up standar Jawwada (`Follow Up 1` s/d `Follow Up 6`) ke `crm_automation_queue`.
- Menghadirkan antarmuka pengelolaan pola pemicu yang sederhana di Tab Rules (hanya membutuhkan: Kata Kunci/Pesan Masuk, Label Program, dan Kategori Badge).
- Menjamin isolasi dan guardrail keamanan: mengabaikan nomor CS internal Jawwada, nomor pengajar, nomor terblokir, serta nomor yang sudah mengisi form Finance (pembayaran).
- Menyediakan status badge visual yang membedakan alur otomatis vs chat historis di tab Prospek.

### Non-Goals
- Tidak mengubah mesin cron pengirim pesan (`automation-followup-cron.php`).
- Tidak mengubah isi template 6 tahap pesan default Jawwada.
- Tidak menghapus tombol penjadwalan manual untuk prospek lama.

---

## 3. Pola Chat Masuk Bawaan (Default Triggers)

Sistem akan memuat (seed) pola pemicu bawaan dari tombol Landing Page:
1. `Kak, saya ingin daftar Tahfidz Cilik` (Label: `Tahfidz Cilik - LP Button`, Kategori: `cilik`)
2. `Tahfidz Cilik` (Label: `Tahfidz Cilik - Keyword`, Kategori: `cilik`)
3. `Kak, saya ingin daftar Kelas Menghafal Pemula` (Label: `Menghafal Pemula - LP Button`, Kategori: `pemula`)
4. `Menghafal Pemula` (Label: `Menghafal Pemula - Keyword`, Kategori: `pemula`)
5. `Kak, saya ingin daftar Kelas Menghafal Lanjutan` (Label: `Menghafal Lanjutan - LP Button`, Kategori: `lanjutan`)
6. `Menghafal Lanjutan` (Label: `Menghafal Lanjutan - Keyword`, Kategori: `lanjutan`)
7. `Kak, saya ingin daftar Kelas Penguatan Hafalan` (Label: `Penguatan Hafalan - LP Button`, Kategori: `lanjutan`)
8. `Penguatan Hafalan` (Label: `Penguatan Hafalan - Keyword`, Kategori: `lanjutan`)
9. `Bismillah, kak mau tanya detail program Tahfidz Private Online Jawwada` (Label: `Tanya Detail Hero - LP Utama`, Kategori: `general`)
10. `Bismillah, perkenalkan nama saya` (Label: `Form Nama LP - Bismillah`, Kategori: `form_lead`)
11. `Kak, saya ingin daftar Kelas` (Label: `Pendaftaran Kelas Umum`, Kategori: `cta`)

---

## 4. Alur Kerja Sistem (System Architecture)

```
[Chat WhatsApp Masuk]
        │
        ▼
   webhook.php
        │
        ├──> crmChatStoreMessage() (Simpan log_wa & crm_messages)
        │
        ▼
   crmAutomationHandleIncomingWebhook()
        │
        ├──> 1. Validasi Keamanan:
        │       - Nomor operasional CS internal? -> Skip
        │       - Nomor pengajar/tutor? -> Skip
        │       - Nomor terblokir? -> Skip
        │       - Sudah isi Finance / bayar? -> Skip
        │
        ├──> 2. Pencocokan Pola Aktif:
        │       - Cocokkan isi pesan dengan crm_automation_rules (is_active = 1)
        │       - Jika tidak cocok pola aktif -> Skip
        │
        ▼
   crmAutomationScheduleSequence()
        │
        ├──> Batalkan pending usang untuk nomor ini (hindari overlap)
        ├──> Masukkan 6 tahap follow-up (+10m, +1j, +5j, +24j, +3d, +7d)
        └──> Tandai trigger_source = 'auto_webhook'
```

---

## 5. Antarmuka Pengguna (UI/UX)
- **Tab Pola Pemicu (Rules):**
  - Form ringkas: Teks Masuk, Label Program, Kategori Badge, Status Aktif.
  - Tabel interaktif: Daftar pola dengan tombol toggle aktif/nonaktif, edit, dan hapus.
- **Tab Prospek (Prospects):**
  - Prospek yang terjadwal otomatis tampil dengan badge `Terjadwal (Otomatis)`.
  - Prospek lama yang belum terjadwal tetap menampilkan tombol `Jadwalkan Alur (6 Tahap)`.
  - Dilengkapi tombol `Stop` untuk membatalkan antrean jika dibutuhkan.
