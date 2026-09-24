<?php
// admin_logs.php
// Pastikan file ini dilindungi oleh autentikasi admin jika belum

// Aktifkan error reporting untuk debugging (hapus di produksi)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Nonaktifkan untuk produksi
ini_set('log_errors', 1);
ini_set('error_log', '/home/digitalprofit.my.id/app.reqra.my.id/jwdpoloap/php_error.log');

require_once 'config.php';

if (!isset($conn) || !$conn) {
    die("Koneksi database gagal.");
}

if ($conn->connect_error) {
    die("Koneksi database error: " . $conn->connect_error);
}

// Ambil parameter filter dari URL
$filter_kategori = $_GET['kategori'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_search = $_GET['search'] ?? '';

// Daftar kategori yang diizinkan
$allowed_categories = [
    'new_user', 'existing', 'promo', 'followup', 'pembayaran', 'reminder',
    'reminder_sent', 'promo_sent', 'followup_sent', 'outgoing'
];
// Daftar status yang diizinkan (jika kolom status digunakan untuk outgoing)
$allowed_statuses = ['sent', 'failed', 'received'];

// Query dasar
$sql = "SELECT nowa, nama, message, kategori, status, created_at FROM log_wa WHERE 1=1";

// Tambahkan filter jika ada dan valid
$params = [];
$types = '';

if ($filter_kategori !== '' && in_array($filter_kategori, $allowed_categories, true)) {
    $sql .= " AND kategori = ?";
    $params[] = $filter_kategori;
    $types .= 's';
}

if ($filter_status !== '' && in_array($filter_status, $allowed_statuses, true)) {
    $sql .= " AND status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_date_from !== '') {
    $sql .= " AND created_at >= ?";
    $params[] = $filter_date_from . ' 00:00:00';
    $types .= 's';
}

if ($filter_date_to !== '') {
    $sql .= " AND created_at <= ?";
    $params[] = $filter_date_to . ' 23:59:59';
    $types .= 's';
}

if ($filter_search !== '') {
    $sql .= " AND (message LIKE ? OR nowa LIKE ? OR nama LIKE ?)";
    $search_term = '%' . $filter_search . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

// Urutkan dari yang terbaru
$sql .= " ORDER BY created_at DESC";

// Tambahkan LIMIT untuk paging (opsional, contoh: 50 per halaman)
$limit = 50;
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii'; // Tambahkan tipe untuk limit dan offset

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$logs = [];
if ($result) {
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
    }
    // Jika num_rows = 0, $logs tetap array kosong
} else {
    error_log("admin_logs.php - Query log gagal: " . $conn->error);
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Chat - Admin</title>
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
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Log Chat Admin</h1>
            <p class="text-lg text-gray-600">Lihat dan filter semua percakapan</p>
        </header>

        <!-- Filter Box -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Filter Log</h2>
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label for="kategori" class="block text-sm font-medium text-gray-700 mb-1">Kategori</label>
                        <select id="kategori" name="kategori" class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                            <option value="">Semua</option>
                            <option value="new_user" <?= $filter_kategori === 'new_user' ? 'selected' : '' ?>>Nomor Baru</option>
                            <option value="existing" <?= $filter_kategori === 'existing' ? 'selected' : '' ?>>Terdaftar</option>
                            <option value="reminder_sent" <?= $filter_kategori === 'reminder_sent' ? 'selected' : '' ?>>Reminder Dikirim</option>
                            <option value="promo_sent" <?= $filter_kategori === 'promo_sent' ? 'selected' : '' ?>>Promo Dikirim</option>
                            <!-- Tambahkan opsi lain sesuai kebutuhan -->
                        </select>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select id="status" name="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                            <option value="">Semua</option>
                            <option value="received" <?= $filter_status === 'received' ? 'selected' : '' ?>>Diterima</option>
                            <option value="sent" <?= $filter_status === 'sent' ? 'selected' : '' ?>>Dikirim</option>
                            <option value="failed" <?= $filter_status === 'failed' ? 'selected' : '' ?>>Gagal</option>
                        </select>
                    </div>
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Dari Tanggal</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">Sampai Tanggal</label>
                        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                </div>
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Cari (Nomor, Nama, Pesan)</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Masukkan kata kunci..." class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                </div>
                <div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Terapkan Filter
                    </button>
                    <a href="?page=<?= $page ?>" class="ml-2 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Reset Filter
                    </a>
                </div>
            </form>
        </div>

        <!-- Log Table -->
        <div class="bg-white rounded-xl shadow-md p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Daftar Log</h2>
            <p class="text-sm text-gray-600 mb-4">Menampilkan <?= count($logs) ?> dari hasil (halaman <?= $page ?>)</p>
            <?php if (!empty($logs)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nomor</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pesan</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kategori</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($logs as $log): ?>
                            <tr class="<?= ($log['kategori'] ?? '') === 'new_user' ? 'bg-yellow-50' : '' ?>">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($log['created_at'] ?? 'N/A') ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-mono"><?= htmlspecialchars($log['nowa'] ?? 'N/A') ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($log['nama'] ?? 'N/A') ?></td>
                                <td class="px-4 py-3 text-sm text-gray-900 max-w-xs truncate"><?= htmlspecialchars($log['message'] ?? '') ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?= ($log['kategori'] ?? '') === 'new_user' ? 'bg-red-100 text-red-800' : '' ?>
                                        <?= ($log['kategori'] ?? '') === 'existing' ? 'bg-green-100 text-green-800' : '' ?>
                                        <?= ($log['kategori'] ?? '') === 'reminder_sent' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800' ?>
                                    ">
                                        <?= htmlspecialchars($log['kategori'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?= ($log['status'] ?? '') === 'sent' ? 'bg-green-100 text-green-800' : '' ?>
                                        <?= ($log['status'] ?? '') === 'failed' ? 'bg-red-100 text-red-800' : '' ?>
                                        <?= ($log['status'] ?? '') === 'received' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800' ?>
                                    ">
                                        <?= htmlspecialchars($log['status'] ?? 'N/A') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-500 py-4">Tidak ada log yang ditemukan untuk filter ini.</p>
            <?php endif; ?>
        </div>

        <!-- Footer Note -->
        <div class="mt-8 text-center text-sm text-gray-500">
            <p>Dashboard ini menampilkan data dari tabel <code class="bg-gray-800 text-green-400 p-1 rounded">log_wa</code>.</p>
        </div>
    </div>
</body>
</html>