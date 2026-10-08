<?php
session_start();
$is_logged_in = isset($_SESSION['account_id']);
if ($is_logged_in) {
    $current_page = 'terms';
    require_once __DIR__ . '/includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service - HONGDOLABS</title>
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
    /* ─── evondev UI/UX Premium Legal Page Layout ─── */
    .terms-hero-card {
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
    .terms-hero-card::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 260px;
        height: 260px;
        background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, transparent 70%);
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
        background: rgba(99, 102, 241, 0.2);
        border: 1px solid rgba(129, 140, 248, 0.3);
        color: #a5b4fc;
        font-size: 12px;
        font-weight: 600;
        border-radius: 20px;
        margin-bottom: 12px;
    }
    .terms-hero-card h1 {
        font-size: 28px;
        font-weight: 800;
        color: #ffffff;
        margin: 0 0 10px;
        letter-spacing: -0.02em;
    }
    .terms-hero-card p {
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
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
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
        color: #4f46e5;
        background: rgba(99, 102, 241, 0.08);
    }
    .toc-link .toc-num {
        width: 22px;
        height: 22px;
        border-radius: 6px;
        background: rgba(99, 102, 241, 0.1);
        color: #6366f1;
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
        border-color: rgba(99, 102, 241, 0.3);
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
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.12) 0%, rgba(79, 70, 229, 0.08) 100%);
        color: #4f46e5;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
        flex-shrink: 0;
        border: 1px solid rgba(99, 102, 241, 0.2);
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
        background: rgba(99, 102, 241, 0.06);
        border: 1px solid rgba(99, 102, 241, 0.2);
        border-left: 4px solid #6366f1;
        border-radius: 10px;
        padding: 14px 18px;
        margin-top: 14px;
        font-size: 13px;
        color: var(--text-main, #1e293b);
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .callout-box.warning {
        background: rgba(245, 158, 11, 0.08);
        border-color: rgba(245, 158, 11, 0.25);
        border-left-color: #f59e0b;
    }
</style>

<!-- Hero Section -->
<div class="terms-hero-card">
    <div class="hero-content-group">
        <div class="hero-badge-pill">📜 Legal Agreement & Terms</div>
        <h1 class="lang-vi">Điều khoản Dịch vụ</h1>
        <h1 class="lang-en" style="display:none;">Terms of Service</h1>
        <p class="lang-vi">Quy định sử dụng và điều khoản thỏa thuận khi truy cập hệ thống Quản lý Fanpage & Tự động hóa nội dung đa nền tảng.</p>
        <p class="lang-en" style="display:none;">Rules, obligations, and service terms governing your access to our Fanpage Management & Content Automation Platform.</p>
        <div class="hero-meta-time">
            <span>🕒 <span class="lang-vi">Cập nhật lần cuối: <?php echo date('d/m/Y'); ?></span><span class="lang-en" style="display:none;">Last updated: <?php echo date('F j, Y'); ?></span></span>
            <span>•</span>
            <span>🌐 Meta Graph API Compliant</span>
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
                    <span class="lang-vi">Chấp nhận Điều khoản</span>
                    <span class="lang-en" style="display:none;">Acceptance of Terms</span>
                </a>
            </li>
            <li>
                <a href="#sec-2" class="toc-link">
                    <span class="toc-num">2</span>
                    <span class="lang-vi">Mô tả Dịch vụ</span>
                    <span class="lang-en" style="display:none;">Description of Service</span>
                </a>
            </li>
            <li>
                <a href="#sec-3" class="toc-link">
                    <span class="toc-num">3</span>
                    <span class="lang-vi">Trách nhiệm Người dùng</span>
                    <span class="lang-en" style="display:none;">User Responsibilities</span>
                </a>
            </li>
            <li>
                <a href="#sec-4" class="toc-link">
                    <span class="toc-num">4</span>
                    <span class="lang-vi">Giới hạn Graph API</span>
                    <span class="lang-en" style="display:none;">API Limitations</span>
                </a>
            </li>
            <li>
                <a href="#sec-5" class="toc-link">
                    <span class="toc-num">5</span>
                    <span class="lang-vi">Chấm dứt Dịch vụ</span>
                    <span class="lang-en" style="display:none;">Service Termination</span>
                </a>
            </li>
            <li>
                <a href="#sec-6" class="toc-link">
                    <span class="toc-num">6</span>
                    <span class="lang-vi">Thay đổi Điều khoản</span>
                    <span class="lang-en" style="display:none;">Changes to Terms</span>
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
                    <h2 class="lang-vi">1. Chấp nhận Điều khoản</h2>
                    <h2 class="lang-en" style="display:none;">1. Acceptance of Terms</h2>
                </div>
            </div>
            <p class="lang-vi">Bằng việc truy cập và sử dụng hệ thống Quản lý đa nền tảng này, bạn chấp nhận và đồng ý bị ràng buộc bởi các điều khoản và quy định của thỏa thuận này.</p>
            <p class="lang-en" style="display:none;">By accessing and using this Fanpage Management system, you accept and agree to be bound by the terms and provisions of this agreement.</p>
            <div class="callout-box">
                <span>💡</span>
                <span class="lang-vi">Nếu bạn không đồng ý với bất kỳ phần nào của các điều khoản này, vui lòng ngừng sử dụng dịch vụ của chúng tôi ngay lập tức.</span>
                <span class="lang-en" style="display:none;">If you do not agree to abide by these terms, please do not access or use this service.</span>
            </div>
        </div>

        <!-- Section 2 -->
        <div class="legal-section-card" id="sec-2">
            <div class="legal-card-header">
                <div class="section-icon-badge">2</div>
                <div>
                    <h2 class="lang-vi">2. Mô tả Dịch vụ</h2>
                    <h2 class="lang-en" style="display:none;">2. Description of Service</h2>
                </div>
            </div>
            <p class="lang-vi">Chúng tôi cung cấp một giao diện web cho phép người dùng quản lý nhiều Fanpage Facebook, đăng nội dung, tự động hóa nội dung và lên lịch tải lên bài viết / video thông qua Facebook Graph API chính thức.</p>
            <p class="lang-en" style="display:none;">We provide a web-based platform allowing users to manage multiple Facebook Fanpages, post content, automate publishing, and schedule uploads directly through the official Facebook Graph API.</p>
        </div>

        <!-- Section 3 -->
        <div class="legal-section-card" id="sec-3">
            <div class="legal-card-header">
                <div class="section-icon-badge">3</div>
                <div>
                    <h2 class="lang-vi">3. Trách nhiệm của Người dùng</h2>
                    <h2 class="lang-en" style="display:none;">3. User Responsibilities</h2>
                </div>
            </div>
            <p class="lang-vi">Bạn chịu trách nhiệm hoàn toàn về mọi hoạt động diễn ra dưới tài khoản của mình. Bạn đồng ý không sử dụng hệ thống cho bất kỳ mục đích bất hợp pháp, phát tán tin giả hay vi phạm tiêu chuẩn cộng đồng nào. Bạn phải tuân thủ tất cả luật pháp địa phương và Chính sách Nền tảng của Meta / Facebook.</p>
            <p class="lang-en" style="display:none;">You are responsible for any activity that occurs under your account. You agree not to use the system for any illegal, spam, or unauthorized purposes. You must strictly comply with all applicable local laws and Meta / Facebook Platform Policies.</p>
            <div class="callout-box warning">
                <span>⚠️</span>
                <span class="lang-vi">Mọi hành vi lạm dụng spam bài viết hoặc vi phạm bản quyền nội dung có thể dẫn đến việc khóa tài khoản vĩnh viễn.</span>
                <span class="lang-en" style="display:none;">Any abuse involving aggressive post spamming or copyright infringement may lead to immediate account suspension.</span>
            </div>
        </div>

        <!-- Section 4 -->
        <div class="legal-section-card" id="sec-4">
            <div class="legal-card-header">
                <div class="section-icon-badge">4</div>
                <div>
                    <h2 class="lang-vi">4. Giới hạn API</h2>
                    <h2 class="lang-en" style="display:none;">4. API Limitations</h2>
                </div>
            </div>
            <p class="lang-vi">Dịch vụ của chúng tôi phụ thuộc trực tiếp vào Facebook Graph API. Chúng tôi không chịu trách nhiệm về bất kỳ thời gian gián đoạn (downtime), thay đổi chính sách API hoặc giới hạn tần suất (rate limits) nào do Facebook áp đặt có thể ảnh hưởng đến chức năng của ứng dụng này.</p>
            <p class="lang-en" style="display:none;">Our service relies heavily on the Facebook Graph API. We are not liable for any service downtime, API structural updates, or rate limits imposed by Facebook that may temporarily affect application features.</p>
        </div>

        <!-- Section 5 -->
        <div class="legal-section-card" id="sec-5">
            <div class="legal-card-header">
                <div class="section-icon-badge">5</div>
                <div>
                    <h2 class="lang-vi">5. Chấm dứt Dịch vụ</h2>
                    <h2 class="lang-en" style="display:none;">5. Service Termination</h2>
                </div>
            </div>
            <p class="lang-vi">Chúng tôi có quyền đình chỉ hoặc chấm dứt quyền truy cập của bạn vào ứng dụng bất cứ lúc nào với bất kỳ lý do gì, đặc biệt là nếu bạn vi phạm các Điều khoản Dịch vụ này hoặc Điều khoản Nền tảng của Facebook.</p>
            <p class="lang-en" style="display:none;">We reserve the right to suspend or terminate your access to the application at any time for any reason, particularly if you violate these Terms of Service or Facebook's Platform Policies.</p>
        </div>

        <!-- Section 6 -->
        <div class="legal-section-card" id="sec-6">
            <div class="legal-card-header">
                <div class="section-icon-badge">6</div>
                <div>
                    <h2 class="lang-vi">6. Thay đổi Điều khoản</h2>
                    <h2 class="lang-en" style="display:none;">6. Changes to Terms</h2>
                </div>
            </div>
            <p class="lang-vi">Chúng tôi có quyền sửa đổi các điều khoản này bất cứ lúc nào. Việc bạn tiếp tục sử dụng dịch vụ sau những thay đổi đó cấu thành sự chấp nhận của bạn đối với Điều khoản Dịch vụ mới.</p>
            <p class="lang-en" style="display:none;">We reserve the right to modify these terms at any time. Your continued use of the service after any such changes constitutes your acceptance of the newly updated Terms of Service.</p>
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
