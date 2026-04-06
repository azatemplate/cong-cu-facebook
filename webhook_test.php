<?php
require_once __DIR__ . '/includes/db.php';

echo "<h2>Chẩn đoán Webhook Database</h2>";
echo "<pre>";

// 1. Kiểm tra bảng tồn tại
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'page_notifications'")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) {
        echo "❌ Bảng page_notifications CHƯA TỒN TẠI!\n";
        echo "→ Đang tạo bảng...\n";
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page (page_id),
            INDEX idx_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        echo "✅ Đã tạo bảng page_notifications!\n";
    } else {
        echo "✅ Bảng page_notifications ĐÃ TỒN TẠI\n";
    }
} catch (Exception $e) {
    echo "❌ Lỗi DB: " . $e->getMessage() . "\n";
}

// 2. Đếm số bản ghi hiện có
try {
    $count = $pdo->query("SELECT COUNT(*) FROM page_notifications")->fetchColumn();
    echo "📊 Số thông báo hiện có: $count\n";
} catch (Exception $e) {
    echo "❌ Lỗi đếm: " . $e->getMessage() . "\n";
}

// 3. Thử INSERT test
try {
    $stmt = $pdo->prepare("INSERT INTO page_notifications (page_id, type, sender_id, sender_name, snippet, post_id) VALUES ('TEST_PAGE_999', 'message', 'test_sender', 'Test User', 'Tin nhắn thử nghiệm từ webhook_test.php', '')");
    $r = $stmt->execute();
    $lid = $pdo->lastInsertId();
    echo "✅ Test INSERT thành công! (ID=$lid)\n";
    $pdo->exec("DELETE FROM page_notifications WHERE page_id='TEST_PAGE_999'");
    echo "✅ Đã xóa bản ghi test\n";
} catch (Exception $e) {
    echo "❌ Lỗi INSERT: " . $e->getMessage() . "\n";
}

// 4. Liệt kê 5 thông báo mới nhất
echo "\n--- 5 thông báo mới nhất ---\n";
try {
    $rows = $pdo->query("SELECT id, page_id, type, sender_name, snippet, is_read, created_at FROM page_notifications ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "(Chưa có thông báo nào)\n";
    } else {
        foreach ($rows as $r) {
            echo "[{$r['id']}] [{$r['type']}] page={$r['page_id']} | {$r['sender_name']}: {$r['snippet']} | read={$r['is_read']} | {$r['created_at']}\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Lỗi SELECT: " . $e->getMessage() . "\n";
}

// 5. Danh sách page_id trong pages table để đối chiếu
echo "\n--- Page IDs trong bảng pages ---\n";
try {
    $pages = $pdo->query("SELECT page_id, name FROM pages LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pages as $p) {
        echo "• {$p['page_id']} - {$p['name']}\n";
    }
} catch (Exception $e) {
    echo "❌ Lỗi pages: " . $e->getMessage() . "\n";
}

echo "</pre>";
