<?php
session_start();
require_once 'auth_checkwa.php';
require_once 'config.php';
require_once 'auto_reply_engine.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ================================
// CONFIG API (Mengambil dari config.php)
// ================================
$apiUrl   = defined('ONESENDER_API_URL') ? ONESENDER_API_URL : "URL_DEFAULT_ANDA";
$apiToken = defined('ONESENDER_API_TOKEN') ? ONESENDER_API_TOKEN : "TOKEN_DEFAULT_ANDA";
$logFile  = __DIR__ . '/auto_reply_log.txt';

$autoReply = new AutoReplyEngine($conn, $apiUrl, $apiToken, $logFile);
// ================================
// HANDLE POST
// ================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ADD RULE
    if (isset($_POST['add_rule'])) {
        $keyword  = trim($_POST['keyword'] ?? '');
        $reply    = trim($_POST['reply'] ?? '');
        $priority = (int)($_POST['priority'] ?? 1);

        if ($keyword === '' || $reply === '') {
            $_SESSION['notification'] = "Keyword dan balasan wajib diisi";
            $_SESSION['notificationType'] = "error";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO auto_reply_rules (keyword, reply, priority, is_active, created_at)
                VALUES (?, ?, ?, 1, NOW())
            ");
            $stmt->bind_param("ssi", $keyword, $reply, $priority);
            $ok = $stmt->execute();
            $stmt->close();

            $_SESSION['notification'] = $ok ? "✅ Rule berhasil ditambahkan" : "❌ Gagal menambahkan rule";
            $_SESSION['notificationType'] = $ok ? "success" : "error";
        }
    }
	// EDIT RULE
if (isset($_POST['edit_rule'])) {
    $id       = (int)$_POST['rule_id'];
    $keyword  = trim($_POST['keyword'] ?? '');
    $reply    = trim($_POST['reply'] ?? '');
    $priority = (int)($_POST['priority'] ?? 1);

    if ($keyword === '' || $reply === '') {
        $_SESSION['notification'] = "❌ Keyword dan balasan wajib diisi";
        $_SESSION['notificationType'] = "error";
    } else {
        $stmt = $conn->prepare("
            UPDATE auto_reply_rules 
            SET keyword = ?, reply = ?, priority = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssii", $keyword, $reply, $priority, $id);
        $ok = $stmt->execute();
        $stmt->close();

        $_SESSION['notification'] = $ok ? "✅ Rule berhasil diperbarui" : "❌ Gagal memperbarui rule";
        $_SESSION['notificationType'] = $ok ? "success" : "error";
    }
}

    // TOGGLE STATUS
    if (isset($_POST['toggle_rule'])) {
        $id     = (int)$_POST['rule_id'];
        $status = (int)$_POST['status'];

        $stmt = $conn->prepare("UPDATE auto_reply_rules SET is_active=? WHERE id=?");
        $stmt->bind_param("ii", $status, $id);
        $ok = $stmt->execute();
        $stmt->close();

        $_SESSION['notification'] = $ok ? "✅ Status rule diperbarui" : "❌ Gagal update status";
        $_SESSION['notificationType'] = $ok ? "success" : "error";
    }

    // DELETE
    if (isset($_POST['delete_rule'])) {
        $id = (int)$_POST['rule_id'];

        $stmt = $conn->prepare("DELETE FROM auto_reply_rules WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();

        $_SESSION['notification'] = $ok ? "🗑️ Rule dihapus" : "❌ Gagal menghapus rule";
        $_SESSION['notificationType'] = $ok ? "success" : "error";
    }

    // TEST RULE
    if (isset($_POST['test_rule'])) {
        $id    = (int)$_POST['rule_id'];
        $phone = preg_replace('/\D/', '', $_POST['test_phone'] ?? '');

        if ($phone[0] === '0') {
            $phone = '62' . substr($phone, 1);
        }

        $q = $conn->prepare("SELECT reply FROM auto_reply_rules WHERE id=?");
        $q->bind_param("i", $id);
        $q->execute();
        $q->bind_result($replyText);
        $found = $q->fetch();
        $q->close();

        if ($found && $replyText) {
            $sent = $autoReply->sendTestMessage($phone, $replyText);
            $_SESSION['notification'] = $sent
                ? "📤 Test message berhasil dikirim"
                : "❌ Gagal kirim test message";
            $_SESSION['notificationType'] = $sent ? "success" : "error";
        } else {
            $_SESSION['notification'] = "⚠️ Rule tidak ditemukan";
            $_SESSION['notificationType'] = "error";
        }
    }

    header("Location: manage_auto_reply.php");
    exit;
}

// ================================
// GET RULES
// ================================
$rules = [];
$res = $conn->query("SELECT * FROM auto_reply_rules ORDER BY priority DESC, id DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rules[] = $row;
    }
    $res->free();
} else {
    $rules = [];
    error_log("Query fetch rules failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Auto Reply | Jawwada Quran</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@latest/css/boxicons.min.css">
    
    <style>
        :root {
            --primary: #374151;
            --primary-dark: #1f2937;
            --secondary: #4b5563;
            --accent: #9ca3af;
            --light-bg: #f3f4f6;
            --card-bg: #FFFFFF;
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --border-color: #e5e7eb;
        }
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: #f9fafb;
            min-height: 100vh;
        }
        
        .card {
            background: var(--card-bg);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }
        
        .card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .btn-primary {
            background: linear-gradient(to right, #6b7280, #4b5563);
            color: white;
            padding: 10px 20px;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-primary:hover {
            background: linear-gradient(to right, #4b5563, #374151);
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: linear-gradient(to right, #9ca3af, #6b7280);
            color: white;
            padding: 10px 20px;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(to right, #6b7280, #4b5563);
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-outline:hover {
            background: var(--light-bg);
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .input-field {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid var(--border-color);
            background: white;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #6b7280;
            box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.1);
        }
        
        .table-container {
            background: white;
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        
        .table-header {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table-row {
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s ease;
        }
        
        .table-row:hover {
            background: #f9fafb;
        }
        
        .table-cell {
            padding: 1rem 1.5rem;
            vertical-align: middle;
        }
        
        .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.375rem;
        }
        
        .status-active {
            background: #10B981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }
        
        .status-inactive {
            background: #9CA3AF;
            box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.2);
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 0.75rem;
            width: 90%;
            max-width: 500px;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        
        .message-preview {
            background: #f9fafb;
            border-radius: 0.5rem;
            padding: 1rem;
            max-height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
            border: 1px solid var(--border-color);
        }
        
        .message-preview::-webkit-scrollbar {
            width: 6px;
        }
        
        .message-preview::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }
        
        .stat-icon-1 { background: linear-gradient(to right, #6b7280, #4b5563); }
        .stat-icon-2 { background: linear-gradient(to right, #9ca3af, #6b7280); }
        .stat-icon-3 { background: linear-gradient(to right, #374151, #1f2937); }
        
        .action-btn {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            font-size: 0.875rem;
        }
        
        .action-btn:hover {
            transform: scale(1.1);
        }
        
        .btn-test { background: #dbeafe; color: #1d4ed8; }
        .btn-test:hover { background: #bfdbfe; }
        
        .btn-toggle { background: #d1fae5; color: #065f46; }
        .btn-toggle:hover { background: #a7f3d0; }
        
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #fecaca; }
        
        .btn-edit { background: #fef3c7; color: #92400e; }
        .btn-edit:hover { background: #fde68a; }
        
        .keyword-tag {
            background: #e5e7eb;
            color: #374151;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            margin-right: 0.375rem;
            margin-bottom: 0.375rem;
            display: inline-block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.4s ease forwards;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
            min-width: 300px;
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            transform: translateX(400px);
            transition: transform 0.4s ease;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification-success {
            background: linear-gradient(to right, #10B981, #059669);
            color: white;
            border-left: 4px solid #065F46;
        }
        
        .notification-error {
            background: linear-gradient(to right, #EF4444, #DC2626);
            color: white;
            border-left: 4px solid #991B1B;
        }
        
        .notification-warning {
            background: linear-gradient(to right, #F59E0B, #D97706);
            color: white;
            border-left: 4px solid #92400E;
        }
        
        .loading-overlay { 
            display: none; 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.7); 
            z-index: 9999; 
            justify-content: center; 
            align-items: center; 
            color: white; 
            font-size: 1.5rem; 
        }
        
        .edit-modal {
            max-width: 600px;
        }
        
        .emoji-picker {
            position: absolute;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            padding: 0.5rem;
            z-index: 50;
            display: none;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .emoji-item {
            padding: 0.5rem;
            cursor: pointer;
            border-radius: 0.25rem;
            font-size: 1.25rem;
        }
        
        .emoji-item:hover {
            background: #f3f4f6;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="text-center">
            <i class="fas fa-spinner fa-spin text-4xl mb-4"></i>
            <p>Memproses, harap tunggu...</p>
        </div>
    </div>

    <!-- Header (SAMA DENGAN REMINDER.PHP) -->
    <header class="bg-gray-800 text-white shadow-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-3">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                <div class="flex items-center space-x-3">
                    <div class="bg-gradient-to-br from-gray-600 to-gray-700 p-2 sm:p-3 rounded-xl shadow-lg">
                        <i class="fas fa-robot text-white text-xl sm:text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-bold text-white">Kelola Auto Reply</h1>
                        <p class="text-xs sm:text-sm text-gray-300">Atur Balasan Otomatis WhatsApp</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                    <a href="promosi.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-bullhorn mr-1"></i> Promosi
                    </a>
                    <a href="kelola_reminder.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-message mr-1"></i> Reminder Peserta
                    </a>
                    <a href="kirimgrup.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-users mr-1"></i> Grup
                    </a>
                    <a href="analitikjwd.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-bar-chart mr-1"></i> Analitik
                    </a>
                    <a href="manage_templates.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-edit mr-1"></i> Template
                    </a>
                    <a href="manage_auto_reply.php" class="text-xs sm:text-sm font-medium bg-blue-600 text-white hover:bg-blue-700 px-2 py-1 rounded">
                        <i class="fas fa-lightbulb mr-1"></i> Auto Reply
                    </a>
                    <a href="logoutwa.php" class="text-xs sm:text-sm font-medium text-gray-300 hover:text-white px-2 py-1 rounded bg-gray-700 bg-opacity-50">
                        <i class="fas fa-right-from-bracket"></i> Keluar
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Notification -->
    <?php if (isset($_SESSION['notification'])): ?>
    <div id="notification" class="notification <?= $_SESSION['notificationType'] === 'success' ? 'notification-success' : ($_SESSION['notificationType'] === 'warning' ? 'notification-warning' : 'notification-error') ?>">
        <div class="mr-3">
            <i class="fas <?= $_SESSION['notificationType'] === 'success' ? 'fa-check-circle' : ($_SESSION['notificationType'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle') ?> text-xl"></i>
        </div>
        <div class="flex-1">
            <div class="font-medium"><?= htmlspecialchars($_SESSION['notification']) ?></div>
        </div>
        <button onclick="closeNotification()" class="ml-4 text-white opacity-70 hover:opacity-100">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php 
        unset($_SESSION['notification'], $_SESSION['notificationType']);
    endif; ?>
    
    <!-- Test Modal -->
    <div id="testModal" class="modal-overlay">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-paper-plane text-blue-500 mr-2"></i>
                            Test Rule
                        </h3>
                        <p class="text-gray-500 text-sm mt-1">Kirim pesan test ke nomor WhatsApp</p>
                    </div>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form id="testForm" method="POST">
                    <input type="hidden" id="test_rule_id" name="rule_id">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone mr-1 text-gray-400"></i>
                            Nomor WhatsApp Tujuan
                        </label>
                        <input type="text" id="test_phone" name="test_phone"
                               placeholder="081234567890 atau 6281234567890"
                               class="input-field"
                               required>
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Nomor akan otomatis diformat ke 62xxxxxxxx
                        </p>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-eye mr-1 text-gray-400"></i>
                            Preview Pesan:
                        </label>
                        <div id="messagePreview" class="message-preview">
                            Pilih rule terlebih dahulu...
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3 pt-4 border-t border-gray-100">
                        <button type="button" onclick="closeModal()"
                                class="btn-outline">
                            <i class="fas fa-times mr-2"></i> Batal
                        </button>
                        <button type="submit" name="test_rule"
                                class="btn-primary">
                            <i class="fas fa-paper-plane mr-2"></i> Kirim Test
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-content edit-modal">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-edit text-yellow-500 mr-2"></i>
                            Edit Rule
                        </h3>
                        <p class="text-gray-500 text-sm mt-1">Edit keyword dan balasan rule</p>
                    </div>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form id="editForm" method="POST">
                    <input type="hidden" id="edit_rule_id" name="rule_id">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-key mr-1 text-gray-400"></i>
                            Keyword / Pesan Trigger
                        </label>
                        <input type="text" id="edit_keyword" name="keyword"
                               placeholder="Contoh: mau ikut, harga, daftar"
                               class="input-field"
                               required>
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Gunakan | untuk multiple keywords
                        </p>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-reply mr-1 text-gray-400"></i>
                            Balasan (Support Emoji)
                            <button type="button" onclick="toggleEmojiPicker('edit_reply')" 
                                    class="ml-2 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1 rounded">
                                <i class="fas fa-smile"></i> Emoji
                            </button>
                        </label>
                        <div class="relative">
                            <textarea id="edit_reply" name="reply"
                                      placeholder="Tulis balasan disini..."
                                      class="input-field"
                                      rows="5" required></textarea>
                            <div id="emojiPickerEdit" class="emoji-picker">
                                <div class="grid grid-cols-8 gap-1">
                                    <?php 
                                    $emojis = ['😊', '😎', '🤔', '👍', '❤️', '🎉', '✅', '❌', 
                                               '⚠️', '📋', '💳', '🏦', '📊', '📸', '✨', '⚡',
                                               '🔥', '🎯', '👶', '📚', '🔗', '🤝', '💪', '🚀',
                                               '💰', '📱', '📞', '📧', '📍', '🕒', '📅', '⭐'];
                                    foreach ($emojis as $emoji): ?>
                                    <div class="emoji-item" onclick="insertEmoji('edit_reply', '<?= $emoji ?>')">
                                        <?= $emoji ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-star mr-1 text-yellow-500"></i>
                            Prioritas
                        </label>
                        <select id="edit_priority" name="priority" class="input-field">
                            <option value="1">🟢 Normal</option>
                            <option value="2">🟡 Tinggi</option>
                            <option value="3">🔴 Sangat Tinggi</option>
                        </select>
                    </div>
                    
                    <div class="flex justify-end space-x-3 pt-4 border-t border-gray-100">
                        <button type="button" onclick="closeEditModal()"
                                class="btn-outline">
                            <i class="fas fa-times mr-2"></i> Batal
                        </button>
                        <button type="submit" name="edit_rule"
                                class="btn-primary">
                            <i class="fas fa-save mr-2"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="container mx-auto px-3 sm:px-6 py-8 max-w-7xl">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 fade-in">
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium mb-1">Total Rules</p>
                        <p class="text-3xl font-bold text-gray-800"><?= count($rules) ?></p>
                    </div>
                    <div class="stat-icon stat-icon-1">
                        <i class="fas fa-list"></i>
                    </div>
                </div>
                <div class="mt-4 text-sm text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>
                    Semua rules yang tersimpan
                </div>
            </div>
            
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium mb-1">Rules Aktif</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?= count(array_filter($rules, fn($r) => isset($r['is_active']) && $r['is_active'])); ?>
                        </p>
                    </div>
                    <div class="stat-icon stat-icon-2">
                        <i class="fas fa-toggle-on"></i>
                    </div>
                </div>
                <div class="mt-4 text-sm text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>
                    Rules yang sedang aktif
                </div>
            </div>
            
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium mb-1">Status Sistem</p>
                        <p class="text-xl font-bold text-green-600">
                            <span class="status-dot status-active"></span> Online
                        </p>
                        <p class="text-gray-400 text-sm mt-1">OneSender API Ready</p>
                    </div>
                    <div class="stat-icon stat-icon-3">
                        <i class="fas fa-server"></i>
                    </div>
                </div>
                <div class="mt-4 text-sm text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>
                    Sistem berjalan dengan baik
                </div>
            </div>
        </div>
        
        <!-- Add Rule Form -->
        <div class="card p-6 mb-8 fade-in">
            <h2 class="text-lg font-bold text-gray-800 mb-6 flex items-center">
                <div class="w-8 h-8 rounded-lg bg-gray-100 text-gray-600 flex items-center justify-center mr-3">
                    <i class="fas fa-plus"></i>
                </div>
                Tambah Rule Baru
            </h2>
            
            <form method="POST" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-key text-gray-400 mr-1"></i>
                            Keyword / Pesan Trigger
                        </label>
                        <input type="text" name="keyword"
                               placeholder="Contoh: mau ikut, harga, daftar"
                               class="input-field"
                               required>
                        <div class="mt-3 text-sm text-gray-500 bg-gray-50 p-3 rounded-lg">
                            <p class="font-medium mb-1"><i class="fas fa-lightbulb mr-1"></i> Tips:</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li>Gunakan | untuk multiple: <code>mau|ingin|ikut</code></li>
                                <li>Case-insensitive (tidak peduli huruf besar/kecil)</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="lg:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-reply text-gray-400 mr-1"></i>
                            Balasan (Support Emoji)
                            <button type="button" onclick="toggleEmojiPicker('reply')" 
                                    class="ml-2 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1 rounded">
                                <i class="fas fa-smile"></i> Tambah Emoji
                            </button>
                        </label>
                        <div class="relative">
                            <textarea name="reply"
                                      placeholder="Contoh: Yuk ikut! 🎉&#10;Silakan daftar di link berikut: https://..."
                                      class="input-field"
                                      rows="4" required></textarea>
                            <div id="emojiPicker" class="emoji-picker">
                                <div class="grid grid-cols-8 gap-1">
                                    <?php foreach ($emojis as $emoji): ?>
                                    <div class="emoji-item" onclick="insertEmoji('reply', '<?= $emoji ?>')">
                                        <?= $emoji ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 text-sm text-gray-500 bg-gray-50 p-3 rounded-lg">
                            <p class="font-medium mb-1"><i class="fas fa-code mr-1"></i> Formatting:</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li>Gunakan <code>\n</code> untuk baris baru</li>
                                <li>Click tombol emoji untuk tambahkan simbol</li>
                                <li>Maksimal 1000 karakter</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-star text-yellow-500 mr-1"></i>
                            Prioritas
                        </label>
                        <select name="priority" class="input-field">
                            <option value="1">🟢 Normal</option>
                            <option value="2">🟡 Tinggi</option>
                            <option value="3">🔴 Sangat Tinggi</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-2">
                            Prioritas tinggi diproses lebih dulu
                        </p>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" name="add_rule"
                                class="btn-primary w-full py-3 text-lg">
                            <i class="fas fa-save mr-2"></i> Simpan Rule Baru
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Rules Table -->
        <div class="fade-in">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-list-alt text-gray-600 mr-2"></i>
                    Daftar Rules Auto-Reply
                </h2>
                <div class="text-sm text-gray-500">
                    <?php
                    $activeCount = count(array_filter($rules, fn($r) => isset($r['is_active']) && $r['is_active']));
                    echo "<span class='font-medium text-green-600'>$activeCount Aktif</span> / " . count($rules) . " Total";
                    ?>
                </div>
            </div>
            
            <?php if (empty($rules)): ?>
            <div class="card p-12 text-center">
                <div class="text-gray-300 mb-6">
                    <i class="fas fa-inbox text-6xl"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-400 mb-3">Belum ada rules</h3>
                <p class="text-gray-500 mb-6 max-w-md mx-auto">Tambahkan rule pertama Anda menggunakan form di atas untuk mulai membalas pesan otomatis</p>
                <a href="#add-rule" class="btn-primary inline-flex">
                    <i class="fas fa-plus mr-2"></i> Tambah Rule Pertama
                </a>
            </div>
            <?php else: ?>
            <div class="table-container">
                <div class="table-header">
                    <div class="flex justify-between items-center">
                        <h3 class="font-medium text-gray-700">Auto-Reply Rules</h3>
                        <div class="text-sm text-gray-500">
                            Urutan: Prioritas Tinggi → Rendah
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="table-cell font-medium text-gray-700 text-left">Keyword</th>
                                <th class="table-cell font-medium text-gray-700 text-left">Balasan</th>
                                <th class="table-cell font-medium text-gray-700 text-left w-28">Prioritas</th>
                                <th class="table-cell font-medium text-gray-700 text-left w-32">Status</th>
                                <th class="table-cell font-medium text-gray-700 text-left w-32">Dibuat</th>
                                <th class="table-cell font-medium text-gray-700 text-left w-48">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $index => $rule): ?>
                            <tr class="table-row fade-in" style="animation-delay: <?= $index * 0.05 ?>s">
                                <td class="table-cell">
                                    <div class="font-medium text-gray-900">
                                        <?= htmlspecialchars($rule['keyword'] ?? 'N/A') ?>
                                    </div>
                                    <?php if (isset($rule['keyword']) && strpos($rule['keyword'], '|') !== false): ?>
                                    <div class="mt-2">
                                        <?php
                                        $keywords = explode('|', $rule['keyword']);
                                        foreach (array_slice($keywords, 0, 3) as $kw):
                                        ?>
                                        <span class="keyword-tag"><?= htmlspecialchars(trim($kw)) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($keywords) > 3): ?>
                                        <span class="keyword-tag">+<?= count($keywords) - 3 ?> lagi</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="table-cell">
                                    <?php
                                    $reply_text = $rule['reply'] ?? 'N/A';
                                    $truncated = mb_strlen($reply_text) > 80 ? mb_substr($reply_text, 0, 80) . '...' : $reply_text;
                                    ?>
                                    <div class="text-gray-600 text-sm mb-1">
                                        <?= nl2br(htmlspecialchars($truncated)) ?>
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        <i class="fas fa-ruler-vertical mr-1"></i>
                                        <?= mb_strlen($reply_text) ?> karakter
                                    </div>
                                </td>
                                <td class="table-cell">
                                    <?php
                                    $priority = $rule['priority'] ?? 1;
                                    $priorityConfig = [
                                        1 => ['label' => 'Normal', 'color' => 'bg-gray-100 text-gray-800', 'icon' => 'fa-circle'],
                                        2 => ['label' => 'Tinggi', 'color' => 'bg-yellow-100 text-yellow-800', 'icon' => 'fa-arrow-up'],
                                        3 => ['label' => 'Tinggi', 'color' => 'bg-red-100 text-red-800', 'icon' => 'fa-fire']
                                    ];
                                    $config = $priorityConfig[$priority] ?? $priorityConfig[1];
                                    ?>
                                    <span class="badge <?= $config['color'] ?>">
                                        <i class="fas <?= $config['icon'] ?> mr-1"></i>
                                        <?= $config['label'] ?>
                                    </span>
                                </td>
                                <td class="table-cell">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="rule_id" value="<?= $rule['id'] ?? 0 ?>">
                                        <input type="hidden" name="status" value="<?= (isset($rule['is_active']) && $rule['is_active']) ? 0 : 1 ?>">
                                        <button type="submit" name="toggle_rule"
                                                class="badge <?= (isset($rule['is_active']) && $rule['is_active']) ? 'badge-success' : 'badge-danger' ?>">
                                            <i class="fas <?= (isset($rule['is_active']) && $rule['is_active']) ? 'fa-toggle-on' : 'fa-toggle-off' ?> mr-1"></i>
                                            <?= (isset($rule['is_active']) && $rule['is_active']) ? 'Aktif' : 'Nonaktif' ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="table-cell">
                                    <div class="text-sm text-gray-900">
                                        <?= isset($rule['created_at']) ? date('d/m/Y', strtotime($rule['created_at'])) : 'N/A' ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?= isset($rule['created_at']) ? date('H:i', strtotime($rule['created_at'])) : '' ?>
                                    </div>
                                </td>
                                <td class="table-cell">
                                    <div class="flex space-x-2">
                                        <button onclick="openTestModal(<?= $rule['id'] ?? 0 ?>, `<?= addslashes(str_replace('`', '\`', $rule['reply'] ?? '')) ?>`)"
                                                class="action-btn btn-test"
                                                title="Test rule">
                                            <i class="fas fa-paper-plane"></i>
                                        </button>
                                        
                                        <button onclick="openEditModal(<?= $rule['id'] ?? 0 ?>, `<?= addslashes(str_replace('`', '\`', $rule['keyword'] ?? '')) ?>`, `<?= addslashes(str_replace('`', '\`', $rule['reply'] ?? '')) ?>`, <?= $rule['priority'] ?? 1 ?>)"
                                                class="action-btn btn-edit"
                                                title="Edit rule">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus rule ini?')">
                                            <input type="hidden" name="rule_id" value="<?= $rule['id'] ?? 0 ?>">
                                            <button type="submit" name="delete_rule"
                                                    class="action-btn btn-delete"
                                                    title="Hapus rule">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                    <div class="text-sm text-gray-500 flex items-center">
                        <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                        Rules diproses berdasarkan prioritas (tinggi → rendah). Jika keyword sama, yang prioritas tinggi akan digunakan.
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Tips Section -->
        <div class="mt-8 card p-6 fade-in">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                <div class="w-8 h-8 rounded-lg bg-gray-100 text-gray-600 flex items-center justify-center mr-3">
                    <i class="fas fa-lightbulb"></i>
                </div>
                Tips & Best Practices
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-gray-50 p-5 rounded-lg border border-gray-200">
                    <h4 class="font-bold text-gray-700 mb-3 flex items-center">
                        <i class="fas fa-bullseye mr-2"></i> Keyword Strategy
                    </h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                            <span>Gunakan kata kunci umum: "harga", "daftar", "info"</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                            <span>Multiple keywords: "mau|ingin|ikut"</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                            <span>Case-insensitive matching</span>
                        </li>
                    </ul>
                </div>
                
                <div class="bg-gray-50 p-5 rounded-lg border border-gray-200">
                    <h4 class="font-bold text-gray-700 mb-3 flex items-center">
                        <i class="fas fa-rocket mr-2"></i> Optimization
                    </h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <i class="fas fa-star text-yellow-500 mt-1 mr-2"></i>
                            <span>Test rule sebelum deploy</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-star text-yellow-500 mt-1 mr-2"></i>
                            <span>Prioritas tinggi untuk keyword penting</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-star text-yellow-500 mt-1 mr-2"></i>
                            <span>Gunakan emoji untuk engagement</span>
                        </li>
                    </ul>
                </div>
                
                <div class="bg-gray-50 p-5 rounded-lg border border-gray-200">
                    <h4 class="font-bold text-gray-700 mb-3 flex items-center">
                        <i class="fas fa-shield-alt mr-2"></i> System Info
                    </h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <i class="fas fa-database mt-1 mr-2"></i>
                            <span>Farhan Ramadhan</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-plug mt-1 mr-2"></i>
                            <span>Bismillah</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-history mt-1 mr-2"></i>
                            <span>Real-time processing</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="mt-8 text-center text-gray-500 text-sm">
            <p>© <?= date('Y') ?> Jawwada Quran - Auto Reply System v2.0</p>
            <p class="mt-1">OneSender WhatsApp API Integration</p>
        </div>
    </div>

    <script>
        // Notification
        <?php if (isset($_SESSION['notification'])): ?>
        setTimeout(() => {
            const notification = document.getElementById('notification');
            if (notification) {
                notification.classList.add('show');
                setTimeout(() => {
                    notification.classList.remove('show');
                }, 5000);
            }
        }, 300);
        <?php endif; ?>
        
        function closeNotification() {
            const notification = document.getElementById('notification');
            if (notification) notification.classList.remove('show');
        }
        
        // Modal Functions
        function openTestModal(ruleId, messageText) {
            document.getElementById('test_rule_id').value = ruleId;
            document.getElementById('messagePreview').innerHTML = 
                '<div class="whitespace-pre-wrap">' + messageText.replace(/\n/g, '<br>') + '</div>';
            document.getElementById('testModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            document.getElementById('test_phone').focus();
        }
        
        function closeModal() {
            document.getElementById('testModal').classList.remove('active');
            document.body.style.overflow = 'auto';
            document.getElementById('testForm').reset();
        }
        
        // Edit Modal Functions
        function openEditModal(ruleId, keyword, reply, priority) {
            document.getElementById('edit_rule_id').value = ruleId;
            document.getElementById('edit_keyword').value = keyword;
            document.getElementById('edit_reply').value = reply;
            document.getElementById('edit_priority').value = priority;
            document.getElementById('editModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            document.getElementById('edit_keyword').focus();
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // Emoji Picker Functions
        function toggleEmojiPicker(targetField) {
            const picker = document.getElementById('emojiPicker' + (targetField === 'reply' ? '' : 'Edit'));
            picker.style.display = picker.style.display === 'block' ? 'none' : 'block';
        }
        
        function insertEmoji(targetField, emoji) {
            const textarea = document.getElementById(targetField);
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            
            textarea.value = text.substring(0, start) + emoji + text.substring(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
            
            // Close picker
            document.getElementById('emojiPicker' + (targetField === 'reply' ? '' : 'Edit')).style.display = 'none';
        }
        
        // Close picker when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.emoji-picker') && !e.target.closest('[onclick*="toggleEmojiPicker"]')) {
                document.getElementById('emojiPicker').style.display = 'none';
                document.getElementById('emojiPickerEdit').style.display = 'none';
            }
        });
        
        // Close modal when clicking outside
        document.getElementById('testModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        
        document.getElementById('editModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        
        // Format phone number
        document.getElementById('test_phone')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.startsWith('0')) {
                value = '62' + value.substring(1);
            }
            e.target.value = value;
        });
        
        // Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeEditModal();
            }
        });
        
        // Scroll to add rule form
        document.querySelectorAll('a[href="#add-rule"]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelector('.card.p-6.mb-8.fade-in').scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
        
        // Auto-hide notification after 5 seconds
        setTimeout(() => {
            const notification = document.getElementById('notification');
            if (notification && notification.classList.contains('show')) {
                notification.classList.remove('show');
            }
        }, 5000);
        
        // Show loading overlay on form submit
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                if (this.querySelector('[name="test_rule"]') || 
                    this.querySelector('[name="add_rule"]') || 
                    this.querySelector('[name="edit_rule"]')) {
                    document.getElementById('loadingOverlay').style.display = 'flex';
                }
            });
        });
    </script>
</body>
</html>