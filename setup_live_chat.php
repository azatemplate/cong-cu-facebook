<?php
require_once __DIR__ . '/includes/db.php';

$flag_file = sys_get_temp_dir() . '/live_chat_setup_v3.done';
if (file_exists($flag_file)) {
    return;
}

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
    
    // Add delay_seconds column if it does not exist
    try {
        $pdo->exec("ALTER TABLE bot_chat_rules ADD COLUMN delay_seconds INT DEFAULT 0 AFTER is_active;");
    } catch (PDOException $e) {}

    // Add history_count column if it does not exist
    try {
        $pdo->exec("ALTER TABLE bot_chat_rules ADD COLUMN history_count INT DEFAULT 6 AFTER delay_seconds;");
    } catch (PDOException $e) {}

    $sql_lock = "CREATE TABLE IF NOT EXISTS bot_chat_locks (
        page_id VARCHAR(50) NOT NULL,
        sender_id VARCHAR(50) NOT NULL,
        expire_at TIMESTAMP NOT NULL,
        PRIMARY KEY (page_id, sender_id),
        INDEX idx_expire (expire_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_lock);

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

    // Migration: Thêm các cột cho tính năng Conversions API (CAPI)
    try {
        $pdo->exec("ALTER TABLE pages ADD COLUMN capi_pixel_id VARCHAR(100) NULL AFTER user_id");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE pages ADD COLUMN capi_token TEXT NULL AFTER capi_pixel_id");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE pages ADD COLUMN auto_send_capi TINYINT DEFAULT 1 AFTER capi_token");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE fb_customers ADD COLUMN capi_pushed TINYINT DEFAULT 0 AFTER info_requested_at");
    } catch (PDOException $e) {}

    @touch($flag_file);
} catch (PDOException $e) {
    // ignore
}


