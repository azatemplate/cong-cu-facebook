<!-- includes/sidebar.php -->
<aside class="sidebar">
    <div class="sidebar-header" style="display: flex; align-items: center; gap: 10px;">
        <img src="logo.png" alt="HONGDOLABS Logo" class="logo-img" style="width: 32px; height: 32px; border-radius: 6px; object-fit: contain; flex-shrink: 0;">
        <div class="logo-text" style="font-weight: 800; font-size: 16px; letter-spacing: 0.5px; color: var(--text-main);">HONGDOLABS</div>
    </div>
    <ul class="sidebar-menu">
        <li class="<?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>">
            <a href="index.php" data-tooltip="Dashboard"><span class="icon">⏱</span><span class="menu-label"> Dashboard</span></a>
        </li>
        <li class="<?php echo ($current_page == 'fanpages') ? 'active' : ''; ?>">
            <a href="fanpages.php" data-tooltip="Fanpages"><span class="icon">📱</span><span class="menu-label"> Fanpages</span></a>
        </li>
        <li class="<?php echo ($current_page == 'manage_posts' || $current_page == 'campaign_detail') ? 'active' : ''; ?>">
            <a href="manage_posts.php" data-tooltip="Campaigns"><span class="icon">📋</span><span class="menu-label"> Campaigns</span></a>
        </li>

        <!-- Menu Facebook Post (Parent Collapsible Submenu) -->
        <li class="has-submenu <?php echo (in_array($current_page, ['posts', 'videos', 'reels', 'story', 'facebook_scraper', 'comment_posts'])) ? 'active open' : ''; ?>">
            <a href="javascript:void(0);" onclick="toggleSidebarSubmenu(this)" data-tooltip="Facebook Post" style="display:flex; justify-content:space-between; align-items:center;">
                <span style="display:flex; align-items:center;"><span class="icon">📘</span><span class="menu-label"> Facebook Post</span></span>
                <span class="submenu-arrow menu-label" style="font-size:10px; transition:transform 0.2s;">▼</span>
            </a>
            <ul class="sidebar-submenu" style="<?php echo (in_array($current_page, ['posts', 'videos', 'reels', 'story', 'facebook_scraper', 'comment_posts'])) ? 'display:block;' : 'display:none;'; ?>">
                <li class="<?php echo ($current_page == 'posts') ? 'active' : ''; ?>">
                    <a href="posts.php" data-tooltip="Post Ảnh"><span class="icon">🖼️</span><span class="menu-label"> Post Ảnh</span></a>
                </li>
                <li class="<?php echo ($current_page == 'videos') ? 'active' : ''; ?>">
                    <a href="videos.php" data-tooltip="Post Video"><span class="icon">📺</span><span class="menu-label"> Post Video</span></a>
                </li>
                <li class="<?php echo ($current_page == 'reels') ? 'active' : ''; ?>">
                    <a href="reels.php" data-tooltip="Post Reels"><span class="icon">🎞️</span><span class="menu-label"> Post Reels</span></a>
                </li>
                <li class="<?php echo ($current_page == 'story') ? 'active' : ''; ?>">
                    <a href="story.php" data-tooltip="Post Story"><span class="icon">▶️</span><span class="menu-label"> Post Story</span></a>
                </li>
                <li class="<?php echo ($current_page == 'facebook_scraper') ? 'active' : ''; ?>">
                    <a href="facebook_scraper.php" data-tooltip="Facebook Scraper"><span class="icon">🕸️</span><span class="menu-label"> Facebook Scraper</span></a>
                </li>
                <li class="<?php echo ($current_page == 'comment_posts') ? 'active' : ''; ?>">
                    <a href="comment_posts.php" data-tooltip="Comment Post"><span class="icon">💬</span><span class="menu-label"> Comment Post</span></a>
                </li>
            </ul>
        </li>

        <li class="<?php echo ($current_page == 'instagram') ? 'active' : ''; ?>">
            <a href="instagram.php" data-tooltip="Instagram Post"><span class="icon">📸</span><span class="menu-label"> Instagram Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'tiktok') ? 'active' : ''; ?>">
            <a href="tiktok.php" data-tooltip="TikTok"><span class="icon">🎵</span><span class="menu-label"> TikTok Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'youtube') ? 'active' : ''; ?>">
            <a href="youtube.php" data-tooltip="YouTube Post"><span class="icon">🚀</span><span class="menu-label"> YouTube Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'buffer' || $current_page == 'buffer_posts' || $current_page == 'buffer_channels') ? 'active' : ''; ?>">
            <a href="buffer.php" data-tooltip="Buffer Post"><span class="icon">📡</span><span class="menu-label"> Buffer Post</span></a>
        </li>
        <li class="<?php echo ($current_page == 'tiktok_search') ? 'active' : ''; ?>">
            <a href="tiktok_search.php" data-tooltip="TikTok Search"><span class="icon">🎵</span><span class="menu-label"> TikTok Search</span></a>
        </li>
        <li class="<?php echo ($current_page == 'insights') ? 'active' : ''; ?>">
            <a href="insights.php" data-tooltip="Insights"><span class="icon">📊</span><span class="menu-label"> Insights</span></a>
        </li>
        <li class="<?php echo ($current_page == 'growth') ? 'active' : ''; ?>">
            <a href="growth.php" data-tooltip="Growth"><span class="icon">📈</span><span class="menu-label"> Growth</span></a>
        </li>
        <li class="<?php echo ($current_page == 'earnings') ? 'active' : ''; ?>">
            <a href="earnings.php" data-tooltip="Earnings"><span class="icon">💰</span><span class="menu-label"> Earnings</span></a>
        </li>
        <li class="<?php echo ($current_page == 'live_chat' || $current_page == 'live_chat_zalo' || $current_page == 'live_chat_tiktok' || $current_page == 'website') ? 'active' : ''; ?>">
            <a href="live_chat.php" data-tooltip="Live Chat"><span class="icon">💬</span><span class="menu-label"> Live Chat</span></a>
        </li>
        <li class="<?php echo ($current_page == 'live_comments') ? 'active' : ''; ?>">
            <a href="live_comments.php" data-tooltip="Live Comments"><span class="icon">📝</span><span class="menu-label"> Live Comments</span></a>
        </li>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <li class="<?php echo ($current_page == 'accounts') ? 'active' : ''; ?>">
            <a href="accounts.php" data-tooltip="User Management"><span class="icon">👥</span><span class="menu-label"> User Management</span></a>
        </li>
        <?php endif; ?>
        <li class="<?php echo ($current_page == 'token') ? 'active' : ''; ?>">
            <a href="token_management.php" data-tooltip="Token Management"><span class="icon">🔑</span><span class="menu-label"> Token Management</span></a>
        </li>
        <li class="<?php echo ($current_page == 'privacy') ? 'active' : ''; ?>">
            <a href="privacy_policy.php" data-tooltip="Privacy Policy"><span class="icon">📜</span><span class="menu-label"> Privacy Policy</span></a>
        </li>
        <li class="<?php echo ($current_page == 'terms') ? 'active' : ''; ?>">
            <a href="terms_of_service.php" data-tooltip="Terms of Service"><span class="icon">⚖️</span><span class="menu-label"> Terms of Service</span></a>
        </li>
        <li class="<?php echo ($current_page == 'proxy') ? 'active' : ''; ?>">
            <a href="proxy.php" data-tooltip="Kho Proxy"><span class="icon">🌐</span><span class="menu-label"> Kho Proxy</span></a>
        </li>
        <li class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>">
            <a href="settings.php" data-tooltip="Settings"><span class="icon">⚙️</span><span class="menu-label"> Settings</span></a>
        </li>
        <li class="<?php echo ($current_page == 'ai_settings') ? 'active' : ''; ?>">
            <a href="ai_settings.php" data-tooltip="AI Configuration"><span class="icon">🤖</span><span class="menu-label"> AI Configuration</span></a>
        </li>
        <li class="logout mt-4">
            <a href="logout.php" data-tooltip="Logout"><span class="icon">⎋</span><span class="menu-label"> Logout</span></a>
        </li>
    </ul>
</aside>

<style>
.sidebar-submenu {
    list-style: none;
    padding-left: 10px;
    background: rgba(0, 0, 0, 0.02);
    border-left: 2px solid var(--border-color);
    margin-left: 22px;
    margin-top: 4px;
    margin-bottom: 4px;
}
.sidebar-submenu li a {
    padding: 8px 12px !important;
    font-size: 13px !important;
    color: #64748b !important;
    background: transparent !important;
    border-left: none !important;
    border-right: none !important;
    font-weight: 400 !important;
    border-radius: 4px;
    transition: all 0.15s ease;
}
.sidebar-submenu li a:hover {
    background: #f1f5f9 !important;
    color: var(--primary-color) !important;
}
.sidebar-submenu li.active a {
    color: #4338ca !important;
    background: #e0e7ff !important;
    font-weight: 700 !important;
    border-left: 3px solid #4338ca !important;
    border-radius: 0 4px 4px 0 !important;
}
body.dark-mode .sidebar-submenu {
    background: rgba(255, 255, 255, 0.03);
}
body.dark-mode .sidebar-submenu li a {
    color: #94a3b8 !important;
}
body.dark-mode .sidebar-submenu li a:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #a5b4fc !important;
}
body.dark-mode .sidebar-submenu li.active a {
    background: rgba(99, 102, 241, 0.3) !important;
    color: #818cf8 !important;
    font-weight: 700 !important;
    border-left: 3px solid #818cf8 !important;
}
.has-submenu.open .submenu-arrow {
    transform: rotate(180deg);
}
.sidebar.collapsed .sidebar-submenu {
    margin-left: 0;
    padding-left: 0;
    border-left: none;
}
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
    // Add immediate visual feedback when clicking sidebar links
    const sidebarLinks = document.querySelectorAll('.sidebar-menu a[href]:not([href="javascript:void(0);"])');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (this.getAttribute('href') === 'logout.php') return;
            document.querySelectorAll('.sidebar-menu li').forEach(li => li.classList.remove('active'));
            const parentLi = this.closest('li');
            if (parentLi) parentLi.classList.add('active');
            this.style.opacity = '0.7';
        });
    });
});
</script>
