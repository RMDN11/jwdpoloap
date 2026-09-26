# Chat Phase 3: Customer Routing, Intent, and Payment State

## Status

Draft design specification for review.

## 1. Tujuan

Phase 3 mengubah Chat dari daftar percakapan umum menjadi workspace operasional yang dapat membedakan customer baru, peserta/pengajar, dan percakapan lainnya berdasarkan data conversation layer.

Tujuan utamanya:
- Customer Baru hanya berisi nomor yang bukan peserta/pengampu/pengajar, bukan nomor admin Jawwada, dan memiliki intent/trigger yang menunjukkan minat.
- Read/unread tersimpan persisten pada conversation.
- Follow-up menjadi event yang dapat dihitung dari riwayat follow-up, bukan sekadar state UI.
- Admin dapat memindahkan conversation secara manual ke room lain dan keputusan manual tersebut mengalahkan klasifikasi otomatis.
- Deteksi payment dari pesan outbound tertentu dapat memindahkan conversation ke room Sudah Payment.
- KPI Customer Baru Hari Ini/Minggu Ini/Bulan Ini menghitung customer/conversation unik, bukan jumlah pesan.
- Peserta, pengajar, dan admin tidak menginflasi KPI Customer Baru.
- Layer baru tetap kompatibel dengan `log_wa` dan `crm_message_history` selama migrasi.

## 2. Room Model

Room efektif:

1. `customer_baru`
2. `sudah_payment`
3. `peserta_pengajar`
4. `lainnya`

Room ditentukan oleh effective classification state pada `crm_conversations`.

### Source priority

Urutan keputusan:

1. Manual override
2. Payment state otomatis
3. Known-contact detection
4. Customer intent/trigger
5. Lainnya

Manual override tidak boleh ditimpa oleh automation berikutnya.

## 3. Customer Baru

Conversation dapat diklasifikasikan sebagai `customer_baru` jika seluruh kondisi terpenuhi:

- nomor WhatsApp valid;
- bukan known contact pada directory peserta, pengampu, atau pengajar yang tersedia;
- bukan nomor internal/admin Jawwada;
- tidak memiliki manual room override;
- memiliki pesan inbound yang cocok dengan trigger/intent aktif;
- belum berada pada payment state otomatis.

Trigger berasal dari mekanisme `crm_prospect_triggers` yang sudah digunakan project. Kategori/keyword yang ada menjadi sumber data, bukan daftar baru yang ditulis ulang secara paralel.

Generic inquiry yang tidak memenuhi trigger minat tetap berada di `lainnya`.

## 4. Known Contact

Known-contact detection menggunakan directory yang benar-benar tersedia pada database. Implementasi tidak boleh mengasumsikan tabel `pengajar` selalu ada.

Nomor harus dinormalisasi sebelum dibandingkan agar format `08...`, `62...`, dan variasi format WhatsApp tidak menghasilkan duplicate identity.

Nomor admin Jawwada diperlakukan sebagai internal exception. Nilai tersebut tidak boleh dijadikan satu-satunya model directory untuk jangka panjang; implementasi harus menyediakan tempat yang jelas untuk daftar nomor internal.

## 5. Intent

Intent disimpan sebagai metadata conversation, bukan sebagai filter yang menghapus pesan dari history.

Contoh kategori yang sudah ada di trigger project:
- Bingung
- Ziyadah Pemula
- Ziyadah Lanjutan
- Muroja'ah
- Tahfidz Cilik
- Mode Intensif
- Mode Normal
- Ekspresi Minat

Jika tidak ada trigger yang cocok, intent dapat bernilai null/`lainnya` dan conversation tidak masuk Customer Baru secara otomatis.

Pesan tetap terlihat di history walaupun intent tidak dikenali.

## 6. Manual Routing

Admin dapat memilih room tujuan dari detail conversation.

Manual routing menyimpan:
- effective room;
- source = `manual`.

Selama source manual aktif, trigger inbound baru dan auto-classifier tidak boleh mengganti room.

Manual routing dapat diubah kembali oleh admin. Saat override dihapus, conversation kembali dievaluasi oleh classifier otomatis berdasarkan state terkini.

## 7. Read / Unread

Read state memakai field Phase 2 yang sudah tersedia:
- `last_read_at`
- `unread_count`

Saat inbound baru diterima:
- `unread_count` bertambah;
- `last_inbound_at` dan `last_message_at` diperbarui.

Saat conversation dibuka:
- `unread_count = 0`;
- `last_read_at = NOW()`.

Badge merah "baru" hanya boleh merepresentasikan unread state pada conversation Customer Baru.

Read state tidak sama dengan follow-up state.

## 8. Follow-up

Follow-up menggunakan `crm_followups` sebagai event source.

Status UI dapat diturunkan:
- `perlu_followup`: customer baru dan belum ada follow-up sukses;
- `sudah_followup`: ada follow-up sukses;
- `sudah_payment`: payment state sudah terdeteksi.

`followup_count` tetap dapat digunakan sebagai counter denormalisasi, tetapi event table menjadi sumber histori.

Jangan membuat `last_followup_at` sebagai field baru bila nilainya dapat dihitung aman dari `crm_followups`.

## 9. Payment Detection

Payment detection berjalan dari pesan outbound yang tersimpan di `crm_messages`.

Rule awal harus mencocokkan indikator payment yang memang digunakan oleh workflow Jawwada, termasuk pola pesan seperti:
- "Wajib segera diisi"
- "Mohon diisi untuk pendataan Finance kami"

Matching harus dinormalisasi terhadap case, whitespace, dan variasi ringan tanpa menjadikan kata umum seperti "finance" sebagai satu-satunya indikator.

Saat payment state terdeteksi:
- conversation masuk `sudah_payment` jika tidak memiliki manual override;
- `payment_detected_at` menyimpan waktu deteksi bila field tersebut diperlukan;
- KPI Customer Baru tidak lagi memasukkan conversation tersebut sebagai Customer Baru aktif.

Pesan dan history tetap dipertahankan.

## 10. KPI

KPI Customer Baru harus menghitung conversation/customer unik.

Rentang:
- Hari Ini: inbound qualifying customer baru pada tanggal berjalan.
- Minggu Ini: mulai Senin pada minggu berjalan.
- Bulan Ini: mulai tanggal 1 bulan berjalan.
- Semua Waktu: seluruh qualifying customer baru yang masih relevan menurut room/state.

Jumlah pesan tidak boleh digunakan sebagai pengganti jumlah customer.

Peserta, pengajar, admin, dan pesan outbound tidak boleh menambah KPI Customer Baru.

Message count, jika ditampilkan, harus diberi label terpisah dan tidak dicampur dengan customer count.

## 11. History

`crm_messages` menjadi sumber utama history dua arah:
- inbound/customer;
- outbound/admin;
- auto-reply bila tersimpan di conversation layer.

`log_wa` dan `crm_message_history` tetap compatibility layer selama migrasi.

Tidak ada automatic historical backfill dari `log_wa` pada phase ini karena struktur legacy tidak menyimpan direction secara eksplisit. Phase 2 sudah mendokumentasikan risiko tersebut.

## 12. Polling / Performance

Chat tetap menggunakan conversation layer dan polling ringan yang sudah ada.

Jangan:
- membaca ribuan row `log_wa` untuk setiap render;
- melakukan grouping seluruh history di PHP;
- membuat polling baru yang tumpang tindih;
- menghitung KPI dari setiap message row bila dapat dihitung dari conversation state.

Query room/KPI harus dapat menggunakan indexed conversation fields sebanyak mungkin.

## 13. Compatibility and Safety

Perubahan phase ini harus:
- tidak menghapus tabel legacy;
- tidak mengubah makna data legacy;
- mempertahankan pengiriman WhatsApp yang sudah berjalan;
- mempertahankan CSRF dan session authentication;
- fail safely jika migration belum tersedia;
- tidak melakukan destructive migration;
- tidak melakukan historical backfill tanpa mapping direction yang tervalidasi.

## 14. Testing Requirements

Sebelum dianggap siap:

### Classification
- nomor peserta tidak masuk Customer Baru;
- nomor pengampu tidak masuk Customer Baru;
- nomor pengajar yang tersedia tidak masuk Customer Baru;
- nomor admin tidak masuk Customer Baru;
- nomor unknown tanpa trigger masuk Lainnya;
- nomor unknown dengan trigger masuk Customer Baru;
- manual override mengalahkan classifier.

### Read
- inbound membuat unread;
- membuka conversation menghapus unread;
- refresh browser mempertahankan state.

### Follow-up
- follow-up sukses tercatat satu event;
- follow-up count konsisten dengan event;
- status UI berubah tanpa mengubah history.

### Payment
- pesan payment trigger mendeteksi payment;
- kata "finance" saja tidak cukup;
- manual override tidak tertimpa payment automation.

### KPI
- satu customer dengan banyak pesan dihitung satu;
- peserta/pengajar/admin tidak masuk KPI Customer Baru;
- range hari/minggu/bulan konsisten dengan timezone database.

### Compatibility
- legacy `log_wa` tetap ditulis oleh webhook;
- outbound tetap dikirim dan dicatat;
- history dua arah tetap tersedia.

## 15. Migration Strategy

Phase 3 migration bersifat additive.

Tahapan:
1. Tambahkan hanya field yang benar-benar diperlukan ke `crm_conversations`.
2. Tambahkan helper classification/routing terpisah dari helper message storage.
3. Integrasikan classifier ke inbound dan outbound flow.
4. Rebuild query room/KPI Chat menggunakan conversation state.
5. Tambahkan action manual routing.
6. Tambahkan regression tests dan verification SQL.
7. Deploy setelah review dan validasi.

Tidak ada penghapusan kolom/tabel legacy.

## 16. Non-goals

Phase ini tidak mencakup:
- CRM pipeline penuh;
- sales funnel multi-stage;
- assignment conversation ke banyak admin;
- SLA/automation scheduler baru;
- backfill seluruh `log_wa`;
- redesign total WhatsApp provider;
- perubahan API provider.

## 17. Success Criteria

Phase dianggap memenuhi desain jika:
- Customer Baru hanya menampilkan qualifying customer unik;
- manual routing persisten;
- read/unread persisten;
- follow-up terukur dari event;
- payment state terdeteksi dari pesan outbound yang tervalidasi;
- KPI tidak terinflasi oleh message count atau internal contacts;
- history inbound/outbound tetap lengkap;
- Chat tetap ringan dan kompatibel dengan legacy flow.
