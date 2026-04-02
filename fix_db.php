<?php
require_once __DIR__ . '/includes/db.php';

echo "<h3>Bắt đầu quét và sửa lỗi thiếu AUTO_INCREMENT cho các bảng...</h3>";

$tables = [
    'users',
    'pages',
    'posts_history',
    'system_accounts',
    'scheduled_posts',
    'post_campaigns',
    'saved_replies',
    'ai_configs',
    'page_shares',
    'youtube_channels',
    'system_settings',
    'conversation_labels',
    'dashboard_snapshots'
];

$success_count = 0;

foreach ($tables as $table) {
    try {
        $check = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($check->rowCount() > 0) {
            $pdo->exec("ALTER TABLE `$table` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
            echo "<p style='color:green;'>✅ Đã sửa thành công bảng: <b>$table</b></p>";
            $success_count++;
        }
    } catch (PDOException $e) {
        // Lỗi 1075 là do bảng bị mất cả bằng chứng nhận PRIMARY KEY
        if (strpos($e->getMessage(), '1075') !== false || strpos($e->getMessage(), 'Multiple primary key') !== false) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");
                $pdo->exec("ALTER TABLE `$table` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
                echo "<p style='color:green;'>✅ Đã bổ sung khoá chính và cập nhật thành công bảng: <b>$table</b></p>";
                $success_count++;
            } catch (PDOException $e2) {
                // Nếu bị lỗi 1062 tức là bảng bị TRÙNG LẶP DỮ LIỆU CÙNG ID
                if (strpos($e2->getMessage(), '1062') !== false || strpos($e2->getMessage(), 'Duplicate entry') !== false) {
                    try {
                        // Tạo bảng tạm, gán khoá chính, đổ dữ liệu lọc trùng vào, rồi tráo bảng
                        $pdo->exec("CREATE TABLE `{$table}_tmp_fix` LIKE `$table`");
                        $pdo->exec("ALTER TABLE `{$table}_tmp_fix` ADD PRIMARY KEY (`id`)");
                        $pdo->exec("INSERT IGNORE INTO `{$table}_tmp_fix` SELECT * FROM `$table`");
                        $pdo->exec("DROP TABLE `$table`");
                        $pdo->exec("RENAME TABLE `{$table}_tmp_fix` TO `$table`");
                        $pdo->exec("ALTER TABLE `$table` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
                        echo "<p style='color:green;'>✅ Đã sửa (lọc dữ liệu trùng lặp) thành công bảng: <b>$table</b></p>";
                        $success_count++;
                    } catch (Exception $e3) {
                        echo "<p style='color:red;'>❌ Bó tay khi cố lọc bảng <b>$table</b>: " . $e3->getMessage() . "</p>";
                    }
                } else {
                    echo "<p style='color:red;'>❌ Lỗi sâu hơn khi sửa bảng <b>$table</b>: " . $e2->getMessage() . "</p>";
                }
            }
        } else {
            echo "<p style='color:red;'>❌ Lỗi khởi tạo bảng <b>$table</b>: " . $e->getMessage() . "</p>";
        }
    }
}

echo "<h3>Hoàn tất sửa lỗi! Đã khắc phục thành công $success_count bảng. Bạn có thể xóa file này đi.</h3>";
?>
