<!-- includes/header.php -->
<?php 
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
set_security_headers();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

// Generate CSRF token for all pages
$_csrf_token = csrf_token();

// Đặt $current_page ở các trang chính để highlight menu
if(!isset($current_page)) $current_page = '';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fb_api.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ thống Quản lý Fanpage</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-wrapper">
        <header class="top-header">
            <div class="header-left">
                <span class="menu-toggle">☰</span>
            </div>
            <div class="header-right">
                <span class="header-icon" id="theme_toggle">🌙</span>
                <span class="header-icon" id="lang_toggle">🌐 EN</span>
                <?php
                // Notification: cache in session for 5 minutes to avoid heavy JOIN every page load
                $failed_posts = [];
                $failed_count = 0;
                $account_id   = $_SESSION['account_id'] ?? 0;

                $notif_ttl = 300; // 5 minutes
                $notif_key = 'notif_cache_' . $account_id;
                $notif_ts  = 'notif_ts_' . $account_id;

                if (!isset($_SESSION[$notif_ts]) || (time() - $_SESSION[$notif_ts]) > $notif_ttl) {
                    try {
                        $notif_stmt = $pdo->prepare("
                            SELECT sp.id, sp.error_msg, sp.scheduled_time, p.name as page_name, p.page_id
                            FROM scheduled_posts sp
                            JOIN pages p ON sp.page_id = p.page_id
                            JOIN users u ON p.user_id = u.id
                            WHERE sp.status = 'failed' AND u.account_id = ?
                            ORDER BY sp.scheduled_time DESC
                            LIMIT 20
                        ");
                        $notif_stmt->execute([$account_id]);
                        $fetched = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $_SESSION[$notif_key] = is_array($fetched) ? $fetched : [];
                        $_SESSION[$notif_ts]  = time();
                    } catch (Exception $e) {
                        $_SESSION[$notif_key] = [];
                        $_SESSION[$notif_ts]  = time();
                    }
                }
                $failed_posts = $_SESSION[$notif_key] ?? [];
                $failed_count = count($failed_posts);
                ?>
                <div style="position: relative; display: inline-block;">
                    <span class="header-icon" id="notif_toggle" style="cursor: pointer;">🔔
                        <?php if ($failed_count > 0): ?>
                        <span id="notif_badge" style="color:white; font-size:10px; position:absolute; margin-left:-8px; margin-top:-5px; background: red; border-radius: 50%; padding: 1px 5px; font-weight: bold;"><?php echo $failed_count > 9 ? '9+' : $failed_count; ?></span>
                        <?php endif; ?>
                    </span>
                    <div id="notif_dropdown" style="display:none; position: absolute; right: 0; top: 30px; width: 320px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; padding: 10px; max-height: 400px; overflow-y: auto;">
                        <div style="font-weight:bold; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center;">
                            <span>Thông báo Lỗi đăng bài</span>
                            <span style="font-size: 11px; background: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 10px;"><?php echo $failed_count; ?> lỗi</span>
                        </div>
                        <div style="font-size: 13px; color: var(--text-muted);">
                            <?php if ($failed_count > 0): ?>
                                <?php foreach ($failed_posts as $fp): ?>
                                    <div style="padding: 8px 0; border-bottom: 1px solid var(--border-color); display: flex; gap: 8px; align-items: flex-start;">
                                        <div style="font-size: 16px; margin-top: 2px;">⚠️</div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500; color: #b91c1c; margin-bottom: 2px;">
                                                Page: <?php echo htmlspecialchars($fp['page_name']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px; line-height: 1.3;">
                                                <?php 
                                                    $errMsg = $fp['error_msg'];
                                                    if (strlen($errMsg) > 80) $errMsg = substr($errMsg, 0, 80) . '...';
                                                    echo htmlspecialchars($errMsg); 
                                                ?>
                                            </div>
                                            <div style="font-size: 11px; color: #9ca3af;">
                                                Lúc: <?php echo date('H:i d/m', strtotime($fp['scheduled_time'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div style="text-align: center; margin-top: 10px;">
                                    <a href="manage_posts.php?status=failed" style="color: var(--primary-color); text-decoration: none; font-weight: 500; font-size: 13px;">Xem tất cả trong Quản lý</a>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 20px 0; color: #9ca3af;">
                                    <div style="font-size: 24px; margin-bottom: 10px;">🎉</div>
                                    Hệ thống hoạt động ổn định.<br>Không có lỗi đăng bài nào.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // Theme toggle
                        const themeToggle = document.getElementById('theme_toggle');
                        if (localStorage.getItem('theme') === 'dark') {
                            document.body.classList.add('dark-mode');
                            themeToggle.textContent = '☀️';
                        }
                        themeToggle.addEventListener('click', () => {
                            document.body.classList.toggle('dark-mode');
                            if (document.body.classList.contains('dark-mode')) {
                                localStorage.setItem('theme', 'dark');
                                themeToggle.textContent = '☀️';
                            } else {
                                localStorage.setItem('theme', 'light');
                                themeToggle.textContent = '🌙';
                            }
                        });

                        // Lang toggle
                        const langToggle = document.getElementById('lang_toggle');
                        let currentLang = localStorage.getItem('lang') || 'EN'; // EN by default
                        langToggle.textContent = (currentLang === 'VN') ? '🌐 EN' : '🌐 VN'; // Shows button to switch to opposite
                        
                        const dict = {
                            'Dashboard': 'Bảng Điều Khiển',
                            'Fanpages': 'Trang Fanpage',
                            'Image & Status Posts': 'Đăng Ảnh / Status',
                            'Campaigns': 'Quản Lý Chiến Dịch',
                            'Video Scheduler': 'Lên Lịch Video',
                            'Reels': 'Đăng Reels',
                            'Story': 'Tin (Story)',
                            'Insights': 'Thống kê (Insights)',
                            'User Management': 'Người Dùng',
                            'Token Management': 'Quản lý Token',
                            'YouTube Accounts': 'Tài khoản YouTube',
                            'YouTube Scheduler': 'Đăng Video YouTube',
                            'Privacy Policy': 'Chính sách bảo mật',
                            'Terms of Service': 'Điều khoản',
                            'Settings': 'Cài Đặt',
                            'Logout': 'Đăng Xuất',
                            'Live Chat': 'Nhắn tin CSKH',
                            'AI Configuration': 'Cấu hình AI'
                        };
                        
                        function applyLang() {
                            if (currentLang === 'VN') {
                                document.querySelectorAll('.sidebar-menu a .menu-label').forEach(span => {
                                    let text = span.textContent.trim();
                                    if (dict[text]) {
                                        span.setAttribute('data-en', text);
                                        span.textContent = ' ' + dict[text];
                                    }
                                });
                            } else {
                                document.querySelectorAll('.sidebar-menu a .menu-label').forEach(span => {
                                    if (span.hasAttribute('data-en')) {
                                        span.textContent = ' ' + span.getAttribute('data-en');
                                    }
                                });
                            }
                        }
                        
                        applyLang();

                        langToggle.addEventListener('click', () => {
                            if (currentLang === 'VN') {
                                currentLang = 'EN';
                                langToggle.textContent = '🌐 VN';
                            } else {
                                currentLang = 'VN';
                                langToggle.textContent = '🌐 EN';
                            }
                            localStorage.setItem('lang', currentLang);
                            applyLang();
                        });

                        // Notification Dropdown
                        const notifToggle = document.getElementById('notif_toggle');
                        const notifDropdown = document.getElementById('notif_dropdown');
                        const notifBadge = document.getElementById('notif_badge');

                        notifToggle.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (notifDropdown.style.display === 'none') {
                                notifDropdown.style.display = 'block';
                                notifBadge.style.display = 'none';
                            } else {
                                notifDropdown.style.display = 'none';
                            }
                        });

                        document.addEventListener('click', () => {
                            notifDropdown.style.display = 'none';
                        });

                        // Sidebar Toggle Logic
                        const menuToggle = document.querySelector('.menu-toggle');
                        const sidebar = document.querySelector('.sidebar');
                        
                        if (menuToggle && sidebar) {
                            // Restore saved state (if user collapsed it before, keep collapsed)
                            if (localStorage.getItem('sidebar_collapsed') === '1') {
                                sidebar.classList.add('collapsed');
                            }

                            // Create overlay for mobile
                            const overlay = document.createElement('div');
                            overlay.className = 'sidebar-overlay';
                            document.body.appendChild(overlay);

                            menuToggle.addEventListener('click', () => {
                                if (window.innerWidth <= 768) {
                                    sidebar.classList.toggle('mobile-open');
                                    overlay.classList.toggle('active');
                                } else {
                                    sidebar.classList.toggle('collapsed');
                                    localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
                                }
                            });

                            overlay.addEventListener('click', () => {
                                sidebar.classList.remove('mobile-open');
                                overlay.classList.remove('active');
                            });

                            // Auto adjust when resizing
                            window.addEventListener('resize', () => {
                                if (window.innerWidth > 768) {
                                    sidebar.classList.remove('mobile-open');
                                    overlay.classList.remove('active');
                                }
                            });
                        }
                    });
                </script>
                <div class="user-profile">
                    <div class="avatar" style="text-transform: uppercase;"><?php echo substr($_SESSION['username'], 0, 1); ?></div>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        <span class="user-role"><?php echo $_SESSION['role'] === 'admin' ? 'Super Administrator' : 'User'; ?></span>
                    </div>
                </div>
            </div>
        </header>
        <div class="content-area">
