<?php
require_once __DIR__ . '/includes/db.php';

$flag_file = sys_get_temp_dir() . '/live_comments_setup_v2.done';
if (file_exists($flag_file)) {
    return;
}

try {
    $sql = "CREATE TABLE IF NOT EXISTS page_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        page_id VARCHAR(50) NOT NULL,
        type VARCHAR(20) NOT NULL, /* 'message' or 'comment' */
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $pdo->exec($sql);
    @touch($flag_file);
} catch (PDOException $e) {}

