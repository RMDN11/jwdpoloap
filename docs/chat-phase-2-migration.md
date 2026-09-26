# Chat Phase 2: Conversation Layer

## Tujuan

Phase 2 menambahkan sumber data percakapan dua arah tanpa menghapus atau mengubah tabel legacy.

Layer baru:
- `crm_conversations`: satu record per nomor WhatsApp, unread/read state, waktu interaksi, dan jumlah follow-up.
- `crm_messages`: setiap pesan inbound/outbound dengan arah dan tipe pengirim.
- `crm_followups`: event follow-up yang dikirim dari CRM.

## Compatibility

`log_wa` dan `crm_message_history` tetap dipakai oleh flow lama.

Webhook inbound:
1. tetap menulis ke `log_wa`;
2. jika tabel Phase 2 tersedia, juga menulis ke `crm_conversations` + `crm_messages`.

CRM outbound:
1. tetap mengirim lewat OneSender;
2. tetap menulis ke `crm_message_history` dan memperbarui `log_wa`;
3. jika tabel Phase 2 tersedia, juga menulis ke `crm_messages` dan `crm_followups`.

Jika migration belum dijalankan, helper Phase 2 berhenti dengan aman dan flow legacy tetap berjalan.

## Deployment

Setelah PR digabung ke `main`, server:

```bash
cd /home/wegqxcgv/subdomains/app.reqra.my.id/jwdpoloap

git fetch origin
git checkout main
git pull --ff-only origin main

git status
git log -3 --oneline
```

Lalu jalankan migration SQL pada database CRM:

```bash
mysql -u <USER> -p <DATABASE> < database/migrations/20260926_chat_phase_2.sql
```

Atau jalankan isi file melalui phpMyAdmin.

## Verification

```sql
SHOW TABLES LIKE 'crm_conversations';
SHOW TABLES LIKE 'crm_messages';
SHOW TABLES LIKE 'crm_followups';

SELECT COUNT(*) AS conversations FROM crm_conversations;
SELECT COUNT(*) AS messages FROM crm_messages;
SELECT COUNT(*) AS followups FROM crm_followups;
```

Kirim satu pesan WhatsApp masuk setelah migration, lalu pastikan:

```sql
SELECT id, nowa, direction, sender_type, source, sent_at
FROM crm_messages
ORDER BY id DESC
LIMIT 5;
```

Kirim satu follow-up dari CRM, lalu cek:

```sql
SELECT id, conversation_id, message_id, template_name, status, sent_at
FROM crm_followups
ORDER BY id DESC
LIMIT 5;
```

## Catatan

Migration ini tidak melakukan backfill otomatis dari `log_wa`. Alasannya sederhana: `log_wa` tidak memiliki field direction, sehingga backfill otomatis berisiko menganggap pesan outbound lama sebagai inbound.

Backfill historis akan menjadi pekerjaan terpisah setelah pola outbound lama dipetakan dengan aman.
