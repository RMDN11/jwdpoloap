<?php
declare(strict_types=1);

/**
 * System Cron Health & Queue Monitoring Helper.
 */

if (!function_exists('crmFormatAgo')) {
    function crmFormatAgo(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'Belum tercatat';
        }
        $diff = max(0, time() - $timestamp);
        if ($diff < 50) {
            return 'Baru saja';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' mnt lalu';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' jam lalu';
        }
        return date('d/m H:i', $timestamp);
    }
}

if (!function_exists('crmGetSystemHealthSummary')) {
    function crmGetSystemHealthSummary(mysqli $conn): array
    {
        $baseDir = dirname(__DIR__, 2);
        $now = time();

        // 1. Cron Grup (Every 5 min)
        $grupLog = file_exists($baseDir . '/cron_grup.log') ? $baseDir . '/cron_grup.log' : $baseDir . '/cron_debug.log';
        $grupTime = file_exists($grupLog) ? (int)filemtime($grupLog) : 0;
        $grupDiff = $now - $grupTime;
        $grupOk = ($grupTime > 0 && $grupDiff < 900); // valid within 15 min

        $todayDate = date('Y-m-d');
        $dayMap = [
            'sunday' => 'ahad', 'monday' => 'senin', 'tuesday' => 'selasa',
            'wednesday' => 'rabu', 'thursday' => 'kamis', 'friday' => 'jumat', 'saturday' => 'sabtu'
        ];
        $indoDay = $dayMap[strtolower(date('l'))] ?? 'senin';

        $grupPending = 0;
        $checkGrup = $conn->query("SHOW TABLES LIKE 'jadwal_pesan_grup'");
        if ($checkGrup && $checkGrup->num_rows > 0) {
            $sql = "SELECT COUNT(*) as c FROM jadwal_pesan_grup WHERE status IN ('pending', 'menunggu') AND (tipe_jadwal = 'harian' OR (tipe_jadwal IN ('sekali','sekarang') AND jadwal_kirim >= '{$todayDate} 00:00:00'))";
            $res = $conn->query($sql);
            if ($res) {
                $grupPending = (int)($res->fetch_assoc()['c'] ?? 0);
            }
        }

        // 2. Cron Reminder Peserta (Every 2 min)
        $remLog = $baseDir . '/cron_reminder.log';
        $remTime = file_exists($remLog) ? (int)filemtime($remLog) : 0;
        $remDiff = $now - $remTime;
        $remOk = ($remTime > 0 && $remDiff < 600); // valid within 10 min

        $remPending = 0;
        $checkRem = $conn->query("SHOW TABLES LIKE 'crm_reminder_campaign_queue'");
        if ($checkRem && $checkRem->num_rows > 0) {
            $res = $conn->query("SELECT COUNT(*) as c FROM crm_reminder_campaign_queue WHERE status = 'pending'");
            if ($res) {
                $remPending = (int)($res->fetch_assoc()['c'] ?? 0);
            }
        }

        // 3. Cron Follow-Up Otomatis (Every 5 min)
        $autoLog = $baseDir . '/cron_automation.log';
        $autoTime = file_exists($autoLog) ? (int)filemtime($autoLog) : 0;
        $autoDiff = $now - $autoTime;
        $autoOk = ($autoTime > 0 && $autoDiff < 900); // valid within 15 min

        $autoPending = 0;
        $checkAuto = $conn->query("SHOW TABLES LIKE 'crm_automation_queue'");
        if ($checkAuto && $checkAuto->num_rows > 0) {
            $res = $conn->query("SELECT COUNT(*) as c FROM crm_automation_queue WHERE status = 'pending'");
            if ($res) {
                $autoPending = (int)($res->fetch_assoc()['c'] ?? 0);
            }
        }

        // 4. Cron Database Retention (Weekly Sunday 02:00)
        $retLog = $baseDir . '/cron_retention.log';
        $retTime = file_exists($retLog) ? (int)filemtime($retLog) : 0;

        return [
            'grup' => [
                'name' => 'Pesan Grup',
                'interval' => 'Tiap 5 Menit',
                'is_healthy' => $grupOk,
                'last_run_ago' => crmFormatAgo($grupTime),
                'last_run_time' => $grupTime > 0 ? date('H:i', $grupTime) : '-',
                'pending_count' => $grupPending,
                'pending_label' => $grupPending . ' jadwal aktif',
                'link' => '?page=group-schedules',
                'icon' => 'fa-users',
            ],
            'reminder' => [
                'name' => 'Reminder Peserta',
                'interval' => 'Tiap 2 Menit',
                'is_healthy' => $remOk,
                'last_run_ago' => crmFormatAgo($remTime),
                'last_run_time' => $remTime > 0 ? date('H:i', $remTime) : '-',
                'pending_count' => $remPending,
                'pending_label' => $remPending . ' antrean pesan',
                'link' => '?page=reminder-peserta',
                'icon' => 'fa-bell',
            ],
            'followup' => [
                'name' => 'Follow-Up Realtime',
                'interval' => 'Tiap 5 Menit',
                'is_healthy' => $autoOk,
                'last_run_ago' => crmFormatAgo($autoTime),
                'last_run_time' => $autoTime > 0 ? date('H:i', $autoTime) : '-',
                'pending_count' => $autoPending,
                'pending_label' => $autoPending . ' antrean prospek',
                'link' => '?page=automation-followup',
                'icon' => 'fa-robot',
            ],
            'retention' => [
                'name' => 'Retensi Database',
                'interval' => 'Mingguan (Ahad)',
                'is_healthy' => true,
                'last_run_ago' => $retTime > 0 ? crmFormatAgo($retTime) : 'Ahad 02.00 WIB',
                'last_run_time' => $retTime > 0 ? date('d/m H:i', $retTime) : '02:00 WIB',
                'pending_count' => 0,
                'pending_label' => 'Auto-purge > 90 hari',
                'link' => '?page=activity',
                'icon' => 'fa-database',
            ],
        ];
    }
}
