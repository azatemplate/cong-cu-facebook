<?php
// setup_website_chat.php
require_once __DIR__ . '/includes/db.php';

try {
    // 1. Bảng lưu cấu hình Chat Widget cho mỗi account
    $sql_config = "CREATE TABLE IF NOT EXISTS web_chat_configs (
        account_id INT NOT NULL PRIMARY KEY,
        bot_name VARCHAR(255) DEFAULT 'Gấu cười',
        bot_avatar VARCHAR(500) DEFAULT 'https://s240-ava-talk.zadn.vn/c/c/6/3/3/240/cd520d4d49a844b5abe6410e9e3dd9aa.jpg',
        bot_subtitle VARCHAR(255) DEFAULT 'Trợ lý AI MONA — đang online',
        policy_notice TEXT NULL,
        greeting_msg TEXT NULL,
        brand_footer VARCHAR(255) DEFAULT 'AI chăm sóc khách hàng bởi MONA',
        zalo_link VARCHAR(500) DEFAULT '',
        messenger_link VARCHAR(500) DEFAULT '',
        primary_color VARCHAR(50) DEFAULT '#0068ff',
        ai_enabled TINYINT DEFAULT 1,
        system_prompt TEXT NULL,
        widget_position VARCHAR(20) DEFAULT 'right',
        bottom_offset INT DEFAULT 24,
        side_offset INT DEFAULT 24,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_config);

function ensure_web_chat_columns($pdo) {
    try { $pdo->exec("ALTER TABLE web_chat_configs ADD COLUMN widget_position VARCHAR(20) DEFAULT 'right'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE web_chat_configs ADD COLUMN bottom_offset INT DEFAULT 24"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE web_chat_configs ADD COLUMN side_offset INT DEFAULT 24"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE web_visitors ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (PDOException $e) {}
}

ensure_web_chat_columns($pdo);

    // 2. Bảng lưu thông tin Khách Vãng Lai truy cập website
    $sql_visitors = "CREATE TABLE IF NOT EXISTS web_visitors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        visitor_uuid VARCHAR(100) NOT NULL,
        visitor_num INT NOT NULL DEFAULT 1,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NULL,
        province VARCHAR(255) NULL,
        notes TEXT NULL,
        consulted TINYINT DEFAULT 0,
        unread_count INT DEFAULT 0,
        last_sender VARCHAR(10) DEFAULT 'customer',
        last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_acc_uuid (account_id, visitor_uuid),
        INDEX idx_acc (account_id),
        INDEX idx_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_visitors);

    // Migration: Thêm created_at nếu bảng đã tồn tại từ trước
    try {
        $pdo->exec("ALTER TABLE web_visitors ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER last_message_at;");
    } catch (PDOException $e) {}

    // 3. Bảng lưu lịch sử tin nhắn chat website
    $sql_messages = "CREATE TABLE IF NOT EXISTS web_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        visitor_uuid VARCHAR(100) NOT NULL,
        sender_type VARCHAR(20) NOT NULL, -- 'user', 'bot', 'agent'
        sender_name VARCHAR(255) NULL,
        message TEXT NOT NULL,
        attachments TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_acc_uuid (account_id, visitor_uuid),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_messages);

} catch (PDOException $e) {
    error_log("Setup Web Chat Error: " . $e->getMessage());
}
