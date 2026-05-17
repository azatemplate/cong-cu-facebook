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
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('disable_local_upload', '0')");
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('max_publish_workers', '30')");
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('max_comment_workers', '15')");
} catch (Exception $e) {}

// Auto-migrate Telegram columns per-user
try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN telegram_bot_token VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE system_accounts ADD COLUMN telegram_chat_id VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}

try {
    // Add column if it doesn't exist
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN retry_count INT DEFAULT 0 AFTER status");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN post_delay_seconds INT DEFAULT 15");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN email VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN login_by_email TINYINT(1) DEFAULT 0");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN retry_interval_minutes INT DEFAULT 1");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE system_accounts ADD COLUMN max_retries INT DEFAULT 3");
} catch (Exception $e) {}

$alert_type = '';
$alert_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current_pass = trim($_POST['current_password'] ?? '');
    $new_pass = trim($_POST['new_password'] ?? '');
    
    $account_id = $_SESSION['account_id'];
    
    $stmt = $pdo->prepare("SELECT * FROM system_accounts WHERE id = ?");
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

    if (isset($_POST['update_email'])) {
        $new_email = trim($_POST['email'] ?? '');
        $login_by_email = isset($_POST['login_by_email']) ? 1 : 0;
        
        // Validate email if login_by_email is enabled
        if ($login_by_email && empty($new_email)) {
            $alert_type = 'danger';
            $alert_message = 'Bạn phải nhập Email trước khi bật đăng nhập bằng Email.';
        } elseif (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $alert_type = 'danger';
            $alert_message = 'Địa chỉ email không hợp lệ.';
        } else {
            // Check email uniqueness (if not empty)
            if (!empty($new_email)) {
                $chk = $pdo->prepare("SELECT id FROM system_accounts WHERE email = ? AND id != ?");
                $chk->execute([$new_email, $account_id]);
                if ($chk->fetch()) {
                    $alert_type = 'danger';
                    $alert_message = 'Email này đã được sử dụng bởi tài khoản khác.';
                    goto skip_email_update;
                }
            }
            $u_stmt = $pdo->prepare("UPDATE system_accounts SET email = ?, login_by_email = ? WHERE id = ?");
            $u_stmt->execute([empty($new_email) ? null : $new_email, $login_by_email, $account_id]);
            $alert_type = 'success';
            $alert_message = 'Cập nhật cấu hình Email thành công.';
            $account['email'] = $new_email;
            $account['login_by_email'] = $login_by_email;
        }
        skip_email_update:
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
    
    if (isset($_POST['update_retry_settings'])) {
        $account_id = $_SESSION['account_id'];
        
        // Update user's delay and retry settings (cho bất kỳ user nào)
        $delay = isset($_POST['post_delay_seconds']) ? (int)trim($_POST['post_delay_seconds']) : 15;
        $interval = isset($_POST['retry_interval_minutes']) ? (int)trim($_POST['retry_interval_minutes']) : 1;
        $max_retries = isset($_POST['max_retries']) ? (int)trim($_POST['max_retries']) : 3;
        
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET post_delay_seconds = ?, retry_interval_minutes = ?, max_retries = ? WHERE id = ?");
        $u_stmt->execute([$delay, $interval, $max_retries, $account_id]);
        
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình Đăng bài thành công.';
        
        $account['post_delay_seconds'] = $delay;
        $account['retry_interval_minutes'] = $interval;
        $account['max_retries'] = $max_retries;
    }

    if (isset($_POST['update_telegram'])) {
        $tg_token = trim($_POST['telegram_bot_token'] ?? '');
        $tg_chat_id = trim($_POST['telegram_chat_id'] ?? '');
        
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET telegram_bot_token = ?, telegram_chat_id = ? WHERE id = ?");
        $u_stmt->execute([$tg_token, $tg_chat_id, $_SESSION['account_id']]);
        
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình Telegram Bot thành công.';
        $account['telegram_bot_token'] = $tg_token;
        $account['telegram_chat_id'] = $tg_chat_id;
    }

    if (isset($_POST['update_upload_restriction']) && $_SESSION['role'] === 'admin') {
        $disable_val = isset($_POST['disable_local_upload']) ? '1' : '0';
        $u_stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'disable_local_upload'");
        $u_stmt->execute([$disable_val]);
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình giới hạn tải tệp thành công.';
    }

    if (isset($_POST['update_server_limits']) && $_SESSION['role'] === 'admin') {
        $max_pub = isset($_POST['max_publish_workers']) ? (int)$_POST['max_publish_workers'] : 30;
        $max_com = isset($_POST['max_comment_workers']) ? (int)$_POST['max_comment_workers'] : 15;
        
        $u_stmt1 = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'max_publish_workers'");
        $u_stmt1->execute([$max_pub]);
        
        $u_stmt2 = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'max_comment_workers'");
        $u_stmt2->execute([$max_com]);
        
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình Throttling Máy chủ thành công.';
    }

    if (isset($_POST['test_telegram'])) {
        require_once __DIR__ . '/includes/telegram.php';
        $ok = send_telegram_notification($pdo, $_SESSION['account_id'], "<b>🔔 Thông báo thử nghiệm</b>\nHệ thống Facebook Automation đã kết nối Telegram thành công!\n\n🕐 Thời gian: " . date('d/m/Y H:i:s'), 'general');
        if ($ok) {
            $alert_type = 'success';
            $alert_message = 'Đã gửi thông báo thử nghiệm lên Telegram thành công! Kiểm tra Telegram của bạn.';
        } else {
            $alert_type = 'danger';
            $alert_message = 'Không gửi được thông báo. Kiểm tra lại Bot Token và Chat ID.';
        }
    }
} else {
    $account_id = $_SESSION['account_id'];
    $stmt = $pdo->prepare("SELECT fb_app_id, fb_app_secret, gg_client_id, gg_client_secret, gg_refresh_token, post_delay_seconds, retry_interval_minutes, max_retries, telegram_bot_token, telegram_chat_id, email, login_by_email FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Lấy Cấu hình Retry từ User
$retry_interval = isset($account['retry_interval_minutes']) && $account['retry_interval_minutes'] !== null ? $account['retry_interval_minutes'] : '1';
$max_retries = isset($account['max_retries']) && $account['max_retries'] !== null ? $account['max_retries'] : '3';

// Lấy cấu hình Telegram từ account của user hiện tại
$tg_bot_token = $account['telegram_bot_token'] ?? '';
$tg_chat_id = $account['telegram_chat_id'] ?? '';

$is_admin = ($_SESSION['role'] === 'admin');

// Đọc cấu hình giới hạn upload
$disable_local_upload = '0';
try {
    $stmt_upload = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'disable_local_upload'");
    $stmt_upload->execute();
    $row_upload = $stmt_upload->fetch(PDO::FETCH_ASSOC);
    if ($row_upload) $disable_local_upload = $row_upload['setting_value'];
} catch (Exception $e) {}

// Đọc cấu hình giới hạn tiến trình Server
$max_publish_workers = '30';
$max_comment_workers = '15';
try {
    $stmt_limit = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_publish_workers', 'max_comment_workers')");
    while ($row_limit = $stmt_limit->fetch(PDO::FETCH_ASSOC)) {
        if ($row_limit['setting_key'] === 'max_publish_workers') $max_publish_workers = $row_limit['setting_value'];
        if ($row_limit['setting_key'] === 'max_comment_workers') $max_comment_workers = $row_limit['setting_value'];
    }
} catch (Exception $e) {}
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

<div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">
    <!-- CỘT TRÁI -->
    <div style="flex: 1; min-width: 400px; display: flex; flex-direction: column; gap: 20px;">
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

    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">📧 Cấu hình Email & Đăng nhập</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">
            Thêm email vào tài khoản của bạn. Khi bật "Đăng nhập bằng Email", bạn sẽ chỉ đăng nhập được bằng email thay vì username.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Địa chỉ Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>" placeholder="example@gmail.com" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;">
            </div>
            <?php 
            $is_login_by_email = !empty($account['login_by_email']);
            ?>
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 12px 15px; background: <?php echo $is_login_by_email ? '#eff6ff' : '#f0fdf4'; ?>; border: 1px solid <?php echo $is_login_by_email ? '#bfdbfe' : '#bbf7d0'; ?>; border-radius: 8px; transition: all 0.2s; margin-bottom: 15px;">
                <input type="checkbox" name="login_by_email" value="1" <?php echo $is_login_by_email ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: #2563eb;">
                <span style="font-weight: 500; color: <?php echo $is_login_by_email ? '#2563eb' : '#15803d'; ?>;">
                    <?php echo $is_login_by_email ? '📧 Đang đăng nhập bằng Email (Username bị vô hiệu)' : '👤 Đang đăng nhập bằng Username (mặc định)'; ?>
                </span>
            </label>
            <small style="color: #ef4444; display: block; margin-bottom: 15px;">⚠️ Khi bật đăng nhập bằng Email, Username sẽ không thể đăng nhập được nữa. Chỉ Email + Mật khẩu mới có hiệu lực.</small>
            <button type="submit" name="update_email" class="btn btn-primary" style="background: #2563eb; border-color: #2563eb;">💾 Lưu Cấu Hình Email</button>
        </form>
    </div>

    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">Cấu hình Google API (Youtube & Drive)</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
            Nhập Client ID và Client Secret ứng dụng riêng từ Google Cloud Console để hệ thống có thể liên kết đăng video Youtube và sử dụng chung cho việc duyệt file từ Google Drive. Cấu hình này là độc lập cho mỗi user.<br><br>
            <a href="https://www.youtube.com/watch?v=i22YCgy90g8" target="_blank" style="color: #ef4444; font-weight: bold; text-decoration: none;">▶️ Xem Hướng Dẫn cách cấu hình Google API</a>
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
            <button type="submit" name="update_gg_app" class="btn btn-primary" style="margin-bottom: 15px;">Lưu Cấu Hình Google</button>
            <div style="background: #f8f9fa; border-left: 4px solid #0d6efd; padding: 12px; font-size: 13px; color: #555;">
                <strong>⚠️ Lưu ý quan trọng khi cấu hình Console của Google:</strong><br>
                Bạn BẮT BUỘC phải thêm 2 đường link dưới đây vào phần <b>Authorized redirect URIs (URI chuyển hướng được ủy quyền)</b> trong bảng điều khiển Google Cloud. Nếu không, Google sẽ báo lỗi <code>redirect_uri_mismatch</code> khi đăng nhập:
                <ul style="margin: 8px 0; padding-left: 20px;">
                    <li><code style="background: #fff; padding: 2px 5px; border-radius: 3px; border: 1px solid #ddd;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/youtube_callback.php"; ?></code></li>
                    <li><code style="background: #fff; padding: 2px 5px; border-radius: 3px; border: 1px solid #ddd;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/google_callback.php"; ?></code></li>
                </ul>
            </div>
        </form>
    </div>

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
                // Check if user has set up the google API Client ID
                if (!empty($account['gg_client_id'])): 
                ?>
                    <a href="google_login.php" class="btn btn-primary" style="background: #ea4335; border-color: #ea4335;">🔗 Đăng nhập & Cấp quyền Google Drive</a>
                <?php else: ?>
                    <p style="font-size: 13px; color: #be185d;">Bạn chưa điền Cấu hình Google API ở bảng phía trên. Vui lòng điền và Lưu lại trước khi sử dụng tính năng này.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 10px;">⚙️ Cấu Hình Giữ Lửa Server (Throttling)</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">
            Hệ thống phân bổ công bằng (Round-Robin). Giới hạn số lượng tiến trình ngầm (Workers) được phép duyệt để đăng bài cùng một thời điểm để chống sập RAM / CPU.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Max Publish Workers (Luồng Đăng tải)</label>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: -5px; margin-bottom: 5px;">Số luồng đăng bài chạy song song tối đa (khuyến nghị: 30 đối với VPS 4GB RAM).</p>
                <input type="number" name="max_publish_workers" value="<?php echo htmlspecialchars($max_publish_workers); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;" min="5">
            </div>
            <div class="form-group">
                <label>Max Comment Workers (Luồng Bình luận)</label>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: -5px; margin-bottom: 5px;">Số luồng bình luận mồi chạy song song tối đa (khuyến nghị: 15 đối với VPS 4GB RAM).</p>
                <input type="number" name="max_comment_workers" value="<?php echo htmlspecialchars($max_comment_workers); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;" min="5">
            </div>
            <button type="submit" name="update_server_limits" class="btn btn-primary" style="margin-top: 5px;">💾 Lưu Throttling</button>
        </form>
    </div>
    <?php endif; ?>

    </div> <!-- ĐÓNG CỘT TRÁI -->

    <!-- CỘT PHẢI -->
    <div style="flex: 1; min-width: 400px; display: flex; flex-direction: column; gap: 20px;">
    <!-- Cài đặt Chung (Hiển thị cho tất cả) -->
    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 20px;">Tuỳ chỉnh Hệ thống chung (Shared Settings)</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
            Cấu hình này áp dụng cho toàn bộ Cronjob đăng bài tự động của tất cả User.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Delay giữa mỗi post (Giây)</label>
                <p style="font-size: 12px; color: var(--text-muted); margin-top: -5px; margin-bottom: 5px;">Thời gian chờ giữa các bài đăng trên các Page khác nhau thuộc cùng 1 Token (mặc định 15s).</p>
                <input type="number" name="post_delay_seconds" value="<?php echo htmlspecialchars($account['post_delay_seconds'] ?? '15'); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;" min="0" max="3600">
            </div>

            <div class="form-group">
                <label>Thời gian chờ thử lại mặc định (Phút)</label>
                <input type="number" name="retry_interval_minutes" value="<?php echo htmlspecialchars($retry_interval); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;" min="1" max="1440">
            </div>
            <div class="form-group">
                <label>Số lần thử lại tối đa (khi API báo lỗi)</label>
                <input type="number" name="max_retries" value="<?php echo htmlspecialchars($max_retries); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box;" min="0" max="10">
            </div>
            <button type="submit" name="update_retry_settings" class="btn btn-primary">Lưu Tùy Chỉnh</button>
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
        <h3 style="margin-bottom: 10px;">🔒 Giới Hạn Tải Tệp Cho User</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">
            Khi bật tùy chọn này, User thường (không phải Admin) sẽ <strong>không thể tải file từ máy tính</strong> lên hệ thống.
            Họ chỉ có thể sử dụng <strong>Google Drive</strong> hoặc <strong>Link TikTok</strong> để cung cấp media cho các trang Reels, Story, Videos và Posts.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 12px 15px; background: <?php echo $disable_local_upload === '1' ? '#fef2f2' : '#f0fdf4'; ?>; border: 1px solid <?php echo $disable_local_upload === '1' ? '#fecaca' : '#bbf7d0'; ?>; border-radius: 8px; transition: all 0.2s;">
                <input type="checkbox" name="disable_local_upload" value="1" <?php echo $disable_local_upload === '1' ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: #dc2626;">
                <span style="font-weight: 500; color: <?php echo $disable_local_upload === '1' ? '#dc2626' : '#15803d'; ?>;">
                    <?php echo $disable_local_upload === '1' ? '🚫 Đã vô hiệu hóa tải tệp từ máy tính cho User' : '✅ User được phép tải tệp từ máy tính'; ?>
                </span>
            </label>
            <button type="submit" name="update_upload_restriction" class="btn btn-primary" style="margin-top: 12px; background: #dc2626; border-color: #dc2626;">💾 Lưu Cấu Hình</button>
        </form>
    </div>
    <?php endif; ?>



    <div class="card" style="margin: 0; box-sizing: border-box;">
        <h3 style="margin-bottom: 10px;">🤖 Thông Báo Telegram Bot</h3>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">
            Nhập Token và Chat ID từ Bot Telegram của bạn để nhận thông báo tự động khi có bài đăng thành công, video đủ điều kiện bình luận, hoặc báo cáo hàng ngày.
        </p>
        <form method="POST" action="settings.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Bot Token</label>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: -5px; margin-bottom: 5px;">Tạo Bot qua <a href="https://t.me/BotFather" target="_blank" style="color: #38bdf8;">@BotFather</a> trên Telegram để lấy Token.</p>
                <input type="text" name="telegram_bot_token" value="<?php echo htmlspecialchars($tg_bot_token); ?>" placeholder="123456:ABCdefGHIjklMNOpqrSTUvwxYZ" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace; font-size: 13px;">
            </div>
            <div class="form-group">
                <label>Chat ID</label>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: -5px; margin-bottom: 5px;">Gửi tin nhắn cho <a href="https://t.me/userinfobot" target="_blank" style="color: #38bdf8;">@userinfobot</a> để biết Chat ID của bạn. Hoặc dùng ID nhóm (bắt đầu bằng -).</p>
                <input type="text" name="telegram_chat_id" value="<?php echo htmlspecialchars($tg_chat_id); ?>" placeholder="123456789 hoặc -100123456789" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: monospace; font-size: 13px;">
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="submit" name="update_telegram" class="btn btn-primary" style="background: #0088cc; border-color: #0088cc;">💾 Lưu Cấu Hình</button>
                <button type="submit" name="test_telegram" class="btn btn-primary" style="background: #16a34a; border-color: #16a34a;">🔔 Gửi Thông Báo Thử</button>
            </div>
        </form>
    </div>

    </div> <!-- ĐÓNG CỘT PHẢI -->

</div>

<?php include 'includes/footer.php'; ?>
