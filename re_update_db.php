<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT
    )");
    echo "system_settings created. ";
    
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('global_notice', '')");
    echo "global_notice inserted. ";
    
    // Also try adding retry count just in case it failed
    $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN retry_count INT DEFAULT 0 AFTER status");
    echo "retry_count added. ";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
