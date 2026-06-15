<?php
$current_page = 'token';
require_once __DIR__ . '/includes/header.php';

// Prepare variables for alerts
$alert_type = '';
$alert_message = '';

if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success') {
        $alert_type = 'success';
        $alert_message = 'Thêm Token thành công! Đã đồng bộ ' . intval($_GET['pages']) . ' Fanpage.';
    } elseif ($_GET['status'] == 'success_delete') {
        $alert_type = 'success';
        $alert_message = 'Xóa Token thành công!';
    } elseif ($_GET['status'] == 'error') {
        $alert_type = 'danger';
        $alert_message = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Đã có lỗi xảy ra.';
    }
}

// Fetch existing tokens
$account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

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
//pages_manage_engagement,business_management
// Define exactly the permissions needed based on user request
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
    <?php
endif; ?>

<div class="card">
    <h3 style="margin-bottom: 15px;">Thêm Mới / Cập nhật Token</h3>

    <?php if ($login_url): ?>
        <div
            style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
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
        <?php
    else: ?>
        <div
            style="background: #fffbeb; border: 1px solid #fde68a; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <p style="margin-top: 0; color: #92400e; font-weight: 500;">Thông báo: Tính năng Lấy Token Tự Động chưa sẵn sàng
            </p>
            <p style="font-size: 13px; color: #b45309; margin-bottom: 0;">
                Bạn chưa cấu hình Facebook App ID trong phần <a href="settings.php"
                    style="color: #ea580c; font-weight: bold;">Cài Đặt Hệ Thống</a>, và Admin cũng chưa cung cấp cấu hình
                dùng chung nên hệ thống chưa thể tạo URL Đăng Nhập.
            </p>
        </div>
        <?php
    endif; ?>

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
                        <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                        <td>
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
                    <td colspan="5" style="text-align:center; color:#6b7280;">Chưa có token nào.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Custom Delete Confirmation Modal -->
<div id="delete-modal"
    style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.55); align-items:center; justify-content:center;">
    <div
        style="background:#fff; border-radius:12px; padding:28px 32px; max-width:380px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,0.25); text-align:center;">
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
</script>

<?php include 'includes/footer.php'; ?>