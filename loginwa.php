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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#075E54">
    <title>Login - Reqra WhatsApp</title>
    <link rel="icon" type="image/png" href="LOGOJWD.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
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
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }
        
        /* ========== DESKTOP: Background dengan animasi & gambar menarik ========== */
        @media (min-width: 769px) {
            body {
                background: linear-gradient(145deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            }
            
            /* Grid pattern background */
            body::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-image: 
                    linear-gradient(rgba(59, 130, 246, 0.05) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(59, 130, 246, 0.05) 1px, transparent 1px);
                background-size: 50px 50px;
                pointer-events: none;
            }
            
            /* Animated gradient blob 1 */
            body::after {
                content: '';
                position: absolute;
                width: 500px;
                height: 500px;
                background: radial-gradient(circle, rgba(37, 99, 235, 0.3) 0%, rgba(139, 92, 246, 0.15) 50%, transparent 70%);
                border-radius: 50%;
                top: -200px;
                right: -150px;
                animation: floatBlob 25s infinite ease-in-out;
                pointer-events: none;
            }
            
            /* Blob 2 */
            .bg-blob-2 {
                position: absolute;
                width: 450px;
                height: 450px;
                background: radial-gradient(circle, rgba(34, 197, 94, 0.2) 0%, rgba(59, 130, 246, 0.1) 60%, transparent 80%);
                border-radius: 50%;
                bottom: -180px;
                left: -150px;
                animation: floatBlob2 30s infinite ease-in-out;
                pointer-events: none;
                z-index: 0;
            }
            
            /* Blob 3 */
            .bg-blob-3 {
                position: absolute;
                width: 300px;
                height: 300px;
                background: radial-gradient(circle, rgba(168, 85, 247, 0.2) 0%, rgba(59, 130, 246, 0.05) 70%);
                border-radius: 50%;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                animation: pulseBlob 15s infinite ease-in-out;
                pointer-events: none;
            }
            
            @keyframes floatBlob {
                0%, 100% { transform: translate(0, 0) scale(1); }
                50% { transform: translate(30px, -30px) scale(1.05); }
            }
            
            @keyframes floatBlob2 {
                0%, 100% { transform: translate(0, 0) scale(1); }
                50% { transform: translate(-25px, 25px) scale(1.08); }
            }
            
            @keyframes pulseBlob {
                0%, 100% { opacity: 0.3; transform: translate(-50%, -50%) scale(1); }
                50% { opacity: 0.6; transform: translate(-50%, -50%) scale(1.1); }
            }
            
            /* Hero illustration area */
            .hero-illustration {
                position: fixed;
                right: 5%;
                top: 50%;
                transform: translateY(-50%);
                width: 35%;
                max-width: 400px;
                opacity: 0.7;
                pointer-events: none;
                z-index: 1;
            }
            
            .hero-illustration svg {
                width: 100%;
                height: auto;
                filter: drop-shadow(0 10px 20px rgba(0,0,0,0.2));
            }
        }
        
        /* ========== MOBILE: Background bersih ========== */
        @media (max-width: 768px) {
            body {
                background: #ffffff;
                padding: 1rem;
                align-items: flex-start;
                padding-top: 2rem;
            }
            
            body::before,
            body::after,
            .bg-blob-2,
            .bg-blob-3,
            .hero-illustration {
                display: none;
            }
        }
        
        /* ========== LOGIN CARD ========== */
        .login-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 2rem;
            width: 100%;
            z-index: 10;
            transition: all 0.3s ease;
            position: relative;
        }
        
        /* Desktop: ukuran normal dengan efek glassmorphism */
        @media (min-width: 769px) {
            .login-card {
                max-width: 440px;
                backdrop-filter: blur(10px);
                background: rgba(255, 255, 255, 0.96);
                border: 1px solid rgba(255, 255, 255, 0.3);
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                margin-left: 8%;
            }
            
            .login-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3);
            }
        }
        
        /* Mobile: compact, lebih kecil dan rapi */
        @media (max-width: 768px) {
            .login-card {
                max-width: 100%;
                border-radius: 1.5rem;
                background: white;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
                border: 1px solid #eef2f6;
            }
        }
        
        /* Card Header */
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
            background: linear-gradient(145deg, #075E54, #128C7E);
            border-radius: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 10px 20px -5px rgba(7, 94, 84, 0.3);
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
            background: linear-gradient(135deg, #075E54, #128C7E);
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
        
        /* Form */
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
            border-color: #128C7E;
            box-shadow: 0 0 0 3px rgba(18, 140, 126, 0.15);
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
            }
        }
        
        .forgot-link {
            font-size: 0.8125rem;
            font-weight: 500;
            color: #128C7E;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        @media (max-width: 768px) {
            .forgot-link {
                font-size: 0.7rem;
            }
        }
        
        .forgot-link:hover {
            color: #075E54;
            text-decoration: underline;
        }
        
        /* Submit Button */
        .btn-submit {
            width: 100%;
            background: linear-gradient(145deg, #075E54, #128C7E);
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
            box-shadow: 0 4px 12px rgba(7, 94, 84, 0.25);
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
            box-shadow: 0 6px 16px rgba(7, 94, 84, 0.35);
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
            color: #22c55e;
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
    <!-- Desktop only: animated blobs -->
    <div class="bg-blob-2"></div>
    <div class="bg-blob-3"></div>
    
    <!-- Desktop only: hero illustration (minimalist whatsapp style) -->
    <div class="hero-illustration">
        <svg viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M100 10C55 10 20 45 20 90C20 110 28 128 40 142L25 185L70 170C84 180 100 185 116 185C161 185 196 150 196 105C196 60 161 10 100 10Z" fill="url(#grad1)" fill-opacity="0.8"/>
            <path d="M100 30C66 30 40 56 40 90C40 106 47 120 58 130L48 162L81 152C91 158 103 162 115 162C149 162 175 136 175 102C175 68 149 30 100 30Z" fill="white" fill-opacity="0.9"/>
            <circle cx="75" cy="85" r="6" fill="#075E54"/>
            <circle cx="100" cy="85" r="6" fill="#075E54"/>
            <circle cx="125" cy="85" r="6" fill="#075E54"/>
            <path d="M75 110C75 110 88 125 100 125C112 125 125 110 125 110" stroke="#075E54" stroke-width="3" stroke-linecap="round" fill="none"/>
            <defs>
                <linearGradient id="grad1" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#075E54"/>
                    <stop offset="100%" stop-color="#128C7E"/>
                </linearGradient>
            </defs>
        </svg>
    </div>

    <div class="login-card">
        <div class="card-header">
            <div class="logo-icon">
                <i class="fab fa-whatsapp"></i>
            </div>
            <h1>Reqra<span style="color:#128C7E;">WA</span></h1>
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

            <form method="POST" action="" novalidate>
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa-solid fa-user"></i> Username
                    </label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-at input-icon"></i>
                        <input type="text" name="username" id="username" class="form-input" 
                               placeholder="Masukkan username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
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
                        <input type="checkbox" name="remember" id="remember">
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

    <script>
    (function() {
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

        const form = document.querySelector('form');
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