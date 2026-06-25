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

// Fetch existing tokens
$stmt = $pdo->prepare("SELECT u.*, (SELECT COUNT(id) FROM pages WHERE user_id = u.id) as page_count FROM users u WHERE u.account_id = ? ORDER BY u.created_at DESC");
$stmt->execute([$account_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get FB App ID to generate login URL (from user first, fallback to admin)
$stmt_app = $pdo->prepare("SELECT fb_app_id FROM system_accounts WHERE id = ?");
$stmt_app->execute([$account_id]);
$fb_app_id = $stmt_app->fetchColumn();

if (empty($fb_app_id)) {
    $stmt_admin = $pdo->prepare("SELECT fb_app_id FROM system_accounts WHERE role = 'admin' LIMIT 1");
    $stmt_admin->execute();
    $fb_app_id = $stmt_admin->fetchColumn();
}

$fb_permissions = "pages_manage_metadata,pages_manage_engagement,business_management,pages_show_list,pages_manage_posts,pages_read_engagement,read_insights,pages_messaging,public_profile";
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

<div class="card">
    <h3 style="margin-bottom: 15px;">Thêm Mới / Cập nhật Token</h3>

    <?php if ($login_url): ?>
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <p style="margin-top: 0; color: #166534; font-weight: 500;">Bấm Đăng Nhập Facebook Để Đăng Nhập</p>
            <p style="font-size: 13px; color: #15803d; margin-bottom: 10px;">
                Nhấn nút bên dưới để cấp quyền thông qua Facebook App của bạn.
            </p>
            <a href="<?php echo htmlspecialchars($login_url); ?>" target="_blank" class="btn btn-primary"
                style="background: #1877f2; display: inline-flex; align-items: center; gap: 8px;">
                <span style="font-weight: bold; font-size: 16px;">f</span> Đăng Nhập Facebook
            </a>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 10px; margin-bottom: 0;">
                <em>Quyền yêu cầu: pages_manage_posts, read_insights, pages_read_engagement, pages_show_list,
                    pages_messaging, public_profile.</em><br>
                Hệ thống sẽ tự nhận diện Token, bạn chỉ việc trải nghiệm!
            </p>
        </div>
    <?php else: ?>
        <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <p style="margin-top: 0; color: #92400e; font-weight: 500;">Thông báo: Tính năng Lấy Token Tự Động chưa sẵn sàng</p>
            <p style="font-size: 13px; color: #b45309; margin-bottom: 0;">
                Bạn chưa cấu hình Facebook App ID trong phần <a href="settings.php"
                    style="color: #ea580c; font-weight: bold;">Cài Đặt Hệ Thống</a>, và Admin cũng chưa cung cấp cấu hình
                dùng chung nên hệ thống chưa thể tạo URL Đăng Nhập.
            </p>
        </div>
    <?php endif; ?>

    <hr style="border-top: 1px solid var(--border-color); margin-bottom: 20px;">
</div>

<div class="card">
    <h3 style="margin-bottom: 15px;">Danh sách Token Đã Lưu</h3>
    <table style="min-width:550px;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tên Người Dùng</th>
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
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
                        <td><?php echo htmlspecialchars($u['name']); ?></td>
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
                    <td colspan="<?php echo ($is_admin || $drive_multi_api) ? 6 : 5; ?>" style="text-align:center; color:#6b7280;">Chưa có token nào.</td>
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
</script>

<?php include 'includes/footer.php'; ?>