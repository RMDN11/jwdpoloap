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
    header("Location: reminder.php");
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
                    
                    header("Location: reminder.php");
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
    <meta name="theme-color" content="#ffffff">
    <title>Login - Reqra WhatsApp</title>
    <link rel="icon" type="image/png" href="LOGOJWD.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- ✅ FIX: Update Font Awesome ke versi stabil -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        :root {
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --accent-500: #8b5cf6;
            --success-500: #22c55e;
            --gray-50: #f9fafb;
            --gray-200: #e5e7eb;
            --gray-400: #9ca3af;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
        }
        
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        
        body { 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            background: linear-gradient(135deg, #f0f9ff 0%, #faf5ff 50%, #fef2f2 100%);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(0.75rem, 3vw, 1.5rem);
            margin: 0;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background */
        body::before, body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.4;
            z-index: 0;
            animation: float 20s infinite ease-in-out;
            pointer-events: none;
        }
        body::before {
            width: 360px; height: 360px;
            background: linear-gradient(135deg, #93c5fd, #a78bfa);
            top: -80px; right: -80px;
        }
        body::after {
            width: 280px; height: 280px;
            background: linear-gradient(135deg, #22c55e, #3b82f6);
            bottom: -60px; left: -60px;
            animation-delay: -10s;
        }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(15px, -15px) scale(1.03); }
        }

        /* Login Card */
        .login-card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 1.5rem;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(255,255,255,0.9);
            width: 100%;
            max-width: 440px;
            border: 1px solid rgba(255,255,255,0.95);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            z-index: 10;
        }
        .login-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(255,255,255,0.95);
        }

        /* Header */
        .card-header {
            background: linear-gradient(135deg, var(--primary-600), var(--accent-500));
            padding: 1.75rem 1.5rem;
            text-align: center;
            border-radius: 1.5rem 1.5rem 0 0;
        }
        .logo-icon {
            width: 64px; height: 64px;
            background: white;
            border-radius: 1rem;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 0.75rem;
            box-shadow: 0 8px 20px -8px rgba(0,0,0,0.2);
        }
        .logo-icon i {
            font-size: 1.75rem;
            background: linear-gradient(135deg, var(--primary-600), var(--accent-500));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .card-header h1 {
            color: white;
            font-size: 1.375rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .card-header p {
            color: rgba(255,255,255,0.9);
            font-size: 0.875rem;
            opacity: 0.95;
        }

        /* Form Body */
        .card-body { padding: 1.5rem; }

        /* Alert Messages */
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex; align-items: flex-start; gap: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            animation: slideIn 0.25s ease-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-error {
            background: #fef2f2; border-left: 4px solid #ef4444; color: #b91c1c;
        }
        .alert-success {
            background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534;
        }
        .alert i { margin-top: 0.125rem; flex-shrink: 0; }

        /* Form Elements */
        .form-group { margin-bottom: 1.25rem; }
        
        .form-label {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.875rem; font-weight: 600; color: var(--gray-700);
            margin-bottom: 0.5rem;
        }
        .form-label i { color: var(--primary-600); }

        .input-wrapper { position: relative; }

        .form-input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.5rem;
            border-radius: 0.875rem;
            border: 1.5px solid var(--gray-200);
            background: var(--gray-50);
            font-size: 1rem; /* ✅ FIX: Prevent iOS zoom */
            font-weight: 500;
            color: var(--gray-800);
            transition: all 0.2s ease;
            font-family: inherit;
        }
        .form-input:focus {
            border-color: var(--primary-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            outline: none;
            background: white;
        }
        .form-input::placeholder { color: var(--gray-400); }

        .input-icon {
            position: absolute; left: 0.875rem; top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400); font-size: 0.95rem;
            pointer-events: none;
        }

        .password-toggle {
            position: absolute; right: 0.75rem; top: 50%;
            transform: translateY(-50%);
            background: transparent; border: none;
            color: var(--gray-400); cursor: pointer;
            padding: 0.25rem; border-radius: 0.375rem;
            display: flex; align-items: center; justify-content: center;
            transition: color 0.2s ease;
        }
        .password-toggle:hover { color: var(--primary-600); }
        .password-toggle:focus { outline: 2px solid var(--primary-400); outline-offset: 2px; }

        /* Form Actions */
        .form-actions {
            display: flex; justify-content: space-between; align-items: center;
            margin: 0.75rem 0 1.5rem; flex-wrap: wrap; gap: 0.5rem;
        }
        .remember-me {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.875rem; color: var(--gray-600); cursor: pointer;
        }
        .remember-me input {
            width: 1rem; height: 1rem; accent-color: var(--primary-600); cursor: pointer;
        }
        .forgot-link {
            font-size: 0.875rem; font-weight: 500; color: var(--primary-600);
            text-decoration: none; transition: color 0.2s ease;
        }
        .forgot-link:hover { color: var(--primary-700); text-decoration: underline; }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--primary-600), var(--primary-700));
            border: none; padding: 0.875rem 1rem;
            border-radius: 0.875rem;
            font-weight: 600; font-size: 1rem; color: white;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
        }
        .btn-submit:hover:not(:disabled) {
            filter: brightness(1.05);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
            transform: translateY(-1px);
        }
        .btn-submit:active:not(:disabled) { transform: translateY(0); }
        .btn-submit:disabled {
            opacity: 0.7; cursor: not-allowed; transform: none; filter: grayscale(0.2);
        }

        /* Security Badge */
        .security-badge {
            display: flex; align-items: center; justify-content: center;
            gap: 0.5rem; padding: 0.75rem 1rem;
            background: var(--gray-50); border: 1px solid var(--gray-200);
            border-radius: 0.75rem; margin-top: 1.25rem;
            font-size: 0.8rem; color: var(--gray-600); font-weight: 500;
        }
        /* ✅ FIX: fa-shield-halved hanya tersedia di fa-solid */
        .security-badge i { color: var(--success-500); font-size: 1rem; }

        /* Footer */
        .card-footer {
            border-top: 1px solid var(--gray-200);
            padding-top: 1.25rem; margin-top: 1.25rem;
            text-align: center; font-size: 0.75rem; color: var(--gray-500);
        }

        /* Responsive */
        @media (max-width: 480px) {
            body { padding: 0.75rem; align-items: flex-start; padding-top: 1.25rem; }
            .login-card { border-radius: 1.25rem; }
            .card-header { padding: 1.5rem 1.25rem; border-radius: 1.25rem 1.25rem 0 0; }
            .card-body { padding: 1.25rem; }
            .form-actions { flex-direction: column; align-items: flex-start; }
            .forgot-link { margin-left: auto; }
            .form-input { font-size: 1rem; } /* ✅ FIX: Prevent iOS zoom */
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Utility */
        .fade-out { animation: fadeOut 0.3s ease-out forwards; }
        @keyframes fadeOut { to { opacity: 0; transform: translateY(-5px); } }
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="login-card">
    <!-- Header -->
    <div class="card-header">
        <div class="logo-icon">
            <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
        </div>
        <h1>Reqra WhatsApp</h1>
        <p>Sistem Pengiriman Pesan Otomatis</p>
    </div>

    <!-- Body -->
    <div class="card-body">
        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="" novalidate>
            <!-- Username -->
            <div class="form-group">
                <label class="form-label" for="username">
                    <i class="fa-solid fa-user" aria-hidden="true"></i> Username
                </label>
                <div class="input-wrapper">
                    <i class="fa-solid fa-at input-icon" aria-hidden="true"></i>
                    <input 
                        type="text" name="username" id="username"
                        class="form-input" placeholder="Masukkan username"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        autocomplete="username" required minlength="3"
                        aria-required="true"
                    >
                </div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label class="form-label" for="password">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i> Password
                </label>
                <div class="input-wrapper">
                    <i class="fa-solid fa-key input-icon" aria-hidden="true"></i>
                    <input 
                        type="password" name="password" id="password"
                        class="form-input" placeholder="Masukkan password"
                        autocomplete="current-password" required minlength="6"
                        aria-required="true"
                    >
                    <button type="button" class="password-toggle" id="togglePwd" 
                            aria-label="Tampilkan password" aria-pressed="false">
                        <i class="fa-solid fa-eye" id="eyeIcon" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <!-- Actions -->
            <div class="form-actions">
                <label class="remember-me">
                    <input type="checkbox" name="remember" id="remember">
                    <span>Ingat saya</span>
                </label>
                <a href="#" class="forgot-link" id="forgotLink">Lupa password?</a>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-submit" id="loginBtn">
                <!-- ✅ FIX: fa-right-to-bracket hanya di fa-solid -->
                <i class="fa-solid fa-right-to-bracket" id="btnIcon" aria-hidden="true"></i>
                <span id="btnText">Masuk</span>
            </button>
        </form>

        <!-- Security -->
        <div class="security-badge">
            <!-- ✅ FIX: fa-shield-halved hanya di fa-solid -->
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            <span>Enkripsi end-to-end · Aman</span>
        </div>

        <!-- Footer -->
        <div class="card-footer">
            Reqra WhatsApp &copy; <?= date('Y') ?>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // Escape HTML untuk keamanan
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Toggle password visibility
    const toggleBtn = document.getElementById('togglePwd');
    const pwdInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    
    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function() {
            const isHidden = pwdInput.type === 'password';
            pwdInput.type = isHidden ? 'text' : 'password';
            eyeIcon.className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            toggleBtn.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Tampilkan password');
            pwdInput.focus();
        });
    }

    // Form validation & loading state
    const form = document.querySelector('form');
    const loginBtn = document.getElementById('loginBtn');
    const btnIcon = document.getElementById('btnIcon');
    const btnText = document.getElementById('btnText');

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

            // Loading state
            if (loginBtn && !loginBtn.disabled) {
                loginBtn.disabled = true;
                btnIcon.className = 'fa-solid fa-circle-notch spin';
                btnText.textContent = 'Memproses...';
            }
            return true;
        });
    }

    // Show alert helper
    function showAlert(message, type) {
        // Hapus alert lama
        const oldAlert = document.querySelector('.alert');
        if (oldAlert) oldAlert.remove();

        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.setAttribute('role', 'alert');
        const icon = type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
        alertDiv.innerHTML = `<i class="fa-solid ${icon}" aria-hidden="true"></i><span>${escapeHtml(message)}</span>`;
        
        const formGroup = document.querySelector('.form-group');
        formGroup?.parentNode?.insertBefore(alertDiv, formGroup);
        
        // Auto hide
        setTimeout(() => {
            alertDiv.classList.add('fade-out');
            setTimeout(() => alertDiv.remove(), 300);
        }, 4500);
    }

    // Forgot link handler
    const forgotLink = document.getElementById('forgotLink');
    if (forgotLink) {
        forgotLink.addEventListener('click', function(e) {
            e.preventDefault();
            showAlert('Hubungi administrator untuk reset password.', 'success');
        });
    }

    // Prevent form resubmission
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    // Auto-focus username di desktop
    if (window.matchMedia('(min-width: 769px)').matches) {
        setTimeout(() => {
            const usernameInput = document.getElementById('username');
            if (usernameInput && !usernameInput.value) usernameInput.focus();
        }, 300);
    }

    // Handle viewport height untuk mobile
    function setVh() {
        document.documentElement.style.setProperty('--vh', `${window.innerHeight * 0.01}px`);
    }
    window.addEventListener('resize', setVh);
    window.addEventListener('orientationchange', setVh);
    setVh();

})();
</script>

</body>
</html>