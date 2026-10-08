<?php
$current_page = 'settings';
require_once __DIR__ . '/includes/header.php';

// Auto-migrate database changes (run once per version)
$settings_flag = sys_get_temp_dir() . '/settings_schema_v3.done';
if (!file_exists($settings_flag)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT
        )");
    } catch (Exception $e) {}

    $migrations = [
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retry_interval_minutes', '1')",
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('max_retries', '1')",
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('disable_local_upload', '0')",
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('max_publish_workers', '15')",
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('max_comment_workers', '15')",
        "ALTER TABLE system_accounts ADD COLUMN telegram_bot_token VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE system_accounts ADD COLUMN telegram_chat_id VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE system_accounts ADD COLUMN tiktok_client_key VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE system_accounts ADD COLUMN tiktok_client_secret VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE scheduled_posts ADD COLUMN retry_count INT DEFAULT 0 AFTER status",
        "ALTER TABLE system_accounts ADD COLUMN post_delay_seconds INT DEFAULT 15",
        "ALTER TABLE system_accounts ADD COLUMN email VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE system_accounts ADD COLUMN login_by_email TINYINT(1) DEFAULT 0",
        "ALTER TABLE system_accounts ADD COLUMN retry_interval_minutes INT DEFAULT 1",
        "ALTER TABLE system_accounts ADD COLUMN max_retries INT DEFAULT 1",
        "ALTER TABLE system_accounts ADD COLUMN sales_list TEXT DEFAULT NULL",
        "ALTER TABLE system_accounts ADD COLUMN tikwmapi_keys TEXT DEFAULT NULL",
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('saveapi_keys', 'sk_live_VMA_hpXvkXk93nCu6gE-nG4ki5critW__Qoq-He1')"
    ];

    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {}
    }

    @touch($settings_flag);
}

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
        
        if ($login_by_email && empty($new_email)) {
            $alert_type = 'danger';
            $alert_message = 'Bạn phải nhập Email trước khi bật đăng nhập bằng Email.';
        } elseif (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $alert_type = 'danger';
            $alert_message = 'Địa chỉ email không hợp lệ.';
        } else {
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
        $delay = isset($_POST['post_delay_seconds']) ? (int)trim($_POST['post_delay_seconds']) : 15;
        $interval = isset($_POST['retry_interval_minutes']) ? (int)trim($_POST['retry_interval_minutes']) : 1;
        $max_retries = isset($_POST['max_retries']) ? (int)trim($_POST['max_retries']) : 1;
        
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET post_delay_seconds = ?, retry_interval_minutes = ?, max_retries = ? WHERE id = ?");
        $u_stmt->execute([$delay, $interval, $max_retries, $account_id]);
        
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình Đăng bài thành công.';
        
        $account['post_delay_seconds'] = $delay;
        $account['retry_interval_minutes'] = $interval;
        $account['max_retries'] = $max_retries;
    }

    if (isset($_POST['reset_all_users_retries']) && $_SESSION['role'] === 'admin') {
        $affected = $pdo->exec("UPDATE system_accounts SET max_retries = 1");
        $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('max_retries', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        $alert_type = 'success';
        $alert_message = "Đã cập nhật tất cả {$affected} tài khoản người dùng về Số lần thử lại = 1 thành công!";
        $account['max_retries'] = 1;
        $max_retries = 1;
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

    if (isset($_POST['update_tiktok_app']) && $_SESSION['role'] === 'admin') {
        $tiktok_client_key = trim($_POST['tiktok_client_key'] ?? '');
        $tiktok_client_secret = trim($_POST['tiktok_client_secret'] ?? '');
        
        $u_stmt1 = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tiktok_client_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $u_stmt1->execute([$tiktok_client_key, $tiktok_client_key]);

        $u_stmt2 = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tiktok_client_secret', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $u_stmt2->execute([$tiktok_client_secret, $tiktok_client_secret]);

        try {
            $pdo->exec("UPDATE system_accounts SET tiktok_client_key = NULL, tiktok_client_secret = NULL");
        } catch (Exception $e) {}
        
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình TikTok Developer App (Client Key & Client Secret) hệ thống thành công.';
    }

    if (isset($_POST['update_upload_restriction']) && $_SESSION['role'] === 'admin') {
        $disable_val = isset($_POST['disable_local_upload']) ? '1' : '0';
        $u_stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'disable_local_upload'");
        $u_stmt->execute([$disable_val]);
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình giới hạn tải tệp thành công.';
    }

    if (isset($_POST['update_server_limits']) && $_SESSION['role'] === 'admin') {
        $max_pub = isset($_POST['max_publish_workers']) ? (int)$_POST['max_publish_workers'] : 15;
        $max_com = isset($_POST['max_comment_workers']) ? (int)$_POST['max_comment_workers'] : 15;
        
        $u_stmt1 = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'max_publish_workers'");
        $u_stmt1->execute([$max_pub]);
        
        $u_stmt2 = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'max_comment_workers'");
        $u_stmt2->execute([$max_com]);
        
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật cấu hình Throttling Máy chủ thành công.';
    }

    if (isset($_POST['update_sales_list'])) {
        $sales_list = trim($_POST['sales_list'] ?? '');
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET sales_list = ? WHERE id = ?");
        $u_stmt->execute([$sales_list, $account_id]);
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật danh sách số điện thoại Sales thành công.';
        $account['sales_list'] = $sales_list;
    }

    if (isset($_POST['update_saveapi_keys']) && $_SESSION['role'] === 'admin') {
        $saveapi_keys = trim($_POST['saveapi_keys'] ?? '');
        $u_stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('saveapi_keys', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $u_stmt->execute([$saveapi_keys, $saveapi_keys]);
        $alert_type = 'success';
        $alert_message = 'Đã cập nhật danh sách SaveAPI Key hệ thống thành công.';
    }

    if (isset($_POST['retry_all_failed']) && $_SESSION['role'] === 'admin') {
        $stmt_retry = $pdo->prepare("UPDATE scheduled_posts SET status = 'pending', retry_count = 0, error_msg = NULL WHERE status IN ('failed', 'checkpoint', 'processing')");
        $stmt_retry->execute();
        $affected = $stmt_retry->rowCount();
        $alert_type = 'success';
        $alert_message = "Đã chuyển toàn bộ {$affected} bài viết bị lỗi/checkpoint/treo về trạng thái Chờ đăng thành công!";
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
    $stmt = $pdo->prepare("SELECT * FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Lấy Cấu hình Retry từ User
$retry_interval = isset($account['retry_interval_minutes']) && $account['retry_interval_minutes'] !== null ? $account['retry_interval_minutes'] : '1';
$max_retries = isset($account['max_retries']) && $account['max_retries'] !== null ? $account['max_retries'] : '1';

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
$max_publish_workers = '15';
$max_comment_workers = '15';
try {
    $stmt_limit = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_publish_workers', 'max_comment_workers')");
    while ($row_limit = $stmt_limit->fetch(PDO::FETCH_ASSOC)) {
        if ($row_limit['setting_key'] === 'max_publish_workers') $max_publish_workers = $row_limit['setting_value'];
        if ($row_limit['setting_key'] === 'max_comment_workers') $max_comment_workers = $row_limit['setting_value'];
    }
} catch (Exception $e) {}

// Đọc Cấu hình TikTok App dùng chung cho toàn hệ thống
$tiktok_client_key = '';
$tiktok_client_secret = '';
try {
    $stmt_tt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('tiktok_client_key', 'tiktok_client_secret')");
    while ($row_tt = $stmt_tt->fetch(PDO::FETCH_ASSOC)) {
        if ($row_tt['setting_key'] === 'tiktok_client_key') $tiktok_client_key = $row_tt['setting_value'];
        if ($row_tt['setting_key'] === 'tiktok_client_secret') $tiktok_client_secret = $row_tt['setting_value'];
    }
} catch (Exception $e) {}

if (empty($tiktok_client_key) && !empty($account['tiktok_client_key'])) {
    $tiktok_client_key = $account['tiktok_client_key'];
    $tiktok_client_secret = $account['tiktok_client_secret'] ?? '';
}

// Đọc Cấu hình SaveAPI Keys dùng chung cho toàn hệ thống
$saveapi_keys = '';
try {
    $stmt_sk = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'saveapi_keys'");
    if ($stmt_sk) {
        $saveapi_keys = $stmt_sk->fetchColumn() ?: '';
    }
} catch (Exception $e) {}
if (empty($saveapi_keys)) {
    $saveapi_keys = "sk_live_VMA_hpXvkXk93nCu6gE-nG4ki5critW__Qoq-He1";
}

$yt_callback_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/youtube_callback.php";
$gg_callback_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/google_callback.php";
?>

<style>
/* ── Design Tokens & Refactored Styling for Settings Page ── */
:root {
    --st-primary: #4f46e5;
    --st-primary-hover: #4338ca;
    --st-primary-glow: rgba(79, 70, 229, 0.15);
    --st-surface: #ffffff;
    --st-bg-subtle: #f8fafc;
    --st-border: #e2e8f0;
    --st-text-main: #0f172a;
    --st-text-muted: #64748b;
    --st-radius: 16px;
}

.settings-header-banner {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    border-radius: var(--st-radius);
    padding: 28px 32px;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    box-shadow: 0 10px 25px -5px rgba(30, 27, 75, 0.2);
    position: relative;
    overflow: hidden;
}
.settings-header-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, rgba(99, 102, 241, 0) 70%);
    pointer-events: none;
}
.settings-header-title {
    display: flex;
    align-items: center;
    gap: 16px;
}
.settings-icon-badge {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #a5b4fc;
}
.settings-header-text h2 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 24px; font-weight: 800;
    margin: 0 0 4px; color: #ffffff;
    letter-spacing: -0.01em;
}
.settings-header-text p {
    font-size: 13px; color: #cbd5e1; margin: 0; font-weight: 400;
}

.diag-btn {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 10px 20px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.2s ease;
    backdrop-filter: blur(8px);
}
.diag-btn:hover {
    background: rgba(255, 255, 255, 0.28);
    transform: translateY(-2px);
    color: #ffffff; text-decoration: none;
}

/* Tab Navigation Filter */
.settings-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 4px;
    border-bottom: 2px solid #e2e8f0;
}
.tab-item {
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    border: none;
    cursor: pointer;
    display: flex; align-items: center; gap: 8px;
    transition: all 0.2s;
    white-space: nowrap;
}
.tab-item:hover { color: var(--st-primary); background: #f1f5f9; }
.tab-item.active {
    color: var(--st-primary);
    background: #eef2ff;
    box-shadow: inset 0 -2px 0 var(--st-primary);
}

/* Settings Cards Grid System */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 24px;
    align-items: start;
}
@media (max-width: 768px) {
    .settings-grid { grid-template-columns: 1fr; }
    .settings-header-banner { flex-direction: column; align-items: flex-start; gap: 16px; }
}

.st-card {
    background: var(--st-surface);
    border: 1px solid var(--st-border);
    border-radius: var(--st-radius);
    padding: 28px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    transition: all 0.25s ease;
    position: relative;
}
.st-card:hover {
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
    border-color: #cbd5e1;
}

.st-card-header {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid #f1f5f9;
}
.st-card-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.st-card-title h3 {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 17px; font-weight: 800;
    color: var(--st-text-main); margin: 0;
    line-height: 1.3;
}
.st-card-title p {
    font-size: 12px; color: var(--st-text-muted); margin: 2px 0 0; font-weight: 400;
}

/* Refactored Form Groups */
.st-form-group {
    margin-bottom: 20px;
}
.st-label {
    display: block;
    font-size: 12px; font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.st-label-desc {
    font-size: 11px; color: #64748b; margin-top: -3px; margin-bottom: 6px; text-transform: none; letter-spacing: normal; font-weight: 400;
}
.st-input-wrapper {
    position: relative; display: flex; align-items: center;
}
.st-input-wrapper > svg.input-icon {
    position: absolute; left: 14px;
    width: 18px; height: 18px; color: #94a3b8;
    pointer-events: none; transition: color 0.2s;
}
.st-input {
    width: 100%;
    padding: 12px 14px 12px 42px;
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 14px; font-weight: 500;
    color: var(--st-text-main);
    font-family: inherit;
    transition: all 0.2s ease;
}
.st-input.no-icon { padding-left: 14px; }
.st-input:focus {
    outline: none;
    border-color: var(--st-primary);
    background: #ffffff;
    box-shadow: 0 0 0 4px var(--st-primary-glow);
}
.st-textarea {
    width: 100%;
    padding: 12px 14px;
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13px; font-weight: 500;
    color: var(--st-text-main);
    font-family: inherit;
    resize: vertical;
    transition: all 0.2s;
}
.st-textarea:focus {
    outline: none;
    border-color: var(--st-primary);
    background: #ffffff;
    box-shadow: 0 0 0 4px var(--st-primary-glow);
}

/* Callout Box Tokens */
.st-callout {
    padding: 14px 16px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 20px;
    display: flex; gap: 12px; align-items: flex-start;
}
.st-callout-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.st-callout-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.st-callout-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.st-callout-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

/* Copy Box Snippets */
.copy-code-box {
    display: flex; align-items: center; justify-content: space-between;
    background: #ffffff; border: 1px solid #cbd5e1;
    border-radius: 8px; padding: 8px 12px; margin-top: 6px; font-family: monospace; font-size: 12px; color: #0f172a;
}
.btn-copy {
    background: #e2e8f0; border: none; border-radius: 6px;
    padding: 4px 10px; font-size: 11px; font-weight: 700; color: #334155;
    cursor: pointer; transition: all 0.2s; flex-shrink: 0;
}
.btn-copy:hover { background: var(--st-primary); color: #ffffff; }

/* Buttons System */
.st-btn {
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 14px; font-weight: 700;
    border: none; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    transition: all 0.2s ease;
    font-family: inherit; text-decoration: none;
}
.st-btn-primary { background: var(--st-primary); color: #ffffff; box-shadow: 0 4px 12px var(--st-primary-glow); }
.st-btn-primary:hover { background: var(--st-primary-hover); transform: translateY(-1px); }

.st-btn-success { background: #059669; color: #ffffff; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2); }
.st-btn-success:hover { background: #047857; transform: translateY(-1px); }

.st-btn-danger { background: #dc2626; color: #ffffff; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2); }
.st-btn-danger:hover { background: #b91c1c; transform: translateY(-1px); }

.st-btn-warning { background: #d97706; color: #ffffff; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.2); }
.st-btn-warning:hover { background: #b45309; transform: translateY(-1px); }

.st-btn-telegram { background: #0284c7; color: #ffffff; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.2); }
.st-btn-telegram:hover { background: #0369a1; transform: translateY(-1px); }

.st-btn-tiktok { background: #fe2c55; color: #ffffff; box-shadow: 0 4px 12px rgba(254, 44, 85, 0.2); }
.st-btn-tiktok:hover { background: #e02447; transform: translateY(-1px); }

/* Switch Toggle Box */
.st-toggle-card {
    display: flex; align-items: center; gap: 14px;
    padding: 16px; border-radius: 12px;
    border: 1.5px solid #e2e8f0; background: #f8fafc;
    cursor: pointer; transition: all 0.2s; margin-bottom: 16px;
}
.st-toggle-card:hover { border-color: #cbd5e1; background: #ffffff; }
.st-toggle-card input[type="checkbox"] {
    width: 20px; height: 20px; accent-color: var(--st-primary); cursor: pointer; flex-shrink: 0;
}
</style>

<!-- Banner Header -->
<div class="settings-header-banner">
    <div class="settings-header-title">
        <div class="settings-icon-badge">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
        </div>
        <div class="settings-header-text">
            <h2>Cài Đặt Hệ Thống</h2>
            <p>Quản lý tài khoản, API tích hợp, giới hạn server và thông báo tự động</p>
        </div>
    </div>
    <?php if ($is_admin): ?>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="diagnostics.php" target="_blank" class="diag-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                Chẩn đoán Cronjob
            </a>
            <form method="POST" action="settings.php" style="margin:0;" onsubmit="return confirm('Bạn có chắc chắn muốn thử lại (Retry) cho TOÀN BỘ bài bị lỗi trên hệ thống?');">
                <?php echo csrf_field(); ?>
                <button type="submit" name="retry_all_failed" class="diag-btn" style="background: rgba(220, 38, 38, 0.85); border-color: rgba(255, 255, 255, 0.3); cursor: pointer;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    Retry All Bài Lỗi
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Flash Alerts -->
<?php 
if (isset($_SESSION['flash_msg'])) {
    $alert_type = 'success';
    $alert_message = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}
?>
<?php if ($alert_message): ?>
    <div class="st-callout st-callout-<?php echo $alert_type === 'success' ? 'success' : 'danger'; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?php echo htmlspecialchars($alert_message); ?></span>
    </div>
<?php endif; ?>

<!-- Settings Category Tabs -->
<div class="settings-tabs">
    <button type="button" class="tab-item active" onclick="filterSettings('all', this)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Tất cả Cài đặt
    </button>
    <button type="button" class="tab-item" onclick="filterSettings('cat-account', this)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Tài khoản &amp; Bảo mật
    </button>
    <button type="button" class="tab-item" onclick="filterSettings('cat-api', this)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        API &amp; Tích hợp
    </button>
    <button type="button" class="tab-item" onclick="filterSettings('cat-server', this)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        Hệ thống &amp; Giới hạn
    </button>
    <button type="button" class="tab-item" onclick="filterSettings('cat-notify', this)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3zm-8.27 4a2 2 0 0 1-3.46 0"/></svg>
        Telegram &amp; Sales
    </button>
</div>

<!-- Main Settings Grid -->
<div class="settings-grid">

    <!-- 1. ĐỔI MẬT KHẨU -->
    <div class="st-card setting-card-item cat-account">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #eef2ff; color: #4f46e5;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Đổi Mật Khẩu</h3>
                <p>Cập nhật mật khẩu bảo mật tài khoản cá nhân</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Mật khẩu hiện tại</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="current_password" class="st-input" required placeholder="••••••••">
                </div>
            </div>
            <div class="st-form-group">
                <label class="st-label">Mật khẩu mới</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.778-7.778zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                    <input type="password" name="new_password" class="st-input" required placeholder="Nhập mật khẩu mới">
                </div>
            </div>
            <button type="submit" name="update_password" class="st-btn st-btn-primary" style="width:100%;">
                <span>Cập nhật Mật khẩu</span>
            </button>
        </form>
    </div>

    <!-- 2. CẤU HÌNH EMAIL & ĐĂNG NHẬP -->
    <div class="st-card setting-card-item cat-account">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #f0fdf4; color: #16a34a;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Cấu hình Email &amp; Đăng nhập</h3>
                <p>Quản lý email đăng nhập &amp; bảo mật tài khoản</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Địa chỉ Email</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <input type="email" name="email" class="st-input" value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>" placeholder="example@gmail.com">
                </div>
            </div>

            <?php $is_login_by_email = !empty($account['login_by_email']); ?>
            <label class="st-toggle-card">
                <input type="checkbox" name="login_by_email" value="1" <?php echo $is_login_by_email ? 'checked' : ''; ?>>
                <div>
                    <strong style="display:block; font-size:13px; color:<?php echo $is_login_by_email ? '#2563eb' : '#0f172a'; ?>;">
                        <?php echo $is_login_by_email ? '📧 Đang kích hoạt: Đăng nhập bằng Email' : '👤 Đang bật: Đăng nhập bằng Username (Mặc định)'; ?>
                    </strong>
                    <span style="font-size:12px; color:#64748b;">Khi bật, bạn chỉ có thể dùng Email + Mật khẩu để đăng nhập.</span>
                </div>
            </label>

            <button type="submit" name="update_email" class="st-btn st-btn-primary" style="width:100%;">
                <span>💾 Lưu Cấu Hình Email</span>
            </button>
        </form>
    </div>

    <!-- 3. CẤU HÌNH GOOGLE API -->
    <div class="st-card setting-card-item cat-api">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #fef2f2; color: #dc2626;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Cấu hình Google API</h3>
                <p>Dùng cho Youtube Upload &amp; Duyệt tệp Google Drive</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Google Client ID</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.778-7.778zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                    <input type="text" name="gg_client_id" class="st-input" value="<?php echo htmlspecialchars($account['gg_client_id'] ?? ''); ?>" placeholder="123456789-abc.apps.googleusercontent.com">
                </div>
            </div>
            <div class="st-form-group">
                <label class="st-label">Google Client Secret</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="gg_client_secret" class="st-input" value="<?php echo htmlspecialchars($account['gg_client_secret'] ?? ''); ?>" placeholder="GOCSXX-••••••••">
                </div>
            </div>

            <div class="st-callout st-callout-info">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <div style="flex:1;">
                    <strong>Authorized redirect URIs (Copy dán vào Google Console):</strong>
                    <div class="copy-code-box">
                        <span id="uri-yt"><?php echo htmlspecialchars($yt_callback_url); ?></span>
                        <button type="button" class="btn-copy" onclick="copyText('uri-yt', this)">Copy</button>
                    </div>
                    <div class="copy-code-box">
                        <span id="uri-gg"><?php echo htmlspecialchars($gg_callback_url); ?></span>
                        <button type="button" class="btn-copy" onclick="copyText('uri-gg', this)">Copy</button>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <button type="submit" name="update_gg_app" class="st-btn st-btn-primary" style="flex:1;">
                    <span>Lưu Cấu Hình Google</span>
                </button>
                <a href="https://www.youtube.com/watch?v=_So6OX0MCzk" target="_blank" class="st-btn" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; text-decoration:none;">
                    <span>▶️ Hướng Dẫn</span>
                </a>
            </div>
        </form>
    </div>

    <!-- 4. KẾT NỐI GOOGLE DRIVE -->
    <div class="st-card setting-card-item cat-api">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #fffbe8; color: #d97706;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Trạng thái Liên kết Google Drive</h3>
                <p>Cấp quyền chọn media trực tiếp từ Google Drive cá nhân</p>
            </div>
        </div>

        <?php if (!empty($account['gg_refresh_token'])): ?>
            <div class="st-callout st-callout-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div>
                    <strong>✅ Đã liên kết thành công!</strong>
                    <div style="font-size:12px; color:#166534; margin-top:2px;">Tài khoản Google Drive của bạn đã sẵn sàng chọn video/hình ảnh.</div>
                </div>
            </div>
            <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
                <?php echo csrf_field(); ?>
                <button type="submit" name="disconnect_gg" class="st-btn st-btn-danger" style="width:100%;">
                    <span>Ngắt kết nối Google Drive</span>
                </button>
            </form>
        <?php else: ?>
            <div class="st-callout st-callout-danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <div>
                    <strong>❌ Chưa liên kết Google Drive</strong>
                    <div style="font-size:12px; color:#991b1b; margin-top:2px;">Vui lòng cấp quyền liên kết tài khoản Drive để duyệt tệp từ xa.</div>
                </div>
            </div>
            <?php if (!empty($account['gg_client_id'])): ?>
                <a href="google_login.php" class="st-btn st-btn-primary" style="width:100%; background:#ea4335; text-decoration:none;">
                    <span>🔗 Đăng nhập &amp; Cấp quyền Google Drive</span>
                </a>
            <?php else: ?>
                <p style="font-size:12px; color:#dc2626; margin:0;">⚠️ Bạn cần điền Google Client ID ở khung Cấu hình Google API trước khi bấm liên kết.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- 5. TUỲ CHỈNH CẤU HÌNH ĐĂNG BÀI CHUNG -->
    <div class="st-card setting-card-item cat-server">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #eef2ff; color: #4f46e5;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Cấu hình Tiến trình Đăng bài</h3>
                <p>Thiết lập thời gian delay &amp; retry khi đăng bài tự động</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Delay giữa mỗi bài đăng (Giây)</label>
                <div class="st-label-desc">Khoảng thời gian nghỉ giữa các Page khác nhau thuộc cùng Token (mặc định 15s)</div>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <input type="number" name="post_delay_seconds" class="st-input" value="<?php echo htmlspecialchars($account['post_delay_seconds'] ?? '15'); ?>" min="0" max="3600">
                </div>
            </div>

            <div class="st-form-group">
                <label class="st-label">Thời gian chờ thử lại (Phút)</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    <input type="number" name="retry_interval_minutes" class="st-input" value="<?php echo htmlspecialchars($retry_interval); ?>" min="1" max="1440">
                </div>
            </div>

            <div class="st-form-group">
                <label class="st-label">Số lần thử lại tối đa (khi API báo lỗi)</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                    <input type="number" name="max_retries" class="st-input" value="<?php echo htmlspecialchars($max_retries); ?>" min="0" max="10">
                </div>
            </div>

            <button type="submit" name="update_retry_settings" class="st-btn st-btn-primary" style="width:100%;">
                <span>💾 Lưu Tùy Chỉnh Đăng Bài</span>
            </button>
        </form>

        <?php if ($is_admin): ?>
            <form method="POST" action="settings.php" style="margin-top: 18px; border-top: 1px dashed var(--st-border); padding-top: 18px;" onsubmit="return confirm('Bạn có chắc chắn muốn chuyển Số lần thử lại về 1 cho TẤT CẢ người dùng trong hệ thống?');">
                <?php echo csrf_field(); ?>
                <button type="submit" name="reset_all_users_retries" class="st-btn st-btn-warning" style="width:100%;">
                    <span>⚡ Đưa toàn bộ User về 1 lần thử lại</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- 6. THÔNG BÁO TELEGRAM BOT -->
    <div class="st-card setting-card-item cat-notify">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #e0f2fe; color: #0284c7;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Thông Báo Telegram Bot</h3>
                <p>Nhận thông báo realtime qua Bot Telegram cá nhân</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Telegram Bot Token</label>
                <div class="st-label-desc">Tạo bot qua <a href="https://t.me/BotFather" target="_blank" style="color:#0284c7; font-weight:600;">@BotFather</a> để lấy Token</div>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.778-7.778zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                    <input type="text" name="telegram_bot_token" class="st-input" value="<?php echo htmlspecialchars($tg_bot_token); ?>" placeholder="123456:ABCdefGHIjklMNOpqrSTUvwxYZ" style="font-family:monospace;">
                </div>
            </div>

            <div class="st-form-group">
                <label class="st-label">Telegram Chat ID</label>
                <div class="st-label-desc">Lấy ID cá nhân qua <a href="https://t.me/userinfobot" target="_blank" style="color:#0284c7; font-weight:600;">@userinfobot</a> hoặc Chat ID nhóm</div>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <input type="text" name="telegram_chat_id" class="st-input" value="<?php echo htmlspecialchars($tg_chat_id); ?>" placeholder="123456789 hoặc -100123456789" style="font-family:monospace;">
                </div>
            </div>

            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <button type="submit" name="update_telegram" class="st-btn st-btn-telegram" style="flex:1;">
                    <span>💾 Lưu Telegram</span>
                </button>
                <button type="submit" name="test_telegram" class="st-btn st-btn-success" style="flex:1;">
                    <span>🔔 Gửi Báo Thử</span>
                </button>
            </div>
        </form>
    </div>

    <!-- 7. DANH SÁCH SALES SỐ ĐIỆN THOẠI -->
    <div class="st-card setting-card-item cat-notify">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #ecfdf5; color: #10b981;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Danh sách Số Điện Thoại Sales</h3>
                <p>Tạo danh sách Sales mặc định chọn nhanh cho bài viết</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Danh sách Sales (Mỗi người 1 dòng)</label>
                <div class="st-label-desc">Ví dụ: Ms Hà 0932 087 886</div>
                <textarea name="sales_list" rows="5" class="st-textarea" placeholder="Ms Hà 0932 087 886&#10;Mr Tuấn 0909 123 456"><?php echo htmlspecialchars($account['sales_list'] ?? ''); ?></textarea>
            </div>
            <button type="submit" name="update_sales_list" class="st-btn st-btn-success" style="width:100%;">
                <span>💾 Lưu Danh Sách Sales</span>
            </button>
        </form>
    </div>

    <?php if ($is_admin): ?>
    <!-- 8. ADMIN: THROTTLING MÁY CHỦ -->
    <div class="st-card setting-card-item cat-server">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #eef2ff; color: #6366f1;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            </div>
            <div class="st-card-title">
                <h3>⚙️ Throttling Máy Chủ (ADMIN)</h3>
                <p>Giới hạn luồng chạy ngầm chống quá tải RAM &amp; CPU VPS</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Max Publish Workers (Luồng Đăng bài)</label>
                <div class="st-label-desc">Số luồng đăng bài song song tối đa (Khuyến nghị: 15 đối với VPS 4GB RAM)</div>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    <input type="number" name="max_publish_workers" class="st-input" value="<?php echo htmlspecialchars($max_publish_workers); ?>" min="5">
                </div>
            </div>

            <div class="st-form-group">
                <label class="st-label">Max Comment Workers (Luồng Bình luận)</label>
                <div class="st-label-desc">Số luồng bình luận mồi chạy song song tối đa (Khuyến nghị: 15 đối với VPS 4GB RAM)</div>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <input type="number" name="max_comment_workers" class="st-input" value="<?php echo htmlspecialchars($max_comment_workers); ?>" min="5">
                </div>
            </div>

            <button type="submit" name="update_server_limits" class="st-btn st-btn-primary" style="width:100%;">
                <span>💾 Lưu Throttling Máy Chủ</span>
            </button>
        </form>
    </div>

    <!-- 9. ADMIN: FACEBOOK APP -->
    <div class="st-card setting-card-item cat-api">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #eff6ff; color: #2563eb;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
            </div>
            <div class="st-card-title">
                <h3>Cấu hình Facebook App (ADMIN)</h3>
                <p>App ID &amp; App Secret dùng chung lấy Token tự động</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Facebook App ID</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    <input type="text" name="fb_app_id" class="st-input" value="<?php echo htmlspecialchars($account['fb_app_id'] ?? ''); ?>" placeholder="Nhập FB App ID">
                </div>
            </div>
            <div class="st-form-group">
                <label class="st-label">Facebook App Secret</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="fb_app_secret" class="st-input" value="<?php echo htmlspecialchars($account['fb_app_secret'] ?? ''); ?>" placeholder="••••••••">
                </div>
            </div>
            <button type="submit" name="update_fb_app" class="st-btn st-btn-primary" style="width:100%;">
                <span>💾 Lưu Cấu Hình FB App</span>
            </button>
        </form>
    </div>

    <!-- 10. ADMIN: GIỚI HẠN UPLOAD FILE -->
    <div class="st-card setting-card-item cat-server">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #fef2f2; color: #dc2626;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div class="st-card-title">
                <h3>🔒 Giới Hạn Tải Tệp Cho User (ADMIN)</h3>
                <p>Khóa tính năng tải file từ máy tính cho User thường</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <label class="st-toggle-card">
                <input type="checkbox" name="disable_local_upload" value="1" <?php echo $disable_local_upload === '1' ? 'checked' : ''; ?>>
                <div>
                    <strong style="display:block; font-size:13px; color:<?php echo $disable_local_upload === '1' ? '#dc2626' : '#16a34a'; ?>;">
                        <?php echo $disable_local_upload === '1' ? '🚫 Đã cấm User tải file từ máy tính' : '✅ Cho phép User tải file từ máy tính'; ?>
                    </strong>
                    <span style="font-size:12px; color:#64748b;">Khi cấm, User bắt buộc dùng Google Drive hoặc Link TikTok để nhập media.</span>
                </div>
            </label>

            <button type="submit" name="update_upload_restriction" class="st-btn st-btn-danger" style="width:100%;">
                <span>💾 Lưu Cấu Hình Upload</span>
            </button>
        </form>
    </div>

    <!-- 11. ADMIN: TIKTOK APP -->
    <div class="st-card setting-card-item cat-api">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #fff1f2; color: #fe2c55;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/></svg>
            </div>
            <div class="st-card-title">
                <h3>🎵 TikTok Developer App (ADMIN)</h3>
                <p>Client Key &amp; Secret từ TikTok Developer Portal</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">TikTok Client Key</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.778-7.778zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                    <input type="text" name="tiktok_client_key" class="st-input" value="<?php echo htmlspecialchars($tiktok_client_key); ?>" placeholder="Nhập Client Key TikTok" style="font-family:monospace;">
                </div>
            </div>
            <div class="st-form-group">
                <label class="st-label">TikTok Client Secret</label>
                <div class="st-input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="tiktok_client_secret" class="st-input" value="<?php echo htmlspecialchars($tiktok_client_secret); ?>" placeholder="••••••••">
                </div>
            </div>
            <button type="submit" name="update_tiktok_app" class="st-btn st-btn-tiktok" style="width:100%;">
                <span>💾 Lưu TikTok App Hệ Thống</span>
            </button>
        </form>
    </div>

    <!-- 12. ADMIN: SAVEAPI KEYS -->
    <div class="st-card setting-card-item cat-api">
        <div class="st-card-header">
            <div class="st-card-icon" style="background: #eef2ff; color: #4f46e5;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </div>
            <div class="st-card-title">
                <h3>🔑 SaveAPI Keys (ADMIN)</h3>
                <p>Khóa tải video đa nền tảng không logo từ saveapi.org</p>
            </div>
        </div>

        <form method="POST" action="settings.php" onsubmit="return handleFormSubmit(this)">
            <?php echo csrf_field(); ?>
            <div class="st-form-group">
                <label class="st-label">Danh sách SaveAPI Keys (Mỗi key 1 dòng)</label>
                <textarea name="saveapi_keys" rows="4" class="st-textarea" style="font-family:monospace;" placeholder="sk_live_VMA_hpXvkXk93nCu6gE-nG4ki5critW__Qoq-He1"><?php echo htmlspecialchars($saveapi_keys); ?></textarea>
            </div>
            <button type="submit" name="update_saveapi_keys" class="st-btn st-btn-primary" style="width:100%;">
                <span>💾 Lưu SaveAPI Keys Hệ Thống</span>
            </button>
        </form>
    </div>
    <?php endif; ?>

</div>

<script>
function filterSettings(cat, btn) {
    document.querySelectorAll('.tab-item').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const cards = document.querySelectorAll('.setting-card-item');
    cards.forEach(card => {
        if (cat === 'all') {
            card.style.display = 'block';
        } else {
            if (card.classList.contains(cat)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        }
    });
}

function copyText(elementId, btn) {
    const text = document.getElementById(elementId).innerText;
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerText;
        btn.innerText = 'Đã Copy!';
        btn.style.background = '#10b981';
        btn.style.color = '#fff';
        setTimeout(() => {
            btn.innerText = orig;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }).catch(err => {
        alert('Không thể copy tự động, vui lòng copy thủ công: ' + text);
    });
}

function handleFormSubmit(form) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        const origHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span><span style="display:inline-block; width:12px; height:12px; border:2px solid #fff; border-top-color:transparent; border-radius:50%; animation: spin 0.8s linear infinite; vertical-align:middle; margin-right:6px;"></span> Đang xử lý...</span>';
        submitBtn.style.opacity = '0.85';
        submitBtn.style.pointerEvents = 'none';
    }
    return true;
}
</script>

<?php include 'includes/footer.php'; ?>
