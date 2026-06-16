<?php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== DATABASE MIGRATION: BOT CHAT LOCKS ===\n\n";

$queries = [
    "CREATE TABLE IF NOT EXISTS bot_chat_locks (
        page_id VARCHAR(50) NOT NULL,
        sender_id VARCHAR(50) NOT NULL,
        expire_at TIMESTAMP NOT NULL,
        PRIMARY KEY (page_id, sender_id),
        INDEX idx_expire (expire_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS zalo_chat_locks (
        oa_id VARCHAR(50) NOT NULL,
        sender_id VARCHAR(50) NOT NULL,
        expire_at TIMESTAMP NOT NULL,
        PRIMARY KEY (oa_id, sender_id),
        INDEX idx_expire (expire_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo "SUCCESS: " . substr($sql, 0, 60) . "...\n";
    } catch (PDOException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration finished.\n";
