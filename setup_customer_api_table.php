<?php
// setup_customer_api_table.php
require_once __DIR__ . '/includes/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS customer_api_configs (
        account_id INT NOT NULL PRIMARY KEY,
        is_enabled TINYINT DEFAULT 0,
        api_url VARCHAR(500) NULL,
        api_token VARCHAR(500) NULL,
        trigger_condition VARCHAR(50) DEFAULT 'has_phone',
        send_scope VARCHAR(50) DEFAULT 'all',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql);

    try { $pdo->exec("ALTER TABLE customer_api_configs ADD COLUMN send_scope VARCHAR(50) DEFAULT 'all'"); } catch (PDOException $e) {}
} catch (PDOException $e) {}
