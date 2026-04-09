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
                <div style="position: relative; display: inline-block;">
                    <span class="header-icon" id="notif_toggle" style="cursor: pointer;">🔔
                        <span id="notif_badge" style="display:none; color:white; font-size:10px; position:absolute; margin-left:-8px; margin-top:-5px; background: red; border-radius: 50%; padding: 1px 5px; font-weight: bold;"></span>
                    </span>
                    <div id="notif_dropdown" style="display:none; position: absolute; right: 0; top: 30px; width: 340px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.15); z-index: 1000; padding: 10px; max-height: 400px; overflow-y: auto;">
                        <div style="font-weight:bold; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center;">
                            <span>Thông báo</span>
                            <span id="notif_count_label" style="font-size: 11px; background: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 10px; display:none;">0 mới</span>
                        </div>
                        <div id="notif_tabs" style="display:flex; gap: 10px; margin-bottom: 10px; font-size: 12px; font-weight: 500; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                            <span class="notif-tab active" data-tab="unread" style="cursor: pointer; padding: 4px 8px; border-radius: 4px; background: #0ea5e9; color: white;">Chưa đọc</span>
                            <span class="notif-tab" data-tab="all" style="cursor: pointer; padding: 4px 8px; border-radius: 4px; color: var(--text-muted); background: transparent;">Tất cả</span>
                            <span class="notif-tab" data-tab="read" style="cursor: pointer; padding: 4px 8px; border-radius: 4px; color: var(--text-muted); background: transparent;">Đã đọc</span>
                        </div>
                        <div id="notif_content" style="font-size: 13px; color: var(--text-muted);">
                            <div style="text-align:center; padding:15px; color:#9ca3af;">Đang tải...</div>
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

                        // Notification Dropdown Logic & Infinite Scroll
                        const notifToggle   = document.getElementById('notif_toggle');
                        const notifDropdown = document.getElementById('notif_dropdown');
                        const notifBadge    = document.getElementById('notif_badge');
                        const notifContent  = document.getElementById('notif_content');
                        const notifCountLabel = document.getElementById('notif_count_label');

                        // Trạng thái phân trang
                        let notifOffset    = 0;
                        let notifHasMore   = false;
                        let notifLoading   = false;
                        let notifInitDone  = false; // đã render lần đầu chưa
                        let currentNotifTab = 'unread';

                        notifToggle.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (notifDropdown.style.display === 'none') {
                                notifDropdown.style.display = 'block';
                                notifBadge.style.display = 'none';
                                // Reset & reload mỗi lần mở
                                notifOffset   = 0;
                                notifHasMore  = false;
                                notifInitDone = false;
                                currentNotifTab = 'unread';
                                document.querySelectorAll('.notif-tab').forEach(t => {
                                    if(t.dataset.tab === currentNotifTab) {
                                        t.classList.add('active');
                                        t.style.background = '#0ea5e9';
                                        t.style.color = 'white';
                                    } else {
                                        t.classList.remove('active');
                                        t.style.background = 'transparent';
                                        t.style.color = 'var(--text-muted)';
                                    }
                                });
                                notifContent.innerHTML = '<div style="text-align:center;padding:15px;color:#9ca3af;">Đang tải...</div>';
                                loadNotifications();
                            } else {
                                notifDropdown.style.display = 'none';
                            }
                        });

                        document.querySelectorAll('.notif-tab').forEach(tabEl => {
                            tabEl.addEventListener('click', function(e) {
                                e.stopPropagation();
                                document.querySelectorAll('.notif-tab').forEach(t => {
                                    t.classList.remove('active');
                                    t.style.background = 'transparent';
                                    t.style.color = 'var(--text-muted)';
                                });
                                this.classList.add('active');
                                this.style.background = '#0ea5e9';
                                this.style.color = 'white';

                                currentNotifTab = this.dataset.tab;
                                notifOffset = 0;
                                notifHasMore = false;
                                notifContent.innerHTML = '<div style="text-align:center;padding:15px;color:#9ca3af;">Đang tải...</div>';
                                loadNotifications();
                            });
                        });

                        document.addEventListener('click', (e) => {
                            if (!notifDropdown.contains(e.target)) {
                                notifDropdown.style.display = 'none';
                            }
                        });

                        // Helper: render 1 live_notif item
                        function renderNotifItem(cn) {
                            const isMsg = cn.type === 'message';
                            const isInsight = cn.type === 'insights_comment';
                            
                            const name  = cn.sender_name || 'Khách hàng';
                            
                            if (isInsight) {
                                let snippetData;
                                let isSuccess = false;
                                let isError = false;
                                let contentHtml = '';
                                let targetPostId = '';
                                
                                try {
                                    snippetData = JSON.parse(cn.snippet);
                                    if (snippetData.video_id) targetPostId = snippetData.video_id;
                                    
                                    if (snippetData.type === 'success') {
                                        isSuccess = true;
                                        contentHtml = `<div style="margin-top:4px;line-height:1.5;">
                                            <div>&bull; Fanpage: ${cn.page_name}</div>
                                            <div>&bull; Hệ thống: ✅ Đã bình luận bài ${targetPostId} đủ điều kiện</div>
                                            <div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;" title='${snippetData.content.replace(/'/g, "&#39;")}'>&bull; B/L: "${snippetData.content}"</div>
                                        </div>`;
                                    } else if (snippetData.type === 'error') {
                                        isError = true;
                                        contentHtml = `<div style="margin-top:4px;line-height:1.5;">
                                            <div>&bull; Fanpage: ${cn.page_name}</div>
                                            <div>&bull; Video: ${targetPostId}</div>
                                            <div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">&bull; Lỗi: ${snippetData.error}</div>
                                        </div>`;
                                    }
                                } catch(e) {
                                    // Fallback cho text cũ
                                    if (cn.snippet && cn.snippet.includes('✅')) isSuccess = true;
                                    else if (cn.snippet && cn.snippet.includes('❌')) isError = true;
                                    
                                    // Thử extract ID bài viết từ text (VD: "Đã bình luận bài 1458834319288390 đủ điều kiện")
                                    if (cn.snippet) {
                                        const match = cn.snippet.match(/bài\s+(\d+)/i) || cn.snippet.match(/video\s+(\d+)/i);
                                        if (match && match[1]) {
                                            targetPostId = match[1];
                                        }
                                    }
                                    
                                    contentHtml = `<div style="margin-top:4px;line-height:1.5;">
                                        <div>&bull; Fanpage: ${cn.page_name}</div>
                                        <div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">&bull; Hệ thống: ${cn.snippet}</div>
                                    </div>`;
                                }

                                const titleText = isSuccess ? '✅ [COMMENT SUCCESS]' : (isError ? '❌ [COMMENT ERROR]' : '📊 [REPORT]');
                                const titleColor = isSuccess ? '#15803d' : (isError ? '#b91c1c' : '#15803d');
                                const bgColor = '#f0fdf4';
                                const hoverColor = '#dcfce7';
                                
                                let link = targetPostId ? `live_comments.php?page_id=${cn.page_id}&post_id=${targetPostId}` : 'manage_posts.php';

                                return `<div onclick="readNotif('${cn.id}','${link}')" style="cursor:pointer;padding:10px;margin-bottom:4px;background:${bgColor};border-radius:6px;transition:background 0.2s;" onmouseover="this.style.background='${hoverColor}'" onmouseout="this.style.background='${bgColor}'">
                                    <div style="font-weight:bold;color:${titleColor};font-size:13px;margin-bottom:2px;">${titleText}</div>
                                    <div style="font-size:12px;color:#334155;">
                                        ${contentHtml}
                                    </div>
                                    <div style="font-size:10px;color:#9ca3af;margin-top:6px;">${cn.created_at}</div>
                                </div>`;
                            }

                            let link = '';
                            if (isMsg) {
                                link = `live_chat.php?page_id=${cn.page_id}&conv_id=${cn.conversation_id || ''}&sender_id=${cn.sender_id || ''}`;
                            } else {
                                link = `live_comments.php?page_id=${cn.page_id}&post_id=${cn.post_id || ''}`;
                            }
                            
                            const bgColor = '#f0f9ff';
                            const hoverColor = '#e0f2fe';


                            const icon  = isMsg ? '💬' : '📝';
                            const nameColor = '#0369a1';
                            return `<div onclick="readNotif('${cn.id}','${link}')" style="cursor:pointer;display:flex;gap:8px;padding:8px;margin-bottom:4px;background:${bgColor};border-radius:6px;transition:background 0.2s;" onmouseover="this.style.background='${hoverColor}'" onmouseout="this.style.background='${bgColor}'">
                                <div style="font-size:16px;">${icon}</div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:500;color:${nameColor};margin-bottom:2px;">[${cn.page_name}] ${name}</div>
                                    <div style="font-size:12px;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${cn.snippet || 'Có thông báo mới'}</div>
                                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;">${cn.created_at}</div>
                                </div>
                            </div>`;
                        }

                        function loadNotifications(append) {
                            if (notifLoading) return;
                            notifLoading = true;

                            // Hiện spinner ở cuối nếu đang load thêm
                            if (append) {
                                let spinner = document.getElementById('notif-load-spinner');
                                if (!spinner) {
                                    spinner = document.createElement('div');
                                    spinner.id = 'notif-load-spinner';
                                    spinner.style.cssText = 'text-align:center;padding:10px;color:#9ca3af;font-size:12px;';
                                    spinner.textContent = '⏳ Đang tải thêm...';
                                    notifContent.appendChild(spinner);
                                }
                            }

                            fetch('actions/get_notifications.php?offset=' + notifOffset + '&tab=' + currentNotifTab)
                            .then(r => r.json())
                            .then(data => {
                                notifLoading = false;
                                // Xóa spinner
                                const spinner = document.getElementById('notif-load-spinner');
                                if (spinner) spinner.remove();

                                if (data.status !== 'success') return;

                                notifHasMore = data.has_more;

                                if (!append) {
                                    // === Lần đầu: render toàn bộ ===
                                    let totalCount = data.failed_posts.length + data.live_notifs.length;

                                    // Cập nhật badge
                                    if (totalCount > 0 && notifDropdown.style.display === 'none') {
                                        notifBadge.style.display = 'inline-block';
                                        notifBadge.innerText = totalCount > 9 ? '9+' : totalCount;
                                    } else if (totalCount === 0) {
                                        notifBadge.style.display = 'none';
                                    }
                                    if (totalCount > 0) {
                                        notifCountLabel.innerText = totalCount + ' mới';
                                        notifCountLabel.style.display = 'inline-block';
                                    } else {
                                        notifCountLabel.style.display = 'none';
                                    }

                                    let html = '';
                                    if (data.failed_posts.length > 0) {
                                        html += `<div style="font-weight:bold;font-size:12px;margin-bottom:5px;color:#b91c1c;">Lỗi bài viết (${data.failed_posts.length})</div>`;
                                        data.failed_posts.forEach(fp => {
                                            html += `<a href="manage_posts.php?status=failed" style="display:flex;gap:8px;padding:8px 0;border-bottom:1px solid var(--border-color);text-decoration:none;color:inherit;">
                                                <div style="font-size:16px;">⚠️</div>
                                                <div style="flex:1;">
                                                    <div style="font-weight:500;color:#b91c1c;margin-bottom:2px;">${fp.page_name}</div>
                                                    <div style="font-size:11px;color:var(--text-muted);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${fp.error_msg}</div>
                                                </div>
                                            </a>`;
                                        });
                                    }
                                    if (data.live_notifs.length > 0) {
                                        html += `<div style="font-weight:bold;font-size:12px;margin-top:10px;margin-bottom:5px;color:#0284c7;">Tin nhắn & Bình luận mới</div>`;
                                        data.live_notifs.forEach(cn => { html += renderNotifItem(cn); });
                                    }
                                    if (totalCount === 0) {
                                        html = `<div style="text-align:center;padding:20px 0;color:#9ca3af;"><div style="font-size:24px;margin-bottom:10px;">🎉</div>Không có thông báo mới.</div>`;
                                    }
                                    notifContent.innerHTML = html;
                                    notifInitDone = true;

                                } else {
                                    // === Load thêm: chỉ append live_notifs ===
                                    if (data.live_notifs.length > 0) {
                                        data.live_notifs.forEach(cn => {
                                            notifContent.insertAdjacentHTML('beforeend', renderNotifItem(cn));
                                        });
                                    }
                                }

                                // Cập nhật offset cho lần tiếp
                                notifOffset += data.live_notifs.length;

                                // Hiện thông báo "hết" nếu không còn thêm
                                if (!notifHasMore && notifOffset > 0) {
                                    let endMsg = document.getElementById('notif-end-msg');
                                    if (!endMsg) {
                                        endMsg = document.createElement('div');
                                        endMsg.id = 'notif-end-msg';
                                        endMsg.style.cssText = 'text-align:center;padding:8px;color:#9ca3af;font-size:11px;';
                                        endMsg.textContent = '— Đã hiển thị tất cả thông báo —';
                                        notifContent.appendChild(endMsg);
                                    }
                                }
                            })
                            .catch(() => { notifLoading = false; });
                        }

                        // Infinite scroll: lắng nghe scroll trong dropdown
                        notifDropdown.addEventListener('scroll', () => {
                            if (!notifInitDone || notifLoading || !notifHasMore) return;
                            const threshold = 60; // px từ đáy
                            const distFromBottom = notifDropdown.scrollHeight - notifDropdown.scrollTop - notifDropdown.clientHeight;
                            if (distFromBottom <= threshold) {
                                loadNotifications(true); // append mode
                            }
                        });

                        window.readNotif = function(id, link) {
                            const fd = new FormData();
                            fd.append('id', id);
                            fetch('actions/read_notification.php', { method: 'POST', body: fd })
                            .then(() => { window.location.href = link; });
                        };

                        // Polling badge mỗi 15s (không reset dropdown đang mở)
                        function pollBadge() {
                            fetch('actions/get_notifications.php?offset=0&tab=unread')
                            .then(r => r.json())
                            .then(data => {
                                if (data.status !== 'success') return;
                                const total = data.failed_posts.length + data.live_notifs.length;
                                if (total > 0 && notifDropdown.style.display === 'none') {
                                    notifBadge.style.display = 'inline-block';
                                    notifBadge.innerText = total > 9 ? '9+' : total;
                                } else if (total === 0) {
                                    notifBadge.style.display = 'none';
                                }
                                if (total > 0) {
                                    notifCountLabel.innerText = total + ' mới';
                                    notifCountLabel.style.display = 'inline-block';
                                } else {
                                    notifCountLabel.style.display = 'none';
                                }
                            }).catch(() => {});
                        }
                        pollBadge();
                        setInterval(pollBadge, 15000);

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
