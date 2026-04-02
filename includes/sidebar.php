<!-- includes/sidebar.php -->
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo-icon">RS</div>
        <div class="logo-text">Reels Media Access</div>
    </div>
    <ul class="sidebar-menu">
        <li class="<?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>">
            <a href="index.php" data-tooltip="Dashboard"><span class="icon">⏱</span><span class="menu-label"> Dashboard</span></a>
        </li>
        <li>
            <a href="fanpages.php" data-tooltip="Fanpages"><span class="icon">📱</span><span class="menu-label"> Fanpages</span></a>
        </li>
        <li class="<?php echo ($current_page == 'posts') ? 'active' : ''; ?>">
            <a href="posts.php" data-tooltip="Image &amp; Posts"><span class="icon">🖼</span><span class="menu-label"> Image &amp; Status Posts</span></a>
        </li>
        <li class="<?php echo ($current_page == 'manage_posts') ? 'active' : ''; ?>">
            <a href="manage_posts.php" data-tooltip="Campaigns"><span class="icon">📋</span><span class="menu-label"> Campaigns</span></a>
        </li>
        <li class="<?php echo ($current_page == 'videos') ? 'active' : ''; ?>">
            <a href="videos.php" data-tooltip="Video Scheduler"><span class="icon">📺</span><span class="menu-label"> Video Scheduler</span></a>
        </li>
        <li class="<?php echo ($current_page == 'reels') ? 'active' : ''; ?>">
            <a href="reels.php" data-tooltip="Reels"><span class="icon">🎞</span><span class="menu-label"> Reels</span></a>
        </li>
        <li class="<?php echo ($current_page == 'story') ? 'active' : ''; ?>">
            <a href="story.php" data-tooltip="Story"><span class="icon">▶</span><span class="menu-label"> Story</span></a>
        </li>
        <li class="<?php echo ($current_page == 'youtube_channels') ? 'active' : ''; ?>">
            <a href="youtube_channels.php" data-tooltip="YouTube Accounts"><span class="icon">📺</span><span class="menu-label"> YouTube Accounts</span></a>
        </li>
        <li class="<?php echo ($current_page == 'youtube') ? 'active' : ''; ?>">
            <a href="youtube.php" data-tooltip="YouTube Scheduler"><span class="icon">🚀</span><span class="menu-label"> YouTube Scheduler</span></a>
        </li>
        <li class="<?php echo ($current_page == 'insights') ? 'active' : ''; ?>">
            <a href="insights.php" data-tooltip="Insights"><span class="icon">📈</span><span class="menu-label"> Insights</span></a>
        </li>
        <li class="<?php echo ($current_page == 'live_chat') ? 'active' : ''; ?>">
            <a href="live_chat.php" data-tooltip="Live Chat"><span class="icon">💬</span><span class="menu-label"> Live Chat</span></a>
        </li>
        <?php if ($_SESSION['role'] === 'admin'): ?>
        <li>
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
