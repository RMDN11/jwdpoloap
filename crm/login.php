<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['logged_in'])) { header('Location: index.php?page=home'); exit; }

$error = '';
$savedUsername = $_COOKIE['remembered_username'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        try {
            $stmt = $conn->prepare('SELECT id, username, password FROM login_event WHERE username = ? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, (string)$user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['username'];
                $_SESSION['logged_in'] = true;
                $_SESSION['role'] = 'user';

                setcookie('remembered_username', $remember ? $username : '', [
                    'expires' => $remember ? time() + 2592000 : time() - 3600,
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => false,
                    'samesite' => 'Lax'
                ]);
                header('Location: index.php?page=home');
                exit;
            }
            $error = 'Username atau password salah.';
        } catch (Throwable $e) {
            error_log('CRM V2 login error: ' . $e->getMessage());
            $error = 'Terjadi kesalahan sistem. Coba lagi.';
        }
    }
    $savedUsername = $username;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#f6f8f7">
<title>Masuk · ReqraWA CRM</title>
<link rel="icon" type="image/png" href="assets/logowa.png">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
.crm-login{min-height:100dvh;display:grid;place-items:center;padding:16px;background:#f6f8f7}
.crm-login-card{width:min(410px,100%);padding:28px;border:1px solid #e3ebe6;border-radius:28px;background:#fff;box-shadow:0 24px 70px rgba(24,55,41,.10)}
.crm-login-brand{text-align:center}.crm-login-logo{width:58px;height:58px;margin:0 auto 13px;display:grid;place-items:center;border-radius:18px;background:linear-gradient(145deg,#166534,#22c55e);color:#fff;font-size:25px;box-shadow:0 12px 28px rgba(22,101,52,.22)}
.crm-login-brand h1{margin:0;color:#10231b;font-size:25px;letter-spacing:-.05em}.crm-login-brand h1 span{color:#168044}.crm-login-brand p{margin:6px 0 0;color:#87948e;font-size:11px}
.crm-login-form{margin-top:25px}.crm-login-field{margin-bottom:14px}.crm-login-field label{display:block;margin-bottom:6px;color:#30473d;font-size:10px;font-weight:800}
.crm-login-input{position:relative}.crm-login-input>i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#91a099;font-size:12px}
.crm-login-input input{width:100%;height:46px;padding:0 42px 0 38px;border:1px solid #dfe8e3;border-radius:14px;background:#f9fbfa;outline:0;color:#173328;font:inherit;font-size:12px}
.crm-login-input input:focus{border-color:#168044;box-shadow:0 0 0 3px rgba(22,128,68,.10);background:#fff}
.crm-login-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:30px;height:30px;border:0;background:transparent;color:#91a099;cursor:pointer}
.crm-login-options{display:flex;justify-content:space-between;align-items:center;margin:5px 0 18px}.crm-login-remember{display:flex;align-items:center;gap:7px;color:#75837c;font-size:10px}.crm-login-remember input{accent-color:#168044}
.crm-login-help{color:#168044;text-decoration:none;font-size:10px;font-weight:700}.crm-login-submit{width:100%;height:46px;border:0;border-radius:14px;background:linear-gradient(145deg,#166534,#22c55e);color:#fff;font:inherit;font-size:12px;font-weight:800;cursor:pointer;box-shadow:0 10px 24px rgba(22,101,52,.20)}
.crm-login-submit:disabled{opacity:.7;cursor:wait}.crm-login-alert{display:flex;gap:9px;align-items:center;margin-bottom:15px;padding:11px 12px;border-radius:13px;background:#fff1f1;border:1px solid #ffd9d9;color:#b42318;font-size:10px}
.crm-login-security{display:flex;justify-content:center;gap:6px;margin-top:17px;color:#91a099;font-size:9px}.crm-login-security i{color:#168044}.crm-login-footer{margin-top:20px;text-align:center;color:#a0aaa5;font-size:8px}
@media(max-width:430px){.crm-login{padding:14px}.crm-login-card{padding:22px 18px;border-radius:23px}.crm-login-brand h1{font-size:23px}}
</style>
</head>
<body>
<main class="crm-login"><section class="crm-login-card">
<div class="crm-login-brand"><div class="crm-login-logo"><i class="fab fa-whatsapp"></i></div><h1>Reqra<span>WA</span></h1><p>CRM Workspace</p></div>
<form class="crm-login-form" method="post" autocomplete="on">
<?php if ($error): ?><div class="crm-login-alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
<div class="crm-login-field"><label for="username">USERNAME</label><div class="crm-login-input"><i class="fa-solid fa-at"></i><input id="username" name="username" value="<?= htmlspecialchars($savedUsername) ?>" placeholder="Masukkan username" autocomplete="username" required></div></div>
<div class="crm-login-field"><label for="password">PASSWORD</label><div class="crm-login-input"><i class="fa-solid fa-lock"></i><input id="password" name="password" type="password" placeholder="Masukkan password" autocomplete="current-password" required><button class="crm-login-toggle" type="button" id="togglePassword"><i class="fa-solid fa-eye"></i></button></div></div>
<div class="crm-login-options"><label class="crm-login-remember"><input type="checkbox" name="remember" <?= $savedUsername !== '' ? 'checked' : '' ?>> Ingat username</label><a class="crm-login-help" href="mailto:admin@reqra.my.id">Butuh bantuan?</a></div>
<button class="crm-login-submit" type="submit" id="loginSubmit"><i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk ke CRM</button>
</form>
<div class="crm-login-security"><i class="fa-solid fa-shield-halved"></i><span>Session terlindungi · ReqraWA CRM</span></div>
<div class="crm-login-footer">ReqraWA CRM · <?= date('Y') ?></div>
</section></main>
<script>
const t=document.getElementById('togglePassword'),p=document.getElementById('password');
t?.addEventListener('click',()=>{const h=p.type==='password';p.type=h?'text':'password';t.innerHTML=h?'<i class="fa-solid fa-eye-slash"></i>':'<i class="fa-solid fa-eye"></i>';});
document.querySelector('.crm-login-form')?.addEventListener('submit',()=>{const b=document.getElementById('loginSubmit');b.disabled=true;b.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Memproses...';});
</script>
</body></html>