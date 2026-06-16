<?php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== DATABASE MIGRATION: AUTO INFO REQUEST ===\n\n";

$migrations = [
    // 1. system_accounts
    "ALTER TABLE system_accounts ADD COLUMN phone_request_enabled TINYINT DEFAULT 0",
    "ALTER TABLE system_accounts ADD COLUMN phone_request_hours INT DEFAULT 1",
    "ALTER TABLE system_accounts ADD COLUMN phone_request_text TEXT DEFAULT NULL",
    "ALTER TABLE system_accounts ADD COLUMN province_request_text TEXT DEFAULT NULL",
    "ALTER TABLE system_accounts ADD COLUMN product_request_text TEXT DEFAULT NULL",

    // 2. zalo_settings
    "ALTER TABLE zalo_settings ADD COLUMN phone_request_enabled TINYINT DEFAULT 0",
    "ALTER TABLE zalo_settings ADD COLUMN phone_request_hours INT DEFAULT 1",
    "ALTER TABLE zalo_settings ADD COLUMN phone_request_text TEXT DEFAULT NULL",
    "ALTER TABLE zalo_settings ADD COLUMN province_request_text TEXT DEFAULT NULL",
    "ALTER TABLE zalo_settings ADD COLUMN product_request_text TEXT DEFAULT NULL",

    // 3. fb_customers
    "ALTER TABLE fb_customers ADD COLUMN last_sender VARCHAR(10) DEFAULT 'customer'",
    "ALTER TABLE fb_customers ADD COLUMN last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE fb_customers ADD COLUMN info_requested_at TIMESTAMP NULL DEFAULT NULL",

    // 4. zalo_customers
    "ALTER TABLE zalo_customers ADD COLUMN last_sender VARCHAR(10) DEFAULT 'customer'",
    "ALTER TABLE zalo_customers ADD COLUMN last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE zalo_customers ADD COLUMN info_requested_at TIMESTAMP NULL DEFAULT NULL"
];

foreach ($migrations as $query) {
    try {
        $pdo->exec($query);
        echo "SUCCESS: $query\n";
    } catch (PDOException $e) {
        if ($e->getCode() === '42S21' || strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "SKIPPED (already exists): $query\n";
        } else {
            echo "ERROR executing: $query. Message: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nMigration finished.\n";
