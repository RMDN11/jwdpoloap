# Chat V2 Phase 1 — Source & Data Flow Audit

Tanggal: 26 September 2026
Repository: RMDN11/jwdpoloap
Baseline: main @ e7155eddcd74f75db34037c4e48fc0ea7eed606d

## Tujuan Phase 1

Phase 1 tidak mengubah perilaku production. Tujuannya memetakan sumber data percakapan sebelum conversation layer dibangun, agar perubahan berikutnya tidak menambal bug di atas sumber data yang salah.

## Temuan Utama

### 1. Inbound WhatsApp

Sumber utama inbound saat ini adalah:

webhook.php
-> menerima POST JSON
-> membaca sender_phone / phone / from
-> membaca message_text / text / message
-> normalisasi nomor
-> INSERT log_wa
-> menjalankan auto_reply_engine.php

Catatan penting:
- webhook.php menyimpan inbound ke log_wa.
- webhook.php juga menulis raw request ke all_requests.log dan payload debug ke debug.log.
- live-chat.php membaca webhook.log sebagai sumber inbound tambahan.
- Ini membuat live-chat bergantung pada file log runtime, bukan hanya database.

### 2. Chat page saat ini

crm/pages/chat.php membaca log_wa sebagai sumber utama daftar contact dan riwayat inbound.
crm_message_history dipakai terpisah untuk riwayat outbound dari CRM.

Saat ini konsep "baru" dihitung dari:

created_at > last_followup_at

Bukan dari status read/unread yang persisten.

Dampak:
- membuka chat tidak mempunyai state read permanen;
- "baru" bercampur dengan "belum follow-up";
- tidak ada definisi unread yang independen dari follow-up.

### 3. Classifier / eligibility

crm/config/prospect.php memiliki:
- crmIsGenericInquiry()
- crmGetProspectTriggers()
- crmProspectClassifyMessage()
- crmIsEligibleProspect()
- crmFindEligibleProspectByNumber()

Masalah arsitektural:
- classification saat ini juga menentukan visibility;
- pesan dengan kategori Lainnya / generic inquiry dapat dianggap tidak eligible;
- chat yang gagal diklasifikasikan dapat hilang dari workspace Chat.

Target Phase 2:
classification hanya menjadi metadata/label, bukan syarat agar message terlihat di inbox.

### 4. Outbound

Outbound CRM dicatat ke:
crm_message_history

Beberapa workflow juga menulis log_wa:
- crm/actions/send-message.php
- reminder-peserta.php
- reminder-pengajar-send.php
- reminder-promosi-send.php
- reminder-send.php
- live-chat.php
- workflow legacy lain

Namun belum ada satu sumber message stream yang menyatukan inbound dan outbound.

### 5. Live Chat legacy

live-chat.php:
- membaca maksimal 300 row log_wa;
- menganggap row database sebagai outbound;
- membaca webhook.log untuk inbound;
- menggabungkan keduanya di PHP;
- melakukan deduplikasi berdasarkan md5(message + timestamp);
- polling setiap 4 detik;
- mengirim balasan dan menyimpan balasan ke log_wa.

Masalah besar:
- arah pesan di log_wa tidak memiliki field direction;
- asumsi arah berdasarkan sumber query;
- file webhook.log menjadi bagian dari data conversation;
- history dapat berbeda dengan page=chat karena dua halaman memakai sumber data berbeda.

### 6. crm_message_history

Bootstrap membuat tabel:
crm_message_history(
  id,
  nowa,
  nama,
  template_id,
  template_name,
  message,
  sent_at,
  status
)

Tabel ini hanya merepresentasikan outbound CRM. Belum memiliki:
- direction;
- conversation_id;
- inbound message;
- read state;
- message id dari WhatsApp;
- sender type.

### 7. Database writes ke log_wa

Ditemukan write dari:
- webhook.php
- live-chat.php
- crm/actions/send-message.php melalui update metadata
- crm/actions/reminder-send.php
- crm/actions/reminder-peserta.php
- crm/actions/reminder-pengajar-send.php
- crm/actions/reminder-promosi-send.php
- reminder.php
- promosi.php
- wa-tut.php
- kirimgrup.php
- pesan.php

Kesimpulan: log_wa saat ini adalah legacy event/log table yang dipakai oleh banyak workflow. Jangan mengganti struktur log_wa secara agresif pada fase awal.

## Source of Truth yang ditetapkan untuk fase berikutnya

Untuk Chat V2:

Inbound canonical source:
webhook payload -> database message record

Outbound canonical source:
successful WhatsApp send -> database message record

Legacy:
log_wa dan crm_message_history tetap dipertahankan sebagai compatibility/history selama migrasi.

File log:
webhook.log, debug.log, all_requests.log hanya dipakai untuk diagnosis/fallback, bukan source of truth utama.

## Target data model

Contact
  |
  +-- Conversation
        |
        +-- Message inbound
        +-- Message outbound
        +-- Follow-up event
        +-- Read state
        +-- Classification metadata

Minimum future tables:
- crm_conversations
- crm_messages
- crm_followups

## Keputusan desain

1. Semua inbound yang valid harus dapat masuk conversation, termasuk kategori Lainnya.
2. Classification tidak boleh menghapus visibility conversation.
3. Read/unread harus disimpan di database.
4. Follow-up count harus berasal dari event/message history, bukan session.
5. Riwayat conversation harus two-way.
6. log_wa tidak dihapus atau diubah besar-besaran pada phase awal.
7. Nomor WhatsApp dinormalisasi menjadi satu canonical identifier.
8. live-chat.php tidak dijadikan fondasi baru; logic-nya hanya menjadi referensi migrasi.
9. Tidak ada perubahan schema production pada Phase 1.
10. Phase 2 dimulai dari conversation/message layer setelah source audit ini disetujui.

## Bug yang sudah terbukti dari source

- Unread belum persistent.
- "Baru" bergantung pada last_followup_at.
- Inbound dan outbound belum mempunyai satu stream.
- live-chat memakai webhook.log sebagai sumber inbound.
- log_wa tidak mempunyai direction.
- classification dapat menyebabkan message tidak eligible dan tidak tampil.
- crm_message_history hanya menyimpan outbound.
- follow-up count belum menjadi event-based metric.
- Dua workspace Chat/Live Chat dapat melihat representasi conversation yang berbeda.

## Scope Phase 2

Phase 2 akan membangun conversation layer tanpa menghapus legacy:
1. schema crm_conversations
2. schema crm_messages
3. schema crm_followups
4. ingestion adapter dari webhook.php
5. outbound adapter dari send-message/live-chat
6. backfill yang aman dari data legacy
7. conversation read state
8. source reconciliation
