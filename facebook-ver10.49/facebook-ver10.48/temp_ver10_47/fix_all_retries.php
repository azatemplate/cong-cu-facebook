<?php
// fix_all_retries.php
// Script cập nhật toàn bộ max_retries của tất cả tài khoản về 1

ignore_user_abort(true);
set_time_limit(60);

require_once __DIR__ . '/includes/db.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<style>body{font-family:sans-serif; padding:20px; background:#f8fafc; color:#334155;} .card{background:#fff; padding:20px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1); max-width:600px; margin:auto;} .success{color:#16a34a; font-weight:bold;}</style>';
    echo '<div class="card"><h2>🔄 Cập nhật Số lần thử lại (max_retries) về 1</h2>';
} else {
    echo "========================================\n";
    echo "  UPDATE MAX_RETRIES TO 1 FOR ALL USERS \n";
    echo "========================================\n";
}

try {
    // 1. Đếm số lượng tài khoản đang để max_retries > 1 hoặc NULL hoặc 3
    $cnt_stmt = $pdo->query("SELECT COUNT(*) FROM system_accounts WHERE max_retries > 1 OR max_retries IS NULL");
    $affected_users = (int)$cnt_stmt->fetchColumn();

    // 2. Cập nhật bảng system_accounts
    $update_acc = $pdo->exec("UPDATE system_accounts SET max_retries = 1 WHERE max_retries > 1 OR max_retries IS NULL");

    // 3. Cập nhật bảng system_settings
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('max_retries', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");

    $msg = "Đã cập nhật thành công {$affected_users} tài khoản người dùng về <b>max_retries = 1</b> (Lỗi chỉ thử lại tối đa 1 lần).";
    
    if (php_sapi_name() !== 'cli') {
        echo "<p class='success'>✅ $msg</p>";
        echo "<p><a href='settings.php' style='display:inline-block; padding:10px 15px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px;'> Quay lại Cài đặt</a></p>";
        echo '</div>';
    } else {
        echo "[SUCCESS] $msg\n";
    }

} catch (Exception $e) {
    $err = "Lỗi khi cập nhật DB: " . $e->getMessage();
    if (php_sapi_name() !== 'cli') {
        echo "<p style='color:#dc2626;'>❌ $err</p></div>";
    } else {
        echo "[ERROR] $err\n";
    }
}
