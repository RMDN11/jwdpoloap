# Legacy Scripts Archive

File-file di dalam folder ini adalah skrip lawas/eksperimental yang sebelumnya berada di root direktori `jwdpoloap`. Seluruh fungsinya kini telah digantikan secara penuh oleh sistem CRM modern:

1. **monggo.php**: Halaman login lama (dialihkan ke `crm/pages/login.php`).
2. **reminder-index.php**: Dashboard peluncur lama (dialihkan ke `crm/index.php`).
3. **admin_logs.php**: Penampil log WA lama (digantikan modul analitik dan chat CRM).
4. **pantau_log.php**: Monitor log AJAX lawas (digantikan halaman chat & follow-up realtime CRM).
5. **view_logs.php**: Penampil log auto-reply lama yang mereferensikan analitikjwd.php (tidak lagi aktif).
6. **download_csv.php**: Skrip ekspor lawas yang membaca `webhook_log.txt` (sudah tidak relevan).

File-file ini diarsipkan agar root direktori tetap bersih, rapi, dan mudah dikelola tanpa kehilangan riwayat Git.
