<?php
// migrate.php
// Run this file ONCE to apply all database schema migrations.
// Access via browser: https://yourdomain.com/facebook/migrate.php
// Then DELETE or protect this file afterward.

require_once __DIR__ . '/includes/db.php';

$results = [];

$migrations = [
    // system_accounts columns
    ["ALTER TABLE system_accounts ADD COLUMN account_id INT DEFAULT 1", "users.account_id"],
    ["ALTER TABLE system_accounts ADD COLUMN expire_date DATETIME DEFAULT NULL", "system_accounts.expire_date"],
    ["ALTER TABLE system_accounts ADD COLUMN page_limit INT DEFAULT 500", "system_accounts.page_limit"],
    ["ALTER TABLE system_accounts ADD COLUMN auto_reply_enabled TINYINT DEFAULT 0", "system_accounts.auto_reply_enabled"],
    ["ALTER TABLE system_accounts ADD COLUMN auto_reply_text TEXT DEFAULT NULL", "system_accounts.auto_reply_text"],
    ["ALTER TABLE system_accounts ADD COLUMN auto_inbox_enabled TINYINT DEFAULT 0", "system_accounts.auto_inbox_enabled"],
    ["ALTER TABLE system_accounts ADD COLUMN auto_inbox_text TEXT DEFAULT NULL", "system_accounts.auto_inbox_text"],
    ["ALTER TABLE system_accounts ADD COLUMN fb_app_id VARCHAR(255) DEFAULT NULL", "system_accounts.fb_app_id"],
    ["ALTER TABLE system_accounts ADD COLUMN fb_app_secret VARCHAR(255) DEFAULT NULL", "system_accounts.fb_app_secret"],
    ["ALTER TABLE system_accounts ADD COLUMN gg_client_id VARCHAR(255) DEFAULT NULL", "system_accounts.gg_client_id"],
    ["ALTER TABLE system_accounts ADD COLUMN gg_client_secret VARCHAR(255) DEFAULT NULL", "system_accounts.gg_client_secret"],
    ["ALTER TABLE system_accounts ADD COLUMN gg_refresh_token TEXT DEFAULT NULL", "system_accounts.gg_refresh_token"],

    // users.account_id
    ["ALTER TABLE users ADD COLUMN account_id INT DEFAULT 1", "users.account_id"],

    // scheduled_posts enhancements
    ["ALTER TABLE scheduled_posts ADD COLUMN retry_count INT DEFAULT 0", "scheduled_posts.retry_count"],
    ["ALTER TABLE scheduled_posts ADD COLUMN campaign_id INT DEFAULT NULL", "scheduled_posts.campaign_id"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_lines TEXT DEFAULT NULL", "scheduled_posts.comment_lines"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_at DATETIME DEFAULT NULL", "scheduled_posts.comment_at"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_done TINYINT(1) DEFAULT 0", "scheduled_posts.comment_done"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_status VARCHAR(20) DEFAULT NULL", "scheduled_posts.comment_status"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_mode VARCHAR(20) DEFAULT NULL", "scheduled_posts.comment_mode"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_threshold_views INT DEFAULT 0", "scheduled_posts.comment_threshold_views"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_threshold_likes INT DEFAULT 0", "scheduled_posts.comment_threshold_likes"],
    ["ALTER TABLE scheduled_posts ADD COLUMN comment_threshold_comments INT DEFAULT 0", "scheduled_posts.comment_threshold_comments"],
    ["ALTER TABLE scheduled_posts ADD COLUMN fb_post_id VARCHAR(100) DEFAULT NULL", "scheduled_posts.fb_post_id"],

    // Character set conversions (run once)
    ["ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "users charset"],
    ["ALTER TABLE pages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "pages charset"],

    // Campaign table
    ["CREATE TABLE IF NOT EXISTS post_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        post_type VARCHAR(50) DEFAULT 'Mixed',
        total_posts INT DEFAULT 0,
        scheduled_time DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (account_id) REFERENCES system_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "post_campaigns table"],

    // system_settings table
    ["CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "system_settings table"],

    // special_watch_targets - fb_user_id column (chọn FB user token khi quét)
    ["ALTER TABLE special_watch_targets ADD COLUMN fb_user_id INT DEFAULT NULL", "special_watch_targets.fb_user_id"],

    // Missing updated_at for scheduled_posts (stuck detection)
    ["ALTER TABLE scheduled_posts ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "scheduled_posts.updated_at"],

    // Telegram per-user columns
    ["ALTER TABLE system_accounts ADD COLUMN telegram_bot_token VARCHAR(255) DEFAULT NULL", "system_accounts.telegram_bot_token"],
    ["ALTER TABLE system_accounts ADD COLUMN telegram_chat_id VARCHAR(100) DEFAULT NULL", "system_accounts.telegram_chat_id"],

    // Email login columns
    ["ALTER TABLE system_accounts ADD COLUMN email VARCHAR(255) DEFAULT NULL", "system_accounts.email"],
    ["ALTER TABLE system_accounts ADD COLUMN login_by_email TINYINT(1) DEFAULT 0", "system_accounts.login_by_email"],

    // Customer consulted status
    ["ALTER TABLE fb_customers ADD COLUMN consulted TINYINT DEFAULT 0", "fb_customers.consulted"],
    ["ALTER TABLE zalo_customers ADD COLUMN consulted TINYINT DEFAULT 0", "zalo_customers.consulted"],

    // Sales Handoff columns
    ["ALTER TABLE fb_customers ADD COLUMN sales_phone VARCHAR(50) DEFAULT NULL", "fb_customers.sales_phone"],
    ["ALTER TABLE fb_customers ADD COLUMN sales_notes TEXT DEFAULT NULL", "fb_customers.sales_notes"],
    ["ALTER TABLE zalo_customers ADD COLUMN sales_phone VARCHAR(50) DEFAULT NULL", "zalo_customers.sales_phone"],
    ["ALTER TABLE zalo_customers ADD COLUMN sales_notes TEXT DEFAULT NULL", "zalo_customers.sales_notes"],
    
    // Quick sales list per account
    ["ALTER TABLE system_accounts ADD COLUMN sales_list TEXT DEFAULT NULL", "system_accounts.sales_list"],

    // New dashboard snapshot columns
    ["ALTER TABLE dashboard_snapshots ADD COLUMN total_accounts INT DEFAULT 0", "dashboard_snapshots.total_accounts"],
    ["ALTER TABLE dashboard_snapshots ADD COLUMN total_reels INT DEFAULT 0", "dashboard_snapshots.total_reels"],
    ["ALTER TABLE dashboard_snapshots ADD COLUMN total_posts INT DEFAULT 0", "dashboard_snapshots.total_posts"],
];

// Default system settings seed
$default_settings = [
    ['retry_interval_minutes', '1'],
    ['max_retries', '3'],
];

foreach ($migrations as [$sql, $label]) {
    try {
        $pdo->exec($sql);
        $results[] = "✅ OK: $label";
    } catch (PDOException $e) {
        // Most errors here are "column already exists" — safe to ignore
        $results[] = "⚠️ Skip (exists): $label — " . $e->getMessage();
    }
}

// Seed default settings
foreach ($default_settings as [$key, $val]) {
    try {
        $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)")
            ->execute([$key, $val]);
        $results[] = "✅ Setting seeded: $key";
    } catch (PDOException $e) {
        $results[] = "⚠️ Setting skip: $key";
    }
}

echo "<pre style='font-family:monospace; font-size:14px; padding:20px;'>";
echo "<b>Database Migration Results</b>\n";
echo str_repeat("-", 60) . "\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo str_repeat("-", 60) . "\n";
echo "<b>Migration complete. You may now delete this file.</b>\n";
echo "</pre>";
?>
