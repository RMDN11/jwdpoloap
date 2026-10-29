# Legacy Scripts Archive

File-file di dalam folder ini adalah skrip lawas/eksperimental yang sebelumnya berada di root direktori `jwdpoloap`. Seluruh fungsinya kini telah digantikan secara penuh oleh sistem CRM modern:

1. **monggo.php**: Halaman login lama (dialihkan ke `crm/login.php`).
2. **reminder-index.php**: Dashboard peluncur lama (dialihkan ke `crm/index.php`).
3. **admin_logs.php**: Penampil log WA lama (digantikan modul analitik dan follow-up CRM).
4. **pantau_log.php**: Monitor log AJAX lawas (digantikan halaman chat & follow-up realtime CRM).
5. **view_logs.php**: Penampil log auto-reply lama yang mereferensikan analitikjwd.php (tidak lagi aktif).
6. **download_csv.php**: Skrip ekspor lawas yang membaca `webhook_log.txt` (sudah tidak relevan).
7. **cek_lanjut.php**: Skrip verifikasi lanjut santri (dimigrasikan penuh ke `crm/pages/cek-lanjut.php` dan `crm/actions/cek-lanjut.php`).
8. **loginwa.php & registerwa.php & auth_checkwa.php**: Sistem auth lama (digantikan `crm/login.php` & session token CRM).
9. **logoutwa.php**: Logout lama (digantikan `crm/logout.php`).
10. **pesan.php & new-send.php & hanwa.php & wa-tut.php**: Skrip blast manual lama (digantikan fitur follow up & reminder CRM).
11. **promosi.php & img-promo.php**: Campaign promosi gambar lama (digantikan `crm/pages/reminder-promosi.php`).
12. **tadzkir.php & reminder.php**: Reminder tahfidz lama (digantikan suite reminder CRM modern).
13. **live-chat.php & fetch_messages.php & get_contact_details.php & send_followup.php**: Modul obrolan lama (digantikan `crm/pages/chat.php` & `crm/actions/send-message.php`).
14. **manage_templates.php**: Kelola template lama (digantikan `crm/pages/templates.php`).
15. **manage_auto_reply.php**: Auto reply lama (digantikan `crm/pages/auto-reply.php`).
16. **kelola_grup.php**: Manajemen grup lama (digantikan `crm/pages/group.php` & `manage-groups.php`).
17. **grafik.php & grafik-chat.php**: Grafik lama (digantikan `crm/pages/analytics.php`).
18. **cron_satu.php**: Antrean pesan satuan lama (digantikan `crm/actions/reminder-schedule-cron.php` & `automation-followup-cron.php`).
19. **validasi_nama.php & search_peserta.php**: Endpoint pencarian nama lama (digantikan AJAX handler CRM).

File-file ini diarsipkan agar root direktori tetap bersih, rapi, dan mudah dikelola tanpa kehilangan riwayat Git.
