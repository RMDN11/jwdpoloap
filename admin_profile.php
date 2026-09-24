<?php
session_start();
// if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
//     header("Location: login.php");
//     exit;
// }

// Contoh data admin (nanti diganti dengan database)
$admin = [
    'id' => 1,
    'username' => 'jwd_admin',
    'name' => 'Admin JWD',
    'email' => 'admin@jwd.com',
    // 'avatar' => 'path/to/avatar.jpg' // Jika ada
];

// Contoh proses update profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newName = trim($_POST['name']);
    $newEmail = trim($_POST['email']);

    $errors = [];
    if (empty($newName)) $errors[] = "Nama tidak boleh kosong.";
    if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) $errors[] = "Email tidak valid.";

    if (empty($errors)) {
        // Simpan ke database (contoh: $db->updateAdmin(...))
        // $db->updateAdmin($admin['id'], $newName, $newEmail);
        $admin['name'] = $newName;
        $admin['email'] = $newEmail;
        $message = "Profil berhasil diperbarui!";
    }
}

// Contoh proses ganti password
if (isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmNewPassword = $_POST['confirm_new_password'];

    $errors = [];
    if (empty($currentPassword) || empty($newPassword) || empty($confirmNewPassword)) {
        $errors[] = "Semua field password harus diisi.";
    }
    if ($newPassword !== $confirmNewPassword) {
        $errors[] = "Konfirmasi password tidak cocok.";
    }
    if (strlen($newPassword) < 6) {
        $errors[] = "Password baru harus minimal 6 karakter.";
    }
    // Validasi password lama (contoh sederhana, ganti dengan verifikasi database)
    // if (!password_verify($currentPassword, $admin['password_hash'])) {
    //     $errors[] = "Password lama salah.";
    // }

    if (empty($errors)) {
        // Simpan password baru ke database (contoh: $db->updateAdminPassword(...))
        // $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        // $db->updateAdminPassword($admin['id'], $hashedNewPassword);
        $message = "Password berhasil diubah!";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Raleway', sans-serif;
            background-color: #FAFAFA;
            color: #334155;
        }
        .card {
            background: white;
            border: 1px solid #E2E8F0;
            border-radius: 4px;
        }
        .input-field {
            border: 1px solid #CBD5E1;
            border-radius: 2px;
            transition: border-color 0.2s;
        }
        .input-field:focus {
            border-color: #64748B;
            outline: none;
        }
    </style>
</head>
<body class="min-h-screen py-12 px-4">
    <div class="max-w-2xl mx-auto">
        <header class="mb-12 border-b border-gray-200 pb-6">
            <h1 class="text-3xl font-light tracking-tight text-slate-900">Pengaturan Profil</h1>
            <p class="text-sm text-slate-500 mt-1 uppercase tracking-widest font-medium">Administrator Console</p>
        </header>

        <?php if (isset($message)): ?>
            <div class="mb-6 p-4 border border-slate-200 bg-slate-50 text-slate-700 text-sm font-medium"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card p-8 mb-8">
            <h2 class="text-lg font-semibold text-slate-800 mb-6">Informasi Akun</h2>
            <form method="POST">
                <div class="space-y-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-tighter mb-2">Username</label>
                        <input type="text" value="<?= htmlspecialchars($admin['username']) ?>" readonly class="block w-full px-4 py-2 bg-gray-50 text-gray-400 border border-gray-200 text-sm cursor-not-allowed">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Nama Lengkap</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($admin['name']) ?>" class="input-field block w-full px-4 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($admin['email']) ?>" class="input-field block w-full px-4 py-2 text-sm">
                    </div>
                </div>
                <button type="submit" class="mt-8 bg-slate-900 text-white px-6 py-2 text-xs font-bold uppercase tracking-widest hover:bg-slate-700 transition-colors">Simpan Perubahan</button>
            </form>
        </div>

        <div class="card p-8">
            <h2 class="text-lg font-semibold text-slate-800 mb-6">Keamanan Akun</h2>
            <form method="POST">
                <div class="space-y-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Password Saat Ini</label>
                        <input type="password" name="current_password" class="input-field block w-full px-4 py-2 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <input type="password" name="new_password" placeholder="Password Baru" class="input-field block w-full px-4 py-2 text-sm">
                        <input type="password" name="confirm_new_password" placeholder="Konfirmasi" class="input-field block w-full px-4 py-2 text-sm">
                    </div>
                </div>
                <button type="submit" name="change_password" class="mt-8 bg-slate-100 text-slate-900 border border-slate-200 px-6 py-2 text-xs font-bold uppercase tracking-widest hover:bg-slate-200 transition-colors">Update Password</button>
            </form>
        </div>
    </div>
</body>
</html>