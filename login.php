<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
set_security_headers();

if (isset($_SESSION['account_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    verify_csrf();

    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limiting: 5 attempts per 15 minutes per IP
    if (!rate_limit_check($client_ip, 5, 900)) {
        $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password, role, expire_date FROM system_accounts WHERE username = ?");
        $stmt->execute([$username]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account && password_verify($password, $account['password'])) {
            // Kiểm tra thời hạn
            if (!empty($account['expire_date']) && strtotime($account['expire_date']) < time()) {
                $error = "Tài khoản của bạn đã hết hạn sử dụng. Vui lòng liên hệ Admin.";
            } else {
                // ── Security: Regenerate session ID to prevent session fixation ──
                session_regenerate_id(true);
                
                $_SESSION['account_id'] = $account['id'];
                $_SESSION['username'] = $account['username'];
                $_SESSION['role'] = $account['role'];
                
                // Clear rate limit on successful login
                rate_limit_clear($client_ip);
                
                header("Location: index.php");
                exit;
            }
        } else {
            // Record failed attempt for rate limiting
            rate_limit_record($client_ip);
            $error = "Sai tên đăng nhập hoặc mật khẩu.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Quản lý Fanpage</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background-color: var(--bg-main);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            font-family: 'Inter', sans-serif;
        }
        .login-card {
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-card h2 {
            margin-top: 0;
            color: var(--text-main);
            margin-bottom: 25px;
        }
        .login-card .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        .login-card input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-sizing: border-box;
            margin-top: 5px;
        }
        .login-card button {
            width: 100%;
            padding: 12px;
            background: var(--primary-color);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
        .login-card button:hover {
            background: #4f46e5;
        }
        .error-msg {
            color: #ef4444;
            background: #fee2e2;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <h2>Đăng Nhập Hệ Thống</h2>
    <?php if ($error): ?>
        <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Tên đăng nhập</label>
            <input type="text" name="username" required placeholder="username" autocomplete="username">
        </div>
        <div class="form-group">
            <label>Mật khẩu</label>
            <input type="password" name="password" required placeholder="password" autocomplete="current-password">
        </div>
        <button type="submit">Đăng Nhập</button>
    </form>
    <div style="margin-top: 20px; font-size: 14px;">
        <a href="terms_of_service.php" style="color: var(--primary-color); text-decoration: none; margin-right: 15px;">Điều khoản dịch vụ</a>
        <a href="privacy_policy.php" style="color: var(--primary-color); text-decoration: none;">Chính sách bảo mật</a>
    </div>
</div>

</body>
</html>
