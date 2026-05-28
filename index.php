<?php
// === IMPOR KONFIGURASI DATABASE & API ===
// Gunakan require_once agar script berhenti jika config.php tidak ditemukan
require_once 'config.php';

// Pastikan koneksi berhasil sebelum melanjutkan
if (!$conn) {
    // Jika $conn tidak diinisialisasi oleh config.php, tampilkan error
    http_response_code(500);
    die("Kesalahan Internal Server: Koneksi database gagal diinisialisasi dari config.php.");
}

// Inisialisasi session
session_start();

// Fungsi untuk mengklasifikasikan pesan
function classifyMessage($message) {
    $message = strtolower($message);
    // Tambahkan pola-pola yang menunjukkan sudah mendaftar
    if (preg_match('/(sudah|siap|oke|ok|ya|yap|saya isi|saya sudah isi|lengkap|selesai|saya sudah|saya|ikut|daftar|kirim|transfer|bayar|konfirmasi|trf|tf|sudah kirim|sudah bayar|pembayaran|lunas|alhamdulillah|pendaftaran|terdaftar|konfirmasi)/', $message)) {
        return 'Sudah Mendaftar';
    }
    // Tambahkan pola-pola yang menunjukkan belum mendaftar atau bertanya
    elseif (preg_match('/(belum|nanti|mau|ingin|coba|kapan|berapa|info|tanya|tanya-tanya|ikut|mode|normal|intensif|flexibel|kelas|jam|waktu|lanjut|baru)/', $message)) {
        return 'Belum Mendaftar';
    } else {
        return 'Pesan Lain';
    }
}

// Fungsi untuk normalisasi nomor WA
function normalizePhone($phone) {
    if (substr($phone, 0, 1) === '0') {
        return '62' . substr($phone, 1);
    } elseif (substr($phone, 0, 2) === '62') {
        return $phone;
    } elseif (substr($phone, 0, 3) === '+62') {
        return '62' . substr($phone, 3);
    } else {
        // Asumsikan format internasional atau hanya angka
        return ltrim($phone, '0+');
    }
}

// --- AMBIL DATA DARI DATABASE ---
$prospek = [];
$paidContactsFromDB = [];

// 1. Ambil pesan masuk dari DB
if ($conn) {
    // Query untuk mengambil data dari tabel pesan_masuk
    // Sesuaikan nama kolom jika berbeda di database Anda
    $sql = "SELECT sender_phone, message_text, timestamp FROM pesan_masuk ORDER BY timestamp ASC";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $senderPhone = normalizePhone($row['sender_phone']);
            $message = $row['message_text'];
            $timestamp = $row['timestamp'];

            if (!isset($prospek[$senderPhone])) {
                $prospek[$senderPhone] = [
                    'first_seen' => $timestamp,
                    'last_message_time' => $timestamp,
                    'last_message' => $message,
                    'pesan_klasifikasi' => classifyMessage($message),
                    'jumlah_pesan' => 0,
                ];
            }

            // Update data terbaru
            $prospek[$senderPhone]['last_message_time'] = $timestamp;
            $prospek[$senderPhone]['last_message'] = $message;
            $prospek[$senderPhone]['pesan_klasifikasi'] = classifyMessage($message);
            $prospek[$senderPhone]['jumlah_pesan']++;
        }
    } else {
        // Jika query pesan_masuk gagal, tampilkan error
        http_response_code(500);
        die("Kesalahan Internal Server: Gagal mengambil data pesan_masuk. Error: " . $conn->error);
    }
} else {
    // Jika koneksi gagal, meskipun sudah dicek di awal, cek ulang
    http_response_code(500);
    die("Kesalahan Internal Server: Koneksi database tidak valid.");
}

// 2. Ambil daftar nomor yang sudah bayar dari calon_peserta
if ($conn) {
    // Query untuk mengambil nomor WA dari calon_peserta
    // Pastikan kolom 'nowa' ada di tabel calon_peserta
    $sql = "SELECT nowa FROM calon_peserta WHERE nowa IS NOT NULL AND nowa != ''";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $nowa = normalizePhone(trim($row['nowa']));
            $paidContactsFromDB[$nowa] = true;
        }
    } else {
        // Jika query calon_peserta gagal, tampilkan error
        http_response_code(500);
        die("Kesalahan Internal Server: Gagal mengambil data calon_peserta. Error: " . $conn->error);
    }
}

// --- FILTER PROSPEK YANG BELUM BAYAR ---
$followUpProspects = array_filter($prospek, function($data, $contactId) use ($paidContactsFromDB) {
    // Jika nomor ada di daftar yang sudah bayar, jangan tampilkan di follow-up
    return !isset($paidContactsFromDB[$contactId]);
}, ARRAY_FILTER_USE_BOTH);

// --- PROSES PENCARIAN ---
$searchQuery = $_GET['search_query'] ?? null;
if ($searchQuery !== null && trim($searchQuery) !== '') {
    $searchTerm = strtolower(trim($searchQuery));
    $followUpProspects = array_filter($followUpProspects, function($data, $contactId) use ($searchTerm) {
        $match = false;
        $match = $match || stripos($contactId, $searchTerm) !== false;
        $match = $match || stripos($data['last_message'], $searchTerm) !== false;
        $match = $match || stripos($data['pesan_klasifikasi'], $searchTerm) !== false;
        return $match;
    }, ARRAY_FILTER_USE_BOTH);
}

// Urutkan berdasarkan waktu pesan terakhir (terlama dulu untuk follow-up)
uasort($followUpProspects, function($a, $b) {
    return strtotime($a['last_message_time']) <=> strtotime($b['last_message_time']);
});

// Notifikasi jika ada
$notification = $_SESSION['notification'] ?? null;
$notificationType = $_SESSION['notificationType'] ?? 'info'; // default ke info
unset($_SESSION['notification'], $_SESSION['notificationType']);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Prospek Tahfidz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"/>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css"/>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <style>
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #3b82f6 !important;
            color: white !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #2563eb !important;
            color: white !important;
        }
    </style>
</head>
<body class="bg-gray-100">
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-8">Follow-Up Prospek Tahfidz</h1>

    <!-- Notifikasi -->
    <?php if ($notification): ?>
        <div id="notification" class="mb-6 p-4 rounded-lg shadow-md <?php echo $notificationType === 'error' ? 'bg-red-100 text-red-700 border border-red-300' : 'bg-blue-100 text-blue-700 border border-blue-300'; ?>">
            <?php echo htmlspecialchars($notification); ?>
        </div>
        <script>
            setTimeout(function() {
                document.getElementById('notification').style.display = 'none';
            }, 5000);
        </script>
    <?php endif; ?>

    <!-- Form Pencarian -->
    <div class="mb-6">
        <form method="GET" class="flex gap-2">
            <input type="text" name="search_query" value="<?= htmlspecialchars($_GET['search_query'] ?? '') ?>" placeholder="Cari berdasarkan nomor, pesan, atau klasifikasi..." class="flex-grow px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-search mr-2"></i> Cari
            </button>
            <a href="?" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-times mr-2"></i> Reset
            </a>
        </form>
    </div>

    <!-- Statistik -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-md p-6 border border-gray-200">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 mr-4">
                    <i class="fas fa-users text-blue-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-gray-600">Total Prospek Ditemukan</p>
                    <p class="text-2xl font-bold text-gray-800"><?= count($prospek) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-md p-6 border border-gray-200">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 mr-4">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-gray-600">Sudah Mendaftar (DB)</p>
                    <p class="text-2xl font-bold text-gray-800"><?= count($paidContactsFromDB) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-md p-6 border border-gray-200">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100 mr-4">
                    <i class="fas fa-exclamation-circle text-yellow-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-gray-600">Perlu Follow-Up</p>
                    <p class="text-2xl font-bold text-gray-800"><?= count($followUpProspects) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel Follow-Up -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8 border border-gray-200">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800">Daftar Prospek Belum Mendaftar</h2>
        </div>
        <div class="overflow-x-auto">
            <table id="prospekTable" class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nomor WA</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pesan Terakhir</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Pesan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Klasifikasi</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jumlah Pesan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($followUpProspects)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">Tidak ada prospek yang perlu di-follow-up.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($followUpProspects as $contactId => $data): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($contactId) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($data['last_message']) ?>"><?= htmlspecialchars($data['last_message']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($data['last_message_time']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php
                                        $class = 'bg-gray-100 text-gray-800';
                                        if (strpos(strtolower($data['pesan_klasifikasi']), 'sudah') !== false) {
                                            $class = 'bg-green-100 text-green-800';
                                        } elseif (strpos(strtolower($data['pesan_klasifikasi']), 'belum') !== false) {
                                            $class = 'bg-yellow-100 text-yellow-800';
                                        }
                                        echo $class;
                                    ?>">
                                    <?= htmlspecialchars($data['pesan_klasifikasi']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $data['jumlah_pesan'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2">
                                    <!-- Tombol Kirim Reminder (Placeholder) -->
                                    <!-- Anda bisa menambahkan logika pengiriman pesan di sini -->
                                    <button class="inline-flex items-center px-2 py-1 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none cursor-not-allowed opacity-50" disabled>
                                        <i class="fas fa-paper-plane mr-1"></i> Kirim
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Info Penting -->
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-lg mb-8">
        <p class="text-sm text-yellow-700">
            <i class="fas fa-info-circle mr-2"></i>
            Status "Sudah Bayar" ditentukan secara otomatis berdasarkan keberadaan nomor WhatsApp di tabel <code>calon_peserta</code>.
            Kontak yang muncul di tabel ini adalah yang <strong>belum terdaftar</strong> di sistem.
        </p>
    </div>

</div>

<script>
    $(document).ready(function() {
        $('#prospekTable').DataTable({
            "pageLength": 25,
            "order": [[ 2, "asc" ]], // Urutkan berdasarkan kolom Waktu Pesan (indeks 2) secara ascending
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json"
            }
        });
    });
</script>

</body>
</html>