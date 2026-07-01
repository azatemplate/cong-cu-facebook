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

    if (!isset($_POST['agree_terms'])) {
        $error = "Bạn phải đồng ý với Điều khoản Dịch vụ (Terms of Service) để đăng nhập.";
    } else if (!rate_limit_check($client_ip, 5, 900)) { // Rate limiting: 5 attempts per 15 minutes per IP
        $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.";
    } else {
        // Try login by username first (only for accounts NOT using login_by_email)
        $stmt = $pdo->prepare("SELECT id, username, password, role, expire_date, login_by_email FROM system_accounts WHERE username = ? AND (login_by_email = 0 OR login_by_email IS NULL)");
        $stmt->execute([$username]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If not found by username, try login by email (for accounts using login_by_email)
        if (!$account) {
            $stmt2 = $pdo->prepare("SELECT id, username, password, role, expire_date, login_by_email FROM system_accounts WHERE email = ? AND login_by_email = 1");
            $stmt2->execute([$username]);
            $account = $stmt2->fetch(PDO::FETCH_ASSOC);
        }

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
    <title>Đăng nhập - REELS MEDIA</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --red-brand: #ef4444;
            --gray-100: #f3f4f6;
            --gray-300: #d1d5db;
            --gray-500: #6b7280;
            --gray-800: #1f2937;
        }
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Inter', sans-serif;
            background-color: var(--gray-100);
            box-sizing: border-box;
        }
        * { box-sizing: inherit; }

        .layout-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
            background-color: var(--gray-100);
        }

        /* Left Panel */
        .left-panel {
            flex: 0 0 55%;
            background: linear-gradient(135deg, #23225a 0%, #171638 100%);
            padding: 60px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        
        .left-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: radial-gradient(circle at 80% 20%, rgba(79, 70, 229, 0.15) 0%, transparent 40%),
                              radial-gradient(circle at 20% 80%, rgba(79, 70, 229, 0.1) 0%, transparent 40%);
            z-index: 0;
        }
        
        .left-content {
            position: relative;
            z-index: 1;
        }
        
        .brand-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 60px;
        }
        .logo-box {
            width: 44px; height: 44px;
            background: #4ade80;
            border-radius: 10px;
            display: flex;
            align-items: center; justify-content: center;
            font-weight: 800; font-size: 24px; color: white;
            box-shadow: 0 4px 10px rgba(74, 222, 128, 0.3);
        }
        .brand-text-wrapper h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.5px;
        }
        .brand-text-wrapper p {
            margin: 2px 0 0;
            font-size: 11px;
            font-weight: 600;
            color: #9ca3af;
            letter-spacing: 1px;
        }

        .main-hero h2 {
            font-size: 40px;
            font-weight: 800;
            line-height: 1.25;
            margin: 0 0 20px;
            font-family: 'Inter', sans-serif;
            color: #f8fafc;
        }
        .main-hero h2 span { color: var(--red-brand); }
        .main-hero p {
            font-size: 15px;
            color: #cbd5e1;
            line-height: 1.6;
            margin-bottom: 50px;
            max-width: 520px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            max-width: 600px;
        }
        .feature-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 24px;
            transition: transform 0.3s, background 0.3s;
        }
        .feature-card:hover {
            background: rgba(255, 255, 255, 0.07);
            transform: translateY(-2px);
        }
        .feat-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 15px; font-weight: 700; margin-bottom: 12px;
        }
        .feat-desc {
            font-size: 13px; color: #94a3b8; line-height: 1.5; margin: 0;
        }

        .left-footer {
            position: relative;
            z-index: 1;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 11px;
            color: #8b96a5;
        }
        .footer-info-col p { margin: 6px 0; display: flex; align-items: center; gap: 8px;}

        /* Right Panel */
        .right-panel {
            flex: 0 0 45%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            padding: 40px;
        }

        .login-box {
            background: #ffffff;
            width: 100%;
            max-width: 460px;
            border-radius: 32px;
            padding: 60px 50px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.03);
            position: relative;
        }

        .login-header { text-align: center; margin-bottom: 45px; }
        .login-header h2 {
            font-family: 'Outfit', 'Inter', sans-serif;
            font-size: 34px;
            font-weight: 900;
            color: #1f2937;
            margin: 0 0 8px;
            letter-spacing: -0.5px;
        }
        .login-header p {
            font-size: 10px;
            font-weight: 700;
            color: #9ca3af;
            letter-spacing: 2px;
            margin: 0;
            text-transform: uppercase;
        }

        .form-group { margin-bottom: 24px; position: relative; }
        .form-group label {
            display: flex;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--gray-500);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group label span { color: var(--red-brand); margin-right:5px; font-size: 14px; line-height:0;}
        
        .input-wrapper {
            position: relative;
            display: flex; align-items: center;
        }
        .input-wrapper > svg {
            position: absolute;
            left: 16px;
            width: 18px; height: 18px;
            color: #9ca3af;
        }
        .input-wrapper input {
            width: 100%;
            padding: 15px 16px 15px 46px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            font-family: inherit;
            color: #1f2937;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
        }
        .input-wrapper input::placeholder { color: #9ca3af; }
        .input-wrapper input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
        
        .eye-btn {
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            background: none; border: none; padding: 0; cursor: pointer; color: #9ca3af;
            display: flex; align-items: center; justify-content: center;
        }

        .form-options {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 30px;
        }
        .checkbox-container {
            display: flex; align-items: center; cursor: pointer;
            font-size: 13px; font-weight: 600; color: #4b5563;
        }
        .checkbox-container input { display: none; }
        .checkmark {
            width: 18px; height: 18px; box-sizing: border-box;
            background-color: #fff; border: 2px solid #d1d5db; border-radius: 5px;
            margin-right: 10px; display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .checkbox-container input:checked ~ .checkmark {
            background-color: var(--primary); border-color: var(--primary);
        }
        .checkmark::after {
            content: ""; width: 4px; height: 8px; border: solid white; border-width: 0 2px 2px 0;
            transform: rotate(45deg); display: none; margin-bottom: 2px;
        }
        .checkbox-container input:checked ~ .checkmark::after { display: block; }
        
        .forgot-link {
            font-size: 13px; font-weight: 600; color: var(--primary); text-decoration: none;
        }
        .forgot-link:hover { text-decoration: underline; }

        .submit-btn {
            width: 100%; padding: 16px;
            background: var(--primary);
            color: white; border: none; border-radius: 14px;
            font-size: 16px; font-weight: 700; cursor: pointer;
            box-shadow: 0 8px 15px rgba(79, 70, 229, 0.25);
            transition: background 0.2s, transform 0.1s;
            position: relative; overflow: hidden;
        }
        .submit-btn::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 100%);
            z-index: 1; pointer-events: none;
        }
        .submit-btn span { position: relative; z-index: 2; }
        .submit-btn:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .submit-btn:active { transform: translateY(1px); box-shadow: 0 4px 8px rgba(79, 70, 229, 0.2); }

        .no-account {
            text-align: center; margin-top: 24px; font-size: 13px; font-weight: 600; color: #6b7280;
        }
        .no-account a { color: var(--primary); text-decoration: none; margin-left: 5px; }

        .legal-footer {
            margin-top: 40px; text-align: center;
        }
        .legal-footer .line-title {
            display: flex; align-items: center; font-size: 9px; font-weight: 800; color: #d1d5db; letter-spacing: 2px;
            margin-bottom: 20px;
        }
        .line-title::before, .line-title::after { content: ''; flex: 1; height: 1px; background: #f3f4f6; }
        .line-title::before { margin-right: 15px; }
        .line-title::after { margin-left: 15px; }
        
        .legal-links { display: flex; justify-content: center; gap: 24px; }
        .legal-links a {
            font-size: 10px; font-weight: 800; color: #9ca3af; text-decoration: none; letter-spacing: 1px;
        }
        .legal-links a:hover { color: #4b5563; }

        .error-msg {
            color: #ef4444; background: #fee2e2; padding: 12px 16px; border-radius: 10px; margin-bottom: 24px; font-size: 13px; font-weight: 600; text-align: center; border: 1px solid #fca5a5;
        }

        /* Responsive */
        @media (max-width: 960px) {
            .left-panel { display: none; }
            .right-panel { flex: 1; padding: 20px; justify-content: center; }
            .login-box { padding: 40px 30px; }
        }
    </style>
</head>
<body>

<div class="layout-wrapper">
    <!-- Left side -->
    <div class="left-panel">
        <div class="left-content">
            <div class="brand-header">
                <div class="logo-box">R</div>
                <div class="brand-text-wrapper">
                    <h1>REELS MEDIA</h1>
                    <p>CÔNG NGHỆ &amp; TRUYỀN THÔNG SỐ</p>
                </div>
            </div>

            <div class="main-hero">
                <h2>Hệ thống <span>Hẹn Giờ Đăng Bài</span> Tự Động 1000 Fanpage+++</h2>
                <p>Giải pháp hiệu quả hỗ trợ quản lý đa nền tảng, AI viết nội dung chuyên nghiệp và phân tích Insights nhanh chóng.</p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feat-title">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#60a5fa"/><path d="M7 12.5l3 3 7-7" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Đăng bài tự động
                    </div>
                    <p class="feat-desc">Lên lịch đăng bài trực tiếp cho Facebook, Reels, Video, Story và Youtube.</p>
                </div>
                <div class="feature-card">
                    <div class="feat-title">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#60a5fa"/><path d="M7 12.5l3 3 7-7" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Gôm chát toàn Fanpage
                    </div>
                    <p class="feat-desc">Quản trị toàn bộ tin nhắn, bình luận mọi Fanpage ngay trên một giao diện chung.</p>
                </div>
                <div class="feature-card">
                    <div class="feat-title">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#60a5fa"/><path d="M7 12.5l3 3 7-7" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Trợ lý AI viết bài
                    </div>
                    <p class="feat-desc">Ứng dụng trí tuệ nhân tạo (AI) giúp bạn tự do sáng tạo vô hạn kịch bản, nội dung.</p>
                </div>
                <div class="feature-card">
                    <div class="feat-title">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#60a5fa"/><path d="M7 12.5l3 3 7-7" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Phân tích Insights
                    </div>
                    <p class="feat-desc">Đo lường, thống kê cực chuẩn số liệu Reach, View của toàn hệ thống Fanpage.</p>
                </div>
            </div>
        </div>

        <div class="left-footer">
            <div class="footer-info-col">
                <p>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    manhongit.dhp@gmail.com
                </p>
                <div style="display:flex; gap:24px;">
                    <p>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        0967849934
                    </p>
                </div>
            </div>
            <div style="font-weight: 500;">
                © 2026 Hồng MMO Automation
            </div>
        </div>
    </div>

    <!-- Right side -->
    <div class="right-panel">
        <div class="login-box">
            <div class="login-header">
                <h2>Access System</h2>
                <p>REELS MEDIA ACCESS • VERSION 2.0</p>
            </div>

            <div id="error-container">
                <?php if ($error): ?>
                    <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </div>

            <form method="POST" action="login.php" onsubmit="return validateForm()">
                <?php echo csrf_field(); ?>
                
                <div class="form-group">
                    <label for="username"><span>*</span> USERNAME / EMAIL</label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input type="text" id="username" name="username" required placeholder="Username hoặc Email" autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><span>*</span> PASSWORD</label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <input type="password" id="password" name="password" required placeholder="••••••••" autocomplete="current-password">
                        <button type="button" class="eye-btn" onclick="togglePassword()">
                            <svg id="eye-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <div class="form-options" style="margin-bottom: 20px; flex-direction: column; align-items: flex-start;">
                    <label class="checkbox-container">
                        <input type="checkbox" name="agree_terms">
                        <div class="checkmark"></div>
                        <span>Tôi đồng ý với <a href="terms_of_service.php" target="_blank" style="color: var(--primary); text-decoration: none;">Điều khoản Dịch vụ</a></span>
                    </label>
                    <span style="font-size: 11px; color: var(--gray-500); margin-top: 5px; margin-left: 28px;">(Khuyến khích người dùng nên đọc kỹ và đồng ý)</span>
                </div>

                <button type="submit" class="submit-btn" style="margin-top: 10px;">
                    <span>Sign In Now</span>
                </button>
            </form>

            <div class="legal-footer">
                <div class="line-title">LEGAL</div>
                <div class="legal-links">
                    <a href="privacy_policy.php">PRIVACY</a>
                    <a href="terms_of_service.php">TERMS</a>
                    <a href="#">DELETION</a>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function togglePassword() {
    const pwd = document.getElementById('password');
    const eyeIcon = document.getElementById('eye-icon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    } else {
        pwd.type = 'password';
        eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    }
}

function validateForm() {
    const agreeCheckbox = document.querySelector('input[name="agree_terms"]');
    const errorContainer = document.getElementById('error-container');
    if (!agreeCheckbox || !agreeCheckbox.checked) {
        errorContainer.innerHTML = '<div class="error-msg">Bạn phải đồng ý với Điều khoản Dịch vụ (Terms of Service) để đăng nhập.</div>';
        errorContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return false;
    }
    return true;
}
</script>
</body>
</html>
