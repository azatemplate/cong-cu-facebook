<?php
// Test write permission
$result = @file_put_contents(__DIR__ . '/webhook_test_write.txt', 'OK_' . date('H:i:s'));
if ($result !== false) {
    echo "✅ Quyền ghi thư mục: OK ({$result} bytes)";
} else {
    echo "❌ Không có quyền ghi thư mục! Webhook sẽ không lưu được DB.";
}
require_once __DIR__ . '/includes/db.php';
try {
    // Test DB write
    $pdo->exec("CREATE TABLE IF NOT EXISTS page_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        page_id VARCHAR(50) NOT NULL,
        type VARCHAR(20) NOT NULL,
        sender_id VARCHAR(50) NULL,
        sender_name VARCHAR(100) NULL,
        snippet TEXT NULL,
        post_id VARCHAR(50) NULL,
        comment_id VARCHAR(50) NULL,
        conversation_id VARCHAR(50) NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("INSERT INTO page_notifications (page_id, type, sender_name, snippet) VALUES ('TEST_PAGE', 'message', 'Test User', 'Test webhook notification')");
    $last = $pdo->lastInsertId();
    echo "\n✅ DB ghi thành công (ID=$last)";
    // Cleanup
    $pdo->exec("DELETE FROM page_notifications WHERE page_id='TEST_PAGE'");
    echo "\n✅ DB đọc/xóa thành công";
} catch (Exception $e) {
    echo "\n❌ Lỗi DB: " . $e->getMessage();
}
echo "\n\n--- Danh sách bảng ---\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $tables);
