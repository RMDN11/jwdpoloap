<?php
session_start();
require_once 'auth_checkwa.php';

require_once 'config.php';

// Menangani notifikasi dari session
$notification = '';
$notificationType = '';
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $notificationType = $_SESSION['notification_type'];
    unset($_SESSION['notification']);
    unset($_SESSION['notification_type']);
}

// PROSES SIMPAN (TAMBAH / UBAH) GRUP SATUAN - BAGIAN INI DIHILANGKAN DARI TAMPILAN
// Namun, kode ini tetap ada untuk memastikan fungsi update dari tabel tetap jalan
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_group'])) {
    $groupId = $_POST['group_id'];
    $namaGrup = trim($_POST['nama_grup']);
    $idGrup = trim($_POST['id_grup']);
    $kategori = trim($_POST['kategori']);

    if (empty($namaGrup) || empty($idGrup)) {
        $_SESSION['notification'] = "Nama grup dan ID grup tidak boleh kosong.";
        $_SESSION['notification_type'] = 'error';
    } else {
        if (empty($groupId)) {
            // Tambah Baru
            $stmt = $conn->prepare("INSERT INTO wa_grup (nama_grup, id_grup, kategori) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $namaGrup, $idGrup, $kategori);
            $pesanSukses = "Grup baru berhasil disimpan.";
            $pesanGagal = "Gagal menyimpan grup baru: ";
        } else {
            // Perbarui
            $stmt = $conn->prepare("UPDATE wa_grup SET nama_grup = ?, id_grup = ?, kategori = ? WHERE id = ?");
            $stmt->bind_param("sssi", $namaGrup, $idGrup, $kategori, $groupId);
            $pesanSukses = "Grup berhasil diperbarui.";
            $pesanGagal = "Gagal memperbarui grup: ";
        }

        if ($stmt->execute()) {
            $_SESSION['notification'] = $pesanSukses;
            $_SESSION['notification_type'] = 'success';
        } else {
            $_SESSION['notification'] = $pesanGagal . $stmt->error;
            $_SESSION['notification_type'] = 'error';
        }
        $stmt->close();
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// PROSES SIMPAN (TAMBAH) GRUP SECARA MASAL
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_massal'])) {
    $dataGrup = trim($_POST['data_grup']);
    $kategori = trim($_POST['kategori_massal']);

    if (empty($dataGrup)) {
        $_SESSION['notification'] = "Data grup tidak boleh kosong.";
        $_SESSION['notification_type'] = 'error';
    } else {
        $lines = explode("\n", $dataGrup);
        $totalSaved = 0;
        $totalUpdated = 0;
        $totalFailed = 0;
        
        $stmt = $conn->prepare("INSERT INTO wa_grup (nama_grup, id_grup, kategori) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE nama_grup=VALUES(nama_grup), kategori=VALUES(kategori)");
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $parts = explode(':', $line, 2); 
                
                if (count($parts) === 2) {
                    $namaGrup = trim($parts[0]);
                    $idGrup = trim($parts[1]);
                    
                    if (!empty($namaGrup) && !empty($idGrup)) {
                        $stmt->bind_param("sss", $namaGrup, $idGrup, $kategori);
                        if ($stmt->execute()) {
                            if ($conn->affected_rows === 1) {
                                $totalSaved++;
                            } else {
                                $totalUpdated++;
                            }
                        } else {
                            $totalFailed++;
                        }
                    } else {
                        $totalFailed++;
                    }
                } else {
                    $totalFailed++;
                }
            }
        }
        
        $stmt->close();
        
        if ($totalSaved > 0 || $totalUpdated > 0) {
            $message = "$totalSaved grup berhasil ditambahkan.";
            if ($totalUpdated > 0) {
                $message .= " $totalUpdated grup diperbarui.";
            }
            if ($totalFailed > 0) {
                $message .= " $totalFailed gagal.";
            }
            $_SESSION['notification'] = $message;
            $_SESSION['notification_type'] = 'success';
        } else {
            $_SESSION['notification'] = "Tidak ada grup yang berhasil disimpan.";
            $_SESSION['notification_type'] = 'error';
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// PROSES HAPUS GRUP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_group_id'])) {
    $groupIdToDelete = $_POST['delete_group_id'];
    $stmt = $conn->prepare("DELETE FROM wa_grup WHERE id = ?");
    $stmt->bind_param("i", $groupIdToDelete);
    if ($stmt->execute()) {
        $_SESSION['notification'] = "Grup berhasil dihapus.";
        $_SESSION['notification_type'] = 'success';
    } else {
        $_SESSION['notification'] = "Gagal menghapus grup: " . $stmt->error;
        $_SESSION['notification_type'] = 'error';
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Ambil semua data grup untuk ditampilkan
$groups = [];
$result = $conn->query("SELECT * FROM wa_grup ORDER BY kategori, nama_grup");
if ($result) {
    $groups = $result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Kelola Grup WA - JWD</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/phosphor-icons/1.4.2/css/phosphor.css" rel="stylesheet">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }
    .modal-backdrop { background-color: rgba(0,0,0,0.5); }
  </style>
</head>
<body class="bg-slate-50 text-slate-800">
  <div id="app" class="flex flex-col min-h-screen">
    <header class="bg-white shadow-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-4">
                <div class="bg-gradient-to-br from-purple-500 to-indigo-600 p-3 rounded-xl shadow-lg"><i class="ph-address-book text-white text-2xl"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Manajemen Grup WhatsApp</h1>
                    <p class="text-sm text-slate-500">Tambah dan kelola daftar grup penerima</p>
                </div>
            </div>
             <a href="kirimgrup.php" class="text-sm font-medium text-slate-600 hover:text-blue-600">Ke Halaman Kirim Grup &raquo;</a>
			   <a href="logoutwa.php" class="text-sm font-medium text-gray-300 hover:text-white"><i class="fas fa-right-from-bracket"></i> Keluar</a>
        </div>
    </header>

    <main class="flex-grow p-4 sm:p-6 lg:p-8">
      <div class="max-w-5xl mx-auto">
        
        <?php if (!empty($notification)): ?>
        <div class="mb-6 p-4 rounded-lg shadow-md <?php echo $notificationType === 'success' ? 'bg-green-100 text-green-800 border-l-4 border-green-500' : 'bg-red-100 text-red-800 border-l-4 border-red-500'; ?>">
          <?php echo htmlspecialchars($notification); ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 gap-6 mb-6">
            <div class="bg-white shadow-md rounded-2xl p-6">
                <h2 class="text-lg font-semibold flex items-center mb-4"><i class="ph-list-plus text-xl mr-2 text-blue-600"></i> Tambah Grup</h2>
                <form method="POST" action="">
                    <div class="space-y-4">
                        <div>
                            <label for="data_grup" class="block font-semibold mb-1 text-sm">
                                Daftar Grup
                                <span class="font-normal text-slate-500 block text-xs mt-1">Masukkan data grup dengan format **Nama Grup : ID Grup** (satu baris untuk setiap grup).</span>
                            </label>
                            <textarea name="data_grup" id="data_grup" rows="6" class="w-full border-slate-300 rounded-lg p-3 text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Contoh:&#10;Grup Alumni JWD : 120363041234567890@g.us&#10;Grup Promosi Oktober : 120363019876543210@g.us"></textarea>
                        </div>
                        <div>
                            <label for="kategori_massal" class="block font-semibold mb-1 text-sm">Kategori</label>
                            <input type="text" name="kategori_massal" id="kategori_massal" class="w-full border-slate-300 rounded-lg p-2" placeholder="Contoh: Promosi, Internal, Alumni">
                        </div>
                    </div>
                    <div class="flex justify-end mt-6">
                        <button type="submit" name="save_massal" class="bg-gradient-to-r from-blue-600 to-violet-600 text-white px-6 py-2 rounded-lg shadow hover:from-blue-700 hover:to-violet-700 transition font-semibold">
                            <i class="ph-cloud-arrow-up mr-1"></i> Simpan Semua Grup
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-2xl p-6">
            <h2 class="text-lg font-semibold flex items-center mb-4"><i class="ph-list-bullets text-xl mr-2 text-blue-600"></i> Daftar Grup Tersimpan</h2>
            <div class="border rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Nama Grup</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ID Grup</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Kategori</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-200">
                            <?php if (!empty($groups)): ?>
                                <?php foreach ($groups as $group): ?>
                                    <tr class="hover:bg-blue-50 transition duration-150">
                                        <td class="px-6 py-4 text-sm font-medium text-slate-900"><?= htmlspecialchars($group['nama_grup']); ?></td>
                                        <td class="px-6 py-4 text-sm text-slate-500 break-all"><?= htmlspecialchars($group['id_grup']); ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <span class="bg-slate-100 text-slate-700 text-xs font-medium px-2.5 py-1 rounded-full"><?= htmlspecialchars($group['kategori']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-center space-x-2">
                                            <button class="edit-group-btn text-blue-600 hover:text-blue-800 font-semibold"
                                                    data-id="<?= $group['id'] ?>"
                                                    data-nama="<?= htmlspecialchars($group['nama_grup']) ?>"
                                                    data-idgrup="<?= htmlspecialchars($group['id_grup']) ?>"
                                                    data-kategori="<?= htmlspecialchars($group['kategori']) ?>">
                                                Ubah
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus grup ini?');" class="inline-block">
                                                <input type="hidden" name="delete_group_id" value="<?= $group['id'] ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-800 font-semibold">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center p-8 text-slate-500">Belum ada grup yang disimpan.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
      </div>
    </main>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.edit-group-btn');
    const namaGrupInput = document.getElementById('nama_grup');
    const idGrupInput = document.getElementById('id_grup');
    const kategoriInput = document.getElementById('kategori');
    const groupIdInput = document.getElementById('group_id');
    
    editButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            groupIdInput.value = this.dataset.id;
            namaGrupInput.value = this.dataset.nama;
            idGrupInput.value = this.dataset.idgrup;
            kategoriInput.value = this.dataset.kategori;
            
            // Scroll ke bagian form edit
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
            
            namaGrupInput.focus();
        });
    });
});
</script>

</body>
</html>