<?php
// setup_tiktok_chat.php
require_once __DIR__ . '/includes/db.php';

try {
    // 1. Table tiktok_customers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tiktok_customers (
            open_id VARCHAR(100) NOT NULL,
            sender_id VARCHAR(100) NOT NULL,
            name VARCHAR(255) NULL,
            avatar TEXT NULL,
            phone VARCHAR(50) NULL,
            province VARCHAR(255) NULL,
            notes TEXT NULL,
            consulted TINYINT(1) DEFAULT 0,
            sales_phone VARCHAR(50) NULL,
            sales_notes TEXT NULL,
            last_message VARCHAR(500) NULL,
            last_sender VARCHAR(10) DEFAULT 'customer',
            unread_count INT DEFAULT 0,
            last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (open_id, sender_id),
            INDEX idx_upd (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Table tiktok_messages
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tiktok_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            open_id VARCHAR(100) NOT NULL,
            sender_id VARCHAR(100) NOT NULL,
            sender_name VARCHAR(255) NULL,
            sender_type ENUM('customer', 'agent', 'bot') DEFAULT 'customer',
            message TEXT NULL,
            attachments TEXT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conv (open_id, sender_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    error_log("setup_tiktok_chat error: " . $e->getMessage());
}
?>
