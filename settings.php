<?php
$current_page = 'settings';
require_once __DIR__ . '/includes/header.php';

// Auto-migrate database changes for Phase 15 and 17
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT
    )");
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retry_interval_minutes', '1')");
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('max_retries', '3')");
} catch (Exception $e) {}

try {
    // Add column if it doesn't exist
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN retry_count INT DEFAULT 0 AFTER status");
} catch (Exception $e) {}

$alert_type = '';
$alert_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current_pass = trim($_POST['current_password'] ?? '');
    $new_pass = trim($_POST['new_password'] ?? '');
    
    $account_id = $_SESSION['account_id'];
    
    $stmt = $pdo->prepare("SELECT password, fb_app_id, fb_app_secret, gg_client_id, gg_client_secret, gg_refresh_token FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (isset($_POST['update_password'])) {
        if ($account && password_verify($current_pass, $account['password'])) {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $u_stmt = $pdo->prepare("UPDATE system_accounts SET password = ? WHERE id = ?");
            $u_stmt->execute([$hashed, $account_id]);
            $alert_type = 'success';
            $alert_message = 'Đổi mật khẩu thành công.';
        } else {
            $alert_type = 'danger';
            $alert_message = 'Mật khẩu hiện tại không đúng.';
        }
    }
    
    if (isset($_POST['update_fb_app']) && $_SESSION['role'] === 'admin') {
        $fb_app_id = trim($_POST['fb_app_id']);
        $fb_app_secret = trim($_POST['fb_app_secret']);
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET fb_app_id = ?, fb_app_secret = ? WHERE id = ?");
        $u_stmt->execute([$fb_app_id, $fb_app_secret, $account_id]);
        $alert_type = 'success';
        $alert_message = 'Cập nhật cấu hình FB App thành công.';
        // Refresh account info
        $account['fb_app_id'] = $fb_app_id;
        $account['fb_app_secret'] = $fb_app_secret;
    }

    
    if (isset($_POST['update_gg_app'])) {
        $gg_client_id = trim($_POST['gg_client_id']);
        $gg_client_secret = trim($_POST['gg_client_secret']);
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET gg_client_id = ?, gg_client_secret = ? WHERE id = ?");
        $u_stmt->execute([$gg_client_id, $gg_client_secret, $account_id]);
        $alert_type = 'success';
        $alert_message = 'Cập nhật cấu hình Google Drive API thành công.';
        $account['gg_client_id'] = $gg_client_id;
        $account['gg_client_secret'] = $gg_client_secret;
    }
    
    if (isset($_POST['disconnect_gg'])) {
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET gg_refresh_token = NULL WHERE id = ?");
        $u_stmt->execute([$account_id]);
        $alert_type = 'success';
        $alert_message = 'Đã hủy liên kết Google Drive.';
        $account['gg_refresh_token'] = null;
    }
    
    if (isset($_POST['update_retry_settings']) && $_SESSION['role'] === 'admin') {
        $interval = (int)trim($_POST['retry_interval_minutes']);
        $max_retries = (int)trim($_POST['max_retries']);
        
        $u_stmt1 = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'retry_interval_minutes'");
        $u_stmt1->execute([$interval]);
        $u_stmt2 = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'max_retries'");
        $u_stmt2->execute([$max_retries]);
        
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình Thử lại thành công.';
    }
} else {
    $account_id = $_SESSION['account_id'];
    $stmt = $pdo->prepare("SELECT fb_app_id, fb_app_secret, gg_client_id, gg_client_secret, gg_refresh_token FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch Retry Settings
$s_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('retry_interval_minutes', 'max_retries')");
$settings = [];
while ($row = $s_stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$retry_interval = isset($settings['retry_interval_minutes']) ? $settings['retry_interval_minutes'] : '1';
$max_retries = isset($settings['max_retries']) ? $settings['max_retries'] : '3';

$is_admin = ($_SESSION['role'] === 'admin');
?>

<div class="page-title" style="display: flex; justify-content: space-between; align-items: center;">
    <span>Cài Đặt Hệ Thống</span>
    <?php if ($is_admin): ?>
        <a href="diagnostics.php" target="_blank" class="btn btn-primary" style="background: #8b5cf6; border-color: #8b5cf6;">🚀 Xem chẩn đoán Cronjob</a>
    <?php endif; ?>
</div>

<?php if ($alert_message): ?>
    <div class="alert alert-<?php echo $alert_type; ?>"><?php echo htmlspecialchars($alert_message); ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; align-items: start;">

    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">Đổi Mật Khẩu</h3>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Mật khẩu hiện tại</label>
                <input type="password" name="current_password" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" required>
            </div>
            <div class="form-group">
                <label>Mật khẩu mới</label>
                <input type="password" name="new_password" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" required>
            </div>
            <button type="submit" name="update_password" class="btn btn-primary">Cập nhật Mật khẩu</button>
        </form>
    </div>

    <!-- Cài đặt Chung (Hiển thị cho tất cả) -->
    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">Tuỳ chỉnh Hệ thống chung (Shared Settings)</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
            Cấu hình này áp dụng cho toàn bộ Cronjob đăng bài tự động của tất cả User.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Thời gian chờ thử lại mặc định (Phút)</label>
                <?php if ($is_admin): ?>
                    <input type="number" name="retry_interval_minutes" value="<?php echo htmlspecialchars($retry_interval); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;" min="1" max="1440">
                <?php else: ?>
                    <div style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: #f9fafb; color: #374151; box-sizing: border-box;">
                        <?php echo htmlspecialchars($retry_interval); ?> phút
                    </div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Số lần thử lại tối đa (khi API báo lỗi)</label>
                <?php if ($is_admin): ?>
                    <input type="number" name="max_retries" value="<?php echo htmlspecialchars($max_retries); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;" min="0" max="10">
                <?php else: ?>
                    <div style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: #f9fafb; color: #374151; box-sizing: border-box;">
                        <?php echo htmlspecialchars($max_retries); ?> lần
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($is_admin): ?>
                <button type="submit" name="update_retry_settings" class="btn btn-primary">Cập nhật Cấu hình Lỗi</button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($is_admin): ?>
    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">Cấu hình Facebook App</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
            Nhập App ID và App Secret của ứng dụng Facebook Business để lấy Token đăng bài tự động. Do hệ thống đã được App Review với tư cách Admin, tất cả người dùng sẽ dùng chung cấu hình App này.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Facebook App ID</label>
                <input type="text" name="fb_app_id" value="<?php echo htmlspecialchars($account['fb_app_id'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            <div class="form-group">
                <label>Facebook App Secret</label>
                <input type="password" name="fb_app_secret" value="<?php echo htmlspecialchars($account['fb_app_secret'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            <button type="submit" name="update_fb_app" class="btn btn-primary">Lưu Cấu Hình</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">Cấu hình Google Drive API</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
            Nhập Client ID và Client Secret từ Google Cloud Console để hệ thống có thể kết nối với kho lưu trữ hình ảnh, video của bạn trên Google Drive.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Google Client ID</label>
                <input type="text" name="gg_client_id" value="<?php echo htmlspecialchars($account['gg_client_id'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            <div class="form-group">
                <label>Google Client Secret</label>
                <input type="password" name="gg_client_secret" value="<?php echo htmlspecialchars($account['gg_client_secret'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            <button type="submit" name="update_gg_app" class="btn btn-primary">Lưu Cấu Hình Google</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">Kết nối Google Drive</h3>
        <div style="background: #fdf2f8; padding: 15px; border-radius: 6px; border: 1px dashed #fbcfe8;">
            <h4 style="margin-top:0; color: #be185d;">Trạng thái Liên kết Drive riêng biệt</h4>
            <?php if (!empty($account['gg_refresh_token'])): ?>
                <p style="color: #15803d; font-weight: bold; margin-bottom: 10px;">✅ Đã liên kết tài khoản Google Drive thành công.</p>
                <form method="POST" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="disconnect_gg" class="btn btn-danger" style="background:#ef4444;">Ngắt kết nối ngầm</button>
                </form>
            <?php else: ?>
                <p style="color: #b91c1c; margin-bottom: 10px;">❌ Chưa liên kết tài khoản Drive của bạn.</p>
                <?php 
                // Check if admin has set up the google API Client ID (using admin's row ID 1)
                $stmt_admin = $pdo->query("SELECT gg_client_id FROM system_accounts WHERE id = 1");
                $admin_gg = $stmt_admin->fetch(PDO::FETCH_ASSOC);
                if (!empty($admin_gg['gg_client_id']) || !empty($account['gg_client_id'])): 
                ?>
                    <a href="google_login.php" class="btn btn-primary" style="background: #ea4335; border-color: #ea4335;">🔗 Đăng nhập & Cấp quyền Google Drive</a>
                <?php else: ?>
                    <p style="font-size: 13px; color: #be185d;">Hệ thống chưa được điền Google Token. Vui lòng liên hệ Admin.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include 'includes/footer.php'; ?>
