<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Koneksi database tidak tersedia. Mohon coba beberapa saat lagi.');
}

$conn->set_charset('utf8mb4');

// 1. Handle AJAX Search Endpoint
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $term = trim((string)($_GET['term'] ?? ''));
    $halaqoh = trim((string)($_GET['halaqoh'] ?? ''));

    if (mb_strlen($term) < 2 || $halaqoh === '') {
        echo json_encode([]);
        exit;
    }

    $searchTerm = '%' . $term . '%';
    $stmt = $conn->prepare("
        SELECT id, nama_lengkap, nowa 
        FROM peserta 
        WHERE nama_lengkap LIKE ? AND halaqoh = ? 
        ORDER BY nama_lengkap ASC 
        LIMIT 25
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Database query error']);
        exit;
    }
    $stmt->bind_param('ss', $searchTerm, $halaqoh);
    $stmt->execute();
    $result = $stmt->get_result();
    $pesertaList = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode($pesertaList);
    exit;
}

// 2. Handle AJAX List All in Halaqoh Endpoint
if (isset($_GET['action']) && $_GET['action'] === 'list_all') {
    header('Content-Type: application/json; charset=utf-8');
    $halaqoh = trim((string)($_GET['halaqoh'] ?? ''));

    if ($halaqoh === '') {
        echo json_encode([]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT id, nama_lengkap, nowa 
        FROM peserta 
        WHERE halaqoh = ? 
        ORDER BY nama_lengkap ASC 
        LIMIT 100
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Database query error']);
        exit;
    }
    $stmt->bind_param('s', $halaqoh);
    $stmt->execute();
    $result = $stmt->get_result();
    $pesertaList = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode($pesertaList);
    exit;
}

// CSRF Token Management
if (empty($_SESSION['tadzkir_csrf_token'])) {
    $_SESSION['tadzkir_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['tadzkir_csrf_token'];

// 3. Handle Form Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_tadzkir'])) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        $_SESSION['tadzkir_flash'] = [
            'type' => 'error',
            'message' => 'Sesi keamanan formulir kadaluarsa. Silakan muat ulang halaman.'
        ];
        header('Location: tadzkir.php');
        exit;
    }

    $halaqoh = trim((string)($_POST['halaqoh'] ?? ''));
    $pesanPengajar = trim((string)($_POST['pesan_pengajar'] ?? ''));
    $selectedPesertaJson = (string)($_POST['selected_peserta_json'] ?? '');
    $selectedPeserta = json_decode($selectedPesertaJson, true);

    if ($halaqoh === '' || !is_array($selectedPeserta) || count($selectedPeserta) === 0) {
        $_SESSION['tadzkir_flash'] = [
            'type' => 'error',
            'message' => 'Gagal! Pilih kelompok halaqoh dan minimal satu peserta terlebih dahulu.'
        ];
        header('Location: tadzkir.php');
        exit;
    }

    $successCount = 0;
    $errorCount = 0;
    $sentNames = [];

    $stmtCheck = $conn->prepare("SELECT id, nama_lengkap, nowa FROM peserta WHERE id = ? AND halaqoh = ? LIMIT 1");
    $stmtInsert = $conn->prepare("
        INSERT INTO reminder_requests (halaqoh, peserta_id, peserta_nama, peserta_nowa, pesan_pengajar, status) 
        VALUES (?, ?, ?, ?, ?, 'menunggu')
    ");

    foreach ($selectedPeserta as $p) {
        $pId = (int)($p['id'] ?? 0);
        if ($pId <= 0) continue;

        if ($stmtCheck) {
            $stmtCheck->bind_param('is', $pId, $halaqoh);
            $stmtCheck->execute();
            $res = $stmtCheck->get_result();
            $valid = $res->fetch_assoc();

            if ($valid && $stmtInsert) {
                $pNama = (string)$valid['nama_lengkap'];
                $pNowa = (string)$valid['nowa'];
                $stmtInsert->bind_param('sisss', $halaqoh, $pId, $pNama, $pNowa, $pesanPengajar);
                if ($stmtInsert->execute()) {
                    $successCount++;
                    $sentNames[] = $pNama;
                } else {
                    $errorCount++;
                }
            } else {
                $errorCount++;
            }
        }
    }

    if ($stmtCheck) $stmtCheck->close();
    if ($stmtInsert) $stmtInsert->close();

    // Regenerate CSRF token after submission
    $_SESSION['tadzkir_csrf_token'] = bin2hex(random_bytes(32));

    if ($successCount > 0) {
        $msg = "Alhamdulillah! Sebanyak {$successCount} permintaan reminder berhasil diteruskan ke Admin CRM.";
        if ($errorCount > 0) {
            $msg .= " ({$errorCount} peserta gagal diproses).";
        }
        $_SESSION['tadzkir_flash'] = [
            'type' => 'success',
            'message' => $msg,
            'halaqoh' => $halaqoh,
            'names' => $sentNames,
            'note' => $pesanPengajar
        ];
    } else {
        $_SESSION['tadzkir_flash'] = [
            'type' => 'error',
            'message' => 'Gagal menyimpan permintaan. Mohon pastikan data peserta valid.'
        ];
    }

    header('Location: tadzkir.php?sent=1');
    exit;
}

// 4. Fetch Active Halaqoh List
$halaqohList = [];
$halaqohQuery = $conn->query("
    SELECT DISTINCT halaqoh 
    FROM peserta 
    WHERE halaqoh IS NOT NULL AND halaqoh != '' 
    ORDER BY halaqoh ASC
");
if ($halaqohQuery) {
    while ($row = $halaqohQuery->fetch_assoc()) {
        $halaqohList[] = (string)$row['halaqoh'];
    }
}

// Retrieve Flash Message
$flash = $_SESSION['tadzkir_flash'] ?? null;
unset($_SESSION['tadzkir_flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Form Tadzkir Pengajar - JWD</title>
    <link rel="icon" href="LOGOJWD.png" type="image/png">
    <meta name="theme-color" content="#168044">
    <meta name="description" content="Form permintaan reminder santri/peserta untuk pengajar halaqoh JWD.">
    
    <style>
        :root {
            --brand-primary: #168044;
            --brand-primary-hover: #126b39;
            --brand-primary-soft: #e8f7ed;
            --brand-accent: #25d366;
            --surface-bg: #f5f8f6;
            --surface-card: #ffffff;
            --surface-border: #e2e8e5;
            --surface-border-subtle: #edf2ef;
            --text-heading: #10231b;
            --text-body: #2d4138;
            --text-muted: #6e8278;
            --status-success-bg: #eafaf1;
            --status-success-border: #b7e8cb;
            --status-success-text: #146c3b;
            --status-error-bg: #fdf2f2;
            --status-error-border: #f8c8c8;
            --status-error-text: #b82b2b;
            --radius-lg: 20px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-card: 0 10px 30px rgba(16, 35, 27, 0.06), 0 2px 6px rgba(16, 35, 27, 0.04);
            --shadow-subtle: 0 2px 4px rgba(16, 35, 27, 0.03);
            --font-stack: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --surface-bg: #0d1612;
                --surface-card: #15221c;
                --surface-border: #22372e;
                --surface-border-subtle: #1c2e26;
                --text-heading: #eef5f1;
                --text-body: #cfded6;
                --text-muted: #8ca397;
                --brand-primary-soft: #193325;
                --status-success-bg: #122c1d;
                --status-success-border: #1e5235;
                --status-success-text: #73df9e;
                --status-error-bg: #2e1717;
                --status-error-border: #522525;
                --status-error-text: #f38b8b;
            }
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: var(--font-stack);
            background: var(--surface-bg);
            color: var(--text-body);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .container {
            width: 100%;
            max-width: 560px;
            margin: auto;
        }

        .card {
            background: var(--surface-card);
            border: 1px solid var(--surface-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            transition: border-color 0.2s ease;
        }

        .card-header {
            padding: 24px 24px 20px;
            border-bottom: 1px solid var(--surface-border-subtle);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-badge {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: var(--brand-primary-soft);
            border: 1px solid var(--surface-border);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }

        .brand-badge img {
            width: 34px;
            height: 34px;
            object-fit: contain;
        }

        .header-content h1 {
            font-size: 19px;
            font-weight: 700;
            color: var(--text-heading);
            letter-spacing: -0.02em;
            line-height: 1.25;
        }

        .header-content p {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 3px;
            line-height: 1.4;
        }

        .flash-alert {
            margin: 16px 20px 0;
            padding: 16px 18px;
            border-radius: var(--radius-md);
            font-size: 13px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: fadeIn 0.3s ease;
            position: relative;
        }

        .flash-alert.success {
            background: var(--status-success-bg);
            border: 1px solid var(--status-success-border);
            color: var(--status-success-text);
        }

        .flash-alert.error {
            background: var(--status-error-bg);
            border: 1px solid var(--status-error-border);
            color: var(--status-error-text);
        }

        .flash-icon {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            margin-top: 1px;
        }

        .flash-names-card {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(22, 128, 68, 0.18);
        }

        @media (prefers-color-scheme: dark) {
            .flash-names-card {
                background: rgba(0, 0, 0, 0.28);
                border-color: rgba(115, 223, 158, 0.2);
            }
        }

        .flash-names-title {
            font-size: 11px;
            font-weight: 750;
            margin-bottom: 8px;
            color: var(--status-success-text);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .flash-names-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 6px;
            max-height: 220px;
            overflow-y: auto;
        }

        .flash-name-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 10px;
            background: var(--surface-card);
            border: 1px solid var(--surface-border-subtle);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .flash-name-idx {
            color: var(--text-muted);
            font-size: 11px;
            min-width: 18px;
        }

        .flash-name-val {
            flex: 1;
            color: var(--text-heading);
            word-break: break-word;
        }

        .flash-name-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--brand-primary-soft);
            color: var(--brand-primary);
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .flash-names-note {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px dashed rgba(22, 128, 68, 0.22);
            font-size: 11px;
            color: var(--text-body);
            line-height: 1.45;
        }

        .flash-close-btn {
            background: none;
            border: none;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            color: inherit;
            opacity: 0.6;
            padding: 0 4px;
            border-radius: 4px;
            transition: opacity 0.15s;
        }

        .flash-close-btn:hover {
            opacity: 1;
        }

        .card-body {
            padding: 22px 24px 26px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-heading);
            letter-spacing: 0.02em;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-label .optional {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-muted);
        }

        .form-select, .form-input, .form-textarea {
            width: 100%;
            padding: 12px 14px;
            font-size: 14px;
            font-family: inherit;
            color: var(--text-heading);
            background: var(--surface-card);
            border: 1px solid var(--surface-border);
            border-radius: var(--radius-md);
            outline: none;
            transition: all 0.2s ease;
        }

        .form-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236e8278' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 14px;
            padding-right: 36px;
        }

        .form-select:focus, .form-input:focus, .form-textarea:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 3px var(--brand-primary-soft);
        }

        .search-container {
            position: relative;
        }

        .search-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-icon {
            position: absolute;
            left: 14px;
            width: 16px;
            height: 16px;
            color: var(--text-muted);
            pointer-events: none;
        }

        .search-input {
            padding-left: 38px;
            padding-right: 38px;
        }

        .search-clear-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            padding: 4px;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .search-clear-btn:hover {
            color: var(--text-heading);
        }

        .suggestions-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: var(--surface-card);
            border: 1px solid var(--surface-border);
            border-radius: var(--radius-md);
            box-shadow: 0 12px 28px rgba(16, 35, 27, 0.12);
            z-index: 50;
            max-height: 240px;
            overflow-y: auto;
            display: none;
        }

        .suggestion-item {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px solid var(--surface-border-subtle);
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-item:hover, .suggestion-item.active {
            background: var(--brand-primary-soft);
        }

        .suggestion-name {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-heading);
            flex: 1;
            word-break: break-word;
        }

        .suggestion-btn {
            background: var(--brand-primary);
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            flex-shrink: 0;
        }

        .suggestion-empty {
            padding: 16px;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
        }

        .quick-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-top: 4px;
        }

        .btn-link {
            background: none;
            border: none;
            color: var(--brand-primary);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 0;
        }

        .btn-link:hover {
            color: var(--brand-primary-hover);
            text-decoration: underline;
        }

        .selected-box {
            border: 1px solid var(--surface-border);
            border-radius: var(--radius-md);
            padding: 12px;
            background: var(--brand-primary-soft);
            min-height: 70px;
            max-height: 190px;
            overflow-y: auto;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-content: flex-start;
        }

        .selected-empty-text {
            width: 100%;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
            padding: 16px 0;
            font-style: italic;
        }

        .participant-chip {
            background: var(--surface-card);
            border: 1px solid var(--surface-border);
            border-radius: 999px;
            padding: 5px 10px 5px 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-heading);
            box-shadow: var(--shadow-subtle);
            animation: chipFadeIn 0.2s ease;
        }

        .chip-remove {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            transition: all 0.15s ease;
        }

        .chip-remove:hover {
            background: rgba(184, 43, 43, 0.1);
            color: var(--status-error-text);
        }

        .badge-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--brand-primary);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            min-width: 20px;
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
            font-size: 13px;
        }

        .submit-btn {
            background: var(--brand-primary);
            color: #ffffff;
            border: none;
            border-radius: var(--radius-md);
            padding: 14px 20px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            box-shadow: 0 4px 14px rgba(22, 128, 68, 0.25);
            transition: all 0.2s ease;
        }

        .submit-btn:hover:not(:disabled) {
            background: var(--brand-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(22, 128, 68, 0.32);
        }

        .submit-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .footer-note {
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 14px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes chipFadeIn {
            from { opacity: 0; transform: scale(0.92); }
            to { opacity: 1; transform: scale(1); }
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <!-- Card Header -->
        <div class="card-header">
            <div class="brand-badge">
                <img src="LOGOJWD.png" alt="Logo JWD" onerror="this.style.display='none'">
            </div>
            <div class="header-content">
                <h1>Form Tadzkir Pengajar</h1>
            </div>
        </div>

        <!-- Flash Notification -->
        <?php if (!empty($flash)): ?>
        <div class="flash-alert <?= htmlspecialchars($flash['type']) ?>" id="flashNotification">
            <svg class="flash-icon" viewBox="0 0 20 20" fill="currentColor">
                <?php if ($flash['type'] === 'success'): ?>
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                <?php else: ?>
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                <?php endif; ?>
            </svg>
            <div class="flash-content" style="flex: 1; min-width: 0;">
                <div style="font-weight: 750; font-size: 13.5px; margin-bottom: 3px;">
                    <?= $flash['type'] === 'success' ? 'Permintaan Berhasil Terkirim ke Admin CRM' : 'Pemberitahuan' ?>
                </div>
                <div style="font-size: 12.5px; line-height: 1.45; opacity: 0.95;">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
                <?php if (!empty($flash['names']) && is_array($flash['names'])): ?>
                    <div class="flash-names-card">
                        <div class="flash-names-title">
                            Daftar santri yang terkirim (<?= count($flash['names']) ?> santri):
                        </div>
                        <ul class="flash-names-list">
                            <?php foreach ($flash['names'] as $idx => $sName): ?>
                                <li class="flash-name-item">
                                    <span class="flash-name-idx"><?= $idx + 1 ?>.</span>
                                    <span class="flash-name-val"><?= htmlspecialchars($sName) ?></span>
                                    <span class="flash-name-badge">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="20 6 9 17 4 12"></polyline>
                                        </svg>
                                        Terkirim
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (!empty($flash['note'])): ?>
                            <div class="flash-names-note">
                                <strong>Catatan Pengajar:</strong> &ldquo;<?= htmlspecialchars($flash['note']) ?>&rdquo;
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" class="flash-close-btn" onclick="document.getElementById('flashNotification').remove()" aria-label="Tutup notifikasi">
                &times;
            </button>
        </div>
        <?php endif; ?>

        <!-- Form Body -->
        <form method="POST" action="tadzkir.php" class="card-body" id="tadzkirForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="selected_peserta_json" id="selectedPesertaJson" value="[]">

            <!-- Step 1: Select Halaqoh -->
            <div class="form-group">
                <label for="halaqoh" class="form-label">
                    <span>1. Kelompok Halaqoh</span>
                    <span class="optional">Wajib dipilih</span>
                </label>
                <select name="halaqoh" id="halaqoh" class="form-select" required>
                    <option value="" disabled selected>-- Pilih Halaqoh Anda --</option>
                    <?php foreach ($halaqohList as $h): ?>
                        <option value="<?= htmlspecialchars($h) ?>"><?= htmlspecialchars($h) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Step 2: Search & Select Peserta (Hidden until halaqoh is chosen) -->
            <div class="form-group" id="pesertaSection" style="display: none;">
                <label for="searchPeserta" class="form-label">
                    <span>2. Cari & Tambah Santri</span>
                    <span class="badge-count" id="countBadge">0 dipilih</span>
                </label>

                <div class="search-container">
                    <div class="search-input-wrapper">
                        <svg class="search-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                        </svg>
                        <input type="text" id="searchPeserta" class="form-input search-input" placeholder="Ketik nama santri..." autocomplete="off">
                        <button type="button" class="search-clear-btn" id="searchClearBtn" aria-label="Hapus pencarian">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>

                    <!-- Suggestions Dropdown -->
                    <div class="suggestions-dropdown" id="suggestionsDropdown"></div>
                </div>

                <div class="quick-actions">
                    <button type="button" class="btn-link" id="btnSelectAllHalaqoh">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Pilih Semua dari Halaqoh Ini
                    </button>
                    <button type="button" class="btn-link" id="btnClearAll" style="color: var(--text-muted); display: none;">
                        Hapus Semua
                    </button>
                </div>

                <!-- Selected Peserta Chips Box -->
                <div class="selected-box" id="selectedBox">
                    <div class="selected-empty-text" id="emptyNotice">Belum ada peserta yang dipilih. Ketik nama atau pilih semua di atas.</div>
                </div>
            </div>

            <!-- Step 3: Optional Teacher Note -->
            <div class="form-group" id="noteSection" style="display: none;">
                <label for="pesanPengajar" class="form-label">
                    <span>3. Catatan Pesan Pengajar</span>
                    <span class="optional">(Opsional untuk tim admin)</span>
                </label>
                <textarea name="pesan_pengajar" id="pesanPengajar" class="form-textarea" placeholder="Contoh: Mohon diingatkan untuk setor hafalan pekan ini atau konfirmasi kehadiran..."></textarea>
            </div>

            <!-- Step 4: Submit Button -->
            <div id="submitSection" style="display: none; margin-top: 4px;">
                <button type="submit" name="submit_tadzkir" id="submitBtn" class="submit-btn" disabled>
                    <span class="spinner" id="btnSpinner"></span>
                    <svg id="btnIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                    <span id="btnText">Kirim Permintaan Reminder</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const halaqohSelect = document.getElementById('halaqoh');
    const pesertaSection = document.getElementById('pesertaSection');
    const noteSection = document.getElementById('noteSection');
    const submitSection = document.getElementById('submitSection');
    const searchInput = document.getElementById('searchPeserta');
    const searchClearBtn = document.getElementById('searchClearBtn');
    const suggestionsDropdown = document.getElementById('suggestionsDropdown');
    const selectedBox = document.getElementById('selectedBox');
    const emptyNotice = document.getElementById('emptyNotice');
    const countBadge = document.getElementById('countBadge');
    const btnSelectAllHalaqoh = document.getElementById('btnSelectAllHalaqoh');
    const btnClearAll = document.getElementById('btnClearAll');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const btnIcon = document.getElementById('btnIcon');
    const btnSpinner = document.getElementById('btnSpinner');
    const selectedJsonInput = document.getElementById('selectedPesertaJson');
    const form = document.getElementById('tadzkirForm');

    let selectedPeserta = [];
    let searchTimeout = null;

    // Halaqoh change listener
    halaqohSelect.addEventListener('change', () => {
        const hVal = halaqohSelect.value.trim();
        if (hVal) {
            pesertaSection.style.display = 'flex';
            noteSection.style.display = 'flex';
            submitSection.style.display = 'block';
            selectedPeserta = [];
            renderSelectedChips();
            searchInput.value = '';
            closeDropdown();
            searchInput.focus();
        } else {
            pesertaSection.style.display = 'none';
            noteSection.style.display = 'none';
            submitSection.style.display = 'none';
        }
    });

    // Debounced Search Input
    searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim();
        searchClearBtn.style.display = query ? 'flex' : 'none';
        clearTimeout(searchTimeout);

        const currentHalaqoh = halaqohSelect.value.trim();
        if (query.length < 2 || !currentHalaqoh) {
            closeDropdown();
            return;
        }

        searchTimeout = setTimeout(() => {
            fetchSearch(query, currentHalaqoh);
        }, 220);
    });

    searchClearBtn.addEventListener('click', () => {
        searchInput.value = '';
        searchClearBtn.style.display = 'none';
        closeDropdown();
        searchInput.focus();
    });

    // Close suggestions on outside click
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !suggestionsDropdown.contains(e.target)) {
            closeDropdown();
        }
    });

    function closeDropdown() {
        suggestionsDropdown.style.display = 'none';
        suggestionsDropdown.innerHTML = '';
    }

    function fetchSearch(term, halaqoh) {
        suggestionsDropdown.style.display = 'block';
        suggestionsDropdown.innerHTML = '<div class="suggestion-empty">Mencari santri...</div>';

        fetch(`tadzkir.php?action=search&term=${encodeURIComponent(term)}&halaqoh=${encodeURIComponent(halaqoh)}`)
            .then(res => res.json())
            .then(data => {
                if (!Array.isArray(data) || data.length === 0) {
                    suggestionsDropdown.innerHTML = '<div class="suggestion-empty">Tidak ada santri yang cocok.</div>';
                    return;
                }

                // Filter out already selected
                const unselected = data.filter(item => !selectedPeserta.some(s => s.id === item.id));

                if (unselected.length === 0) {
                    suggestionsDropdown.innerHTML = '<div class="suggestion-empty">Semua santri yang ditemukan sudah dipilih.</div>';
                    return;
                }

                suggestionsDropdown.innerHTML = '';
                unselected.forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'suggestion-item';
                    row.innerHTML = `
                        <div class="suggestion-name">${escapeHtml(item.nama_lengkap)}</div>
                        <button type="button" class="suggestion-btn">+ Pilih</button>
                    `;
                    row.addEventListener('click', () => {
                        addPeserta(item);
                        searchInput.value = '';
                        searchClearBtn.style.display = 'none';
                        closeDropdown();
                        searchInput.focus();
                    });
                    suggestionsDropdown.appendChild(row);
                });
            })
            .catch(() => {
                suggestionsDropdown.innerHTML = '<div class="suggestion-empty" style="color: var(--status-error-text);">Gagal memuat data pencarian.</div>';
            });
    }

    // Select All in Halaqoh
    btnSelectAllHalaqoh.addEventListener('click', () => {
        const curHalaqoh = halaqohSelect.value.trim();
        if (!curHalaqoh) return;

        btnSelectAllHalaqoh.innerHTML = 'Memuat semua santri...';

        fetch(`tadzkir.php?action=list_all&halaqoh=${encodeURIComponent(curHalaqoh)}`)
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(item => {
                        if (!selectedPeserta.some(s => s.id === item.id)) {
                            selectedPeserta.push(item);
                        }
                    });
                    renderSelectedChips();
                }
            })
            .finally(() => {
                btnSelectAllHalaqoh.innerHTML = `
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Pilih Semua dari Halaqoh Ini
                `;
            });
    });

    // Clear all
    btnClearAll.addEventListener('click', () => {
        selectedPeserta = [];
        renderSelectedChips();
    });

    function addPeserta(item) {
        if (!selectedPeserta.some(s => s.id === item.id)) {
            selectedPeserta.push(item);
            renderSelectedChips();
        }
    }

    function removePeserta(id) {
        selectedPeserta = selectedPeserta.filter(s => s.id !== id);
        renderSelectedChips();
    }

    function renderSelectedChips() {
        selectedBox.innerHTML = '';
        const count = selectedPeserta.length;
        countBadge.textContent = `${count} dipilih`;

        if (count === 0) {
            selectedBox.appendChild(emptyNotice);
            emptyNotice.style.display = 'block';
            btnClearAll.style.display = 'none';
            submitBtn.disabled = true;
            btnText.textContent = 'Pilih Santri Terlebih Dahulu';
        } else {
            emptyNotice.style.display = 'none';
            btnClearAll.style.display = 'inline-flex';
            submitBtn.disabled = false;
            btnText.textContent = `Kirim ${count} Permintaan Reminder`;

            selectedPeserta.forEach(p => {
                const chip = document.createElement('div');
                chip.className = 'participant-chip';
                chip.innerHTML = `
                    <span>${escapeHtml(p.nama_lengkap)}</span>
                    <button type="button" class="chip-remove" aria-label="Hapus ${escapeHtml(p.nama_lengkap)}">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                `;
                chip.querySelector('.chip-remove').addEventListener('click', (e) => {
                    e.stopPropagation();
                    removePeserta(p.id);
                });
                selectedBox.appendChild(chip);
            });
        }

        selectedJsonInput.value = JSON.stringify(selectedPeserta);
    }

    // Submit protection
    form.addEventListener('submit', (e) => {
        if (selectedPeserta.length === 0) {
            e.preventDefault();
            alert('Silakan pilih minimal satu santri/peserta.');
            return;
        }

        submitBtn.disabled = true;
        btnText.textContent = 'Mengirim Permintaan...';
        btnIcon.style.display = 'none';
        btnSpinner.style.display = 'inline-block';
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
</script>

</body>
</html>
