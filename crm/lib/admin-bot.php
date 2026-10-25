<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin-bot.php';

/**
 * Tambah catatan to-do baru
 */
function crmAdminBotAddTodo(mysqli $conn, string $phone, string $task): array
{
    $phone = crmAdminBotNormalizeNumber($phone);
    $task = trim($task);

    if ($task === '') {
        return ['success' => false, 'error' => 'Deskripsi task tidak boleh kosong.'];
    }

    $stmt = $conn->prepare("INSERT INTO crm_admin_todos (admin_phone, task, status, created_at) VALUES (?, ?, 'pending', NOW())");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Prepare failed: ' . $conn->error];
    }

    $stmt->bind_param('ss', $phone, $task);
    $exec = $stmt->execute();
    $insertId = $stmt->insert_id;
    $stmt->close();

    if ($exec) {
        return ['success' => true, 'id' => $insertId, 'task' => $task];
    }

    return ['success' => false, 'error' => 'Gagal menyimpan to-do ke database.'];
}

/**
 * Ambil daftar to-do admin
 */
function crmAdminBotGetTodos(mysqli $conn, string $phone, string $status = 'pending', int $limit = 10): array
{
    $phone = crmAdminBotNormalizeNumber($phone);
    $limit = max(1, min(50, $limit));

    if ($status === 'all') {
        $stmt = $conn->prepare("SELECT id, task, status, created_at, completed_at FROM crm_admin_todos WHERE admin_phone = ? ORDER BY id DESC LIMIT ?");
        if (!$stmt) return [];
        $stmt->bind_param('si', $phone, $limit);
    } else {
        $stmt = $conn->prepare("SELECT id, task, status, created_at, completed_at FROM crm_admin_todos WHERE admin_phone = ? AND status = ? ORDER BY id DESC LIMIT ?");
        if (!$stmt) return [];
        $stmt->bind_param('ssi', $phone, $status, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $todos = [];
    while ($row = $result->fetch_assoc()) {
        $todos[] = $row;
    }
    $stmt->close();

    return $todos;
}

/**
 * Tandai to-do selesai
 */
function crmAdminBotCompleteTodo(mysqli $conn, string $phone, int $todoId): array
{
    $phone = crmAdminBotNormalizeNumber($phone);
    if ($todoId <= 0) {
        return ['success' => false, 'error' => 'ID task tidak valid.'];
    }

    $stmt = $conn->prepare("UPDATE crm_admin_todos SET status = 'completed', completed_at = NOW() WHERE id = ? AND admin_phone = ?");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Prepare failed: ' . $conn->error];
    }

    $stmt->bind_param('is', $todoId, $phone);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        return ['success' => true, 'affected' => $affected];
    }

    return ['success' => false, 'error' => "Task #{$todoId} tidak ditemukan atau sudah selesai."];
}

/**
 * Hapus to-do
 */
function crmAdminBotDeleteTodo(mysqli $conn, string $phone, int $todoId): array
{
    $phone = crmAdminBotNormalizeNumber($phone);
    if ($todoId <= 0) {
        return ['success' => false, 'error' => 'ID task tidak valid.'];
    }

    $stmt = $conn->prepare("DELETE FROM crm_admin_todos WHERE id = ? AND admin_phone = ?");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Prepare failed: ' . $conn->error];
    }

    $stmt->bind_param('is', $todoId, $phone);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        return ['success' => true, 'affected' => $affected];
    }

    return ['success' => false, 'error' => "Task #{$todoId} tidak ditemukan."];
}

/**
 * Format string daftar to-do untuk pesan WhatsApp
 */
function crmAdminBotFormatTodosResponse(array $todos, string $status = 'pending'): string
{
    if (empty($todos)) {
        if ($status === 'pending') {
            return "✅ *Tidak ada to-do pending!*\nSemua tugas Anda sudah selesai atau belum ada task baru.\nKetik `!todo catat <tugas>` untuk menambah.";
        }
        return "ℹ️ Belum ada data to-do yang tercatat.";
    }

    $label = ($status === 'pending') ? '📝 *DAFTAR TO-DO AKTIF*' : '📋 *DAFTAR TO-DO TERAKHIR*';
    $out = "{$label}\n────────────────────\n";

    foreach ($todos as $item) {
        $icon = ($item['status'] === 'completed') ? '✅' : '⏳';
        $time = date('d/m H:i', strtotime($item['created_at']));
        $out .= "{$icon} *#{$item['id']}* [{$time}]\n{$item['task']}\n\n";
    }

    $out .= "────────────────────\n";
    $out .= "💡 _Tandai selesai: `!todo selesai <id>`_\n💡 _Hapus: `!todo hapus <id>`_";

    return trim($out);
}

/**
 * Ambil status kesehatan sistem jwdpoloap
 */
function crmAdminBotGetSystemStatus(mysqli $conn, string $baseDir): string
{
    $dbOk = ($conn instanceof mysqli && $conn->ping());
    $dbStatusStr = $dbOk ? "🟢 Online (Terhubung)" : "🔴 Error / Disconnected";

    // Pesan masuk hari ini
    $todayMsgCount = 0;
    if ($dbOk) {
        $res = $conn->query("SELECT COUNT(*) AS total FROM log_wa WHERE DATE(created_at) = CURDATE()");
        if ($res && $row = $res->fetch_assoc()) {
            $todayMsgCount = (int)$row['total'];
        }
    }

    // Antrean follow up otomasi
    $queuePending = 0;
    $queueSentToday = 0;
    $queueFailed = 0;
    if ($dbOk) {
        $checkQueue = $conn->query("SHOW TABLES LIKE 'crm_automation_queue'");
        if ($checkQueue && $checkQueue->num_rows > 0) {
            $qRes = $conn->query("SELECT status, COUNT(*) AS total FROM crm_automation_queue GROUP BY status");
            if ($qRes) {
                while ($qRow = $qRes->fetch_assoc()) {
                    if ($qRow['status'] === 'pending') $queuePending = (int)$qRow['total'];
                    if ($qRow['status'] === 'sent') $queueSentToday = (int)$qRow['total'];
                    if ($qRow['status'] === 'failed') $queueFailed = (int)$qRow['total'];
                }
            }
        }
    }

    // Cek file log
    $logFiles = [
        'webhook.log' => $baseDir . '/webhook.log',
        'debug.log' => $baseDir . '/debug.log',
        'auto_reply_log.txt' => $baseDir . '/auto_reply_log.txt',
    ];

    $logSummary = "";
    foreach ($logFiles as $name => $path) {
        if (file_exists($path)) {
            $sizeKb = round(filesize($path) / 1024, 1);
            $modified = date('H:i:s', filemtime($path));
            $logSummary .= "• `{$name}`: {$sizeKb} KB (Upd: {$modified})\n";
        } else {
            $logSummary .= "• `{$name}`: _Tidak ada_\n";
        }
    }

    $timeNow = date('d M Y - H:i:s');

    $report = "📊 *MONITORING SISTEM JWDPOLOAP*\n";
    $report .= "⏱️ Waktu: {$timeNow} WIB\n";
    $report .= "────────────────────\n";
    $report .= "🗄️ *Database:* {$dbStatusStr}\n";
    $report .= "📩 *Pesan Masuk Hari Ini:* {$todayMsgCount} chat\n\n";
    $report .= "🚀 *Otomasi Follow-Up:*\n";
    $report .= "• Pending Antrean: *{$queuePending}*\n";
    $report .= "• Berhasil Terkirim: *{$queueSentToday}*\n";
    $report .= "• Gagal / Error: *{$queueFailed}*\n\n";
    $report .= "📁 *Status File Log:*\n" . $logSummary;
    $report .= "────────────────────\n";
    $report .= "💡 _Ketik `!queue` untuk cek detail antrean aktif._";

    return trim($report);
}

/**
 * Ambil status 5 antrean follow-up berikutnya
 */
function crmAdminBotGetQueueStatus(mysqli $conn, int $limit = 5): string
{
    $check = $conn->query("SHOW TABLES LIKE 'crm_automation_queue'");
    if (!$check || $check->num_rows === 0) {
        return "⚠️ Tabel `crm_automation_queue` belum tersedia.";
    }

    $limit = max(1, min(20, $limit));
    $stmt = $conn->prepare("SELECT id, nowa, nama, stage, scheduled_at, status FROM crm_automation_queue WHERE status = 'pending' ORDER BY scheduled_at ASC LIMIT ?");
    if (!$stmt) {
        return "⚠️ Gagal mengambil data antrean.";
    }

    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();

    if (empty($rows)) {
        return "✅ *Antrean Follow-Up Bersih!*\nTidak ada pesan follow-up yang sedang pending.";
    }

    $out = "⏳ *ANTREAN FOLLOW-UP AKTIF (Next {$limit})*\n────────────────────\n";
    foreach ($rows as $item) {
        $maskedPhone = substr($item['nowa'], 0, 5) . '****' . substr($item['nowa'], -3);
        $schedTime = date('d/m H:i', strtotime($item['scheduled_at']));
        $nama = !empty($item['nama']) ? $item['nama'] : 'Kak';
        $stage = $item['stage'] ?? 1;

        $out .= "📌 *#{$item['id']}* - {$nama} ({$maskedPhone})\n";
        $out .= "   Tahap: Step {$stage} | Jadwal: {$schedTime}\n\n";
    }
    $out .= "────────────────────\n💡 _Ketik `!status` untuk ringkasan sistem._";

    return trim($out);
}

/**
 * Panduan Bantuan Perintah Admin
 */
function crmAdminBotGetHelpMessage(): string
{
    $msg = "🤖 *PANDUAN ASISTEN ADMIN WA*\n";
    $msg .= "────────────────────\n";
    $msg .= "📌 *Pencatatan To-Do:*\n";
    $msg .= "• `!todo` atau `!todo list` : Cek to-do pending\n";
    $msg .= "• `!todo catat <tugas>` : Tambah to-do baru\n";
    $msg .= "• `!todo selesai <id>` : Tandai task selesai\n";
    $msg .= "• `!todo hapus <id>` : Hapus to-do\n";
    $msg .= "• `!todo all` : Lihat 10 to-do terakhir\n\n";
    $msg .= "📊 *Monitoring Sistem jwdpoloap:*\n";
    $msg .= "• `!status` atau `!monitor` : Cek kondisi server, DB & antrean\n";
    $msg .= "• `!queue` : Cek 5 antrean follow-up berikutnya\n\n";
    $msg .= "💡 *Tanya-Jawab AI (Gemini):*\n";
    $msg .= "• `!ai <pertanyaan>` : Tanya langsung ke AI\n";
    $msg .= "• Atau ketik chat pertanyaan biasa tanpa awalan `!`, asisten AI akan otomatis membalas.\n\n";
    $msg .= "⚡ *Antigravity Agent (MacBook Coding & Terminal):*\n";
    $msg .= "• `!agy <perintah>` : Perintahkan agent di Mac (misal: `!agy periksa git status`)\n";
    $msg .= "────────────────────";

    return $msg;
}

/**
 * Panggil Gemini AI REST API
 */
function crmAdminBotCallGemini(string $prompt, ?string $apiKey = null, ?string $model = null): string
{
    $apiKey = $apiKey ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
    $model = $model ?: (defined('GEMINI_API_MODEL') ? GEMINI_API_MODEL : 'gemini-3.5-flash-lite');

    if (empty($apiKey)) {
        return "⚠️ *Asisten AI belum aktif!*\nKunci API Gemini (`GEMINI_API_KEY`) belum dikonfigurasi di server.\nSilakan setel API Key di `crm/config/admin-bot.php` atau environment variable `GEMINI_API_KEY`.";
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $systemInstruction = "Anda adalah asisten AI pribadi untuk Admin Jawwada (sistem manajemen kursus Al-Qur'an dan CRM jwdpoloap). Berikan jawaban yang ramah, ringkas, padat, profesional, dan to the point dalam bahasa Indonesia.";

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $systemInstruction . "\n\nPertanyaan Admin:\n" . $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 1000
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return "❌ Gagal menghubungi layanan AI (cURL error: {$curlErr}).";
    }

    if ($httpCode !== 200 || empty($response)) {
        return "❌ Layanan AI mengembalikan status HTTP {$httpCode}. Silakan coba beberapa saat lagi.";
    }

    $json = json_decode($response, true);
    $replyText = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (!empty($replyText)) {
        return "🤖 *Jawaban AI:*\n\n" . trim($replyText);
    }

    return "⚠️ Maaf, AI tidak memberikan respons yang dapat dibaca.";
}

/**
 * Kirim pesan ke WhatsApp via OneSender REST API
 */
function crmAdminBotSendMessage(string $to, string $message, string $apiUrl, string $apiToken): bool
{
    if (empty($to) || empty($message) || empty($apiUrl) || empty($apiToken)) {
        return false;
    }

    $cleanPhone = crmAdminBotNormalizeNumber($to);
    $payload = json_encode([
        'recipient_type' => 'individual',
        'to'             => $cleanPhone,
        'type'           => 'text',
        'text'           => [
            'body' => $message
        ]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result) {
        $resJson = json_decode($result, true);
        if (isset($resJson['code']) && (int)$resJson['code'] === 200) {
            return true;
        }
    }

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Dispatcher utama penanganan pesan dari WhatsApp Admin
 */
function crmAdminBotProcessMessage(
    mysqli $conn,
    string $senderPhone,
    string $messageText,
    string $senderName,
    string $apiUrl,
    string $apiToken,
    string $baseDir
): array {
    $senderPhone = crmAdminBotNormalizeNumber($senderPhone);
    $rawMsg = trim($messageText);
    $lowerMsg = strtolower($rawMsg);

    $reply = "";
    $action = "unknown";

    // 0. Perintah Antigravity AI Agent (MacBook Core)
    if (str_starts_with($lowerMsg, '!agy')) {
        $action = "antigravity";
        $prompt = trim(substr($rawMsg, 4));

        if ($prompt === '') {
            $reply = "🤖 *Antigravity AI Agent*\nSertakan perintah coding atau task setelah `!agy`.\n\nContoh:\n• `!agy cek git status`\n• `!agy buatkan file test.txt di home folder`\n• `!agy periksa apakah ada error di webhook.log`";
        } else {
            $stmt = $conn->prepare("INSERT INTO crm_antigravity_tasks (nowa, prompt, status, created_at) VALUES (?, ?, 'pending', NOW())");
            if ($stmt) {
                $stmt->bind_param('ss', $senderPhone, $prompt);
                $stmt->execute();
                $taskId = $stmt->insert_id;
                $stmt->close();

                $reply = "⏳ *[Antigravity Agent]* Task #{$taskId} Diterima!\nPerintah sedang diproses oleh agent Antigravity di MacBook:\n\n📝 _\"{$prompt}\"_\n\n⏱️ Hasil pengerjaan akan segera dikirimkan ke chat ini begitu selesai.";
            } else {
                $reply = "❌ Gagal mendaftarkan task Antigravity: " . $conn->error;
            }
        }
    }
    // 1. Perintah To-Do
    elseif (str_starts_with($lowerMsg, '!todo')) {
        $action = "todo";
        $sub = trim(substr($rawMsg, 5));
        $lowerSub = strtolower($sub);

        if ($sub === '' || $lowerSub === 'list') {
            $todos = crmAdminBotGetTodos($conn, $senderPhone, 'pending', 10);
            $reply = crmAdminBotFormatTodosResponse($todos, 'pending');
        } elseif ($lowerSub === 'all') {
            $todos = crmAdminBotGetTodos($conn, $senderPhone, 'all', 10);
            $reply = crmAdminBotFormatTodosResponse($todos, 'all');
        } elseif (preg_match('/^(?:catat|tambah|add)\s+(.+)$/is', $sub, $m)) {
            $taskText = trim($m[1]);
            $res = crmAdminBotAddTodo($conn, $senderPhone, $taskText);
            if ($res['success']) {
                $reply = "✅ *To-Do Ditambahkan!*\nTask #{$res['id']}: \"{$taskText}\"\n\nKetik `!todo` untuk melihat daftar.";
            } else {
                $reply = "❌ " . ($res['error'] ?? 'Gagal menambah to-do.');
            }
        } elseif (preg_match('/^(?:selesai|done|check)\s+(\d+)$/i', $sub, $m)) {
            $todoId = (int)$m[1];
            $res = crmAdminBotCompleteTodo($conn, $senderPhone, $todoId);
            if ($res['success']) {
                $reply = "🎉 *Task #{$todoId} Ditandai Selesai!*\nKerja bagus! Ketik `!todo` untuk cek sisa task.";
            } else {
                $reply = "❌ " . ($res['error'] ?? 'Gagal menandai selesai.');
            }
        } elseif (preg_match('/^(?:hapus|delete|del|rm)\s+(\d+)$/i', $sub, $m)) {
            $todoId = (int)$m[1];
            $res = crmAdminBotDeleteTodo($conn, $senderPhone, $todoId);
            if ($res['success']) {
                $reply = "🗑️ *Task #{$todoId} Berhasil Dihapus!*";
            } else {
                $reply = "❌ " . ($res['error'] ?? 'Gagal menghapus task.');
            }
        } else {
            $reply = "⚠️ Format perintah to-do tidak dikenal.\n• Tambah: `!todo catat <tugas>`\n• Lihat: `!todo`\n• Selesai: `!todo selesai <id>`\n• Hapus: `!todo hapus <id>`";
        }
    }
    // 2. Monitoring Sistem
    elseif ($lowerMsg === '!status' || $lowerMsg === '!monitor' || $lowerMsg === '!sistem') {
        $action = "status";
        $reply = crmAdminBotGetSystemStatus($conn, $baseDir);
    }
    // 3. Queue Follow-up
    elseif ($lowerMsg === '!queue' || $lowerMsg === '!antrean') {
        $action = "queue";
        $reply = crmAdminBotGetQueueStatus($conn, 5);
    }
    // 4. Bantuan
    elseif ($lowerMsg === '!help' || $lowerMsg === '!bantuan' || $lowerMsg === '!menu') {
        $action = "help";
        $reply = crmAdminBotGetHelpMessage();
    }
    // 5. AI Tanya-Jawab
    elseif (str_starts_with($lowerMsg, '!ai') || str_starts_with($lowerMsg, '?')) {
        $action = "ai";
        $prompt = str_starts_with($lowerMsg, '!ai') ? trim(substr($rawMsg, 3)) : trim(substr($rawMsg, 1));
        if ($prompt === '') {
            $reply = "💡 Silakan sertakan pertanyaan setelah perintah. Contoh: `!ai buatkan copywriting follow up kelas tahfidz`";
        } else {
            $reply = crmAdminBotCallGemini($prompt);
        }
    }
    // 6. Default chat bebas dari Admin -> Ditangani oleh AI Assistant
    else {
        $action = "ai_conversational";
        $reply = crmAdminBotCallGemini($rawMsg);
    }

    // Kirim balasan ke admin
    $sent = false;
    if ($reply !== '' && !empty($apiUrl) && !empty($apiToken)) {
        $sent = crmAdminBotSendMessage($senderPhone, $reply, $apiUrl, $apiToken);
    }

    return [
        'handled' => true,
        'action'  => $action,
        'reply'   => $reply,
        'sent'    => $sent
    ];
}
