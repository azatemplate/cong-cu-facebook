<?php
// setup_zalo.php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== KHỞI TẠO CƠ SỞ DỮ LIỆU ZALO OA ===\n\n";

try {
    // 1. Tạo bảng zalo_settings
    $sql_settings = "CREATE TABLE IF NOT EXISTS zalo_settings (
        account_id INT NOT NULL PRIMARY KEY,
        app_id VARCHAR(100) NULL,
        app_secret VARCHAR(255) NULL,
        oa_secret VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_settings);
    
    try {
        $pdo->exec("ALTER TABLE zalo_settings ADD COLUMN oa_secret VARCHAR(255) NULL AFTER app_secret");
    } catch (PDOException $e) {
        // Cột có thể đã tồn tại, bỏ qua
    }
    echo "1. Tạo/Cập nhật bảng zalo_settings thành công.\n";

    // 2. Tạo bảng zalo_oas
    $sql_oas = "CREATE TABLE IF NOT EXISTS zalo_oas (
        oa_id VARCHAR(100) NOT NULL PRIMARY KEY,
        account_id INT NOT NULL,
        name VARCHAR(255) NULL,
        avatar TEXT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        expires_at TIMESTAMP NULL,
        refresh_expires_at TIMESTAMP NULL,
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_account (account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_oas);
    echo "2. Tạo bảng zalo_oas thành công.\n";

    // 3. Tạo bảng zalo_customers
    $sql_customers = "CREATE TABLE IF NOT EXISTS zalo_customers (
        oa_id VARCHAR(100) NOT NULL,
        sender_id VARCHAR(100) NOT NULL,
        name VARCHAR(255) NULL,
        avatar TEXT NULL,
        phone VARCHAR(50) NULL,
        province VARCHAR(255) NULL,
        notes TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (oa_id, sender_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_customers);
    echo "3. Tạo bảng zalo_customers thành công.\n";

    // 4. Tạo bảng zalo_messages (để lưu thông tin hội thoại đệm, tối ưu tốc độ load)
    $sql_messages = "CREATE TABLE IF NOT EXISTS zalo_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        oa_id VARCHAR(100) NOT NULL,
        sender_id VARCHAR(100) NOT NULL,
        sender_name VARCHAR(255) NULL,
        type VARCHAR(20) DEFAULT 'message',
        snippet TEXT NULL,
        unread_count INT DEFAULT 0,
        updated_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_oa_sender (oa_id, sender_id),
        INDEX idx_upd_time (updated_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_messages);
    echo "4. Tạo bảng zalo_messages thành công.\n";

    // 5. Tạo bảng zalo_chat_locks
    $sql_locks = "CREATE TABLE IF NOT EXISTS zalo_chat_locks (
        oa_id VARCHAR(100) NOT NULL,
        sender_id VARCHAR(100) NOT NULL,
        expire_at TIMESTAMP NOT NULL,
        PRIMARY KEY (oa_id, sender_id),
        INDEX idx_expire (expire_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_locks);
    echo "5. Tạo bảng zalo_chat_locks thành công.\n";

    echo "\n=== QUÁ TRÌNH KHỞI TẠO HOÀN TẤT ===\n";

} catch (PDOException $e) {
    echo "Lỗi khởi tạo: " . $e->getMessage() . "\n";
}
