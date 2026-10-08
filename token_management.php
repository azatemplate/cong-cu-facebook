<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];

// Fetch system account permissions
$stmt_acc = $pdo->prepare("SELECT role, drive_multi_api FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$current_account = $stmt_acc->fetch(PDO::FETCH_ASSOC);

$is_admin = ($current_account['role'] ?? '') === 'admin';
$drive_multi_api = (int)($current_account['drive_multi_api'] ?? 0);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'edit_api' || $_POST['action'] === 'disconnect_gg') {
        if (!$is_admin && !$drive_multi_api) {
            $_SESSION['flash_msg'] = "Bạn không có quyền thực hiện thao tác này.";
            header("Location: token_management.php");
            exit;
        }
    }

    if ($_POST['action'] === 'edit_api') {
        verify_csrf();
        $user_id_db = intval($_POST['user_id_db']);
        $gg_client_id = trim($_POST['gg_client_id'] ?? '');
        $gg_client_secret = trim($_POST['gg_client_secret'] ?? '');
        
        $upd_stmt = $pdo->prepare("UPDATE users SET gg_client_id = ?, gg_client_secret = ? WHERE id = ? AND account_id = ?");
        $upd_stmt->execute([
            empty($gg_client_id) ? null : $gg_client_id,
            empty($gg_client_secret) ? null : $gg_client_secret,
            $user_id_db,
            $account_id
        ]);
        
        $_SESSION['flash_msg'] = "Cập nhật cấu hình Google API của tài khoản thành công!";
        header("Location: token_management.php");
        exit;
    }
    
    if ($_POST['action'] === 'disconnect_gg') {
        verify_csrf();
        $user_id_db = intval($_POST['user_id_db']);
        
        $upd_stmt = $pdo->prepare("UPDATE users SET gg_refresh_token = NULL WHERE id = ? AND account_id = ?");
        $upd_stmt->execute([$user_id_db, $account_id]);
        
        $_SESSION['flash_msg'] = "Đã hủy liên kết Google Drive của tài khoản.";
        header("Location: token_management.php");
        exit;
    }
}

$current_page = 'token';
require_once __DIR__ . '/includes/header.php';

// Prepare variables for alerts
$alert_type = '';
$alert_message = '';

if (!empty($global_flash_msg)) {
    $alert_type = $global_flash_type ?: 'success';
    $alert_message = $global_flash_msg;
} elseif (!empty($global_flash_error)) {
    $alert_type = 'danger';
    $alert_message = $global_flash_error;
}

if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success') {
        $alert_type = 'success';
        $alert_message = 'Thêm Token thành công! Đã đồng bộ ' . intval($_GET['pages']) . ' Fanpage.';
    } elseif ($_GET['status'] == 'success_delete') {
        $alert_type = 'success';
        $alert_message = 'Xóa Token thành công!';
    } elseif ($_GET['status'] == 'success_drive') {
        $alert_type = 'success';
        $alert_message = 'Liên kết Google Drive thành công!';
    } elseif ($_GET['status'] == 'error') {
        $alert_type = 'danger';
        $alert_message = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Đã có lỗi xảy ra.';
    }
}

// Fetch existing tokens with checkpoint detection
$stmt = $pdo->prepare("
    SELECT u.*, 
        px.proxy_string, px.status as proxy_status, px.ip as proxy_ip, px.port as proxy_port,
        COALESCE(pg.page_count, 0) as page_count,
        COALESCE(chk.checkpoint_count, 0) as checkpoint_count
    FROM users u 
    LEFT JOIN proxies px ON u.proxy_id = px.id
    LEFT JOIN (
        SELECT p.user_id, COUNT(*) as page_count 
        FROM pages p
        WHERE p.user_id IN (SELECT id FROM users WHERE account_id = ?)
        GROUP BY p.user_id
    ) pg ON pg.user_id = u.id
    LEFT JOIN (
        SELECT p.user_id, COUNT(sp.id) as checkpoint_count
        FROM pages p 
        JOIN scheduled_posts sp ON sp.page_id = p.page_id 
        WHERE sp.account_id = ? 
          AND sp.status = 'checkpoint'
        GROUP BY p.user_id
    ) chk ON chk.user_id = u.id
    WHERE u.account_id = ? 
    ORDER BY u.created_at DESC
");
$stmt->execute([$account_id, $account_id, $account_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_tokens = count($users);
$live_tokens = 0;
$checkpoint_tokens = 0;

foreach ($users as &$u) {
    $is_cp = (int)($u['checkpoint_count'] ?? 0) > 0 || (isset($u['status']) && $u['status'] === 'checkpoint');
    $u['computed_status'] = $is_cp ? 'checkpoint' : 'live';
    if ($is_cp) {
        $checkpoint_tokens++;
    } else {
        $live_tokens++;
    }
}
unset($u);

// Get FB App ID to generate login URL (from user first, fallback to admin)
$stmt_app = $pdo->prepare("SELECT fb_app_id FROM system_accounts WHERE id = ?");
$stmt_app->execute([$account_id]);
$fb_app_id = $stmt_app->fetchColumn();

if (empty($fb_app_id)) {
    $stmt_admin = $pdo->prepare("SELECT fb_app_id FROM system_accounts WHERE role = 'admin' LIMIT 1");
    $stmt_admin->execute();
    $fb_app_id = $stmt_admin->fetchColumn();
}

$fb_permissions = "pages_manage_metadata,pages_manage_engagement,business_management,pages_show_list,pages_manage_posts,pages_read_engagement,read_insights,pages_messaging,public_profile,instagram_basic,instagram_content_publish,instagram_manage_comments,instagram_manage_insights";
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . get_base_url() . "redirect_callback.php";

$login_url = "";
if ($fb_app_id) {
    $login_url = "https://www.facebook.com/v25.0/dialog/oauth?client_id=" . urlencode($fb_app_id) . "&redirect_uri=" . urlencode($redirect_uri) . "&scope=" . urlencode($fb_permissions) . "&response_type=token";
}

// Fetch user page limit
$stmt_limit = $pdo->prepare("SELECT max_fb_pages FROM system_accounts WHERE id = ?");
$stmt_limit->execute([$account_id]);
$max_fb_pages = intval($stmt_limit->fetchColumn() ?: 450);
$max_fb_display = $is_admin ? 'Không giới hạn' : number_format($max_fb_pages);

$stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = ?");
$stmt_cnt->execute([$account_id]);
$total_connected_pages = intval($stmt_cnt->fetchColumn() ?: 0);
?>

<style>
/* ── Design Tokens & Refactored Styling for Token Management ── */
:root {
    --tk-primary: #4f46e5;
    --tk-primary-hover: #4338ca;
    --tk-primary-glow: rgba(79, 70, 229, 0.15);
    --tk-surface: #ffffff;
    --tk-border: #e2e8f0;
    --tk-text-main: #0f172a;
    --tk-text-muted: #64748b;
    --tk-radius: 16px;
}

.token-header-banner {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    border-radius: var(--tk-radius);
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
.token-header-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, rgba(99, 102, 241, 0) 70%);
    pointer-events: none;
}
.token-header-title { display: flex; align-items: center; gap: 16px; }
.token-icon-badge {
    width: 50px; height: 50px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #a5b4fc; flex-shrink: 0;
}
.token-header-text h2 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 24px; font-weight: 800;
    margin: 0 0 4px; color: #ffffff;
    letter-spacing: -0.01em;
}
.token-header-text p { font-size: 13px; color: #cbd5e1; margin: 0; }

.limit-badge-pill {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(8px);
    padding: 10px 18px;
    border-radius: 12px;
    color: #ffffff;
    font-size: 13px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 8px;
}

/* Callouts */
.tk-alert {
    padding: 14px 18px; border-radius: 12px; font-size: 13px;
    margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-weight: 600;
}
.tk-alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.tk-alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

/* Grid for Adding Tokens */
.methods-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.method-card {
    background: var(--tk-surface);
    border: 1px solid var(--tk-border);
    border-radius: var(--tk-radius);
    padding: 24px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    display: flex; flex-direction: column; justify-content: space-between;
    transition: all 0.2s ease;
}
.method-card:hover {
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
    border-color: #cbd5e1;
}

.method-card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px;
}
.method-card-title {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 16px; font-weight: 800; color: var(--tk-text-main);
    display: flex; align-items: center; gap: 8px;
}
.method-card-desc {
    font-size: 13px; color: var(--tk-text-muted); line-height: 1.5; margin-bottom: 16px;
}

/* OAuth Button */
.fb-oauth-btn {
    background: linear-gradient(135deg, #1877f2 0%, #166fe5 100%);
    color: #ffffff;
    border: none; border-radius: 12px;
    padding: 14px 20px;
    font-size: 14px; font-weight: 700;
    text-decoration: none;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    box-shadow: 0 8px 20px rgba(24, 119, 242, 0.25);
    transition: all 0.2s ease;
}
.fb-oauth-btn:hover { transform: translateY(-1px); box-shadow: 0 12px 25px rgba(24, 119, 242, 0.35); color: #ffffff; }

.video-guide-btn {
    background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
    padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;
    text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
    transition: all 0.2s;
}
.video-guide-btn:hover { background: #fee2e2; color: #b91c1c; }

/* Custom Textarea */
.st-textarea-token {
    width: 100%;
    padding: 12px;
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    font-family: monospace; font-size: 12px; color: #0f172a;
    resize: vertical; transition: all 0.2s; box-sizing: border-box;
}
.st-textarea-token:focus {
    outline: none; border-color: var(--tk-primary); background: #ffffff;
    box-shadow: 0 0 0 4px var(--tk-primary-glow);
}

/* Token List Card & Toolbar */
.list-card {
    background: var(--tk-surface);
    border: 1px solid var(--tk-border);
    border-radius: var(--tk-radius);
    padding: 26px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
}

.list-toolbar {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 16px; margin-bottom: 20px;
}
.list-toolbar h3 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 18px; font-weight: 800; color: var(--tk-text-main); margin: 0;
}

.search-box-wrapper { position: relative; min-width: 240px; }
.search-box-wrapper input {
    width: 100%; padding: 10px 14px 10px 38px;
    background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 10px;
    font-size: 13px; font-weight: 500; color: var(--tk-text-main);
    transition: all 0.2s; box-sizing: border-box;
}
.search-box-wrapper input:focus {
    outline: none; border-color: var(--tk-primary); background: #ffffff;
    box-shadow: 0 0 0 4px var(--tk-primary-glow);
}
.search-box-wrapper svg {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: #94a3b8; pointer-events: none;
}

/* Filter Tabs */
.token-pills-bar {
    display: flex; background: #f1f5f9; padding: 4px; border-radius: 10px; gap: 4px;
}
.tk-pill {
    padding: 6px 14px; border: none; border-radius: 8px;
    font-size: 12px; font-weight: 700; cursor: pointer;
    background: transparent; color: #64748b; transition: all 0.2s;
}
.tk-pill.active { background: #ffffff; color: var(--tk-text-main); box-shadow: 0 2px 6px rgba(0,0,0,0.06); }

/* Modern Table */
.tk-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.tk-table th {
    background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 16px;
    border-bottom: 1.5px solid #e2e8f0; text-align: left;
}
.tk-table td {
    padding: 16px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px;
    color: var(--tk-text-main); vertical-align: middle;
}
.tk-table tr:last-child td { border-bottom: none; }
.tk-table tr:hover td { background: #f8fafc; }

/* Status Badges */
.badge-live {
    background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;
    padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
    display: inline-flex; align-items: center; gap: 5px;
}
.badge-cp {
    background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;
    padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
    display: inline-flex; align-items: center; gap: 5px;
}

/* Action Buttons Group */
.act-btn {
    padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;
    border: none; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s;
}
.act-btn-edit { background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; }
.act-btn-edit:hover { background: #4f46e5; color: #ffffff; }

.act-btn-drive { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.act-btn-drive:hover { background: #059669; color: #ffffff; }

.act-btn-disc { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }
.act-btn-disc:hover { background: #e11d48; color: #ffffff; }

.act-btn-del { background: transparent; color: #ef4444; border: none; font-weight: 700; }
.act-btn-del:hover { text-decoration: underline; color: #b91c1c; }

/* Glassmorphism Modals */
.tk-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(6px);
    align-items: center; justify-content: center; padding: 20px;
}
.tk-modal-card {
    background: #ffffff; border-radius: 20px; padding: 30px;
    max-width: 480px; width: 100%; box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
    position: relative; animation: modalFadeIn 0.25s ease-out;
}
@keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
</style>

<!-- Header Banner -->
<div class="token-header-banner">
    <div class="token-header-title">
        <div class="token-icon-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div class="token-header-text">
            <h2>Quản lý Token Facebook</h2>
            <p>Quản lý danh sách Access Token, kết nối Fanpage &amp; tích hợp Google API</p>
        </div>
    </div>

    <div class="limit-badge-pill">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span>Giới hạn Fanpage: <strong><?php echo number_format($total_connected_pages); ?></strong> / <strong><?php echo $max_fb_display; ?></strong></span>
    </div>
</div>

<!-- Flash Alerts -->
<?php if ($alert_message): ?>
    <div class="tk-alert tk-alert-<?php echo $alert_type === 'success' ? 'success' : 'danger'; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?php echo $alert_message; ?></span>
    </div>
<?php endif; ?>

<!-- Grid for Adding Tokens -->
<div class="methods-grid">
    <!-- Phương thức 1: OAuth -->
    <div class="method-card">
        <div>
            <div class="method-card-head">
                <div class="method-card-title">
                    <span style="color:#1877f2;">🔵</span> Phương Thức 1: Facebook App OAuth
                </div>
            </div>
            <p class="method-card-desc">
                Cấp quyền tự động bằng đăng nhập Facebook App. Hệ thống sẽ tự động lấy Token và đồng bộ toàn bộ Fanpage &amp; Instagram liên kết.
            </p>
        </div>

        <?php if ($login_url): ?>
            <div>
                <a href="<?php echo htmlspecialchars($login_url); ?>" target="_blank" class="fb-oauth-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    <span>Đăng Nhập Facebook Ngay</span>
                </a>
                <p style="font-size: 11px; color: var(--tk-text-muted); margin-top: 10px; text-align: center;">
                    Bao gồm quyền: pages_manage_posts, instagram_content_publish,...
                </p>
            </div>
        <?php else: ?>
            <div style="font-size: 12px; color: #92400e; background: #fffbeb; padding: 12px 14px; border-radius: 10px; border: 1px solid #fde68a;">
                ⚠️ Chưa cấu hình Facebook App ID trong phần Cài Đặt Hệ Thống.
            </div>
        <?php endif; ?>
    </div>

    <!-- Phương thức 2: Manual Tokens -->
    <div class="method-card">
        <div>
            <div class="method-card-head">
                <div class="method-card-title">
                    <span style="color:#d97706;">🔑</span> Nhập Token Thủ Công (Nhiều Token)
                </div>
                <a href="https://www.youtube.com/watch?v=xG6BdQ8GlH4" target="_blank" onclick="openTokenGuideVideo(event)" class="video-guide-btn">
                    ▶️ Video Hướng Dẫn
                </a>
            </div>
            <p class="method-card-desc">
                Dán danh sách Access Token (EAAG...). Hỗ trợ dán nhiều Token cùng lúc (mỗi Token 1 dòng).
            </p>
        </div>

        <form method="POST" action="actions/save_token.php">
            <?php echo csrf_field(); ?>
            <textarea name="access_tokens" rows="3" class="st-textarea-token" placeholder="EAAG...&#10;EAAG... (Nhập mỗi Token 1 dòng)" required></textarea>
            
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 12px;">
                <label style="font-size: 12px; color: #475569; cursor: pointer; display: flex; align-items: center; gap: 6px; font-weight:600;">
                    <input type="checkbox" name="overwrite_conflict" value="1" style="width:16px; height:16px; accent-color:var(--tk-primary);"> Ghi đè nếu trùng Fanpage
                </label>
                <button type="submit" class="act-btn act-btn-edit" style="background:var(--tk-primary); color:#fff; border:none; padding:10px 18px; border-radius:10px;">
                    <span>➕ Thêm &amp; Đồng Bộ</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Token List Card -->
<div class="list-card">
    <div class="list-toolbar">
        <h3>Danh Sách Token Đã Lưu</h3>

        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <!-- Search Box -->
            <div class="search-box-wrapper">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="tokenSearchInput" onkeyup="filterTokenList()" placeholder="Tìm tên người dùng...">
            </div>

            <!-- Filter Pills -->
            <div class="token-pills-bar">
                <button type="button" onclick="setTokenFilter('all')" id="btn-filter-all" class="tk-pill active">
                    Tất cả (<?php echo $total_tokens; ?>)
                </button>
                <button type="button" onclick="setTokenFilter('live')" id="btn-filter-live" class="tk-pill">
                    🟢 Live (<?php echo $live_tokens; ?>)
                </button>
                <button type="button" onclick="setTokenFilter('checkpoint')" id="btn-filter-checkpoint" class="tk-pill">
                    🚫 Checkpoint (<?php echo $checkpoint_tokens; ?>)
                </button>
            </div>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="tk-table" id="tokenTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tên Người Dùng</th>
                    <th>Trạng Thái</th>
                    <th>Fanpage</th>
                    <?php if ($is_admin || $drive_multi_api): ?>
                        <th>API &amp; Drive</th>
                    <?php endif; ?>
                    <th>Ngày Thêm</th>
                    <th>Thao Tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($users) > 0): ?>
                    <?php foreach ($users as $u): 
                        $status = $u['computed_status'];
                    ?>
                        <tr class="token-row" data-status="<?php echo $status; ?>" data-user-name="<?php echo htmlspecialchars(mb_strtolower($u['name'] ?? '', 'UTF-8')); ?>">
                            <td style="font-weight:700; color:#64748b;"><?php echo $u['id']; ?></td>
                            <td>
                                <div style="font-weight: 700; color: var(--tk-text-main); font-size:14px;">
                                    <?php echo htmlspecialchars($u['name']); ?>
                                </div>
                                <?php if (!empty($u['proxy_ip'])): ?>
                                    <div style="margin-top: 4px;">
                                        <a href="proxy.php" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 2px 8px; border-radius: 6px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="<?php echo htmlspecialchars($u['proxy_string']); ?>">
                                            🌐 Proxy: <?php echo htmlspecialchars($u['proxy_ip']); ?> (<?php echo $u['proxy_status'] === 'live' ? 'Sống ✓' : ($u['proxy_status'] === 'dead' ? 'Chết ✗' : 'Chưa thử'); ?>)
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($status === 'checkpoint'): ?>
                                    <span class="badge-cp" title="Tài khoản bị Checkpoint hoặc cần kết nối lại">
                                        🚫 Checkpoint
                                    </span>
                                <?php else: ?>
                                    <span class="badge-live">
                                        🟢 Live
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 800; color: var(--tk-primary); font-size:15px;"><?php echo (int) $u['page_count']; ?></td>
                            <?php if ($is_admin || $drive_multi_api): ?>
                                <td>
                                    <?php if (!empty($u['gg_client_id'])): ?>
                                        <span style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 700;">API Riêng</span><br>
                                        <small style="color: var(--tk-text-muted); font-size: 10.5px;">ID: <?php echo htmlspecialchars(substr($u['gg_client_id'], 0, 14)); ?>...</small><br>
                                    <?php else: ?>
                                        <span style="background: #f1f5f9; color: #64748b; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">API Mặc định</span><br>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($u['gg_refresh_token'])): ?>
                                        <span style="background: #ecfdf5; color: #047857; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-top: 3px; display: inline-block;">Drive Riêng: ✅</span>
                                    <?php else: ?>
                                        <span style="background: #f1f5f9; color: #64748b; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500; margin-top: 3px; display: inline-block;">Drive Mặc định</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td style="color:#64748b; font-size:12.5px;"><?php echo htmlspecialchars($u['created_at']); ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <?php if ($is_admin || $drive_multi_api): ?>
                                        <button type="button" onclick="openEditApiModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($u['gg_client_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($u['gg_client_secret'] ?? '', ENT_QUOTES); ?>')" class="act-btn act-btn-edit">
                                            Sửa API
                                        </button>
                                        
                                        <a href="google_login.php?user_id=<?php echo $u['id']; ?>" class="act-btn act-btn-drive">
                                            Kết nối Drive
                                        </a>
                                        
                                        <?php if (!empty($u['gg_refresh_token'])): ?>
                                            <form method="POST" action="token_management.php" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn ngắt kết nối Drive của tài khoản này?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="disconnect_gg">
                                                <input type="hidden" name="user_id_db" value="<?php echo $u['id']; ?>">
                                                <button type="submit" class="act-btn act-btn-disc">Hủy Drive</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <form id="del-form-<?php echo $u['id']; ?>" method="POST" action="actions/delete_token.php" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                        <button type="button" onclick="showDeleteModal(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['name'])); ?>')" class="act-btn act-btn-del">
                                            Xóa
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo ($is_admin || $drive_multi_api) ? 7 : 6; ?>" style="text-align:center; color:#64748b; padding: 28px;">Chưa có token nào được lưu trong hệ thống.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Xóa Token -->
<div id="delete-modal" class="tk-modal-overlay">
    <div class="tk-modal-card" style="text-align:center;">
        <div style="font-size:46px; margin-bottom:12px;">🗑️</div>
        <h3 style="margin:0 0 8px; color:#0f172a; font-size:20px; font-weight:800;">Xác Nhận Xóa Token</h3>
        <p style="color:#64748b; font-size:14px; margin:0 0 24px; line-height:1.5;">
            Bạn có chắc chắn muốn xóa token của tài khoản<br>
            <strong id="modal-user-name" style="color:#0f172a; font-weight:700;"></strong>?<br>
            <span style="color:#ef4444; font-size:12px; font-weight:600;">⚠️ Thao tác này không thể hoàn tác.</span>
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button onclick="closeDeleteModal()" style="flex:1; padding:12px; border:1.5px solid #cbd5e1; border-radius:12px; background:#ffffff; color:#334155; font-size:14px; font-weight:700; cursor:pointer;">
                Hủy Bỏ
            </button>
            <button id="modal-confirm-btn" onclick="confirmDelete()" style="flex:1; padding:12px; border:none; border-radius:12px; background:#ef4444; color:#ffffff; font-size:14px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(239,68,68,0.25);">
                Xóa Token
            </button>
        </div>
    </div>
</div>

<!-- Modal Sửa API Google -->
<div id="editApiModal" class="tk-modal-overlay">
    <div class="tk-modal-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h3 style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">Sửa Google API: <span id="e_user_name_label" style="color:var(--tk-primary);"></span></h3>
            <button onclick="closeEditApiModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <form method="POST" action="token_management.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_api">
            <input type="hidden" name="user_id_db" id="e_user_id_db">
            
            <div style="margin-bottom: 16px;">
                <label style="font-size:12px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:6px; display:block;">Google Client ID riêng</label>
                <input type="text" id="e_gg_client_id" name="gg_client_id" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; box-sizing:border-box;">
            </div>
            
            <div style="margin-bottom: 24px;">
                <label style="font-size:12px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:6px; display:block;">Google Client Secret riêng</label>
                <input type="text" id="e_gg_client_secret" name="gg_client_secret" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; box-sizing:border-box;">
            </div>
            
            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button type="button" onclick="closeEditApiModal()" style="padding:10px 18px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-weight:700; cursor:pointer;">Hủy</button>
                <button type="submit" style="padding:10px 18px; background:var(--tk-primary); color:#ffffff; border:none; border-radius:10px; font-weight:700; cursor:pointer;">Lưu Thay Đổi</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Video Hướng Dẫn -->
<div id="tokenGuideVideoModal" class="tk-modal-overlay">
    <div class="tk-modal-card" style="max-width:760px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h4 style="margin:0; font-size:16px; color:#0f172a; font-weight:800; display:flex; align-items:center; gap:6px;">🎬 Hướng Dẫn Lấy Token Facebook</h4>
            <button onclick="closeTokenGuideVideo()" style="border:none; background:none; font-size:22px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <div style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; border-radius:12px; background:#000;">
            <iframe id="tokenGuideIframe" src="" style="position:absolute; top:0; left:0; width:100%; height:100%; border:0;" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; font-size:13px;">
            <span style="color:#64748b;">Video hướng dẫn chi tiết lấy Token cá nhân</span>
            <a href="https://www.youtube.com/watch?v=xG6BdQ8GlH4" target="_blank" style="color:#dc2626; font-weight:700; text-decoration:none;">Xem trên YouTube ↗</a>
        </div>
    </div>
</div>

<script>
    let currentFilterStatus = 'all';

    function setTokenFilter(status) {
        currentFilterStatus = status;
        document.querySelectorAll('.tk-pill').forEach(btn => btn.classList.remove('active'));
        const activeBtn = document.getElementById('btn-filter-' + status);
        if (activeBtn) activeBtn.classList.add('active');

        filterTokenList();
    }

    function filterTokenList() {
        const searchInput = document.getElementById('tokenSearchInput');
        const searchVal = searchInput ? (searchInput.value || '').trim().toLowerCase() : '';
        const rows = document.querySelectorAll('#tokenTable tbody tr.token-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const userName = (row.getAttribute('data-user-name') || '').toLowerCase();
            const rowStatus = row.getAttribute('data-status') || '';

            const matchesSearch = !searchVal || userName.includes(searchVal);
            const matchesStatus = (currentFilterStatus === 'all') || (rowStatus === currentFilterStatus);

            if (matchesSearch && matchesStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        let noMatchRow = document.getElementById('noMatchRow');
        const colSpanCount = <?php echo ($is_admin || $drive_multi_api) ? 7 : 6; ?>;
        
        if (visibleCount === 0 && rows.length > 0) {
            if (!noMatchRow) {
                noMatchRow = document.createElement('tr');
                noMatchRow.id = 'noMatchRow';
                noMatchRow.innerHTML = '<td colspan="' + colSpanCount + '" style="text-align:center; padding:24px; color:#64748b;">Không tìm thấy tài khoản phù hợp với tìm kiếm / bộ lọc.</td>';
                document.querySelector('#tokenTable tbody').appendChild(noMatchRow);
            } else {
                noMatchRow.style.display = '';
            }
        } else if (noMatchRow) {
            noMatchRow.style.display = 'none';
        }
    }

    var _deleteFormId = null;

    function showDeleteModal(userId, userName) {
        _deleteFormId = 'del-form-' + userId;
        document.getElementById('modal-user-name').textContent = userName;
        var modal = document.getElementById('delete-modal');
        modal.style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').style.display = 'none';
        _deleteFormId = null;
    }

    function confirmDelete() {
        if (_deleteFormId) {
            document.getElementById(_deleteFormId).submit();
        }
    }

    document.getElementById('delete-modal').addEventListener('click', function (e) {
        if (e.target === this) closeDeleteModal();
    });

    function openEditApiModal(id, name, client_id, client_secret) {
        document.getElementById('e_user_id_db').value = id;
        document.getElementById('e_user_name_label').textContent = name;
        document.getElementById('e_gg_client_id').value = client_id;
        document.getElementById('e_gg_client_secret').value = client_secret;
        document.getElementById('editApiModal').style.display = 'flex';
    }
    
    function closeEditApiModal() {
        document.getElementById('editApiModal').style.display = 'none';
    }

    function openTokenGuideVideo(e) {
        if (e) e.preventDefault();
        var modal = document.getElementById('tokenGuideVideoModal');
        var iframe = document.getElementById('tokenGuideIframe');
        if (iframe) {
            iframe.src = 'https://www.youtube.com/embed/xG6BdQ8GlH4?autoplay=1';
        }
        if (modal) {
            modal.style.display = 'flex';
        }
    }

    function closeTokenGuideVideo() {
        var modal = document.getElementById('tokenGuideVideoModal');
        var iframe = document.getElementById('tokenGuideIframe');
        if (iframe) {
            iframe.src = '';
        }
        if (modal) {
            modal.style.display = 'none';
        }
    }

    document.getElementById('tokenGuideVideoModal')?.addEventListener('click', function (e) {
        if (e.target === this) closeTokenGuideVideo();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeDeleteModal();
            closeEditApiModal();
            closeTokenGuideVideo();
        }
    });
</script>

<?php include 'includes/footer.php'; ?>