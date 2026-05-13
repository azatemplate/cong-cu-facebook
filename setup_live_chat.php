<?php
require_once __DIR__ . '/includes/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS bot_chat_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        rule_type VARCHAR(20) NOT NULL, -- 'welcome' or 'keyword'
        keywords TEXT NULL,
        message TEXT NOT NULL,
        pages_scope TEXT NULL,
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_acc (account_id),
        INDEX idx_type (rule_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $pdo->exec($sql);
    echo "Tạo bảng bot_chat_rules thành công.\n";
} catch (PDOException $e) {
    echo "Lỗi: " . $e->getMessage() . "\n";
}
