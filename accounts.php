<?php
$current_page = 'accounts';
require_once __DIR__ . '/includes/header.php';

if ($_SESSION['role'] !== 'admin') {
    echo "<div class='page-title'>Truy cập bị từ chối</div>";
    echo "<div class='card'>Bạn không có quyền truy cập trang này.</div>";
    include 'includes/footer.php';
    exit;
}

$alert_type = '';
$alert_message = '';

// Handle Create / Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        
        if (!empty($username) && !empty($password)) {
            $stmt = $pdo->prepare("SELECT id FROM system_accounts WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $alert_type = 'danger';
                $alert_message = 'Tên đăng nhập đã tồn tại.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $i_stmt = $pdo->prepare("INSERT INTO system_accounts (username, password, role, expire_date, page_limit) VALUES (?, ?, 'user', DATE_ADD(NOW(), INTERVAL 7 DAY), 500)");
                $i_stmt->execute([$username, $hashed]);
                $alert_type = 'success';
                $alert_message = 'Tạo tài khoản thành công. (Mặc định: Hạn 7 ngày, 500 Bài/Ngày)';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_limits') {
        try {
            $edit_id = intval($_POST['edit_id']);
            $new_expire = trim($_POST['expire_date']);
            $new_limit = intval($_POST['page_limit']);
            $max_fb_pages = intval($_POST['max_fb_pages'] ?? 450);
            $max_yt_channels = intval($_POST['max_yt_channels'] ?? 10);
            $max_tiktok_accounts = intval($_POST['max_tiktok_accounts'] ?? 10);
            $max_buffer_channels = intval($_POST['max_buffer_channels'] ?? 10);
            $max_instagram_accounts = intval($_POST['max_instagram_accounts'] ?? 10);
            $youtube_multi_api = isset($_POST['youtube_multi_api']) ? 1 : 0;
            $drive_multi_api = isset($_POST['drive_multi_api']) ? 1 : 0;
            $disable_live_chat = isset($_POST['disable_live_chat']) ? 1 : 0;
            $enable_live_chat = $disable_live_chat ? 0 : 1;
            $enable_live_chat_oa = $enable_live_chat;
            $enable_live_chat_tiktok = $enable_live_chat;
            $enable_website = $enable_live_chat;
            $enable_customers = $enable_live_chat;
            
            if ($new_limit < 0) $new_limit = 0;
            if ($max_fb_pages < 0) $max_fb_pages = 0;
            if ($max_yt_channels < 0) $max_yt_channels = 0;
            if ($max_tiktok_accounts < 0) $max_tiktok_accounts = 0;
            if ($max_buffer_channels < 0) $max_buffer_channels = 0;
            if ($max_instagram_accounts < 0) $max_instagram_accounts = 0;

            // Ensure system_accounts columns exist
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN enable_live_chat TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN enable_live_chat_oa TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN enable_live_chat_tiktok TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN enable_website TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN enable_customers TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_fb_pages INT DEFAULT 450"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_yt_channels INT DEFAULT 10"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_tiktok_accounts INT DEFAULT 10"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_buffer_channels INT DEFAULT 10"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_instagram_accounts INT DEFAULT 10"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN youtube_multi_api TINYINT DEFAULT 0"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN drive_multi_api TINYINT DEFAULT 0"); } catch (Exception $e) {}
            
            $upd_stmt = $pdo->prepare("UPDATE system_accounts SET expire_date = ?, page_limit = ?, max_fb_pages = ?, max_yt_channels = ?, max_tiktok_accounts = ?, max_buffer_channels = ?, max_instagram_accounts = ?, youtube_multi_api = ?, drive_multi_api = ?, enable_live_chat = ?, enable_live_chat_oa = ?, enable_live_chat_tiktok = ?, enable_website = ?, enable_customers = ? WHERE id = ?");
            $upd_stmt->execute([
                empty($new_expire) ? null : $new_expire, 
                $new_limit, 
                $max_fb_pages,
                $max_yt_channels,
                $max_tiktok_accounts,
                $max_buffer_channels,
                $max_instagram_accounts,
                $youtube_multi_api,
                $drive_multi_api,
                $enable_live_chat,
                $enable_live_chat_oa,
                $enable_live_chat_tiktok,
                $enable_website,
                $enable_customers,
                $edit_id
            ]);

            // Tự động giải phóng tất cả file lock đăng bài của các tài khoản con khi gia hạn
            try {
                $u_stmt = $pdo->prepare("SELECT id FROM users WHERE account_id = ?");
                $u_stmt->execute([$edit_id]);
                $user_ids = $u_stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($user_ids)) {
                    foreach ($user_ids as $uid) {
                        $lock_key = md5('uid_' . $uid);
                        $lock_file = __DIR__ . "/locks/publish_user_" . $lock_key . ".lock";
                        if (file_exists($lock_file)) {
                            @unlink($lock_file);
                        }
                    }
                }
            } catch (Exception $e) {}

            // Tự động kích hoạt lại tiến trình đăng bài ngay sau khi gia hạn
            try {
                if (!function_exists('get_php_cli_bin')) {
                    require_once __DIR__ . '/includes/php_cli.php';
                }
                $php_bin = get_php_cli_bin();
                $script = __DIR__ . '/cron/start_publish.php';
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    pclose(popen("start /B \"\" \"$php_bin\" \"$script\"", "r"));
                } else {
                    exec("\"$php_bin\" \"$script\" > /dev/null 2>&1 &");
                }
            } catch (Exception $e) {}
            
            $alert_type = 'success';
            $alert_message = 'Cập nhật giới hạn & quyền tính năng thành công!';
        } catch (Exception $e) {
            $alert_type = 'danger';
            $alert_message = 'Lỗi cập nhật tài khoản: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    verify_csrf();
    $reset_id = intval($_POST['reset_id']);
    $default_password = password_hash('123456', PASSWORD_DEFAULT);
    $r_stmt = $pdo->prepare("UPDATE system_accounts SET password = ? WHERE id = ?");
    $r_stmt->execute([$default_password, $reset_id]);
    $alert_type = 'success';
    $alert_message = 'Đã reset mật khẩu về mặc định: 123456';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    verify_csrf();
    $del_id = intval($_POST['del_id']);
    if ($del_id !== $_SESSION['account_id']) {
        @set_time_limit(600);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '1024M');

        try {
            // 1. Lấy toàn bộ user_id thuộc account này
            $u_stmt = $pdo->prepare("SELECT id FROM users WHERE account_id = ?");
            $u_stmt->execute([$del_id]);
            $user_ids = $u_stmt->fetchAll(PDO::FETCH_COLUMN);

            // 2. Lấy toàn bộ page_id thuộc các users đó
            $page_ids = [];
            if (!empty($user_ids)) {
                foreach (array_chunk($user_ids, 500) as $u_chunk) {
                    $in_u = implode(',', array_fill(0, count($u_chunk), '?'));
                    $pg_stmt = $pdo->prepare("SELECT page_id FROM pages WHERE user_id IN ($in_u)");
                    $pg_stmt->execute($u_chunk);
                    $page_ids = array_merge($page_ids, $pg_stmt->fetchAll(PDO::FETCH_COLUMN));
                }
            }
            $page_ids = array_values(array_unique($page_ids));

            // 3. Xóa scheduled_posts trực tiếp theo account_id
            do {
                $del_sp = $pdo->prepare("DELETE FROM scheduled_posts WHERE account_id = ? LIMIT 5000");
                $del_sp->execute([$del_id]);
                $affected_sp = $del_sp->rowCount();
            } while ($affected_sp > 0);

            // 4. Xóa dữ liệu liên quan đến các page
            if (!empty($page_ids)) {
                foreach (array_chunk($page_ids, 500) as $p_chunk) {
                    $in_p = implode(',', array_fill(0, count($p_chunk), '?'));

                    do {
                        $del_sp2 = $pdo->prepare("DELETE FROM scheduled_posts WHERE page_id IN ($in_p) LIMIT 5000");
                        $del_sp2->execute($p_chunk);
                        $aff2 = $del_sp2->rowCount();
                    } while ($aff2 > 0);

                    $pdo->prepare("DELETE FROM posts_history WHERE page_id IN ($in_p)")->execute($p_chunk);
                    $pdo->prepare("DELETE FROM page_shares WHERE page_id IN ($in_p)")->execute($p_chunk);
                }
            }

            // 5. Xóa các bảng liên quan đến account_id
            $account_tables = [
                'page_shares' => 'owner_account_id = ? OR shared_with_account_id = ?',
                'post_campaigns' => 'account_id = ?',
                'fetched_fanpage_posts' => 'account_id = ?',
                'dashboard_snapshots' => 'account_id = ?',
                'saved_replies' => 'account_id = ?',
                'ai_configs' => 'account_id = ?',
                'youtube_channels' => 'account_id = ?',
                'tiktok_accounts' => 'account_id = ?',
                'buffer_channels' => 'account_id = ?',
                'buffer_accounts' => 'account_id = ?',
                'instagram_accounts' => 'account_id = ?',
                'zalo_oas' => 'account_id = ?',
                'zalo_settings' => 'account_id = ?',
                'proxies' => 'account_id = ?',
                'web_visitors' => 'account_id = ?',
                'web_messages' => 'account_id = ?',
                'web_chat_configs' => 'account_id = ?',
                'customer_api_configs' => 'account_id = ?',
                'bot_chat_rules' => 'account_id = ?'
            ];

            foreach ($account_tables as $tbl => $cond) {
                try {
                    if (strpos($cond, 'OR') !== false) {
                        $pdo->prepare("DELETE FROM {$tbl} WHERE {$cond}")->execute([$del_id, $del_id]);
                    } else {
                        $pdo->prepare("DELETE FROM {$tbl} WHERE {$cond}")->execute([$del_id]);
                    }
                } catch (PDOException $e) {}
            }

            // 6. Xóa pages
            if (!empty($user_ids)) {
                foreach (array_chunk($user_ids, 500) as $u_chunk) {
                    $in_u = implode(',', array_fill(0, count($u_chunk), '?'));
                    $pdo->prepare("DELETE FROM pages WHERE user_id IN ($in_u)")->execute($u_chunk);
                }
            }

            // 7. Xóa users & system_accounts
            $pdo->prepare("DELETE FROM users WHERE account_id = ?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM system_accounts WHERE id = ?")->execute([$del_id]);

            // Clear cache
            $cache_dir = __DIR__ . '/uploads/cache';
            if (is_dir($cache_dir)) {
                foreach (glob($cache_dir . "/dashboard_user_{$del_id}_*.json") as $cf) { @unlink($cf); }
                foreach (glob($cache_dir . "/dashboard_admin_*.json") as $cf) { @unlink($cf); }
            }

            $alert_type = 'success';
            $alert_message = 'Đã xóa tài khoản và toàn bộ dữ liệu liên quan thành công.';
        } catch (Exception $e) {
            $alert_type = 'danger';
            $alert_message = 'Lỗi khi xóa tài khoản: ' . $e->getMessage();
        }
    } else {
        $alert_type = 'danger';
        $alert_message = 'Bạn không thể tự xóa chính mình.';
    }
}

// Fetch Accounts
$accounts = [];
try {
    $stmt = $pdo->query("
        SELECT sa.*, 
               (SELECT COUNT(*) FROM users u WHERE u.account_id = sa.id) as total_users,
               (SELECT COUNT(*) FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = sa.id) as total_pages,
               (SELECT COUNT(*) FROM youtube_channels yt WHERE yt.account_id = sa.id) as total_youtube,
               (SELECT COUNT(*) FROM tiktok_accounts tt WHERE tt.account_id = sa.id) as total_tiktok,
               (SELECT COUNT(*) FROM buffer_channels bc WHERE bc.account_id = sa.id) as total_buffer,
               (SELECT COUNT(*) FROM instagram_accounts ig WHERE ig.account_id = sa.id) as total_instagram,
               (SELECT COUNT(id) FROM scheduled_posts sp WHERE sp.account_id = sa.id AND sp.status = 'published' AND DATE(sp.scheduled_time) = CURDATE()) as posts_today
        FROM system_accounts sa 
        ORDER BY sa.id ASC
    ");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $pdo->query("SELECT sa.*, 0 as total_users, 0 as total_pages, 0 as total_youtube, 0 as total_tiktok, 0 as total_buffer, 0 as total_instagram, 0 as posts_today FROM system_accounts sa ORDER BY sa.id ASC");
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {}
}
?>

<style>
/* ── Design Tokens & Refactored Styling for Accounts Management ── */
:root {
    --ac-primary: #4f46e5;
    --ac-primary-hover: #4338ca;
    --ac-primary-glow: rgba(79, 70, 229, 0.15);
    --ac-surface: #ffffff;
    --ac-border: #e2e8f0;
    --ac-text-main: #0f172a;
    --ac-text-muted: #64748b;
    --ac-radius: 16px;
}

.ac-header-banner {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    border-radius: var(--ac-radius);
    padding: 26px 30px;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(30, 27, 75, 0.2);
    position: relative;
    overflow: hidden;
}
.ac-header-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, rgba(99, 102, 241, 0) 70%);
    pointer-events: none;
}
.ac-header-title { display: flex; align-items: center; gap: 16px; }
.ac-icon-badge {
    width: 50px; height: 50px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #a5b4fc; flex-shrink: 0;
}
.ac-header-text h2 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 24px; font-weight: 800;
    margin: 0 0 4px; color: #ffffff;
    letter-spacing: -0.01em;
}
.ac-header-text p { font-size: 13px; color: #cbd5e1; margin: 0; }

.ac-count-pill {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(8px);
    padding: 10px 18px;
    border-radius: 12px;
    color: #ffffff;
    font-size: 13px; font-weight: 700;
    display: inline-flex; align-items: center; gap: 8px;
}

/* Callout Alert */
.ac-alert {
    padding: 14px 18px; border-radius: 12px; font-size: 13px;
    margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-weight: 600;
}
.ac-alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.ac-alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

/* Create Card */
.ac-card {
    background: var(--ac-surface);
    border: 1px solid var(--ac-border);
    border-radius: var(--ac-radius);
    padding: 26px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    margin-bottom: 24px;
    transition: all 0.25s ease;
}
.ac-card:hover {
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
    border-color: #cbd5e1;
}

.ac-card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9;
}
.ac-card-head h3 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 18px; font-weight: 800; color: var(--ac-text-main); margin: 0;
    display: flex; align-items: center; gap: 8px;
}

/* Form Controls */
.ac-form-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)) 160px;
    gap: 16px; align-items: end;
}
@media (max-width: 768px) {
    .ac-form-grid { grid-template-columns: 1fr; }
}

.st-label {
    display: block; font-size: 12px; font-weight: 700;
    color: #334155; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;
}
.st-input-wrapper { position: relative; display: flex; align-items: center; }
.st-input-wrapper > svg {
    position: absolute; left: 14px; width: 18px; height: 18px;
    color: #94a3b8; pointer-events: none; transition: color 0.2s;
}
.st-input {
    width: 100%; padding: 12px 14px 12px 42px;
    background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 10px;
    font-size: 14px; font-weight: 500; color: var(--ac-text-main); font-family: inherit;
    transition: all 0.2s ease; box-sizing: border-box;
}
.st-input:focus {
    outline: none; border-color: var(--ac-primary); background: #ffffff;
    box-shadow: 0 0 0 4px var(--ac-primary-glow);
}

.ac-btn-primary {
    padding: 12px 20px; background: var(--ac-primary); color: #ffffff;
    border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 12px var(--ac-primary-glow); transition: all 0.2s; font-family: inherit;
}
.ac-btn-primary:hover { background: var(--ac-primary-hover); transform: translateY(-1px); }

.ac-btn-danger {
    padding: 8px 16px; background: #dc2626; color: #ffffff;
    border: none; border-radius: 10px; font-size: 13px; font-weight: 700;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2); transition: all 0.2s;
}
.ac-btn-danger:hover { background: #b91c1c; transform: translateY(-1px); }

/* Table System */
.ac-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.ac-table th {
    background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 16px;
    border-bottom: 1.5px solid #e2e8f0; text-align: left;
}
.ac-table td {
    padding: 16px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px;
    color: var(--ac-text-main); vertical-align: middle;
}
.ac-table tr:last-child td { border-bottom: none; }
.ac-table tr:hover td { background: #f8fafc; }

/* Badges */
.role-badge-admin {
    background: #fef9c3; color: #854d0e; border: 1px solid #fde047;
    padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
}
.role-badge-user {
    background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;
    padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
}

.mini-channel-badge {
    padding: 2px 6px; border-radius: 6px; font-size: 11px; font-weight: 600;
    white-space: nowrap; display: inline-flex; align-items: center; gap: 3px;
}

/* Action Group */
.act-link {
    background: none; border: none; font-size: 12.5px; font-weight: 700;
    cursor: pointer; padding: 0; transition: all 0.2s; text-decoration: none;
}
.act-link-edit { color: var(--ac-primary); }
.act-link-edit:hover { text-decoration: underline; color: #3730a3; }

.act-link-reset { color: #d97706; }
.act-link-reset:hover { text-decoration: underline; color: #b45309; }

.act-link-del { color: #ef4444; }
.act-link-del:hover { text-decoration: underline; color: #b91c1c; }

/* Glassmorphism Modal */
.ac-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(6px);
    align-items: center; justify-content: center; padding: 20px;
}
.ac-modal-card {
    background: #ffffff; border-radius: 20px; padding: 28px;
    max-width: 520px; width: 100%; max-height: 90vh; overflow-y: auto;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
    position: relative; animation: modalFadeIn 0.25s ease-out;
}
@keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
@keyframes rotation { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<!-- Banner Header -->
<div class="ac-header-banner">
    <div class="ac-header-title">
        <div class="ac-icon-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="ac-header-text">
            <h2>Quản Lý Tài Khoản System</h2>
            <p>Phân quyền người dùng, thiết lập hạn ngạch bài đăng &amp; giới hạn kênh nối</p>
        </div>
    </div>

    <div class="ac-count-pill">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span>Tổng Tài Khoản: <strong><?php echo count($accounts); ?></strong></span>
    </div>
</div>

<!-- Flash Alerts -->
<?php if ($alert_message): ?>
    <div class="ac-alert ac-alert-<?php echo $alert_type === 'success' ? 'success' : 'danger'; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?php echo htmlspecialchars($alert_message); ?></span>
    </div>
<?php endif; ?>

<!-- Create Account Card -->
<div class="ac-card">
    <div class="ac-card-head">
        <h3>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--ac-primary);"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            Tạo Tài Khoản Mới
        </h3>
    </div>

    <form method="POST" action="accounts.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="ac-form-grid">
            <div>
                <label class="st-label">Tên đăng nhập (Username)</label>
                <div class="st-input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" name="username" class="st-input" required placeholder="Nhập tên đăng nhập...">
                </div>
            </div>

            <div>
                <label class="st-label">Mật khẩu ban đầu</label>
                <div class="st-input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="password" class="st-input" required placeholder="••••••••">
                </div>
            </div>

            <div>
                <button type="submit" class="ac-btn-primary" style="width:100%;">
                    <span>Tạo Account</span>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Accounts List Card -->
<div class="ac-card">
    <div class="ac-card-head">
        <h3>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--ac-primary);"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            Danh Sách Tài Khoản
        </h3>

        <button onclick="runDataCleanup()" class="ac-btn-danger">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            <span>Dọn Dẹp Dữ Liệu Rác</span>
        </button>
    </div>

    <div style="overflow-x: auto;">
        <table class="ac-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tên Đăng Nhập</th>
                    <th>Chức Vụ</th>
                    <th>Ngày Tạo</th>
                    <th>Ngày Hết Hạn</th>
                    <th>Bài/Ngày</th>
                    <th>Tài Sản Đã Nối</th>
                    <th>Thao Tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td style="font-weight:700; color:#64748b;"><?php echo $acc['id']; ?></td>
                        <td>
                            <span style="font-weight: 700; color: var(--ac-primary); font-size:14px;"><?php echo htmlspecialchars($acc['username']); ?></span>
                        </td>
                        <td>
                            <?php if ($acc['role'] === 'admin'): ?>
                                <span class="role-badge-admin">Admin</span>
                            <?php else: ?>
                                <span class="role-badge-user">User</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#64748b; font-size:12.5px;"><?php echo date('d/m/Y', strtotime($acc['created_at'])); ?></td>
                        <td>
                            <?php 
                            if ($acc['role'] === 'admin') {
                                echo '<span style="color: #059669; font-weight: 700;">🟢 Vĩnh viễn</span>';
                            } else {
                                if (empty($acc['expire_date'])) {
                                    echo '<span style="color: #059669; font-weight: 700;">🟢 Vĩnh viễn</span>';
                                } else {
                                    $is_expired = (strtotime($acc['expire_date']) < time());
                                    $color = $is_expired ? '#dc2626' : '#059669';
                                    $icon = $is_expired ? '🔴' : '🟢';
                                    echo '<span style="color: '.$color.'; font-weight: 700;">' . $icon . ' ' . date('d/m/Y H:i', strtotime($acc['expire_date'])) . '</span>';
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if ($acc['role'] === 'admin') {
                                echo '<span style="color: #64748b; font-weight:700;">&infin;</span>';
                            } else {
                                $posts_today = (int)$acc['posts_today'];
                                $limit = (int)$acc['page_limit'];
                                $color = ($posts_today >= $limit && $limit > 0) ? '#dc2626' : '#0f172a';
                                echo '<span style="font-weight: 800; color: ' . $color . ';">' . $posts_today . ' / ' . $limit . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            $total_channels = (int)$acc['total_pages'] + (int)$acc['total_youtube'] + (int)$acc['total_tiktok'] + (int)$acc['total_buffer'] + (int)$acc['total_instagram'];
                            $is_acc_admin = ($acc['role'] === 'admin');
                            $mfb  = $is_acc_admin ? '&infin;' : (int)($acc['max_fb_pages'] ?? 450);
                            $myt  = $is_acc_admin ? '&infin;' : (int)($acc['max_yt_channels'] ?? 10);
                            $mtt  = $is_acc_admin ? '&infin;' : (int)($acc['max_tiktok_accounts'] ?? 10);
                            $mbuf = $is_acc_admin ? '&infin;' : (int)($acc['max_buffer_channels'] ?? 10);
                            $mig  = $is_acc_admin ? '&infin;' : (int)($acc['max_instagram_accounts'] ?? 10);
                            ?>
                            <div style="font-size: 12px; font-weight: 700; margin-bottom: 6px; color: var(--ac-text-main);">
                                Tổng: <span style="color: #4f46e5; font-weight: 800; font-size:13px;"><?php echo $total_channels; ?></span> Kênh
                            </div>
                            <div style="display: flex; flex-wrap: wrap; gap: 4px; font-size: 11px; max-width: 240px;">
                                <span class="mini-channel-badge" title="Nick FB" style="background: #eef2ff; color: #4f46e5;">👤 <?php echo $acc['total_users']; ?></span>
                                <span class="mini-channel-badge" title="Fanpage FB" style="background: #ecfdf5; color: #059669;">📄 <?php echo $acc['total_pages']; ?>/<?php echo $mfb; ?></span>
                                <span class="mini-channel-badge" title="YouTube" style="background: #fef2f2; color: #dc2626;">▶️ <?php echo $acc['total_youtube']; ?>/<?php echo $myt; ?></span>
                                <span class="mini-channel-badge" title="TikTok" style="background: #fff1f2; color: #fe2c55;">🎵 <?php echo $acc['total_tiktok']; ?>/<?php echo $mtt; ?></span>
                                <span class="mini-channel-badge" title="Buffer" style="background: #eff6ff; color: #2563eb;">⚡ <?php echo $acc['total_buffer']; ?>/<?php echo $mbuf; ?></span>
                                <span class="mini-channel-badge" title="Instagram" style="background: #fdf2f8; color: #db2777;">📸 <?php echo $acc['total_instagram']; ?>/<?php echo $mig; ?></span>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                <?php if ($acc['role'] !== 'admin'): ?>
                                    <button onclick="openEditModal(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars($acc['username'], ENT_QUOTES); ?>', '<?php echo empty($acc['expire_date']) ? '' : date('Y-m-d\TH:i', strtotime($acc['expire_date'])); ?>', <?php echo (int)$acc['page_limit']; ?>, <?php echo (int)$acc['youtube_multi_api']; ?>, <?php echo (int)$acc['drive_multi_api']; ?>, <?php echo (int)($acc['max_fb_pages'] ?? 450); ?>, <?php echo (int)($acc['max_yt_channels'] ?? 10); ?>, <?php echo (int)($acc['max_tiktok_accounts'] ?? 10); ?>, <?php echo (int)($acc['max_buffer_channels'] ?? 10); ?>, <?php echo (int)($acc['max_instagram_accounts'] ?? 10); ?>, <?php echo (int)($acc['enable_live_chat'] ?? 1); ?>)" class="act-link act-link-edit">
                                        Sửa LH
                                    </button>
                                <?php endif; ?>
                                
                                <form method="POST" action="accounts.php" style="display:inline;" onsubmit="return confirm('Reset mật khẩu về 123456?');">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="reset_id" value="<?php echo $acc['id']; ?>">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="act-link act-link-reset">Reset Pass</button>
                                </form>

                                <?php if ($acc['id'] !== $_SESSION['account_id']): ?>
                                    <form method="POST" action="accounts.php" style="display:inline;" onsubmit="return confirm('Xóa tài khoản này và toàn bộ dữ liệu liên quan?');">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="del_id" value="<?php echo $acc['id']; ?>">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="act-link act-link-del">Xóa</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px; font-weight:600;">Đang ĐN</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Sửa Giới Hạn -->
<div id="editLimitModal" onclick="if(event.target === this) closeEditModal();" class="ac-modal-overlay">
    <div class="ac-modal-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid #f1f5f9;">
            <h3 style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">Sửa Hạn Ngạch: <span id="e_username_label" style="color:var(--ac-primary);"></span></h3>
            <button type="button" onclick="closeEditModal()" title="Đóng" style="background:#f1f5f9; border:none; font-size:16px; font-weight:bold; color:#64748b; cursor:pointer; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center;">✕</button>
        </div>

        <form method="POST" action="accounts.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_limits">
            <input type="hidden" name="edit_id" id="e_id_input">
            
            <div style="margin-bottom: 16px;">
                <label class="st-label">📅 Ngày Giờ Hết Hạn</label>
                <input type="datetime-local" id="e_expire_input" name="expire_date" class="st-input" style="padding-left:14px;">
                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Để trống = Vĩnh viễn (Giống Admin).</small>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label class="st-label">🚀 Giới Hạn Bài Đăng Tối Đa/Ngày</label>
                <input type="number" id="e_limit_input" name="page_limit" class="st-input" style="padding-left:14px;" min="0">
            </div>

            <div style="background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 16px; margin-bottom: 18px;">
                <h4 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 800; color: #334155; text-transform:uppercase; letter-spacing:0.5px;">🔒 Giới Hạn Số Kênh Nối (Max Channels)</h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="font-size: 11px; font-weight: 700; color:#475569;">📄 Max Fanpage FB</label>
                        <input type="number" id="e_max_fb_pages" name="max_fb_pages" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; margin-top:3px; box-sizing:border-box;" min="0">
                    </div>
                    <div>
                        <label style="font-size: 11px; font-weight: 700; color:#475569;">▶️ Max Kênh YouTube</label>
                        <input type="number" id="e_max_yt_channels" name="max_yt_channels" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; margin-top:3px; box-sizing:border-box;" min="0">
                    </div>
                    <div>
                        <label style="font-size: 11px; font-weight: 700; color:#475569;">🎵 Max Kênh TikTok</label>
                        <input type="number" id="e_max_tiktok_accounts" name="max_tiktok_accounts" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; margin-top:3px; box-sizing:border-box;" min="0">
                    </div>
                    <div>
                        <label style="font-size: 11px; font-weight: 700; color:#475569;">⚡ Max Kênh Buffer</label>
                        <input type="number" id="e_max_buffer_channels" name="max_buffer_channels" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; margin-top:3px; box-sizing:border-box;" min="0">
                    </div>
                    <div>
                        <label style="font-size: 11px; font-weight: 700; color:#475569;">📸 Max Kênh Instagram</label>
                        <input type="number" id="e_max_instagram_accounts" name="max_instagram_accounts" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; margin-top:3px; box-sizing:border-box;" min="0">
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: #dc2626; font-weight: 700;">
                    <input type="checkbox" id="e_disable_live_chat" name="disable_live_chat" value="1" style="width: 18px; height: 18px; accent-color: #dc2626;">
                    <span>🚫 Tắt Live Chat</span>
                </label>
            </div>
            
            <div style="margin-bottom: 10px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight:600; color:#334155;">
                    <input type="checkbox" id="e_youtube_multi_api" name="youtube_multi_api" value="1" style="width: 18px; height: 18px; accent-color: var(--ac-primary);">
                    <span>Mở rộng tính năng API YouTube</span>
                </label>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight:600; color:#334155;">
                    <input type="checkbox" id="e_drive_multi_api" name="drive_multi_api" value="1" style="width: 18px; height: 18px; accent-color: var(--ac-primary);">
                    <span>Mở rộng tính năng API Drive</span>
                </label>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:12px; border-top: 1px solid #f1f5f9; padding-top: 16px;">
                <button type="button" onclick="closeEditModal()" style="padding:10px 18px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-weight:700; cursor:pointer;">Hủy</button>
                <button type="submit" class="ac-btn-primary">Lưu Thay Đổi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, username, expire, limit, youtube_multi_api, drive_multi_api, m_fb, m_yt, m_tt, m_buf, m_ig, en_lc) {
    document.getElementById('e_id_input').value = id;
    document.getElementById('e_username_label').textContent = username;
    document.getElementById('e_expire_input').value = expire;
    document.getElementById('e_limit_input').value = limit;
    document.getElementById('e_youtube_multi_api').checked = (youtube_multi_api === 1);
    document.getElementById('e_drive_multi_api').checked = (drive_multi_api === 1);
    
    document.getElementById('e_max_fb_pages').value = m_fb !== undefined ? m_fb : 450;
    document.getElementById('e_max_yt_channels').value = m_yt !== undefined ? m_yt : 10;
    document.getElementById('e_max_tiktok_accounts').value = m_tt !== undefined ? m_tt : 10;
    document.getElementById('e_max_buffer_channels').value = m_buf !== undefined ? m_buf : 10;
    document.getElementById('e_max_instagram_accounts').value = m_ig !== undefined ? m_ig : 10;
    
    document.getElementById('e_disable_live_chat').checked = (en_lc === 0);
    
    document.getElementById('editLimitModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editLimitModal').style.display = 'none';
}

function runDataCleanup() {
    const btn = document.querySelector('button[onclick="runDataCleanup()"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span style="width:14px;height:14px;border:2px solid #fff;border-bottom-color:transparent;border-radius:50%;display:inline-block;animation:rotation 1s linear infinite;"></span> Đang dọn dẹp...';
    btn.disabled = true;
    btn.style.opacity = '0.7';

    fetch('actions/cleanup_data.php', {
        method: 'POST'
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            btn.innerHTML = '✅ Hoàn tất!';
            setTimeout(() => { 
                btn.innerHTML = originalText; 
                btn.disabled = false; 
                btn.style.opacity = '1';
                window.location.reload();
            }, 2000);
        } else {
            btn.innerHTML = '❌ Có lỗi!';
            setTimeout(() => { 
                btn.innerHTML = originalText; 
                btn.disabled = false; 
                btn.style.opacity = '1';
            }, 3000);
        }
    })
    .catch(err => {
        btn.innerHTML = '❌ Lỗi kết nối';
        setTimeout(() => { 
            btn.innerHTML = originalText; 
            btn.disabled = false; 
            btn.style.opacity = '1';
        }, 3000);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
