<?php
session_start();
require_once 'auth_checkwa.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once 'config.php';
} catch (Exception $e) {
    die("Configuration error");
}

if (isset($conn) && $conn) {
    mysqli_set_charset($conn, "utf8mb4");
}

// Proses Update Nama
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_nama'])) {
    $nowa = $_POST['nowa'];
    $namaBaru = trim($_POST['nama_baru']);
    
    // Update nama di log_wa
    $stmt = $conn->prepare("UPDATE log_wa SET nama = ? WHERE nowa = ?");
    if ($stmt) {
        $stmt->bind_param("ss", $namaBaru, $nowa);
        if ($stmt->execute()) {
            $_SESSION['notif'] = "✅ Nama untuk " . htmlspecialchars($nowa) . " berhasil diperbarui menjadi: " . htmlspecialchars($namaBaru);
            $_SESSION['notif_type'] = "success";
        } else {
            $_SESSION['notif'] = "❌ Gagal memperbarui nama.";
            $_SESSION['notif_type'] = "error";
        }
        $stmt->close();
    }
    header("Location: validasi_nama.php");
    exit;
}

// Ambil data prospek unik dari log_wa yang terbaru
$prospekData = [];
if ($conn) {
    // Menggunakan GROUP BY untuk mengambil data unik per nomor WA dengan nama terakhir
    $sql = "SELECT nowa, nama, MAX(created_at) as last_chat, message 
            FROM log_wa 
            WHERE nama IS NOT NULL AND nama != '' 
            GROUP BY nowa 
            ORDER BY last_chat DESC 
            LIMIT 100"; 
            
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $prospekData[] = $row;
        }
    }
}

// Fungsi sederhana untuk membersihkan karakter aneh otomatis (opsional)
function autoCleanName($name) {
    // Hapus emoji, simbol, dan angka. Sisakan huruf dan spasi
    $clean = preg_replace('/[^a-zA-Z\s]/', '', $name);
    // Hapus spasi berlebih
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    // Kapitalisasi huruf pertama setiap kata
    return ucwords(strtolower($clean));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validasi Nama Prospek | JWD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); transition: all 0.3s ease; }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-6xl mx-auto">
    <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-2xl p-6 md:p-8 mb-8 text-white shadow-lg flex flex-col md:flex-row justify-between items-center gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center border border-white/30">
                <i class="fas fa-user-check text-2xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold">Validasi Nama Prospek</h1>
                <p class="text-blue-100 text-sm mt-1">Perbaiki nama yang aneh sebelum melakukan follow-up massal</p>
            </div>
        </div>
        <a href="analitik_chat.php" class="bg-white/20 hover:bg-white/30 px-6 py-2.5 rounded-xl font-medium transition-colors border border-white/30 backdrop-blur-sm flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Kembali ke Analitik
        </a>
    </div>

    <?php if (isset($_SESSION['notif'])): ?>
    <div class="mb-6 p-4 rounded-xl border <?= $_SESSION['notif_type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?> flex items-center gap-3">
        <span class="font-medium"><?= $_SESSION['notif'] ?></span>
    </div>
    <?php unset($_SESSION['notif'], $_SESSION['notif_type']); endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-5 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
            <h3 class="font-bold text-gray-700 flex items-center gap-2">
                <i class="fas fa-list-ul text-blue-500"></i> Daftar Nama Terakhir
            </h3>
            <span class="bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1 rounded-full"><?= count($prospekData) ?> Kontak</span>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-sm text-gray-500">
                        <th class="p-4 font-semibold w-1/4">Nomor WA</th>
                        <th class="p-4 font-semibold w-1/3">Nama Saat Ini (Raw)</th>
                        <th class="p-4 font-semibold w-1/3">Edit Nama (Bersih)</th>
                        <th class="p-4 font-semibold text-center w-1/12">Simpan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($prospekData)): ?>
                    <tr>
                        <td colspan="4" class="p-8 text-center text-gray-400">Belum ada data prospek.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($prospekData as $row): 
                        // Coba buatkan saran nama yang sudah dibersihkan
                        $saranNama = autoCleanName($row['nama']);
                    ?>
                    <tr class="hover:bg-blue-50/50 transition-colors">
                        <td class="p-4">
                            <div class="font-mono text-sm font-semibold text-gray-700"><?= htmlspecialchars($row['nowa']) ?></div>
                            <div class="text-xs text-gray-400 mt-1 truncate max-w-[200px]" title="<?= htmlspecialchars($row['message']) ?>">
                                "<?= htmlspecialchars($row['message']) ?>"
                            </div>
                        </td>
                        <td class="p-4">
                            <div class="text-sm text-gray-800 font-medium bg-gray-100 px-3 py-2 rounded-lg inline-block break-all">
                                <?= htmlspecialchars($row['nama']) ?>
                            </div>
                        </td>
                        <td class="p-4">
                            <form method="POST" class="flex gap-2 w-full" id="form-<?= $row['nowa'] ?>">
                                <input type="hidden" name="nowa" value="<?= htmlspecialchars($row['nowa']) ?>">
                                <input type="text" name="nama_baru" value="<?= htmlspecialchars($saranNama ?: $row['nama']) ?>" 
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                            </form>
                        </td>
                        <td class="p-4 text-center">
                            <button type="submit" form="form-<?= $row['nowa'] ?>" name="update_nama" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white w-10 h-10 rounded-lg flex items-center justify-center transition-transform hover:scale-105 shadow-sm">
                                <i class="fas fa-save"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>