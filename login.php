<?php
// Force HTTPS for secure context (required by Chrome/browsers to save passwords)
if ((empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === "off") && $_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1') {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $redirect);
    exit;
}
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

// Generate CSRF token BEFORE closing session write so it's persisted to session store
csrf_token();

// Release session lock immediately for GET requests so browser asset loads/reloads never block
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
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
    <title>Đăng nhập - HONGDOLABS Automation</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="shortcut icon" type="image/png" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page: #f8fafc;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-glow: rgba(79, 70, 229, 0.15);
            --accent-purple: #9333ea;
            --accent-rose: #e11d48;
            --accent-emerald: #059669;
            --accent-cyan: #0284c7;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body, html {
            height: 100%;
            font-family: 'Be Vietnam Pro', 'Inter', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-body);
            overflow-x: hidden;
        }

        .layout-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* ── Left Hero Panel (Sáng sang trọng, tương phản cao, phông tiếng Việt chuẩn) ── */
        .left-panel {
            flex: 0 0 55%;
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
            padding: 55px 65px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            color: #ffffff;
        }

        /* Subtle Light Gradients */
        .left-panel::before {
            content: '';
            position: absolute;
            top: -15%; left: -10%;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.25) 0%, rgba(99, 102, 241, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .left-content { position: relative; z-index: 2; }

        /* Brand Header */
        .brand-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 50px;
        }
        .logo-box {
            width: 50px; height: 50px;
            border-radius: 12px;
            background: #ffffff;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            padding: 4px; flex-shrink: 0;
        }
        .logo-box img { width: 100%; height: 100%; border-radius: 8px; object-fit: contain; }
        .brand-text h1 {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 22px; font-weight: 800;
            letter-spacing: 0.5px;
            color: #ffffff;
            margin: 0;
        }
        .brand-text p {
            font-size: 11px; font-weight: 700;
            color: #a5b4fc; letter-spacing: 1.5px;
            text-transform: uppercase; margin-top: 3px;
        }

        /* Hero Text */
        .main-hero { margin-bottom: 42px; }
        .main-hero h2 {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 40px; font-weight: 800;
            line-height: 1.25; letter-spacing: -0.01em;
            color: #ffffff; margin-bottom: 18px;
        }
        .main-hero h2 span {
            color: #f43f5e;
            background: linear-gradient(135deg, #fb7185 0%, #f43f5e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .main-hero p {
            font-size: 15px; color: #cbd5e1;
            line-height: 1.65; max-width: 540px; font-weight: 400;
        }

        /* Features Grid */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
            max-width: 640px;
        }
        .feature-card {
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 22px;
            transition: all 0.3s ease;
        }
        .feature-card:hover {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.25);
            transform: translateY(-3px);
        }
        .feat-icon-box {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
        }
        .feat-title {
            font-size: 15px; font-weight: 700; color: #ffffff;
            margin-bottom: 6px; display: flex; align-items: center; gap: 8px;
        }
        .feat-desc {
            font-size: 13px; color: #cbd5e1;
            line-height: 1.5; margin: 0; font-weight: 400;
        }

        /* Left Footer */
        .left-footer {
            position: relative; z-index: 2;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            padding-top: 22px;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 13px; color: #94a3b8;
        }
        .footer-contacts { display: flex; gap: 24px; }
        .footer-contacts span { display: flex; align-items: center; gap: 8px; color: #cbd5e1; }

        /* ── Right Form Panel (Sáng sủa, chữ đậm nét, dễ đọc 100%) ── */
        .right-panel {
            flex: 0 0 45%;
            display: flex; align-items: center; justify-content: center;
            padding: 40px 30px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            position: relative;
        }

        .login-box {
            width: 100%; max-width: 450px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            padding: 46px 40px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.08);
            position: relative;
        }

        .login-header { text-align: center; margin-bottom: 34px; }
        .login-header h2 {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 32px; font-weight: 800;
            color: #0f172a; margin: 0 0 6px;
            letter-spacing: -0.02em;
        }
        .login-header p {
            font-size: 11px; font-weight: 700;
            color: var(--primary); letter-spacing: 2px;
            text-transform: uppercase; margin: 0;
        }

        .mobile-logo-header {
            display: none;
            align-items: center; justify-content: center;
            gap: 12px; margin-bottom: 22px;
        }
        .mobile-logo-header img { width: 40px; height: 40px; border-radius: 10px; }
        .mobile-logo-header span {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 20px; font-weight: 800; color: #0f172a;
        }

        /* Form Controls */
        .form-group { margin-bottom: 22px; position: relative; }
        .form-group label {
            display: flex; align-items: center;
            font-size: 12px; font-weight: 700;
            color: #334155;
            margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .form-group label span { color: var(--accent-rose); margin-right: 4px; }

        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper > svg {
            position: absolute; left: 16px;
            width: 18px; height: 18px;
            color: #64748b;
            transition: color 0.2s;
        }
        .input-wrapper input {
            width: 100%;
            padding: 14px 16px 14px 46px;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px; font-weight: 500;
            font-family: inherit; color: #0f172a;
            transition: all 0.2s ease;
        }
        .input-wrapper input::placeholder { color: #94a3b8; }
        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px var(--primary-glow);
        }
        .input-wrapper input:focus + svg,
        .input-wrapper input:focus ~ svg { color: var(--primary); }

        .eye-btn {
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            background: none; border: none; padding: 0; cursor: pointer;
            color: #64748b; display: flex; align-items: center; justify-content: center;
            transition: color 0.2s;
        }
        .eye-btn:hover { color: var(--primary); }

        /* Checkbox & Terms */
        .form-options {
            margin-bottom: 24px;
        }
        .checkbox-container {
            display: flex; align-items: center; cursor: pointer;
            font-size: 13px; font-weight: 600; color: #334155;
            user-select: none;
        }
        .checkbox-container input { display: none; }
        .checkmark {
            width: 18px; height: 18px;
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 5px; margin-right: 10px;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; flex-shrink: 0;
        }
        .checkbox-container input:checked ~ .checkmark {
            background: var(--primary); border-color: var(--primary);
            box-shadow: 0 0 10px var(--primary-glow);
        }
        .checkmark::after {
            content: ""; width: 4px; height: 8px; border: solid white;
            border-width: 0 2px 2px 0; transform: rotate(45deg); display: none; margin-bottom: 2px;
        }
        .checkbox-container input:checked ~ .checkmark::after { display: block; }
        .checkbox-container a { color: var(--primary); text-decoration: none; font-weight: 700; }
        .checkbox-container a:hover { text-decoration: underline; }

        /* Buttons */
        .submit-btn {
            width: 100%; padding: 15px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: white; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            box-shadow: 0 8px 20px var(--primary-glow);
            transition: all 0.2s ease;
            position: relative; overflow: hidden; font-family: inherit;
        }
        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 25px var(--primary-glow);
        }
        .submit-btn:active { transform: translateY(1px); }

        /* Error Alert */
        .error-msg {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px; border-radius: 10px;
            margin-bottom: 22px; font-size: 13px; font-weight: 600;
            display: flex; align-items: center; gap: 10px;
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* Legal Footer */
        .legal-footer { margin-top: 34px; text-align: center; }
        .legal-footer .line-title {
            display: flex; align-items: center; font-size: 10px; font-weight: 800;
            color: #94a3b8; letter-spacing: 2px; margin-bottom: 16px;
        }
        .line-title::before, .line-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
        .line-title::before { margin-right: 12px; }
        .line-title::after { margin-left: 12px; }

        .legal-links { display: flex; justify-content: center; gap: 20px; }
        .legal-links a {
            font-size: 11px; font-weight: 700; color: #64748b;
            text-decoration: none; letter-spacing: 0.5px; transition: color 0.2s;
        }
        .legal-links a:hover { color: var(--primary); }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .left-panel { flex: 0 0 50%; padding: 40px; }
            .main-hero h2 { font-size: 34px; }
            .features-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 960px) {
            .left-panel { display: none; }
            .right-panel { flex: 1; padding: 24px; }
            .login-box { padding: 36px 28px; border-radius: 20px; }
            .mobile-logo-header { display: flex !important; }
        }
    </style>
</head>
<body>

<div class="layout-wrapper">
    <!-- Left Hero Side -->
    <div class="left-panel">
        <div class="left-content">
            <div class="brand-header">
                <div class="logo-box">
                    <img src="logo.png" alt="HONGDOLABS Logo">
                </div>
                <div class="brand-text">
                    <h1>HONGDOLABS</h1>
                    <p>CÔNG NGHỆ &amp; TRUYỀN THÔNG SỐ</p>
                </div>
            </div>

            <div class="main-hero">
                <h2>Hệ Thống <span>Hẹn Giờ Đăng Bài</span> Tự Động 1000+ Fanpage</h2>
                <p>Giải pháp quản trị đa kênh tập trung, tích hợp trí tuệ nhân tạo (AI) sáng tạo nội dung và đo lường số liệu Insights hệ thống tức thì.</p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feat-icon-box" style="background: rgba(165, 180, 252, 0.2); color: #a5b4fc;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="feat-title">Đăng bài tự động</div>
                    <p class="feat-desc">Lên lịch đăng bài linh hoạt cho Facebook, Reels, Video, Story và Youtube.</p>
                </div>
                <div class="feature-card">
                    <div class="feat-icon-box" style="background: rgba(216, 180, 254, 0.2); color: #d8b4fe;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <div class="feat-title">Gôm chát toàn Fanpage</div>
                    <p class="feat-desc">Quản trị toàn bộ tin nhắn & bình luận đa fanpage ngay tại một giao diện.</p>
                </div>
                <div class="feature-card">
                    <div class="feat-icon-box" style="background: rgba(110, 231, 183, 0.2); color: #6ee7b7;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div class="feat-title">Trợ lý AI viết bài</div>
                    <p class="feat-desc">Tạo tự động kịch bản & nội dung sáng tạo nhờ trợ lý trí tuệ nhân tạo (AI).</p>
                </div>
                <div class="feature-card">
                    <div class="feat-icon-box" style="background: rgba(125, 211, 252, 0.2); color: #7dd3fc;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div class="feat-title">Phân tích Insights</div>
                    <p class="feat-desc">Đo lường chi tiết chỉ số tiếp cận (Reach) và lượt xem (Views) theo thời gian thực.</p>
                </div>
            </div>
        </div>

        <div class="left-footer">
            <div class="footer-contacts">
                <span>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    manhongit.dhp@gmail.com
                </span>
                <span>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    0967849934
                </span>
            </div>
            <div>© 2026 HONGDOLABS Automation</div>
        </div>
    </div>

    <!-- Right Form Side -->
    <div class="right-panel">
        <div class="login-box">
            <div class="login-header">
                <div class="mobile-logo-header">
                    <img src="logo.png" alt="HONGDOLABS Logo">
                    <span>HONGDOLABS</span>
                </div>
                <h2>Access System</h2>
                <p>HONGDOLABS • VERSION 2.0</p>
            </div>

            <div id="error-container">
                <?php if ($error): ?>
                    <div class="error-msg">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
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
                        <span class="eye-btn" onclick="togglePassword()">
                            <svg id="eye-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </span>
                    </div>
                </div>

                <div class="form-options">
                    <label class="checkbox-container">
                        <input type="checkbox" name="agree_terms">
                        <div class="checkmark"></div>
                        <span>Tôi đồng ý với <a href="terms_of_service.php" target="_blank">Điều khoản Dịch vụ</a></span>
                    </label>
                </div>

                <button type="submit" class="submit-btn">
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
        errorContainer.innerHTML = '<div class="error-msg"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <span>Bạn phải đồng ý với Điều khoản Dịch vụ để đăng nhập.</span></div>';
        errorContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return false;
    }
    const btn = document.querySelector('.submit-btn');
    if (btn) {
        btn.innerHTML = '<span><span style="display:inline-block; width:14px; height:14px; border:2px solid #fff; border-top-color:transparent; border-radius:50%; animation: spin 0.8s linear infinite; vertical-align:middle; margin-right:8px;"></span> Đang xác thực...</span>';
        btn.style.opacity = '0.85';
        btn.style.pointerEvents = 'none';
    }
    return true;
}
</script>
</body>
</html>
