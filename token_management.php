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
    }
    elseif ($_GET['status'] == 'success_delete') {
        $alert_type = 'success';
        $alert_message = 'Xóa Token thành công!';
    }
    elseif ($_GET['status'] == 'error') {
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
$fb_permissions = "pages_manage_engagement,business_management,pages_show_list,pages_manage_posts,pages_read_engagement,read_insights,pages_messaging,public_profile";
$redirect_uri = "https://hongvippro.com/facebook/redirect_callback.php";

$login_url = "";
if ($fb_app_id) {
    $login_url = "https://www.facebook.com/v19.0/dialog/oauth?client_id=" . urlencode($fb_app_id) . "&redirect_uri=" . urlencode($redirect_uri) . "&scope=" . urlencode($fb_permissions) . "&response_type=token";
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
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <p style="margin-top: 0; color: #166534; font-weight: 500;">Bấm Đăng Nhập Facebook Để Đăng Nhập</p>
            <p style="font-size: 13px; color: #15803d; margin-bottom: 10px;">
                Nhấn nút bên dưới để cấp quyền thông qua Facebook App của bạn. Cần đảm bảo App của bạn đã cấu hình <strong>Valid OAuth Redirect URIs</strong> thành <code><?php echo $redirect_uri; ?></code>.
            </p>
            <a href="<?php echo htmlspecialchars($login_url); ?>" target="_blank" class="btn btn-primary" style="background: #1877f2; display: inline-flex; align-items: center; gap: 8px;">
                <span style="font-weight: bold; font-size: 16px;">f</span> Đăng Nhập Facebook
            </a>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 10px; margin-bottom: 0;">
                <em>Quyền yêu cầu: pages_manage_posts, read_insights, pages_read_engagement, pages_show_list, pages_messaging, public_profile.</em><br>
                Hệ thống sẽ tự nhận diện Token, bạn chỉ việc trải nghiệm!
            </p>
        </div>
    <?php
else: ?>
        <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <p style="margin-top: 0; color: #92400e; font-weight: 500;">Thông báo: Tính năng Lấy Token Tự Động chưa sẵn sàng</p>
            <p style="font-size: 13px; color: #b45309; margin-bottom: 0;">
                Bạn chưa cấu hình Facebook App ID trong phần <a href="settings.php" style="color: #ea580c; font-weight: bold;">Cài Đặt Hệ Thống</a>, và Admin cũng chưa cung cấp cấu hình dùng chung nên hệ thống chưa thể tạo URL Đăng Nhập.
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
                        <td style="font-weight: bold; color: var(--primary-color);"><?php echo (int)$u['page_count']; ?></td>
                        <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                        <td><a href="actions/delete_token.php?id=<?php echo $u['id']; ?>" class="color-red" style="text-decoration:none;">Xóa</a></td>
                    </tr>
                <?php
    endforeach; ?>
            <?php
else: ?>
                <tr>
                    <td colspan="5" style="text-align:center; color:#6b7280;">Chưa có token nào.</td>
                </tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
