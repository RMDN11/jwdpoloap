<?php
session_start();
require_once 'auth_checkwa.php';
require_once 'config.php';

$notification = '';
$notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notificationType'];
    unset($_SESSION['notification'], $_SESSION['notificationType']);
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
        header("Location: " . $_SERVER['PHP_SELF']); exit;
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
                    $_SESSION['notification'] = "Tidak ada perubahan data.";
                    $_SESSION['notificationType'] = 'warning';
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
        header("Location: " . $_SERVER['PHP_SELF']); exit;
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
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
}

$pesanTemplates = [];
if ($conn) {
    $stmt = $conn->prepare("SELECT id, name, content FROM poloap_templates ORDER BY name");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $pesanTemplates[] = $row; }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Template Pesan | JWD</title>
    <?php $cache_buster = time(); ?>
    <link rel="icon" href="LOGOJWD.png?v=<?= $cache_buster ?>" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #334155; }
        
        .custom-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
        
        .crm-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05); transition: all 0.2s ease-in-out; }
        .crm-input { background: #f8fafc; border: 1px solid #e2e8f0; color: #334155; border-radius: 8px; transition: all 0.2s; }
        .crm-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); background: #ffffff; }
        
        .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .row-animate { transition: background-color 0.2s ease; }
        .row-animate:hover { background-color: #f1f5f9; }
        
        @keyframes fadeInScale { from { opacity: 0; transform: scale(0.97) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .animate-fade-in { animation: fadeInScale 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards; }
    </style>
</head>
<body class="flex flex-col h-screen overflow-hidden bg-slate-50 relative">

    <div id="toast-container" class="fixed top-5 right-5 z-[9999] flex flex-col gap-3 pointer-events-none"></div>

    <header class="h-[70px] shrink-0 bg-white border-b border-slate-200 px-6 flex items-center justify-between z-10 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="bg-blue-600 text-white w-8 h-8 flex justify-center items-center rounded-lg shadow-sm"><i class="fas fa-comment-dots"></i></div>
            <h2 class="text-base font-bold text-slate-800">Manajemen Template Pesan</h2>
        </div>
        <div class="flex gap-2 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0 custom-scroll">
            <a href="pesan.php" class="shrink-0 bg-white border border-slate-200 text-slate-600 hover:text-blue-600 hover:border-blue-300 hover:bg-blue-50 px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm">
                <i class="fas fa-arrow-left mr-1"></i> Kembali
            </a>
            <a href="grafik.php" class="shrink-0 bg-slate-800 hover:bg-slate-900 text-white px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all shadow-sm">
                <i class="fas fa-chart-line mr-1"></i> Analitik Data
            </a>
        </div>
    </header>

    <div class="flex-1 overflow-y-auto p-5 lg:p-6 space-y-6 custom-scroll">
        
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <div class="lg:col-span-4 space-y-6">
                <div class="bg-gradient-to-r from-blue-700 to-indigo-800 rounded-xl p-5 text-white shadow-md animate-fade-in border border-blue-900/50">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20"><i class="fas fa-info-circle"></i></div>
                        <div>
                            <h3 class="font-bold text-sm">Informasi Placeholder</h3>
                            <p class="text-[10px] text-blue-200 mt-0.5">Gunakan <b class="text-white px-1 bg-black/20 rounded">{nama}</b> untuk menyapa prospek secara otomatis.</p>
                        </div>
                    </div>
                </div>

                <div class="crm-card p-5 animate-fade-in">
                    <h3 class="font-bold text-sm text-slate-800 mb-4 pb-3 border-b border-slate-100 flex items-center">
                        <i class="fas fa-edit text-blue-500 mr-2"></i> <span id="form-title">Tambah Template Baru</span>
                    </h3>
                    <form method="POST" id="templateForm" class="space-y-4">
                        <input type="hidden" name="manage_templates_action" id="form_action" value="add">
                        <input type="hidden" name="template_id" id="template_id_input" value="">
                        
                        <div>
                            <label class="text-[11px] font-bold text-slate-500 uppercase mb-1 block">Nama Template</label>
                            <input type="text" name="new_template_name" id="new_template_name" required class="crm-input w-full p-2.5 text-xs font-medium" placeholder="Contoh: Follow Up 1">
                        </div>
                        
                        <div>
                            <label class="text-[11px] font-bold text-slate-500 uppercase mb-1 block">Isi Pesan WhatsApp</label>
                            <textarea name="new_template_content" id="new_template_content" required class="crm-input w-full p-2.5 text-xs font-medium min-h-[150px] custom-scroll" placeholder="Assalamu'alaikum {nama}, ..."></textarea>
                        </div>
                        
                        <div class="pt-2 flex gap-2">
                            <button type="submit" id="submit_template_btn" class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg font-bold text-xs hover:bg-blue-700 transition-colors shadow-sm"><i class="fas fa-save mr-1.5"></i> <span id="submit-text">Simpan</span></button>
                            <button type="button" onclick="resetForm()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2.5 rounded-lg font-bold text-xs transition-colors shadow-sm" title="Batal/Reset"><i class="fas fa-undo"></i></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-8">
                <div class="crm-card overflow-hidden animate-fade-in" style="animation-delay: 0.1s;">
                    <div class="p-4 border-b border-slate-200 bg-white flex justify-between items-center">
                        <h3 class="font-bold text-sm text-slate-800 flex items-center gap-2">
                            <i class="fas fa-list-ul text-blue-500 bg-blue-50 p-1.5 rounded text-[10px]"></i> Daftar Template
                            <span class="bg-slate-100 text-slate-600 text-[10px] px-2 py-0.5 rounded-full ml-1 font-bold"><?= count($pesanTemplates) ?></span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto custom-scroll max-h-[600px]">
                        <table class="w-full text-left text-sm text-slate-600">
                            <thead class="text-[10px] text-slate-500 uppercase bg-slate-50/80 border-b border-slate-200 sticky top-0 z-10 backdrop-blur-sm">
                                <tr>
                                    <th class="p-4 font-bold w-1/3">Nama Template</th>
                                    <th class="p-4 font-bold">Cuplikan Isi Pesan</th>
                                    <th class="p-4 font-bold text-right w-24">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if(empty($pesanTemplates)): ?>
                                    <tr><td colspan="3" class="p-8 text-center text-slate-400 text-xs font-medium">Belum ada template yang disimpan.</td></tr>
                                <?php else: ?>
                                    <?php foreach($pesanTemplates as $tmpl): ?>
                                    <tr class="row-animate group">
                                        <td class="p-4 font-bold text-slate-800 text-xs align-top">
                                            <div class="flex items-center gap-2"><i class="fas fa-file-alt text-blue-400"></i> <?= htmlspecialchars($tmpl['name']) ?></div>
                                        </td>
                                        <td class="p-4 align-top">
                                            <div class="text-[11px] text-slate-500 bg-slate-50 p-2.5 rounded border border-slate-100 whitespace-pre-wrap max-h-[100px] overflow-y-auto custom-scroll leading-relaxed"><?= htmlspecialchars($tmpl['content']) ?></div>
                                        </td>
                                        <td class="p-4 text-right align-top">
                                            <div class="flex justify-end gap-1.5">
                                                <button type="button" class="bg-white border border-slate-200 text-blue-500 p-2 rounded hover:bg-blue-50 hover:border-blue-200 transition-colors shadow-sm edit-btn" 
                                                        data-id="<?= $tmpl['id'] ?>" data-name="<?= htmlspecialchars($tmpl['name'], ENT_QUOTES) ?>" data-content="<?= htmlspecialchars($tmpl['content'], ENT_QUOTES) ?>" title="Edit">
                                                    <i class="fas fa-pen text-[10px]"></i>
                                                </button>
                                                <form method="POST" class="m-0" onsubmit="return confirm('Hapus template <?= htmlspecialchars($tmpl['name'], ENT_QUOTES) ?>?')">
                                                    <input type="hidden" name="manage_templates_action" value="delete">
                                                    <input type="hidden" name="template_id" value="<?= $tmpl['id'] ?>">
                                                    <button type="submit" class="bg-white border border-slate-200 text-slate-400 p-2 rounded hover:bg-rose-50 hover:text-rose-500 hover:border-rose-200 transition-colors shadow-sm" title="Hapus">
                                                        <i class="fas fa-trash-alt text-[10px]"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
        <div class="h-10"></div>
    </div>

    <script>
        // --- TOAST NOTIFICATION SYSTEM ---
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            let colors = 'bg-emerald-500 text-white shadow-emerald-500/30'; let icon = 'fa-check-circle';
            if (type === 'error') { colors = 'bg-rose-500 text-white shadow-rose-500/30'; icon = 'fa-times-circle'; }
            else if (type === 'warning') { colors = 'bg-amber-500 text-white shadow-amber-500/30'; icon = 'fa-exclamation-triangle'; }
            
            toast.className = `flex items-center gap-2.5 px-4 py-3 rounded-xl shadow-lg text-[13px] font-bold transform transition-all duration-300 translate-x-full opacity-0 pointer-events-auto ${colors}`;
            toast.innerHTML = `<i class="fas ${icon} text-lg"></i> <span>${message}</span>`;
            
            container.appendChild(toast);
            setTimeout(() => toast.classList.remove('translate-x-full', 'opacity-0'), 10);
            setTimeout(() => { toast.classList.add('translate-x-full', 'opacity-0'); setTimeout(() => toast.remove(), 300); }, 4000);
        }

        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($notification): ?>
                showToast("<?= addslashes($notification) ?>", "<?= $notificationType ?>");
            <?php endif; ?>

            // Form Logic
            const formTitle = document.getElementById('form-title');
            const submitText = document.getElementById('submit-text');
            const formAction = document.getElementById('form_action');
            const templateIdInput = document.getElementById('template_id_input');
            const templateNameInput = document.getElementById('new_template_name');
            const templateContentInput = document.getElementById('new_template_content');

            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    templateIdInput.value = this.dataset.id;
                    templateNameInput.value = this.dataset.name;
                    templateContentInput.value = this.dataset.content;
                    formTitle.innerHTML = 'Edit Template';
                    submitText.innerHTML = 'Update';
                    formAction.value = 'edit';
                    templateNameInput.focus();
                });
            });

            window.resetForm = function() {
                templateIdInput.value = '';
                templateNameInput.value = '';
                templateContentInput.value = '';
                formTitle.innerHTML = 'Tambah Template Baru';
                submitText.innerHTML = 'Simpan';
                formAction.value = 'add';
            };
        });

        // Hide header if in iframe
        if (window.self !== window.top) {
            const headerElement = document.querySelector('header');
            if (headerElement) headerElement.style.display = 'none';
            document.body.style.backgroundColor = "transparent";
        }
    </script>
</body>
</html>