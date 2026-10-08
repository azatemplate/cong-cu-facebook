<!-- includes/sidebar.php -->
<aside class="sidebar">
    <!-- Brand Header -->
    <div class="sidebar-header-box">
        <div class="sidebar-logo-icon">
            <img src="logo.png" alt="HONGDOLABS Logo">
        </div>
        <div class="sidebar-brand-info menu-label">
            <div class="brand-name">HONGDOLABS</div>
            <div class="brand-tagline">VERSION 2.0 PRO</div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <ul class="sidebar-menu">

        <!-- SECTION 1: TỔNG QUAN & CHIẾN DỊCH -->
        <li class="menu-section-header menu-label">TỔNG QUAN &amp; CHIẾN DỊCH</li>
        
        <li class="<?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>">
            <a href="index.php" data-tooltip="Dashboard">
                <span class="icon">⏱</span>
                <span class="menu-label">Dashboard</span>
            </a>
        </li>
        <li class="<?php echo ($current_page == 'fanpages') ? 'active' : ''; ?>">
            <a href="fanpages.php" data-tooltip="Fanpages">
                <span class="icon">📱</span>
                <span class="menu-label">Fanpages</span>
            </a>
        </li>
        <li class="<?php echo ($current_page == 'manage_posts' || $current_page == 'campaign_detail') ? 'active' : ''; ?>">
            <a href="manage_posts.php" data-tooltip="Campaigns">
                <span class="icon">📋</span>
                <span class="menu-label">Campaigns</span>
            </a>
        </li>

        <!-- SECTION 2: ĐĂNG BÀI ĐA NỀN TẢNG -->
        <li class="menu-section-header menu-label">ĐĂNG BÀI ĐA NỀN TẢNG</li>

        <!-- Facebook Post Collapsible Submenu -->
        <li class="has-submenu <?php echo (in_array($current_page, ['posts', 'videos', 'reels', 'story', 'facebook_scraper', 'comment_posts'])) ? 'active open' : ''; ?>">
            <a href="javascript:void(0);" onclick="toggleSidebarSubmenu(this)" data-tooltip="Facebook Post" class="submenu-toggle-btn">
                <span class="submenu-left">
                    <span class="icon">📘</span>
                    <span class="menu-label">Facebook Post</span>
                </span>
                <svg class="submenu-arrow menu-label" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </a>
            <ul class="sidebar-submenu" style="<?php echo (in_array($current_page, ['posts', 'videos', 'reels', 'story', 'facebook_scraper', 'comment_posts'])) ? 'display:block;' : 'display:none;'; ?>">
                <li class="<?php echo ($current_page == 'posts') ? 'active' : ''; ?>">
                    <a href="posts.php" data-tooltip="Post Ảnh"><span class="icon">🖼️</span><span class="menu-label">Post Ảnh</span></a>
                </li>
                <li class="<?php echo ($current_page == 'videos') ? 'active' : ''; ?>">
                    <a href="videos.php" data-tooltip="Post Video"><span class="icon">📺</span><span class="menu-label">Post Video</span></a>
                </li>
                <li class="<?php echo ($current_page == 'reels') ? 'active' : ''; ?>">
                    <a href="reels.php" data-tooltip="Post Reels"><span class="icon">🎞️</span><span class="menu-label">Post Reels</span></a>
                </li>
                <li class="<?php echo ($current_page == 'story') ? 'active' : ''; ?>">
                    <a href="story.php" data-tooltip="Post Story"><span class="icon">▶️</span><span class="menu-label">Post Story</span></a>
                </li>
                <li class="<?php echo ($current_page == 'facebook_scraper') ? 'active' : ''; ?>">
                    <a href="facebook_scraper.php" data-tooltip="Facebook Scraper"><span class="icon">🕸️</span><span class="menu-label">Facebook Scraper</span></a>
                </li>
                <li class="<?php echo ($current_page == 'comment_posts') ? 'active' : ''; ?>">
                    <a href="comment_posts.php" data-tooltip="Comment Post"><span class="icon">💬</span><span class="menu-label">Comment Post</span></a>
                </li>
            </ul>
        </li>

        <li class="<?php echo ($current_page == 'instagram') ? 'active' : ''; ?>">
            <a href="instagram.php" data-tooltip="Instagram Post"><span class="icon">📸</span><span class="menu-label">Instagram Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'tiktok') ? 'active' : ''; ?>">
            <a href="tiktok.php" data-tooltip="TikTok Post"><span class="icon">🎵</span><span class="menu-label">TikTok Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'youtube') ? 'active' : ''; ?>">
            <a href="youtube.php" data-tooltip="YouTube Post"><span class="icon">🚀</span><span class="menu-label">YouTube Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'buffer' || $current_page == 'buffer_posts' || $current_page == 'buffer_channels') ? 'active' : ''; ?>">
            <a href="buffer.php" data-tooltip="Buffer Post"><span class="icon">📡</span><span class="menu-label">Buffer Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'tiktok_search') ? 'active' : ''; ?>">
            <a href="tiktok_search.php" data-tooltip="Kho Data"><span class="icon">📁</span><span class="menu-label">Kho Data</span></a>
        </li>

        <!-- SECTION 3: THỐNG KÊ & CSKH -->
        <li class="menu-section-header menu-label">THỐNG KÊ &amp; CSKH</li>

        <li class="<?php echo ($current_page == 'insights') ? 'active' : ''; ?>">
            <a href="insights.php" data-tooltip="Insights"><span class="icon">📊</span><span class="menu-label">Insights</span></a>
        </li>
        <li class="<?php echo ($current_page == 'growth') ? 'active' : ''; ?>">
            <a href="growth.php" data-tooltip="Growth"><span class="icon">📈</span><span class="menu-label">Growth</span></a>
        </li>
        <li class="<?php echo ($current_page == 'earnings') ? 'active' : ''; ?>">
            <a href="earnings.php" data-tooltip="Earnings"><span class="icon">💰</span><span class="menu-label">Earnings</span></a>
        </li>

        <?php
        $sb_user_id = $_SESSION['account_id'] ?? 0;
        $sb_is_admin = (($_SESSION['role'] ?? '') === 'admin');
        $sb_enable_live_chat = 1;
        $sb_enable_live_chat_oa = 1;
        $sb_enable_live_chat_tiktok = 1;
        $sb_enable_website = 1;
        $sb_enable_customers = 1;

        if (!$sb_is_admin && $sb_user_id > 0 && isset($pdo)) {
            try {
                $st_sb = $pdo->prepare("SELECT enable_live_chat, enable_live_chat_oa, enable_live_chat_tiktok, enable_website, enable_customers FROM system_accounts WHERE id = ?");
                $st_sb->execute([$sb_user_id]);
                $sb_f = $st_sb->fetch(PDO::FETCH_ASSOC);
                if ($sb_f) {
                    $sb_enable_live_chat = (int)($sb_f['enable_live_chat'] ?? 1);
                    $sb_enable_live_chat_oa = (int)($sb_f['enable_live_chat_oa'] ?? 1);
                    $sb_enable_live_chat_tiktok = (int)($sb_f['enable_live_chat_tiktok'] ?? 1);
                    $sb_enable_website = (int)($sb_f['enable_website'] ?? 1);
                    $sb_enable_customers = (int)($sb_f['enable_customers'] ?? 1);
                }
            } catch (Exception $e) {}
        }

        $sb_first_chat_page = '';
        if ($sb_enable_live_chat) $sb_first_chat_page = 'live_chat.php';
        elseif ($sb_enable_live_chat_oa) $sb_first_chat_page = 'live-chat-oa.php';
        elseif ($sb_enable_website) $sb_first_chat_page = 'website.php';
        elseif ($sb_enable_customers) $sb_first_chat_page = 'customers.php';
        ?>

        <?php if (!empty($sb_first_chat_page)): ?>
        <li class="<?php echo (in_array($current_page, ['live_chat', 'live_chat_zalo', 'website', 'customers'])) ? 'active' : ''; ?>">
            <a href="<?php echo $sb_first_chat_page; ?>" data-tooltip="Live Chat"><span class="icon">💬</span><span class="menu-label">Live Chat</span></a>
        </li>
        <?php endif; ?>

        <li class="<?php echo ($current_page == 'live_comments') ? 'active' : ''; ?>">
            <a href="live_comments.php" data-tooltip="Live Comments"><span class="icon">📝</span><span class="menu-label">Live Comments</span></a>
        </li>

        <!-- SECTION 4: QUẢN TRỊ & HỆ THỐNG -->
        <li class="menu-section-header menu-label">QUẢN TRỊ &amp; HỆ THỐNG</li>

        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <li class="<?php echo ($current_page == 'accounts') ? 'active' : ''; ?>">
            <a href="accounts.php" data-tooltip="User Management"><span class="icon">👥</span><span class="menu-label">User Management</span></a>
        </li>
        <?php endif; ?>
        <li class="<?php echo ($current_page == 'token') ? 'active' : ''; ?>">
            <a href="token_management.php" data-tooltip="Token Management"><span class="icon">🔑</span><span class="menu-label">Token Management</span></a>
        </li>
        <li class="<?php echo ($current_page == 'proxy') ? 'active' : ''; ?>">
            <a href="proxy.php" data-tooltip="Kho Proxy"><span class="icon">🌐</span><span class="menu-label">Kho Proxy</span></a>
        </li>
        <li class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>">
            <a href="settings.php" data-tooltip="Settings"><span class="icon">⚙️</span><span class="menu-label">Settings</span></a>
        </li>
        <li class="<?php echo ($current_page == 'ai_settings') ? 'active' : ''; ?>">
            <a href="ai_settings.php" data-tooltip="AI Configuration"><span class="icon">🤖</span><span class="menu-label">AI Configuration</span></a>
        </li>
        <li class="<?php echo ($current_page == 'privacy') ? 'active' : ''; ?>">
            <a href="privacy_policy.php" data-tooltip="Privacy Policy"><span class="icon">📜</span><span class="menu-label">Privacy Policy</span></a>
        </li>
        <li class="<?php echo ($current_page == 'terms') ? 'active' : ''; ?>">
            <a href="terms_of_service.php" data-tooltip="Terms of Service"><span class="icon">⚖️</span><span class="menu-label">Terms of Service</span></a>
        </li>

        <!-- LOGOUT -->
        <li class="logout-item">
            <a href="logout.php" data-tooltip="Logout"><span class="icon">⎋</span><span class="menu-label">Logout</span></a>
        </li>
    </ul>
</aside>

<style>
/* ── Refactored Sidebar Styling (evondevKit Slate/Indigo Theme) ── */
.sidebar {
    font-family: 'Be Vietnam Pro', sans-serif;
}

/* Brand Header */
.sidebar-header-box {
    height: 70px;
    padding: 0 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid var(--border-color);
}
.sidebar-logo-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: #ffffff;
    padding: 4px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.sidebar-logo-icon img {
    width: 100%; height: 100%;
    object-fit: contain;
    border-radius: 6px;
}
.sidebar-brand-info .brand-name {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 16px;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: 0.5px;
    line-height: 1.2;
}
.sidebar-brand-info .brand-tagline {
    font-size: 9.5px;
    font-weight: 800;
    color: #4f46e5;
    letter-spacing: 1px;
    background: #eef2ff;
    padding: 1px 6px;
    border-radius: 4px;
    display: inline-block;
    margin-top: 2px;
}

/* Menu Section Headers */
.menu-section-header {
    font-size: 10px;
    font-weight: 800;
    color: #94a3b8;
    letter-spacing: 1.2px;
    padding: 18px 20px 6px;
    pointer-events: none;
    user-select: none;
}
.sidebar.collapsed .menu-section-header {
    display: none !important;
}

/* Menu Items & Links */
.sidebar-menu {
    padding: 10px 12px;
}
.sidebar-menu li {
    margin-bottom: 3px;
}
.sidebar-menu li a {
    display: flex;
    align-items: center;
    padding: 10px 14px;
    border-radius: 10px;
    color: #475569;
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 600;
    transition: all 0.2s ease;
}
.sidebar-menu li a .icon {
    font-size: 17px;
    width: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    flex-shrink: 0;
}
.sidebar-menu li a:hover {
    background: #f1f5f9;
    color: #4f46e5;
    transform: translateX(4px);
}

/* Active State */
.sidebar-menu li.active > a:not(.submenu-toggle-btn) {
    background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3) !important;
    transform: translateX(0) !important;
}
.sidebar-menu li.active > a .icon {
    color: #ffffff;
}

/* Submenu Header */
.submenu-toggle-btn {
    width: 100%;
    box-sizing: border-box;
}
.submenu-left {
    display: flex;
    align-items: center;
}
.submenu-arrow {
    transition: transform 0.25s ease;
    color: #94a3b8;
}
.has-submenu.open > a .submenu-arrow {
    transform: rotate(180deg);
    color: #4f46e5;
}

/* Submenu Dropdown List */
.sidebar-submenu {
    list-style: none;
    padding-left: 12px;
    margin: 4px 0 6px 14px;
    border-left: 2px solid #e2e8f0;
}
.sidebar-submenu li {
    margin-bottom: 2px;
}
.sidebar-submenu li a {
    padding: 8px 12px !important;
    font-size: 13px !important;
    color: #64748b !important;
    border-radius: 8px !important;
    font-weight: 500 !important;
    background: transparent !important;
}
.sidebar-submenu li a:hover {
    background: #eef2ff !important;
    color: #4f46e5 !important;
    transform: translateX(3px) !important;
}
.sidebar-submenu li.active a {
    background: #e0e7ff !important;
    color: #3730a3 !important;
    font-weight: 700 !important;
}

/* Logout Button */
.logout-item {
    margin-top: 20px;
    border-top: 1px dashed #e2e8f0;
    padding-top: 14px;
}
.logout-item a {
    color: #ef4444 !important;
}
.logout-item a:hover {
    background: #fef2f2 !important;
    color: #dc2626 !important;
}

/* Dark Mode Overrides */
body.dark-mode .sidebar-header-box { border-color: #374151; }
body.dark-mode .sidebar-brand-info .brand-name { color: #f3f4f6; }
body.dark-mode .menu-section-header { color: #64748b; }
body.dark-mode .sidebar-menu li a { color: #cbd5e1; }
body.dark-mode .sidebar-menu li a:hover { background: #374151; color: #a5b4fc; }
body.dark-mode .sidebar-submenu { border-left-color: #374151; }
body.dark-mode .sidebar-submenu li a { color: #94a3b8 !important; }
body.dark-mode .sidebar-submenu li a:hover { background: rgba(99, 102, 241, 0.15) !important; color: #a5b4fc !important; }
body.dark-mode .sidebar-submenu li.active a { background: rgba(99, 102, 241, 0.3) !important; color: #818cf8 !important; }
body.dark-mode .logout-item { border-top-color: #374151; }
</style>

<script>
function toggleSidebarSubmenu(el) {
    const parent = el.closest('.has-submenu');
    if (!parent) return;
    const sub = parent.querySelector('.sidebar-submenu');
    if (sub) {
        const isHidden = (sub.style.display === 'none' || !sub.style.display);
        sub.style.display = isHidden ? 'block' : 'none';
        if (isHidden) parent.classList.add('open');
        else parent.classList.remove('open');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const sidebarMenu = document.querySelector('.sidebar-menu');
    
    if (sidebarMenu) {
        // 1. Khôi phục vị trí cuộn thanh sidebar từ sessionStorage
        const savedScrollTop = sessionStorage.getItem('sidebar_scroll_top');
        if (savedScrollTop !== null) {
            sidebarMenu.scrollTop = parseInt(savedScrollTop, 10);
        } else {
            // Nếu chưa có vị trí lưu, tự động cuộn mục active vào tầm mắt
            const activeItem = sidebarMenu.querySelector('li.active');
            if (activeItem) {
                activeItem.scrollIntoView({ block: 'nearest' });
            }
        }

        // 2. Lưu vị trí cuộn khi cuộn sidebar
        sidebarMenu.addEventListener('scroll', function() {
            sessionStorage.setItem('sidebar_scroll_top', sidebarMenu.scrollTop);
        });

        // 3. Phản hồi tức thì khi click chọn menu
        const sidebarLinks = sidebarMenu.querySelectorAll('a[href]:not([href="javascript:void(0);"])');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                sessionStorage.setItem('sidebar_scroll_top', sidebarMenu.scrollTop);
                if (this.getAttribute('href') === 'logout.php') return;
                document.querySelectorAll('.sidebar-menu li').forEach(li => li.classList.remove('active'));
                const parentLi = this.closest('li');
                if (parentLi) parentLi.classList.add('active');
                this.style.opacity = '0.8';
            });
        });
    }
});
</script>
