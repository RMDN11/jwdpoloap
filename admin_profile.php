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
    <title>Profil Admin - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --jwd-primary: #4f46e5;
            --jwd-success: #10b981;
            --jwd-warning: #f59e0b;
            --jwd-danger: #ef4444;
            --jwd-dark: #1f2937;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen font-sans text-gray-800">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Profil Saya</h1>
            <p class="text-lg text-gray-600">Kelola informasi akun admin Anda.</p>
        </header>

        <?php if (isset($message)): ?>
            <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Detail Akun</h2>
            <form method="POST">
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                        <input type="text" id="username" value="<?= htmlspecialchars($admin['username']) ?>" readonly class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-gray-100 rounded-md shadow-sm cursor-not-allowed">
                    </div>
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap</label>
                        <input type="text" name="name" id="name" value="<?= htmlspecialchars($admin['name']) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Alamat Email</label>
                        <input type="email" name="email" id="email" value="<?= htmlspecialchars($admin['email']) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Perbarui Profil
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Keamanan</h2>
            <form method="POST">
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Password Saat Ini</label>
                        <input type="password" name="current_password" id="current_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">Password Baru</label>
                        <input type="password" name="new_password" id="new_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="confirm_new_password" class="block text-sm font-medium text-gray-700 mb-1">Konfirmasi Password Baru</label>
                        <input type="password" name="confirm_new_password" id="confirm_new_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="submit" name="change_password" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Ganti Password
                    </button>
                </div>
            </form>
        </div>

    </div>
</body>
</html>