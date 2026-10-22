<?php
$is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $is_https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

include 'config/database.php';
include 'config/roles.php';

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

function login_rate_limit_file(string $username): ?string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'servicenet-login-attempts';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('Folder pembatasan login tidak dapat dibuat.');
        return null;
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return $directory . DIRECTORY_SEPARATOR . hash('sha256', strtolower($username) . '|' . $ip_address) . '.json';
}

function login_rate_limit_state(?string $file): array
{
    if ($file === null || !is_file($file)) {
        return ['failures' => 0, 'first_failure' => 0, 'locked_until' => 0];
    }

    $state = json_decode((string) file_get_contents($file), true);
    return is_array($state) ? $state + ['failures' => 0, 'first_failure' => 0, 'locked_until' => 0] : ['failures' => 0, 'first_failure' => 0, 'locked_until' => 0];
}

function login_rate_limit_save(?string $file, array $state): void
{
    if ($file !== null) {
        file_put_contents($file, json_encode($state), LOCK_EX);
    }
}

$username_value = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_value = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $rate_file = login_rate_limit_file($username_value);
    $rate_state = login_rate_limit_state($rate_file);
    $now = time();

    if (($rate_state['locked_until'] ?? 0) > $now) {
        $error = 'Terlalu banyak percobaan login. Silakan coba kembali dalam 15 menit.';
    } elseif (!hash_equals($_SESSION['login_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Sesi formulir telah berakhir. Silakan coba kembali.';
    } elseif ($username_value === '' || mb_strlen($username_value) > 64 || $password === '' || strlen($password) > 1024) {
        $error = 'Username atau password salah.';
    } else {
        $stmt = $pdo->prepare('SELECT id, nama, jabatan, password FROM staff_it WHERE username = ? AND status = \'active\' LIMIT 1');
        $stmt->execute([$username_value]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $update_password = $pdo->prepare('UPDATE staff_it SET password = ? WHERE id = ?');
                $update_password->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }

            if ($rate_file !== null && is_file($rate_file)) {
                @unlink($rate_file);
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['nama'] = $user['nama'];
            $_SESSION['jabatan'] = $user['jabatan'];
            unset($_SESSION['login_csrf']);

            header('Location: ' . getRedirectAfterLogin($user['jabatan']));
            exit;
        }

        $first_failure = (int) ($rate_state['first_failure'] ?? 0);
        if ($first_failure === 0 || ($now - $first_failure) > 900) {
            $rate_state = ['failures' => 0, 'first_failure' => $now, 'locked_until' => 0];
        }

        $rate_state['failures'] = (int) ($rate_state['failures'] ?? 0) + 1;
        if ($rate_state['failures'] >= 5) {
            $rate_state['locked_until'] = $now + 900;
        }
        login_rate_limit_save($rate_file, $rate_state);
        $error = 'Username atau password salah.';
    }
}

// Jika sudah login, redirect berdasarkan role
if (isset($_SESSION['user_id'])) {
    $redirect_url = getRedirectAfterLogin($_SESSION['jabatan']);
    header('Location: ' . $redirect_url);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="uploads/icon/favicon.ico">
    <title>Login - Psitek</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
    <style>
        :root {
            --primary: #6f61e8;
            --secondary: #8b7cf6;
        }
        
        /* Style yang sama dengan file sebelumnya */
        .login-body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f6f6fa;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            background: #fff;
            border: 1px solid #ecebf2;
            border-radius: 12px;
            box-shadow: 0 5px 24px rgba(35, 31, 71, 0.08);
            padding: 36px;
            text-align: center;
        }

        .login-logo {
            margin: 0 auto 20px auto;
        }

        .login-logo-container {
            width: 60px;
            height: 60px;
            background-color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
            border: 1px solid #ecebf2;
            padding: 8px;
            margin: 0 auto 15px auto;
        }

        .login-logo-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .login-title {
            text-align: center;
            margin: 10px 0 5px 0;
            color: #4d4c59;
            font-size: 1.65rem;
            font-weight: 600;
        }

        .login-subtitle {
            text-align: center;
            margin-bottom: 25px;
            color: #666;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border: 1px solid #f5c6cb;
            border-left: 4px solid #dc3545;
        }

        .btn-login {
            width: 100%;
            padding: 14px 20px;
            border-radius: 12px;
            background: var(--primary);
            color: white;
            font-weight: 700;
            font-size: 16px;
            border: none;
            box-shadow: none;
            transition: background-color 0.18s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-login:hover {
            transform: none;
            box-shadow: none;
            background: #5b4ed4;
        }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <!-- Logo di atas teks login -->
        <div class="login-logo">
            <div class="login-logo-container">
                <img src="uploads/images/logo-unisba.png" alt="PSITek Logo">
            </div>
            <h1 class="login-title">Network Psitek</h1>
            <p class="login-subtitle">Universitas Islam Bandung</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <form method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username_value, ENT_QUOTES, 'UTF-8'); ?>" maxlength="64" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-login">Login</button>
        </form>
    </div>
</body>
</html>
