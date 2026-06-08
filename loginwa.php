<?php
session_start();

// Debug mode untuk development (nonaktifkan di production)
error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';

$error = '';
$success_message = '';

// Cek jika sudah login
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: hanwa.php");
    exit();
}

// Cek pesan logout
if (isset($_SESSION['logout_message'])) {
    $success_message = $_SESSION['logout_message'];
    unset($_SESSION['logout_message']);
}

// Proses login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember = isset($_POST['remember']) ? true : false;
    
    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $conn->prepare("SELECT id, username, password FROM login_event WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['role'] = 'user';
                    
                    // Jika "Ingat Saya" dicentang, simpan username di cookie
                    if ($remember) {
                        setcookie('remembered_username', $username, time() + (86400 * 30), "/");
                    } else {
                        setcookie('remembered_username', '', time() - 3600, "/");
                    }
                    
                    header("Location: hanwa.php");
                    exit();
                } else {
                    $error = "Password salah!";
                }
            } else {
                $error = "Username tidak ditemukan!";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Terjadi kesalahan sistem";
            error_log("Login error: " . $e->getMessage());
        }
    } else {
        $error = "Username dan password harus diisi!";
    }
}

// Ambil username yang tersimpan di cookie (jika ada)
$savedUsername = isset($_COOKIE['remembered_username']) ? $_COOKIE['remembered_username'] : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#166534">
    <title>Login - Reqra WhatsApp</title>
    <link rel="icon" type="image/png" href="LOGOJWD.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --primary: #166534;
            --primary-light: #15803d;
            --primary-dark: #14532d;
            --primary-soft: #dcfce7;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            min-height: 100dvh;
            background: #ffffff;
            overflow-x: hidden;
        }
        
        /* ========== DESKTOP: Layout 2 kolom ========== */
        @media (min-width: 769px) {
            body {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .login-container {
                display: flex;
                width: 100%;
                max-width: 1280px;
                margin: 0 auto;
                padding: 2rem;
                gap: 3rem;
                align-items: center;
                justify-content: space-between;
            }
            
            .login-box {
                flex: 1;
                max-width: 460px;
                margin: 0 auto;
            }
            
            .hero-animation {
                flex: 1;
                display: flex;
                justify-content: center;
                align-items: center;
                position: relative;
            }
            
            .bubble-container {
                position: relative;
                width: 100%;
                max-width: 480px;
                min-height: 480px;
            }
            
            /* Floating Icon dengan warna #166534 */
            .whatsapp-icon {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 120px;
                height: 120px;
                background: linear-gradient(145deg, var(--primary), var(--primary-light));
                border-radius: 2rem;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 20px 40px -12px rgba(22, 101, 52, 0.3);
                z-index: 10;
                animation: pulseIcon 3s infinite ease-in-out;
            }
            
            .whatsapp-icon i {
                font-size: 4rem;
                color: white;
            }
            
            /* Bubbles dengan warna #166534 */
            .bubble {
                position: absolute;
                background: rgba(22, 101, 52, 0.06);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(2px);
                border: 1px solid rgba(22, 101, 52, 0.1);
            }
            
            .bubble i {
                font-size: 1.5rem;
                color: var(--primary);
                opacity: 0.7;
            }
            
            .bubble-1 { top: 0; left: 10%; width: 80px; height: 80px; animation: float1 8s infinite ease-in-out; }
            .bubble-2 { bottom: 5%; right: 0; width: 100px; height: 100px; animation: float2 10s infinite ease-in-out; }
            .bubble-3 { top: 20%; right: 5%; width: 60px; height: 60px; animation: float3 7s infinite ease-in-out; }
            .bubble-4 { bottom: 25%; left: 0; width: 70px; height: 70px; animation: float4 9s infinite ease-in-out; }
            .bubble-5 { top: 60%; left: 20%; width: 45px; height: 45px; animation: float5 6s infinite ease-in-out; }
            .bubble-6 { top: 10%; right: 25%; width: 50px; height: 50px; animation: float6 11s infinite ease-in-out; }
            
            @keyframes float1 { 0%,100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-20px) rotate(5deg); } }
            @keyframes float2 { 0%,100% { transform: translateX(0) rotate(0deg); } 50% { transform: translateX(-20px) rotate(-5deg); } }
            @keyframes float3 { 0%,100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-15px) translateX(10px); } }
            @keyframes float4 { 0%,100% { transform: translateX(0); } 50% { transform: translateX(15px); } }
            @keyframes float5 { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
            @keyframes float6 { 0%,100% { transform: translateX(0) rotate(0deg); } 50% { transform: translateX(-12px) rotate(8deg); } }
            
            @keyframes pulseIcon { 
                0%,100% { transform: translate(-50%, -50%) scale(1); box-shadow: 0 20px 40px -12px rgba(22,101,52,0.3); } 
                50% { transform: translate(-50%, -50%) scale(1.05); box-shadow: 0 30px 60px -12px rgba(22,101,52,0.4); } 
            }
            
            .text-bubble {
                position: absolute;
                bottom: 15%;
                right: 10%;
                background: white;
                border-radius: 1.5rem;
                padding: 0.75rem 1.25rem;
                box-shadow: 0 8px 20px rgba(0,0,0,0.08);
                border: 1px solid #eef2f6;
                animation: fadeInUp 1s ease-out;
            }
            
            .text-bubble p {
                font-size: 0.875rem;
                color: #1e293b;
                font-weight: 500;
                margin: 0;
            }
            
            .text-bubble i {
                color: var(--primary);
                margin-right: 0.5rem;
            }
            
            @keyframes fadeInUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
        }
        
        /* ========== MOBILE: Hanya box login compact ========== */
        @media (max-width: 768px) {
            body {
                display: flex;
                align-items: flex-start;
                justify-content: center;
                padding: 1rem;
                padding-top: 2rem;
            }
            
            .login-container {
                width: 100%;
            }
            
            .hero-animation {
                display: none;
            }
            
            .login-box {
                width: 100%;
            }
        }
        
        /* ========== LOGIN CARD STYLE ========== */
        .login-card {
            background: white;
            border-radius: 2rem;
            width: 100%;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.08);
            border: 1px solid #eef2f6;
            transition: all 0.3s ease;
        }
        
        @media (min-width: 769px) {
            .login-card {
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
            }
            .login-card:hover {
                transform: translateY(-4px);
            }
        }
        
        @media (max-width: 768px) {
            .login-card {
                border-radius: 1.5rem;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            }
        }
        
        /* Card Header - menggunakan warna #166534 */
        .card-header {
            text-align: center;
            padding: 2rem 1.5rem 1.5rem;
        }
        
        @media (max-width: 768px) {
            .card-header {
                padding: 1.25rem 1rem 1rem;
            }
        }
        
        .logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(145deg, var(--primary), var(--primary-light));
            border-radius: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 10px 20px -5px rgba(22, 101, 52, 0.3);
        }
        
        @media (max-width: 768px) {
            .logo-icon {
                width: 52px;
                height: 52px;
                border-radius: 1rem;
                margin-bottom: 0.75rem;
            }
            .logo-icon i {
                font-size: 1.5rem !important;
            }
        }
        
        .logo-icon i {
            font-size: 2rem;
            color: white;
        }
        
        .card-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.02em;
        }
        
        @media (max-width: 768px) {
            .card-header h1 {
                font-size: 1.35rem;
            }
        }
        
        .card-header p {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        @media (max-width: 768px) {
            .card-header p {
                font-size: 0.7rem;
            }
        }
        
        /* Card Body */
        .card-body {
            padding: 0 1.75rem 1.75rem;
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 0 1rem 1.25rem;
            }
        }
        
        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-size: 0.8125rem;
            font-weight: 500;
            animation: slideIn 0.25s ease-out;
        }
        
        @media (max-width: 768px) {
            .alert {
                padding: 0.6rem 0.875rem;
                font-size: 0.75rem;
                margin-bottom: 1rem;
            }
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-error {
            background: #fef2f2;
            border-left: 3px solid #ef4444;
            color: #b91c1c;
        }
        
        .alert-success {
            background: #f0fdf4;
            border-left: 3px solid #22c55e;
            color: #166534;
        }
        
        /* Form - focus border menggunakan #166534 */
        .form-group {
            margin-bottom: 1.125rem;
        }
        
        @media (max-width: 768px) {
            .form-group {
                margin-bottom: 0.875rem;
            }
        }
        
        .form-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .form-label {
                font-size: 0.7rem;
                margin-bottom: 0.375rem;
            }
            .form-label i {
                font-size: 0.7rem;
            }
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .form-input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.5rem;
            border-radius: 1rem;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.9375rem;
            font-weight: 500;
            color: #0f172a;
            transition: all 0.2s ease;
        }
        
        @media (max-width: 768px) {
            .form-input {
                padding: 0.7rem 0.875rem 0.7rem 2rem;
                border-radius: 0.875rem;
                font-size: 0.875rem;
            }
        }
        
        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22, 101, 52, 0.15);
            outline: none;
            background: white;
        }
        
        .input-icon {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.875rem;
        }
        
        @media (max-width: 768px) {
            .input-icon {
                left: 0.7rem;
                font-size: 0.75rem;
            }
        }
        
        .password-toggle {
            position: absolute;
            right: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.25rem;
        }
        
        .password-toggle:hover {
            color: var(--primary);
        }
        
        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0.5rem 0 1.25rem;
        }
        
        @media (max-width: 768px) {
            .form-actions {
                margin: 0.25rem 0 1rem;
            }
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            color: #475569;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .remember-me {
                font-size: 0.7rem;
            }
            .remember-me input {
                width: 0.875rem;
                height: 0.875rem;
                accent-color: var(--primary);
            }
        }
        
        .remember-me input {
            accent-color: var(--primary);
        }
        
        .forgot-link {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }
        
        @media (max-width: 768px) {
            .forgot-link {
                font-size: 0.7rem;
            }
        }
        
        .forgot-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        /* Submit Button - menggunakan #166534 */
        .btn-submit {
            width: 100%;
            background: linear-gradient(145deg, var(--primary), var(--primary-light));
            border: none;
            padding: 0.875rem 1rem;
            border-radius: 1rem;
            font-weight: 600;
            font-size: 0.9375rem;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(22, 101, 52, 0.25);
        }
        
        @media (max-width: 768px) {
            .btn-submit {
                padding: 0.7rem 1rem;
                border-radius: 0.875rem;
                font-size: 0.875rem;
            }
        }
        
        .btn-submit:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(22, 101, 52, 0.35);
        }
        
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        /* Security Badge */
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: #f8fafc;
            border-radius: 0.875rem;
            margin-top: 1.25rem;
            font-size: 0.75rem;
            color: #475569;
        }
        
        @media (max-width: 768px) {
            .security-badge {
                padding: 0.5rem 0.75rem;
                margin-top: 1rem;
                font-size: 0.65rem;
            }
        }
        
        .security-badge i {
            color: var(--primary);
        }
        
        /* Footer */
        .card-footer {
            border-top: 1px solid #eef2f6;
            padding-top: 1.125rem;
            margin-top: 1.125rem;
            text-align: center;
            font-size: 0.6875rem;
            color: #94a3b8;
        }
        
        @media (max-width: 768px) {
            .card-footer {
                padding-top: 0.875rem;
                margin-top: 0.875rem;
                font-size: 0.6rem;
            }
        }
        
        /* Loading spinner */
        .spin {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .fade-out {
            animation: fadeOut 0.3s ease-out forwards;
        }
        
        @keyframes fadeOut {
            to { opacity: 0; transform: translateY(-5px); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Kiri: Login Box -->
        <div class="login-box">
            <div class="login-card">
                <div class="card-header">
                    <div class="logo-icon">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <h1>Reqra<span style="color:var(--primary);">WA</span></h1>
                    <p>Sistem Pengiriman Pesan Otomatis</p>
                </div>

                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class="fa-solid fa-circle-check"></i>
                            <span><?= htmlspecialchars($success_message) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" novalidate id="loginForm">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fa-solid fa-user"></i> Username
                            </label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-at input-icon"></i>
                                <input type="text" name="username" id="username" class="form-input" 
                                       placeholder="Masukkan username" value="<?= htmlspecialchars($savedUsername) ?>"
                                       autocomplete="username" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fa-solid fa-lock"></i> Password
                            </label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-key input-icon"></i>
                                <input type="password" name="password" id="password" class="form-input" 
                                       placeholder="Masukkan password" autocomplete="current-password" required>
                                <button type="button" class="password-toggle" id="togglePwd">
                                    <i class="fa-solid fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-actions">
                            <label class="remember-me">
                                <input type="checkbox" name="remember" id="remember" <?= $savedUsername ? 'checked' : '' ?>>
                                <span>Ingat saya</span>
                            </label>
                            <a href="#" class="forgot-link" id="forgotLink">Lupa password?</a>
                        </div>

                        <button type="submit" class="btn-submit" id="loginBtn">
                            <i class="fa-solid fa-right-to-bracket" id="btnIcon"></i>
                            <span id="btnText">Masuk</span>
                        </button>
                    </form>

                    <div class="security-badge">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>End-to-End Encryption · Aman</span>
                    </div>

                    <div class="card-footer">
                        Reqra WhatsApp &copy; <?= date('Y') ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kanan: Animasi Icon & Bubble (Desktop Only) -->
        <div class="hero-animation">
            <div class="bubble-container">
                <div class="whatsapp-icon">
                    <i class="fab fa-whatsapp"></i>
                </div>
                <div class="bubble bubble-1">
                    <i class="fas fa-comment-dots"></i>
                </div>
                <div class="bubble bubble-2">
                    <i class="fas fa-users"></i>
                </div>
                <div class="bubble bubble-3">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div class="bubble bubble-4">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="bubble bubble-5">
                    <i class="fas fa-reply-all"></i>
                </div>
                <div class="bubble bubble-6">
                    <i class="fas fa-file-alt"></i>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        // Toggle password visibility
        const toggleBtn = document.getElementById('togglePwd');
        const pwdInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        
        if (toggleBtn && pwdInput) {
            toggleBtn.addEventListener('click', function() {
                const isHidden = pwdInput.type === 'password';
                pwdInput.type = isHidden ? 'text' : 'password';
                eyeIcon.className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
                pwdInput.focus();
            });
        }

        const form = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const btnIcon = document.getElementById('btnIcon');
        const btnText = document.getElementById('btnText');

        function showAlert(message, type) {
            const oldAlert = document.querySelector('.alert');
            if (oldAlert) oldAlert.remove();

            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            const icon = type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
            alertDiv.innerHTML = `<i class="fa-solid ${icon}"></i><span>${escapeHtml(message)}</span>`;
            
            const formGroup = document.querySelector('.form-group');
            formGroup?.parentNode?.insertBefore(alertDiv, formGroup);
            
            setTimeout(() => {
                alertDiv.classList.add('fade-out');
                setTimeout(() => alertDiv.remove(), 300);
            }, 4500);
        }

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                const username = document.getElementById('username')?.value.trim();
                const password = document.getElementById('password')?.value.trim();
                
                if (!username || !password) {
                    e.preventDefault();
                    showAlert('Username dan password wajib diisi!', 'error');
                    return false;
                }
                if (username.length < 3) {
                    e.preventDefault();
                    showAlert('Username minimal 3 karakter!', 'error');
                    return false;
                }
                if (password.length < 6) {
                    e.preventDefault();
                    showAlert('Password minimal 6 karakter!', 'error');
                    return false;
                }

                if (loginBtn && !loginBtn.disabled) {
                    loginBtn.disabled = true;
                    btnIcon.className = 'fa-solid fa-circle-notch spin';
                    btnText.textContent = 'Memproses...';
                }
                return true;
            });
        }

        const forgotLink = document.getElementById('forgotLink');
        if (forgotLink) {
            forgotLink.addEventListener('click', function(e) {
                e.preventDefault();
                showAlert('Hubungi administrator untuk reset password.', 'success');
            });
        }

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Desktop: auto focus username
        if (window.matchMedia('(min-width: 769px)').matches) {
            setTimeout(() => {
                const usernameInput = document.getElementById('username');
                if (usernameInput && !usernameInput.value) usernameInput.focus();
            }, 300);
        }
    })();
    </script>
</body>
</html>