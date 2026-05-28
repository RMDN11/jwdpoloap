<?php
session_start();

// Jika sudah login, langsung alihkan ke halaman reminder-index.php
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: reminder-index.php");
    exit;
}

require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Ngaran pamaké sareng kecap konci teu kenging kosong.";
    } else {
        if ($conn) {
            $query = "SELECT id, username, password FROM users WHERE username = ?";
            
            if ($stmt = mysqli_prepare($conn, $query)) {
                mysqli_stmt_bind_param($stmt, "s", $username);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $user = mysqli_fetch_assoc($result);

                if ($user && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['loggedin'] = true;
                    // PERUBAHAN DI SINI: Mengalihkan ke reminder-index.php
                    header("Location: reminder-index.php");
                    exit();
                } else {
                    $error = "Ngaran pamaké atanapi kecap konci lepat";
                }
                mysqli_stmt_close($stmt);
            } else {
                $error = "Gagal menyiapkan statement.";
            }
        } else {
            $error = "Gagal nyambung ka basis data.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="su">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asup ka dieu - JWD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #121212;
            --card-bg-color: #1E1E1E;
            --input-bg-color: #2a2a2a;
            --border-color: #444;
            --text-primary: #FFFFFF;
            --text-secondary: #AAAAAA;
            --accent-color: #FFFFFF;
            --accent-text-color: #121212;
        }
        body {
            background-color: var(--bg-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
            color: var(--text-primary);
        }
        body, .table, .card, .modal-content, .btn, input, select, textarea, h1, h2, h3, h4, h5, h6, p, span, div {
            font-family: 'Montserrat', sans-serif !important;
        }
        .card {
            border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            background-color: var(--card-bg-color);
            border: 1px solid var(--border-color);
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }
        .card-title {
            color: var(--text-primary);
            font-weight: 700;
            font-size: calc(1.325rem + 0.5vw);
        }
        .form-label {
            color: var(--text-secondary);
            font-weight: 500;
        }
        .form-control {
            border-radius: 0.5rem;
            padding: 0.85rem 1rem;
            background-color: var(--input-bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            background-color: var(--input-bg-color);
            color: var(--text-primary);
            box-shadow: 0 0 0 0.25rem rgba(255, 255, 255, 0.1);
            border-color: var(--accent-color);
        }
        .form-control::placeholder {
            color: #777;
        }
        .btn-primary {
            background-color: var(--accent-color);
            color: var(--accent-text-color);
            border: none;
            border-radius: 0.5rem;
            padding: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: background-color 0.3s, transform 0.2s;
        }
        .btn-primary:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }
        .input-group-text {
            background-color: var(--input-bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }
        .password-toggle { cursor: pointer; }
        .alert-danger {
            background-color: #4a2125;
            color: #f8d7da;
            border-color: #842029;
        }
        .text-link {
            color: var(--text-secondary);
            text-decoration: none;
        }
        .text-link a {
            color: var(--accent-color);
            font-weight: 600;
            text-decoration: none;
        }
        .text-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card">
                    <div class="card-body p-4 p-md-5">
                        <h2 class="card-title text-center mb-4">Login Kanggo Panginget</h2>
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="mb-3">
                                <label for="username" class="form-label">Ngaran pamaké</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" required autocomplete="username">
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="password" class="form-label">Kecap konci</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                                    <span class="input-group-text password-toggle" onclick="togglePassword()">
                                        <i class="fas fa-eye" id="toggleIcon"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="d-grid mb-4">
                                <button type="submit" class="btn btn-primary">Asup</button>
                            </div>
                            <div class="text-center text-link">
        
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>