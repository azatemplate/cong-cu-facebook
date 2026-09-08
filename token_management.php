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

if (isset($_SESSION['flash_msg'])) {
    $alert_type = 'success';
    $alert_message = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
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
        (SELECT COUNT(id) FROM pages WHERE user_id = u.id) as page_count,
        (SELECT COUNT(sp.id) 
         FROM scheduled_posts sp 
         JOIN pages p ON sp.page_id = p.page_id 
         WHERE p.user_id = u.id 
           AND (sp.status = 'checkpoint' OR sp.error_msg LIKE '%checkpoint%' OR sp.error_msg LIKE '%log in%')
        ) as checkpoint_count
    FROM users u 
    LEFT JOIN proxies px ON u.proxy_id = px.id
    WHERE u.account_id = ? 
    ORDER BY u.created_at DESC
");
$stmt->execute([$account_id]);
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

$fb_permissions = "pages_manage_metadata,pages_manage_engagement,business_management,pages_show_list,pages_manage_posts,pages_read_engagement,read_insights,pages_messaging,public_profile,instagram_basic,instagram_content_publish,instagram_manage_comments,instagram_manage_insights,instagram_manage_messages";
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . get_base_url() . "redirect_callback.php";

$login_url = "";
if ($fb_app_id) {
    $login_url = "https://www.facebook.com/v25.0/dialog/oauth?client_id=" . urlencode($fb_app_id) . "&redirect_uri=" . urlencode($redirect_uri) . "&scope=" . urlencode($fb_permissions) . "&response_type=token";
}
?>

<div class="page-title">Quản lý Token</div>

<?php if ($alert_message): ?>
    <div class="alert alert-<?php echo $alert_type; ?>">
        <?php echo $alert_message; ?>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom: 24px;">
    <h3 style="margin-bottom: 15px;">Thêm Mới / Cập Nhật Token Facebook</h3>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
        <!-- Phương thức 1: Đăng nhập Facebook App -->
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 18px; border-radius: 8px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="font-weight: 700; color: #166534; font-size: 15px; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                    <span>🔵</span> Phương Thức 1: Đăng Nhập Facebook App
                </div>
                <p style="font-size: 13px; color: #15803d; margin-bottom: 12px; line-height: 1.5;">
                    Cấp quyền tự động thông qua Facebook App. Hệ thống sẽ tự lấy Token và đồng bộ tất cả Fanpage & Instagram.
                </p>
            </div>
            <?php if ($login_url): ?>
                <div>
                    <a href="<?php echo htmlspecialchars($login_url); ?>" target="_blank" class="btn btn-primary"
                        style="background: #1877f2; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-weight: bold; width: 100%; box-sizing: border-box;">
                        <span style="font-weight: bold; font-size: 16px;">f</span> Đăng Nhập Facebook
                    </a>
                    <p style="font-size: 11px; color: var(--text-muted); margin-top: 8px; margin-bottom: 0; text-align: center;">
                        Quyền: pages_manage_posts, instagram_content_publish, instagram_manage_comments,...
                    </p>
                </div>
            <?php else: ?>
                <div style="font-size: 12px; color: #b45309; background: #fffbeb; padding: 8px 10px; border-radius: 6px; border: 1px solid #fde68a;">
                    ⚠️ Chưa cấu hình Facebook App ID trong phần Cài Đặt Hệ Thống.
                </div>
            <?php endif; ?>
        </div>

        <!-- Phương thức 2: Nhập Token Thủ Công (Nhiều Token) -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 18px; border-radius: 8px;">
            <div style="font-weight: 700; color: #1e293b; font-size: 15px; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span>🔑</span> Phương Thức 2: Nhập Token Thủ Công (Nhiều Token)
                </div>
                <a href="https://www.youtube.com/watch?v=xG6BdQ8GlH4" target="_blank" onclick="openTokenGuideVideo(event)"
                   style="display: inline-flex; align-items: center; gap: 5px; color: #dc2626; font-weight: 600; font-size: 12px; text-decoration: none; background: #fef2f2; padding: 4px 10px; border-radius: 6px; border: 1px solid #fca5a5; white-space: nowrap;">
                   ▶️ Video Hướng Dẫn Lấy Token ↗
                </a>
            </div>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 10px; line-height: 1.4;">
                Tự dán danh sách Access Token (EAAG...). Nhập nhiều Token cùng lúc, mỗi Token 1 dòng.
            </p>
            <form method="POST" action="actions/save_token.php">
                <?php echo csrf_field(); ?>
                <textarea name="access_tokens" rows="3" placeholder="EAAG...&#10;EAAG... (Nhập mỗi Token 1 dòng)" 
                    style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-family: monospace; font-size: 12px; background: var(--card-bg); color: var(--text-main); box-sizing: border-box; margin-bottom: 10px; resize: vertical;" required></textarea>
                
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <label style="font-size: 12px; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; gap: 5px;">
                        <input type="checkbox" name="overwrite_conflict" value="1"> Ghi đè nếu trùng Fanpage
                    </label>
                    <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-weight: bold; font-size: 13px;">
                        ➕ Thêm & Đồng Bộ Token
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Video Hướng Dẫn Lấy Token -->
<div id="tokenGuideVideoModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:20px; max-width:760px; width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.3); position:relative;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h4 style="margin:0; font-size:16px; color:#111; display:flex; align-items:center; gap:6px;">🎬 Hướng Dẫn Lấy Token Facebook</h4>
            <button onclick="closeTokenGuideVideo()" style="border:none; background:none; font-size:22px; cursor:pointer; color:#666; padding:0 4px;">✕</button>
        </div>
        <div style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; border-radius:8px; background:#000;">
            <iframe id="tokenGuideIframe" src="" style="position:absolute; top:0; left:0; width:100%; height:100%; border:0;" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; font-size:13px;">
            <span style="color:var(--text-muted);">Video hướng dẫn cách lấy Token Facebook cá nhân</span>
            <a href="https://www.youtube.com/watch?v=xG6BdQ8GlH4" target="_blank" style="color:#dc2626; font-weight:600; text-decoration:none;">Xem trên YouTube ↗</a>
        </div>
    </div>
</div>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
        <h3 style="margin:0;">Danh sách Token Đã Lưu</h3>

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <!-- Ô Tìm kiếm Tên Người Dùng -->
            <div style="position:relative; min-width:200px;">
                <input type="text" id="tokenSearchInput" onkeyup="filterTokenList()" placeholder="Tìm tên người dùng..." 
                    style="width:100%; padding:7px 12px 7px 30px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; background:var(--card-bg); color:var(--text-main); box-sizing:border-box;">
                <span style="position:absolute; left:9px; top:50%; transform:translateY(-50%); font-size:13px; color:var(--text-muted); pointer-events:none;">🔍</span>
            </div>

            <!-- Bộ lọc All, Live, Checkpoint -->
            <div class="token-filter-tabs" style="display:flex; background:#f1f5f9; padding:3px; border-radius:8px; gap:2px; border:1px solid var(--border-color);">
                <button type="button" onclick="setTokenFilter('all')" id="btn-filter-all" class="token-filter-btn" style="padding:5px 12px; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:#ffffff; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                    Tất cả (<?php echo $total_tokens; ?>)
                </button>
                <button type="button" onclick="setTokenFilter('live')" id="btn-filter-live" class="token-filter-btn" style="padding:5px 12px; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:transparent; color:#047857;">
                    🟢 Live (<?php echo $live_tokens; ?>)
                </button>
                <button type="button" onclick="setTokenFilter('checkpoint')" id="btn-filter-checkpoint" class="token-filter-btn" style="padding:5px 12px; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:transparent; color:#b91c1c;">
                    🚫 Checkpoint (<?php echo $checkpoint_tokens; ?>)
                </button>
            </div>
        </div>
    </div>

    <table style="min-width:650px;" id="tokenTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tên Người Dùng</th>
                <th>Trạng Thái</th>
                <th>Số Fanpage</th>
                <?php if ($is_admin || $drive_multi_api): ?>
                    <th>Cấu hình API / Drive</th>
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
                        <td><?php echo $u['id']; ?></td>
                        <td style="font-weight: 600; color: var(--text-main);">
                            <?php echo htmlspecialchars($u['name']); ?>
                            <?php if (!empty($u['proxy_ip'])): ?>
                                <div style="margin-top: 4px;">
                                    <a href="proxy.php" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;" title="<?php echo htmlspecialchars($u['proxy_string']); ?>">
                                        🌐 <?php echo htmlspecialchars($u['proxy_ip']); ?> (<?php echo $u['proxy_status'] === 'live' ? 'Sống ✓' : ($u['proxy_status'] === 'dead' ? 'Chết ✗' : 'Chưa thử'); ?>)
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($status === 'checkpoint'): ?>
                                <span class="status-tag" style="background:#fee2e2; color:#991b1b; font-size:11px; padding:3px 8px; border-radius:12px; font-weight:600; border:1px solid #fca5a5; display:inline-flex; align-items:center; gap:4px;" title="Tài khoản bị Checkpoint hoặc cần kết nối lại">
                                    🚫 Checkpoint
                                </span>
                            <?php else: ?>
                                <span class="status-tag" style="background:#ecfdf5; color:#047857; font-size:11px; padding:3px 8px; border-radius:12px; font-weight:600; border:1px solid #a7f3d0; display:inline-flex; align-items:center; gap:4px;">
                                    🟢 Live
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight: bold; color: var(--primary-color);"><?php echo (int) $u['page_count']; ?></td>
                        <?php if ($is_admin || $drive_multi_api): ?>
                            <td>
                                <?php if (!empty($u['gg_client_id'])): ?>
                                    <span class="status-tag" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">API Riêng</span><br>
                                    <small style="color: var(--text-muted); font-size: 11px; word-break: break-all;">ID: <?php echo htmlspecialchars(substr($u['gg_client_id'], 0, 15)); ?>...</small><br>
                                <?php else: ?>
                                    <span class="status-tag" style="background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">Mặc định</span><br>
                                <?php endif; ?>
                                
                                <?php if (!empty($u['gg_refresh_token'])): ?>
                                    <span class="status-tag" style="background: #ecfdf5; color: #047857; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500; margin-top: 3px; display: inline-block;">Drive: Đã liên kết</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                        <td>
                            <?php if ($is_admin || $drive_multi_api): ?>
                                <button type="button" onclick="openEditApiModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($u['gg_client_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($u['gg_client_secret'] ?? '', ENT_QUOTES); ?>')" class="btn" style="background: var(--primary-color); color: white; padding: 4px 8px; font-size: 12px; margin-right: 5px; border: none; border-radius: 4px; cursor: pointer;">Sửa API</button>
                                
                                <a href="google_login.php?user_id=<?php echo $u['id']; ?>" class="btn" style="background: #10b981; color: white; padding: 4.5px 8px; font-size: 12px; margin-right: 5px; text-decoration: none; border-radius: 4px; display: inline-block;">Kết nối Drive</a>
                                
                                <?php if (!empty($u['gg_refresh_token'])): ?>
                                    <form method="POST" action="token_management.php" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn ngắt kết nối Drive của tài khoản này?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="disconnect_gg">
                                        <input type="hidden" name="user_id_db" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn" style="background: #f43f5e; color: white; padding: 4px 8px; font-size: 12px; margin-right: 5px; border: none; border-radius: 4px; cursor: pointer;">Hủy Drive</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <form id="del-form-<?php echo $u['id']; ?>" method="POST" action="actions/delete_token.php"
                                style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="button"
                                    onclick="showDeleteModal(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['name'])); ?>')"
                                    style="background:none; border:none; cursor:pointer; text-decoration:underline; color:var(--red-danger); font-size:13px; padding:0;">
                                    Xóa
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?php echo ($is_admin || $drive_multi_api) ? 7 : 6; ?>" style="text-align:center; color:#6b7280; padding: 20px;">Chưa có token nào.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Custom Delete Confirmation Modal -->
<div id="delete-modal"
    style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.55); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:28px 32px; max-width:380px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,0.25); text-align:center;">
        <div style="font-size:40px; margin-bottom:12px;">🗑️</div>
        <h3 style="margin:0 0 8px; color:#111; font-size:17px;">Xác nhận xóa Token</h3>
        <p style="color:#6b7280; font-size:14px; margin:0 0 20px;">
            Bạn có chắc muốn xóa token của<br>
            <strong id="modal-user-name" style="color:#111;"></strong>?<br>
            <span style="color:#ef4444; font-size:12px;">Thao tác này không thể hoàn tác.</span>
        </p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button onclick="closeDeleteModal()"
                style="padding:9px 24px; border:1px solid #d1d5db; border-radius:8px; background:#f9fafb; color:#374151; font-size:14px; cursor:pointer; font-weight:500;">
                Huỷ
            </button>
            <button id="modal-confirm-btn" onclick="confirmDelete()"
                style="padding:9px 24px; border:none; border-radius:8px; background:#ef4444; color:#fff; font-size:14px; cursor:pointer; font-weight:600;">
                Xóa Token
            </button>
        </div>
    </div>
</div>

<!-- Modal Sửa API Google của Token -->
<div id="editApiModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:25px; border-radius:8px; width:100%; max-width:450px; position:relative; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
        <h3 style="margin-top:0;">Sửa Cấu Hình Google API: <span id="e_user_name_label" style="color:var(--primary-color);"></span></h3>
        <form method="POST" action="token_management.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_api">
            <input type="hidden" name="user_id_db" id="e_user_id_db">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client ID riêng</label>
                <input type="text" id="e_gg_client_id" name="gg_client_id" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
            </div>
            
            <div class="form-group" style="margin-bottom: 25px;">
                <label style="font-weight: 500; display: block; margin-bottom: 5px;">Google Client Secret riêng</label>
                <input type="text" id="e_gg_client_secret" name="gg_client_secret" placeholder="Để trống để dùng API mặc định..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:6px; box-sizing: border-box;">
            </div>
            
            <div style="text-align: right;">
                <button type="button" onclick="closeEditApiModal()" class="btn" style="background:#f3f4f6; color:#374151; margin-right:10px; border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Hủy</button>
                <button type="submit" class="btn btn-primary" style="border: none; border-radius: 4px; padding: 8px 15px; cursor: pointer;">Lưu Thay Đổi</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentFilterStatus = 'all';

    function setTokenFilter(status) {
        currentFilterStatus = status;
        
        const btnAll = document.getElementById('btn-filter-all');
        const btnLive = document.getElementById('btn-filter-live');
        const btnCheck = document.getElementById('btn-filter-checkpoint');

        [btnAll, btnLive, btnCheck].forEach(btn => {
            if (btn) {
                btn.style.background = 'transparent';
                btn.style.boxShadow = 'none';
            }
        });

        const activeBtn = document.getElementById('btn-filter-' + status);
        if (activeBtn) {
            activeBtn.style.background = '#ffffff';
            activeBtn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
        }

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
                noMatchRow.innerHTML = '<td colspan="' + colSpanCount + '" style="text-align:center; padding:20px; color:var(--text-muted);">Không tìm thấy tài khoản phù hợp với tìm kiếm / bộ lọc.</td>';
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

    // Đóng modal khi bấm vào vùng tối bên ngoài
    document.getElementById('delete-modal').addEventListener('click', function (e) {
        if (e.target === this) closeDeleteModal();
    });

    // Đóng modal khi nhấn Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDeleteModal();
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