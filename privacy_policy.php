<?php
session_start();
$is_logged_in = isset($_SESSION['account_id']);
if ($is_logged_in) {
    $current_page = 'privacy';
    require_once __DIR__ . '/includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - HONGDOLABS</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { 
            background: var(--bg-color, #f8fafc); 
            color: var(--text-main, #1e293b); 
            font-family: 'Be Vietnam Pro', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .public-wrapper {
            max-width: 1140px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }
        .public-top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color, #e2e8f0);
        }
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            font-size: 18px;
            color: var(--text-main, #0f172a);
            text-decoration: none;
        }
        .brand-logo img {
            width: 32px;
            height: 32px;
            border-radius: 8px;
        }
        .btn-back-login {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: rgba(99, 102, 241, 0.1);
            color: #4f46e5;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-back-login:hover {
            background: #4f46e5;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="public-wrapper">
        <div class="public-top-nav">
            <a href="login.php" class="brand-logo">
                <img src="logo.png" alt="Logo" onerror="this.style.display='none'">
                <span>HONGDOLABS</span>
            </a>
            <a href="login.php" class="btn-back-login">← Quay lại Đăng nhập</a>
        </div>
<?php } ?>

<style>
    /* ─── evondev UI/UX Premium Privacy Policy Layout ─── */
    .privacy-hero-card {
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
        border: 1px solid rgba(99, 102, 241, 0.25);
        border-radius: 20px;
        padding: 36px 40px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 30px -5px rgba(15, 23, 42, 0.3);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 24px;
        flex-wrap: wrap;
    }
    .privacy-hero-card::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 260px;
        height: 260px;
        background: radial-gradient(circle, rgba(16, 185, 129, 0.25) 0%, transparent 70%);
        pointer-events: none;
    }
    .hero-content-group {
        max-width: 680px;
        position: relative;
        z-index: 1;
    }
    .hero-badge-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        background: rgba(16, 185, 129, 0.18);
        border: 1px solid rgba(52, 211, 153, 0.3);
        color: #6ee7b7;
        font-size: 12px;
        font-weight: 600;
        border-radius: 20px;
        margin-bottom: 12px;
    }
    .privacy-hero-card h1 {
        font-size: 28px;
        font-weight: 800;
        color: #ffffff;
        margin: 0 0 10px;
        letter-spacing: -0.02em;
    }
    .privacy-hero-card p {
        font-size: 14px;
        color: #cbd5e1;
        margin: 0 0 16px;
        line-height: 1.6;
    }
    .hero-meta-time {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: #94a3b8;
    }

    /* Language Toggle Control */
    .lang-switcher-pill {
        display: inline-flex;
        background: rgba(15, 23, 42, 0.6);
        padding: 4px;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(8px);
        position: relative;
        z-index: 2;
    }
    .lang-btn {
        padding: 8px 18px;
        border: none;
        background: transparent;
        color: #94a3b8;
        font-size: 13px;
        font-weight: 700;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .lang-btn.active {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    }

    /* Layout Grid: Toc & Main Content */
    .legal-grid-layout {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 28px;
        align-items: start;
    }
    @media (max-width: 900px) {
        .legal-grid-layout {
            grid-template-columns: 1fr;
        }
    }

    /* Table of Contents Sticky Sidebar */
    .toc-sticky-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 16px;
        padding: 20px;
        position: sticky;
        top: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .toc-title {
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted, #64748b);
        margin-bottom: 14px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .toc-nav-list {
        display: flex;
        flex-direction: column;
        gap: 4px;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .toc-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        font-size: 13px;
        font-weight: 600;
        color: var(--text-muted, #64748b);
        text-decoration: none;
        border-radius: 8px;
        transition: all 0.2s ease;
    }
    .toc-link:hover, .toc-link.active {
        color: #059669;
        background: rgba(16, 185, 129, 0.08);
    }
    .toc-link .toc-num {
        width: 22px;
        height: 22px;
        border-radius: 6px;
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
        flex-shrink: 0;
    }

    /* Legal Section Cards */
    .legal-content-container {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .legal-section-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 16px;
        padding: 28px 32px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        scroll-margin-top: 30px;
    }
    .legal-section-card:hover {
        border-color: rgba(16, 185, 129, 0.3);
        box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.06);
    }
    .legal-card-header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 16px;
    }
    .section-icon-badge {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.12) 0%, rgba(5, 150, 105, 0.08) 100%);
        color: #059669;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
        flex-shrink: 0;
        border: 1px solid rgba(16, 185, 129, 0.2);
    }
    .legal-card-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--text-main, #0f172a);
        margin: 0;
    }
    .legal-section-card p {
        font-size: 14px;
        line-height: 1.7;
        color: var(--text-main, #334155);
        margin: 0 0 12px;
    }
    .legal-section-card p:last-child {
        margin-bottom: 0;
    }

    /* Alert / Notice callouts */
    .callout-box {
        background: rgba(16, 185, 129, 0.06);
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-left: 4px solid #10b981;
        border-radius: 10px;
        padding: 14px 18px;
        margin-top: 14px;
        font-size: 13px;
        color: var(--text-main, #1e293b);
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .callout-box.info {
        background: rgba(14, 165, 233, 0.06);
        border-color: rgba(14, 165, 233, 0.2);
        border-left-color: #0ea5e9;
    }
</style>

<!-- Hero Section -->
<div class="privacy-hero-card">
    <div class="hero-content-group">
        <div class="hero-badge-pill">🛡️ Data Protection & Privacy Standard</div>
        <h1 class="lang-vi">Chính sách Bảo mật</h1>
        <h1 class="lang-en" style="display:none;">Privacy Policy</h1>
        <p class="lang-vi">Cam kết minh bạch về cách thu thập, lưu trữ an toàn và bảo mật thông tin tài khoản & Access Token Facebook của bạn.</p>
        <p class="lang-en" style="display:none;">Transparency report on how we collect, securely store, and protect your account data and Facebook Access Tokens.</p>
        <div class="hero-meta-time">
            <span>🕒 <span class="lang-vi">Cập nhật lần cuối: <?php echo date('d/m/Y'); ?></span><span class="lang-en" style="display:none;">Last updated: <?php echo date('F j, Y'); ?></span></span>
            <span>•</span>
            <span>🔒 AES Secure Encrypted Token Storage</span>
        </div>
    </div>
    <div class="lang-switcher-pill">
        <button onclick="setLang('vi')" id="btn-vi" class="lang-btn active">🇻🇳 VN</button>
        <button onclick="setLang('en')" id="btn-en" class="lang-btn">🇺🇸 EN</button>
    </div>
</div>

<!-- Main 2-Column Grid -->
<div class="legal-grid-layout">
    <!-- TOC Sidebar -->
    <div class="toc-sticky-card">
        <div class="toc-title">
            <span>📑</span>
            <span class="lang-vi">Mục lục Nội dung</span>
            <span class="lang-en" style="display:none;">Table of Contents</span>
        </div>
        <ul class="toc-nav-list">
            <li>
                <a href="#sec-1" class="toc-link">
                    <span class="toc-num">1</span>
                    <span class="lang-vi">Thông tin Thu thập</span>
                    <span class="lang-en" style="display:none;">Information We Collect</span>
                </a>
            </li>
            <li>
                <a href="#sec-2" class="toc-link">
                    <span class="toc-num">2</span>
                    <span class="lang-vi">Cách Sử dụng Thông tin</span>
                    <span class="lang-en" style="display:none;">How We Use Information</span>
                </a>
            </li>
            <li>
                <a href="#sec-3" class="toc-link">
                    <span class="toc-num">3</span>
                    <span class="lang-vi">Chia sẻ Dữ liệu</span>
                    <span class="lang-en" style="display:none;">Data Sharing</span>
                </a>
            </li>
            <li>
                <a href="#sec-4" class="toc-link">
                    <span class="toc-num">4</span>
                    <span class="lang-vi">Lưu giữ & Xóa Dữ liệu</span>
                    <span class="lang-en" style="display:none;">Data Retention & Deletion</span>
                </a>
            </li>
            <li>
                <a href="#sec-5" class="toc-link">
                    <span class="toc-num">5</span>
                    <span class="lang-vi">Bảo mật & Mã hóa</span>
                    <span class="lang-en" style="display:none;">Security & Encryption</span>
                </a>
            </li>
            <li>
                <a href="#sec-6" class="toc-link">
                    <span class="toc-num">6</span>
                    <span class="lang-vi">Liên hệ & Hỗ trợ</span>
                    <span class="lang-en" style="display:none;">Contact Us</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Section Content -->
    <div class="legal-content-container">
        <!-- Section 1 -->
        <div class="legal-section-card" id="sec-1">
            <div class="legal-card-header">
                <div class="section-icon-badge">1</div>
                <div>
                    <h2 class="lang-vi">1. Thông tin Chúng tôi Thu thập</h2>
                    <h2 class="lang-en" style="display:none;">1. Information We Collect</h2>
                </div>
            </div>
            <p class="lang-vi">Ứng dụng của chúng tôi thu thập ID Người dùng Facebook, Tên hiển thị và Token Truy cập Trang (Page Access Token) của bạn để cung cấp dịch vụ Quản lý Fanpage & Đăng bài tự động. Chúng tôi tuyệt đối KHÔNG thu thập mật khẩu tài khoản cá nhân hoặc bất kỳ dữ liệu riêng tư nào khác ngoài phạm vi bạn ủy quyền qua Facebook Login.</p>
            <p class="lang-en" style="display:none;">Our application collects your Facebook User ID, Name, and Page Access Tokens solely to provide Fanpage Management & Auto-publishing services. We NEVER collect personal passwords or any data outside of what you explicitly authorize via Facebook Login.</p>
            <div class="callout-box">
                <span>🛡️</span>
                <span class="lang-vi">Chúng tôi không bao giờ yêu cầu mật khẩu Facebook cá nhân của bạn dưới bất kỳ hình thức nào.</span>
                <span class="lang-en" style="display:none;">We never ask for your personal Facebook account password under any circumstances.</span>
            </div>
        </div>

        <!-- Section 2 -->
        <div class="legal-section-card" id="sec-2">
            <div class="legal-card-header">
                <div class="section-icon-badge">2</div>
                <div>
                    <h2 class="lang-vi">2. Cách Chúng tôi Sử dụng Thông tin</h2>
                    <h2 class="lang-en" style="display:none;">2. How We Use Your Information</h2>
                </div>
            </div>
            <p class="lang-vi">Chúng tôi sử dụng thông tin thu thập được chỉ nhằm mục đích cho phép bạn quản lý, xuất bản bài viết, tải lên Reels/Video và lên lịch nội dung trên các Fanpage Facebook của mình từ bảng điều khiển. Token Truy cập Trang của bạn được lưu trữ mã hóa an toàn và chỉ được sử dụng để thực hiện các lệnh do chính bạn khởi xướng trên hệ thống.</p>
            <p class="lang-en" style="display:none;">We use collected information strictly to enable managing, publishing, uploading Reels/Videos, and scheduling content across your Facebook Fanpages from our dashboard. Your Page Access Tokens are securely stored and only executed for actions initiated by you.</p>
        </div>

        <!-- Section 3 -->
        <div class="legal-section-card" id="sec-3">
            <div class="legal-card-header">
                <div class="section-icon-badge">3</div>
                <div>
                    <h2 class="lang-vi">3. Chia sẻ Dữ liệu</h2>
                    <h2 class="lang-en" style="display:none;">3. Data Sharing</h2>
                </div>
            </div>
            <p class="lang-vi">Chúng tôi cam kết KHÔNG chia sẻ, bán, thương mại hóa hoặc phân phối dữ liệu của bạn cho bất kỳ bên thứ ba nào. Mọi tương tác dữ liệu đều diễn ra trực tiếp thông qua kết nối bảo mật giữa máy chủ của chúng tôi và Facebook Graph API chính thức.</p>
            <p class="lang-en" style="display:none;">We do not share, sell, trade, or distribute your data to any third parties. All data transmissions take place directly between our secured server and the official Meta / Facebook Graph API.</p>
        </div>

        <!-- Section 4 -->
        <div class="legal-section-card" id="sec-4">
            <div class="legal-card-header">
                <div class="section-icon-badge">4</div>
                <div>
                    <h2 class="lang-vi">4. Lưu giữ & Xóa Dữ liệu</h2>
                    <h2 class="lang-en" style="display:none;">4. Data Retention & Deletion</h2>
                </div>
            </div>
            <p class="lang-vi">Token truy cập và dữ liệu trang được liên kết của bạn sẽ được lưu giữ chừng nào bạn còn duy trì tài khoản hoạt động trên hệ thống. Bạn có thể xóa token của mình bất cứ lúc nào từ phần <b>Quản lý Token</b>, thao tác này sẽ lập tức xóa vĩnh viễn token và các bản ghi fanpage liên quan khỏi cơ sở dữ liệu.</p>
            <p class="lang-en" style="display:none;">Your access tokens and associated page records are retained as long as you maintain an active account with us. You can delete your tokens at any time from the <b>Token Management</b> section, which will permanently purge the tokens and fanpage records from our database.</p>
            <div class="callout-box info">
                <span>🗑️</span>
                <span class="lang-vi">Quyền kiểm soát dữ liệu hoàn toàn thuộc về bạn. Việc xóa Token trên giao diện sẽ kích hoạt xóa sạch toàn bộ dữ liệu liên quan ngay lập tức.</span>
                <span class="lang-en" style="display:none;">You maintain full ownership of your data. Deleting tokens from the interface immediately purges all associated records.</span>
            </div>
        </div>

        <!-- Section 5 -->
        <div class="legal-section-card" id="sec-5">
            <div class="legal-card-header">
                <div class="section-icon-badge">5</div>
                <div>
                    <h2 class="lang-vi">5. Bảo mật & Mã hóa</h2>
                    <h2 class="lang-en" style="display:none;">5. Security</h2>
                </div>
            </div>
            <p class="lang-vi">Chúng tôi áp dụng các chuẩn mực bảo mật cao cấp nhất để bảo vệ dữ liệu của bạn. Tất cả kết nối dữ liệu đều được mã hóa SSL/TLS, cơ sở dữ liệu được bảo vệ nghiêm ngặt và phân quyền chỉ cho phép tài khoản hợp lệ truy cập vào các token sở hữu.</p>
            <p class="lang-en" style="display:none;">We implement industry-standard security measures to safeguard your information. All connections enforce SSL/TLS encryption, and access controls guarantee that only authenticated users can access their authorized tokens.</p>
        </div>

        <!-- Section 6 -->
        <div class="legal-section-card" id="sec-6">
            <div class="legal-card-header">
                <div class="section-icon-badge">6</div>
                <div>
                    <h2 class="lang-vi">6. Liên hệ & Hỗ trợ</h2>
                    <h2 class="lang-en" style="display:none;">6. Contact Us</h2>
                </div>
            </div>
            <p class="lang-vi">Nếu bạn có bất kỳ thắc mắc, đóng góp ý kiến hoặc yêu cầu hỗ trợ nào liên quan đến Chính sách Bảo mật này hoặc quy trình xử lý dữ liệu, vui lòng liên hệ với Quản trị viên hệ thống HONGDOLABS.</p>
            <p class="lang-en" style="display:none;">If you have any questions, feedback, or inquiries regarding this Privacy Policy or how data is processed, please contact the HONGDOLABS system administrator.</p>
        </div>
    </div>
</div>

<script>
function setLang(lang) {
    const isEn = (lang === 'en');
    document.querySelectorAll('.lang-en').forEach(el => el.style.display = isEn ? 'inline' : 'none');
    document.querySelectorAll('.lang-vi').forEach(el => el.style.display = isEn ? 'none' : 'inline');
    
    // Switch block display for elements like headings & paragraphs
    document.querySelectorAll('h1.lang-en, h1.lang-vi, h2.lang-en, h2.lang-vi, p.lang-en, p.lang-vi, span.lang-en, span.lang-vi').forEach(el => {
        if (el.tagName === 'H1' || el.tagName === 'H2' || el.tagName === 'P') {
            const elIsEn = el.classList.contains('lang-en');
            el.style.display = (isEn && elIsEn) || (!isEn && !elIsEn) ? 'block' : 'none';
        }
    });

    const btnEn = document.getElementById('btn-en');
    const btnVi = document.getElementById('btn-vi');
    if (btnEn && btnVi) {
        if (isEn) {
            btnEn.classList.add('active');
            btnVi.classList.remove('active');
        } else {
            btnVi.classList.add('active');
            btnEn.classList.remove('active');
        }
    }
    localStorage.setItem('pref_lang', lang);
}

document.addEventListener('DOMContentLoaded', function() {
    const pref = localStorage.getItem('pref_lang') || 'vi';
    setLang(pref);
});
</script>

<?php 
if ($is_logged_in) {
    include 'includes/footer.php';
} else {
?>
    </div>
</body>
</html>
<?php } ?>
