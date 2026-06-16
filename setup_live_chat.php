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
    
    // Add delay_seconds column if it does not exist
    try {
        $pdo->exec("ALTER TABLE bot_chat_rules ADD COLUMN delay_seconds INT DEFAULT 0 AFTER is_active;");
    } catch (PDOException $e) {
        // column likely exists
    }

    // Add history_count column if it does not exist
    try {
        $pdo->exec("ALTER TABLE bot_chat_rules ADD COLUMN history_count INT DEFAULT 6 AFTER delay_seconds;");
    } catch (PDOException $e) {
        // column likely exists
    }

    $sql_lock = "CREATE TABLE IF NOT EXISTS bot_chat_locks (
        page_id VARCHAR(50) NOT NULL,
        sender_id VARCHAR(50) NOT NULL,
        expire_at TIMESTAMP NOT NULL,
        PRIMARY KEY (page_id, sender_id),
        INDEX idx_expire (expire_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_lock);
    echo "Tạo bảng bot_chat_locks thành công.\n";

    $sql_customers = "CREATE TABLE IF NOT EXISTS fb_customers (
        page_id VARCHAR(50) NOT NULL,
        sender_id VARCHAR(50) NOT NULL,
        name VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        province VARCHAR(255) NULL,
        notes TEXT NULL,
        last_sender VARCHAR(10) DEFAULT 'customer',
        last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        info_requested_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (page_id, sender_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_customers);
    echo "Tạo bảng fb_customers thành công.\n";

} catch (PDOException $e) {
    echo "Lỗi: " . $e->getMessage() . "\n";
}
