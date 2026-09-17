<?php
// fix_mariadb_indexes.php
// Tải trước DB & chạy trực tiếp các lệnh bổ sung Index tối ưu cho MariaDB trên 600,000 dòng

require_once __DIR__ . '/includes/db.php';

echo "=== BẮT ĐẦU TỐI ƯU INDEX CHO MARIADB ===\n";

$indexes = [
    ['scheduled_posts', 'idx_status_retry', '(status, retry_count)'],
    ['scheduled_posts', 'idx_cron_dispatch', '(status, scheduled_time, page_id)'],
    ['scheduled_posts', 'idx_comment_queue', '(status, comment_at, comment_done)'],
    ['scheduled_posts', 'idx_camp_status', '(campaign_id, status)'],
    ['scheduled_posts', 'idx_acc_status_sched', '(account_id, status, scheduled_time)'],
    ['scheduled_posts', 'idx_status_sched', '(status, scheduled_time)'],
    ['scheduled_posts', 'idx_post_type_sched', '(post_type, scheduled_time)'],
    ['scheduled_posts', 'idx_updated_at', '(updated_at)'],
    ['fb_customers', 'idx_page_sender', '(page_id, sender_id)'],
    ['fb_customers', 'idx_cust_last_msg', '(page_id, customer_last_message_at)'],
    ['fb_conversations', 'idx_page_sender_conv', '(page_id, sender_id)']
];

foreach ($indexes as $idx) {
    list($tbl, $name, $cols) = $idx;
    try {
        $chk = $pdo->query("SHOW INDEX FROM `$tbl` WHERE Key_name = '$name'");
        if ($chk && $chk->fetch()) {
            echo "  [OK] Index `$name` đã tồn tại trên bảng `$tbl`.\n";
        } else {
            echo "  [CREATING] Đang tạo Index `$name` $cols trên bảng `$tbl` (Vui lòng đợi vài giây)... ";
            $pdo->exec("ALTER TABLE `$tbl` ADD INDEX `$name` $cols");
            echo "HOÀN TẤT!\n";
        }
    } catch (Exception $e) {
        echo "LỖI khi tạo Index `$name`: " . $e->getMessage() . "\n";
    }
}

// Xóa các cờ cache cũ để ép db.php nạp lại
@unlink(sys_get_temp_dir() . '/fb_schema_init_v14.done');
@unlink(sys_get_temp_dir() . '/fb_schema_init_v15.done');
@file_put_contents(sys_get_temp_dir() . '/fb_schema_init_v16.done', date('Y-m-d H:i:s'));

try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('schema_init_v16_done', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
} catch (Exception $e) {}

echo "=== TỐI ƯU INDEX HOÀN TẤT CỰC KỲ THÀNH CÔNG ===\n";
