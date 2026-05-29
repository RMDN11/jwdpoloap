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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Grup & Peserta | Reqrasend</title>
    <link href="https://api.fontshare.com/v2/css?f[]=clash-display@600,700,500&f[]=plus-jakarta-sans@400,500,600,700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --bg-main: #060709;
            --surface-panel: #0f1115;
            --surface-card: #161920;
            --border-muted: rgba(255, 255, 255, 0.06);
            --border-glow: rgba(255, 255, 255, 0.15);
            --text-pure: #ffffff;
            --text-muted: #808694;
            --accent-raw: #f4f4f5;
            --accent-danger: #ef4444;
            --font-head: 'Clash Display', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: var(--font-body);
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-pure);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        /* Layout Architecture */
        .app-container {
            display: flex;
            width: 100%;
        }

        /* Sidebar Glassmorphism */
        .sidebar {
            width: 280px;
            background: var(--surface-panel);
            border-right: 1px solid var(--border-muted);
            padding: 2.5rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 3rem;
        }

        .brand-core {
            font-family: var(--font-head);
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .nav-group {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.85rem 1rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            border-radius: 10px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .nav-link:hover, .nav-link.active {
            color: var(--text-pure);
            background: var(--surface-card);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
        }

        /* Main Viewport */
        .main-viewport {
            flex: 1;
            padding: 3rem 4rem;
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
        }

        .view-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 3.5rem;
            animation: varFadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .view-title h1 {
            font-family: var(--font-head);
            font-size: 2.75rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin-bottom: 0.5rem;
        }

        .view-title p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        /* Action Primary Button */
        .btn-industrial {
            background: var(--accent-raw);
            color: var(--bg-main);
            border: none;
            padding: 0.85rem 1.75rem;
            border-radius: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-industrial:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(255, 255, 255, 0.1);
        }

        /* Dual-Grid Management Section */
        .management-grid {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 2.5rem;
            align-items: start;
        }

        /* Group Panel Cards */
        .panel-box {
            background: var(--surface-panel);
            border: 1px solid var(--border-muted);
            border-radius: 20px;
            padding: 1.75rem;
        }

        .panel-box h2 {
            font-family: var(--font-head);
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .group-stack {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .group-card {
            background: var(--surface-card);
            border: 1px solid transparent;
            border-radius: 14px;
            padding: 1.25rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
        }

        .group-card:hover, .group-card.active {
            border-color: var(--border-glow);
            background: rgba(255, 255, 255, 0.02);
        }

        .group-card.active {
            background: rgba(255, 255, 255, 0.04);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
        }

        .group-info h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .group-info p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Dynamic Interactive Table Data */
        .table-wrapper {
            background: var(--surface-panel);
            border: 1px solid var(--border-muted);
            border-radius: 24px;
            padding: 2rem;
            overflow: hidden;
        }

        .table-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .search-input-wrapper {
            position: relative;
            width: 320px;
        }

        .search-input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            width: 18px;
        }

        .search-core {
            width: 100%;
            background: var(--surface-card);
            border: 1px solid var(--border-muted);
            padding: 0.75rem 1rem 0.75rem 2.7rem;
            border-radius: 10px;
            color: var(--text-pure);
            font-size: 0.9rem;
            transition: border-color 0.2s ease;
        }

        .search-core:focus {
            outline: none;
            border-color: var(--border-glow);
        }

        .industrial-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.5rem;
        }

        .industrial-table th {
            text-align: left;
            padding: 1rem;
            color: var(--text-muted);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-muted);
        }

        .industrial-table tr-row {
            background: var(--surface-card);
        }

        .industrial-table td {
            padding: 1.25rem 1rem;
            background: var(--surface-card);
            border-top: 1px solid var(--border-muted);
            border-bottom: 1px solid var(--border-muted);
        }

        .industrial-table tr td:first-child {
            border-left: 1px solid var(--border-muted);
            border-radius: 12px 0 0 12px;
        }

        .industrial-table tr td:last-child {
            border-right: 1px solid var(--border-muted);
            border-radius: 0 12px 12px 0;
        }

        .action-icon-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .action-icon-btn:hover {
            color: var(--accent-danger);
            background: rgba(239, 68, 68, 0.1);
        }

        @keyframes varFadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <div class="app-container">
        <aside class="sidebar">
            <div class="brand-core">
                <i data-lucide="radio-tower"></i> Reqrasend.
            </div>
            <ul class="nav-group">
                <li><a href="index.php" class="nav-link"><i data-lucide="layout-dashboard"></i> Dashboard</a></li>
                <li><a href="pesan.php" class="nav-link"><i data-lucide="send"></i> Broadcast Pesan</a></li>
                <li><a href="kelola_grup.php" class="nav-link active"><i data-lucide="users"></i> Kelola Grup</a></li>
                <li><a href="auto_reply_engine.php" class="nav-link"><i data-lucide="bot"></i> Auto Reply Engine</a></li>
                <li><a href="webhook.php" class="nav-link"><i data-lucide="webhook"></i> Webhook Logs</a></li>
            </ul>
        </aside>

        <main class="main-viewport">
            <header class="view-header">
                <div class="view-title">
                    <h1>Data Kontrak & Grup</h1>
                    <p>Klusterisasi target kontak broadcast dan konfigurasi otomatisasi entitas.</p>
                </div>
                <button class="btn-industrial" id="openModalGrup">
                    <i data-lucide="plus-circle"></i> Buat Grup Baru
                </button>
            </header>

            <div class="management-grid">
                <div class="panel-box">
                    <h2>Daftar Kluster Grup</h2>
                    <div class="group-stack">
                        <?php
                        // Contoh skrip backend loop:
                        // $query_grup = mysqli_query($koneksi, "SELECT * FROM grup");
                        // while($g = mysqli_fetch_array($query_grup)) { ... }
                        ?>
                        <div class="group-card active">
                            <div class="group-info">
                                <h3>Batch April 2026</h3>
                                <p>142 Partisipan Terhubung</p>
                            </div>
                            <i data-lucide="chevron-right" style="width: 18px; color: var(--text-muted)"></i>
                        </div>
                        <div class="group-card">
                            <div class="group-info">
                                <h3>Premium Members</h3>
                                <p>58 Partisipan Terhubung</p>
                            </div>
                            <i data-lucide="chevron-right" style="width: 18px; color: var(--text-muted)"></i>
                        </div>
                        <div class="group-card">
                            <div class="group-info">
                                <h3>Reseller Region A</h3>
                                <p>210 Partisipan Terhubung</p>
                            </div>
                            <i data-lucide="chevron-right" style="width: 18px; color: var(--text-muted)"></i>
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-actions">
                        <div class="search-input-wrapper">
                            <i data-lucide="search"></i>
                            <input type="text" class="search-core" placeholder="Cari nama atau nomor WhatsApp...">
                        </div>
                        <button class="btn-industrial" style="background: rgba(255,255,255,0.05); color: var(--text-pure); border: 1px solid var(--border-muted);" id="openModalPeserta">
                            <i data-lucide="user-plus"></i> Tambah Peserta
                        </button>
                    </div>

                    <table class="industrial-table">
                        <thead>
                            <tr>
                                <th>Nama Lengkap</th>
                                <th>Nomor WhatsApp</th>
                                <th>Tanggal Gabung</th>
                                <th style="text-align: right; padding-right: 1.5rem;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="font-weight: 600;">Amiruddin Siregar</td>
                                <td style="font-family: monospace; color: var(--text-muted);">+62 812-9877-2211</td>
                                <td style="color: var(--text-muted);">24 Mei 2026</td>
                                <td style="text-align: right;">
                                    <button class="action-icon-btn" title="Hapus Peserta dari Grup">
                                        <i data-lucide="trash-2" style="width: 18px;"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;">Siti Sarah Rahmawati</td>
                                <td style="font-family: monospace; color: var(--text-muted);">+62 857-1122-3344</td>
                                <td style="color: var(--text-muted);">22 Mei 2026</td>
                                <td style="text-align: right;">
                                    <button class="action-icon-btn" title="Hapus Peserta">
                                        <i data-lucide="trash-2" style="width: 18px;"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Inisialisasi ikon representatif
        lucide.createIcons();
    </script>
</body>
</html>