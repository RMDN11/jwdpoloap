<?php
// admin_profile.php
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
    <title>Sancutary | Profil Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #FDFCF7;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
            background-blend-mode: overlay;
            opacity: 0.98;
        }
        h1, h2 { font-family: 'Cormorant Garamond', serif; }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            box-shadow: 0 20px 40px rgba(6, 78, 59, 0.05);
        }

        .input-field {
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid #E5E7EB;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-field:focus {
            border-color: #064E3B;
            box-shadow: 0 0 0 4px rgba(6, 78, 59, 0.05);
            background: white;
        }

        .btn-emerald {
            background: #064E3B;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-emerald:hover {
            background: #042f24;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(6, 78, 59, 0.2);
        }

        .stagger-1 { animation: fadeInUp 0.8s ease forwards; opacity: 0; }
        .stagger-2 { animation: fadeInUp 0.8s ease 0.2s forwards; opacity: 0; }

        @keyframes fadeInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body class="min-h-screen py-16 px-6">
    <div class="max-w-3xl mx-auto">
        <header class="mb-16 text-center stagger-1">
            <span class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-800 mb-4 block">Management Console</span>
            <h1 class="text-6xl font-bold text-gray-900 mb-4 italic">Profil Pengurus</h1>
            <div class="w-12 h-[2px] bg-emerald-800 mx-auto"></div>
        </header>

        <?php if (isset($message)): ?>
            <div class="mb-8 p-4 glass-card border-l-4 border-emerald-800 text-emerald-900 rounded-r-lg stagger-1">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="glass-card rounded-2xl p-10 mb-10 stagger-1">
            <h2 class="text-3xl font-bold text-gray-800 mb-8 border-b border-gray-100 pb-4">Detail Identitas</h2>
            <form method="POST">
                <div class="grid grid-cols-1 gap-8">
                    <div>
                        <label for="username" class="block text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Username</label>
                        <input type="text" id="username" value="<?= htmlspecialchars($admin['username']) ?>" readonly class="block w-full px-4 py-3 border border-gray-200 bg-gray-50/50 rounded-lg text-gray-400 cursor-not-allowed font-medium">
                    </div>
                    <div>
                        <label for="name" class="block text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Nama Lengkap</label>
                        <input type="text" name="name" id="name" value="<?= htmlspecialchars($admin['name']) ?>" class="input-field block w-full px-4 py-3 rounded-lg focus:outline-none">
                    </div>
                    <div>
                        <label for="email" class="block text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Alamat Email</label>
                        <input type="email" name="email" id="email" value="<?= htmlspecialchars($admin['email']) ?>" class="input-field block w-full px-4 py-3 rounded-lg focus:outline-none">
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="btn-emerald inline-flex justify-center py-3 px-8 text-sm font-bold uppercase tracking-widest text-white rounded-lg">
                        Perbarui Profil
                    </button>
                </div>
            </form>
        </div>

        <div class="glass-card rounded-2xl p-10 stagger-2">
            <h2 class="text-3xl font-bold text-gray-800 mb-8 border-b border-gray-100 pb-4">Proteksi Akun</h2>
            <form method="POST">
                <div class="grid grid-cols-1 gap-8">
                    <div>
                        <label for="current_password" class="block text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Password Saat Ini</label>
                        <input type="password" name="current_password" id="current_password" class="input-field block w-full px-4 py-3 rounded-lg focus:outline-none">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <label for="new_password" class="block text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Password Baru</label>
                            <input type="password" name="new_password" id="new_password" class="input-field block w-full px-4 py-3 rounded-lg focus:outline-none">
                        </div>
                        <div>
                            <label for="confirm_new_password" class="block text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Konfirmasi</label>
                            <input type="password" name="confirm_new_password" id="confirm_new_password" class="input-field block w-full px-4 py-3 rounded-lg focus:outline-none">
                        </div>
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="submit" name="change_password" class="btn-emerald inline-flex justify-center py-3 px-8 text-sm font-bold uppercase tracking-widest text-white rounded-lg">
                        Ubah Kredensial
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>