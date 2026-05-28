<?php
session_start();
require_once 'auth_checkwa.php';
require_once 'config.php';

// --- NOTIFIKASI ---
$notification = '';
$notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notificationType'];
    unset($_SESSION['notification']);
    unset($_SESSION['notificationType']);
}

// ========================================
// 🔄 LOGIKA POST untuk Template
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_templates_action'])) {
    $action = $_POST['manage_templates_action'];

    if ($action === 'add' && $conn) {
        $newName = trim($_POST['new_template_name']);
        $newContent = trim($_POST['new_template_content']);
        if ($newName && $newContent) {
            $stmt = $conn->prepare("INSERT INTO poloap_templates (name, content) VALUES (?, ?)");
            $stmt->bind_param("ss", $newName, $newContent);
            if ($stmt->execute()) {
                $_SESSION['notification'] = "Template baru berhasil ditambahkan.";
                $_SESSION['notificationType'] = 'success';
            } else {
                $_SESSION['notification'] = "Gagal menambahkan template: " . $stmt->error;
                $_SESSION['notificationType'] = 'error';
            }
            $stmt->close();
        } else {
            $_SESSION['notification'] = "Nama dan isi template tidak boleh kosong.";
            $_SESSION['notificationType'] = 'error';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'edit' && $conn) {
        $editId = (int)$_POST['template_id'];
        $editName = trim($_POST['new_template_name']);
        $editContent = trim($_POST['new_template_content']);
        if ($editId && $editName && $editContent) {
            $stmt = $conn->prepare("UPDATE poloap_templates SET name = ?, content = ? WHERE id = ?");
            $stmt->bind_param("ssi", $editName, $editContent, $editId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $_SESSION['notification'] = "Template berhasil diupdate.";
                    $_SESSION['notificationType'] = 'success';
                } else {
                    $_SESSION['notification'] = "Tidak ada data yang diubah.";
                    $_SESSION['notificationType'] = 'error';
                }
            } else {
                $_SESSION['notification'] = "Gagal mengupdate template: " . $stmt->error;
                $_SESSION['notificationType'] = 'error';
            }
            $stmt->close();
        } else {
            $_SESSION['notification'] = "Data template tidak valid.";
            $_SESSION['notificationType'] = 'error';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'delete' && $conn) {
        $delId = (int)$_POST['template_id'];
        if ($delId) {
            $stmt = $conn->prepare("DELETE FROM poloap_templates WHERE id = ?");
            $stmt->bind_param("i", $delId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $_SESSION['notification'] = "Template berhasil dihapus.";
                    $_SESSION['notificationType'] = 'success';
                } else {
                    $_SESSION['notification'] = "Template tidak ditemukan.";
                    $_SESSION['notificationType'] = 'error';
                }
            } else {
                $_SESSION['notification'] = "Gagal menghapus template: " . $stmt->error;
                $_SESSION['notificationType'] = 'error';
            }
            $stmt->close();
        } else {
            $_SESSION['notification'] = "ID template tidak valid.";
            $_SESSION['notificationType'] = 'error';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Ambil templates untuk ditampilkan
$pesanTemplates = [];
if ($conn) {
    $stmt = $conn->prepare("SELECT id, name, content FROM poloap_templates ORDER BY name");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pesanTemplates[] = $row;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Template Pesan</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        :root {
            --primary: #374151;
            --secondary: #4b5563;
            --accent: #9ca3af;
            --card-bg: #f3f4f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background-color: #f9fafb;
            color: #111827;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        /* Header Styles - Sama seperti analitik chat */
        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 2rem;
        }
        
        .title-section {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .logo-container {
            width: 80px;
            height: 80px;
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .logo-container i {
            font-size: 2rem;
            color: white;
        }
        
        .title-content h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .title-content p {
            color: #d1d5db;
            font-size: 1.1rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .action-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .btn-logout {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        /* Notification */
        .notification {
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            margin-bottom: 2rem;
            border-left: 4px solid;
            animation: slideIn 0.5s ease-out;
        }
        
        .notification.success {
            background: #d1fae5;
            border-left-color: var(--success);
            color: #065f46;
        }
        
        .notification.error {
            background: #fee2e2;
            border-left-color: var(--danger);
            color: #991b1b;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Main Content Layout */
        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        /* Form Panel */
        .form-panel {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            height: fit-content;
        }
        
        .panel-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .panel-title i {
            color: var(--accent);
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #374151;
        }
        
        .form-input, .form-textarea {
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 120px;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: linear-gradient(to right, #6b7280, #4b5563);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(to right, #4b5563, #374151);
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: linear-gradient(to right, #10b981, #059669);
            color: white;
        }
        
        .btn-success:hover {
            background: linear-gradient(to right, #059669, #047857);
        }
        
        .btn-secondary {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            border-color: #9ca3af;
        }
        
        /* Templates Panel */
        .templates-panel {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .panel-header {
            padding: 1.5rem 2rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .templates-list {
            max-height: 600px;
            overflow-y: auto;
            padding: 1rem;
        }
        
        .template-item {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .template-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .template-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .template-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #111827;
            flex: 1;
        }
        
        .template-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            border-radius: 0.5rem;
        }
        
        .btn-edit {
            background: linear-gradient(to right, #3b82f6, #2563eb);
            color: white;
        }
        
        .btn-edit:hover {
            background: linear-gradient(to right, #2563eb, #1d4ed8);
        }
        
        .btn-delete {
            background: linear-gradient(to right, #ef4444, #dc2626);
            color: white;
        }
        
        .btn-delete:hover {
            background: linear-gradient(to right, #dc2626, #b91c1c);
        }
        
        .template-content {
            color: #4b5563;
            font-size: 0.9rem;
            line-height: 1.5;
            background: white;
            padding: 1rem;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .main-content {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .title-section {
                flex-direction: column;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .template-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .template-actions {
                width: 100%;
                justify-content: center;
            }
            
            .btn-sm {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header - Sama seperti analitik chat -->
        <header class="header">
            <div class="header-content">
                <div class="title-section">
                    <div class="logo-container">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="title-content">
                        <h1>Kelola Template Pesan</h1>
                        <p>Atur template pesan untuk pengiriman reminder otomatis</p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <a href="analitikjwd.php" class="action-btn">
                        <i class="fas fa-chart-line"></i>
                        Analitik Chat
                    </a>
                    <a href="grafik-chat.php" class="action-btn">
                        <i class="fas fa-chart-bar"></i>
                        Grafik Chat
                    </a>
                    <a href="reminder.php" class="action-btn">
                        <i class="fas fa-bell"></i>
                        Reminder Pembayaran
                    </a>
                    <a href="logoutwa.php" class="action-btn btn-logout">
                        <i class="fas fa-sign-out-alt"></i>
                        Keluar
                    </a>
                </div>
            </div>
        </header>

        <?php if (!empty($notification)): ?>
        <div class="notification <?= $notificationType ?>">
            <?= htmlspecialchars($notification); ?>
        </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Form Panel -->
            <div class="form-panel">
                <h3 class="panel-title">
                    <i class="fas fa-plus-circle"></i>
                    <span id="form-title">Tambah Template Baru</span>
                </h3>
                <form method="POST" id="templateForm">
                    <input type="hidden" name="manage_templates_action" id="form_action" value="add">
                    <input type="hidden" name="template_id" id="template_id_input" value="">
                    
                    <div class="form-group">
                        <label class="form-label">Nama Template</label>
                        <input type="text" name="new_template_name" id="new_template_name" required 
                               class="form-input" placeholder="Masukkan nama template">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Isi Pesan Template</label>
                        <textarea name="new_template_content" id="new_template_content" required 
                                  class="form-textarea" placeholder="Tulis isi pesan template di sini..."></textarea>
                        <div class="text-xs text-gray-500 mt-1">
                            Gunakan <code class="bg-gray-100 px-1 py-0.5 rounded">{nama}</code> dan 
                            <code class="bg-gray-100 px-1 py-0.5 rounded">{nomor}</code> sebagai placeholder
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" id="submit_template_btn" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <span id="submit-text">Tambah Template</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Templates Panel -->
            <div class="templates-panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="fas fa-list"></i>
                        Daftar Template Tersimpan
                    </h3>
                </div>
                
                <div class="templates-list">
                    <?php if (empty($pesanTemplates)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <div>Belum ada template</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pesanTemplates as $tmpl): ?>
                            <div class="template-item">
                                <div class="template-header">
                                    <div class="template-name"><?= htmlspecialchars($tmpl['name']) ?></div>
                                    <div class="template-actions">
                                        <button type="button" class="btn btn-edit btn-sm edit-template-btn"
                                                data-id="<?= $tmpl['id'] ?>"
                                                data-name="<?= htmlspecialchars($tmpl['name'], ENT_QUOTES) ?>"
                                                data-content="<?= htmlspecialchars($tmpl['content'], ENT_QUOTES) ?>">
                                            <i class="fas fa-edit"></i>
                                            Edit
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Hapus template <?= htmlspecialchars($tmpl['name']) ?>?');" style="display:inline;">
                                            <input type="hidden" name="manage_templates_action" value="delete">
                                            <input type="hidden" name="template_id" value="<?= $tmpl['id'] ?>">
                                            <button type="submit" class="btn btn-delete btn-sm">
                                                <i class="fas fa-trash"></i>
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div class="template-content">
                                    <?= nl2br(htmlspecialchars($tmpl['content'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const editButtons = document.querySelectorAll('.edit-template-btn');
            const formTitle = document.getElementById('form-title');
            const submitText = document.getElementById('submit-text');
            const submitButton = document.getElementById('submit_template_btn');
            const formAction = document.getElementById('form_action');
            const templateIdInput = document.getElementById('template_id_input');
            const templateNameInput = document.getElementById('new_template_name');
            const templateContentInput = document.getElementById('new_template_content');
            
            // Edit template functionality
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    const content = this.getAttribute('data-content');
                    
                    // Fill form with template data
                    templateNameInput.value = name;
                    templateContentInput.value = content;
                    templateIdInput.value = id;
                    
                    // Update form for edit mode
                    formTitle.textContent = 'Edit Template';
                    submitText.textContent = 'Update Template';
                    formAction.value = 'edit';
                    
                    // Scroll to form
                    document.querySelector('.form-panel').scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'start' 
                    });
                    
                    // Focus on name input
                    templateNameInput.focus();
                });
            });
            
            // Reset form when clicking on "Tambah Template" if in edit mode
            submitButton.addEventListener('click', function() {
                if (formAction.value === 'edit' && !templateIdInput.value) {
                    resetForm();
                }
            });
            
            // Form submission handling
            document.getElementById('templateForm').addEventListener('submit', function() {
                // Add loading state
                const originalText = submitButton.innerHTML;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                submitButton.disabled = true;
                
                // Revert after 3 seconds if still processing
                setTimeout(() => {
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                }, 3000);
            });
            
            // Auto-hide notifications after 5 seconds
            const notifications = document.querySelectorAll('.notification');
            notifications.forEach(notification => {
                setTimeout(() => {
                    notification.style.opacity = '0';
                    notification.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        notification.remove();
                    }, 300);
                }, 5000);
            });
            
            function resetForm() {
                templateNameInput.value = '';
                templateContentInput.value = '';
                templateIdInput.value = '';
                formTitle.textContent = 'Tambah Template Baru';
                submitText.textContent = 'Tambah Template';
                formAction.value = 'add';
            }
            
            // Add hover effects to template items
            const templateItems = document.querySelectorAll('.template-item');
            templateItems.forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
</body>
</html>