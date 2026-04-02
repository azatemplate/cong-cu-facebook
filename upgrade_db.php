<?php
require_once __DIR__ . '/includes/db.php';

$results = [];

// ── system_accounts columns ──────────────────────────────────────────────
foreach (['fb_app_id VARCHAR(255) DEFAULT NULL', 'fb_app_secret VARCHAR(255) DEFAULT NULL'] as $col_def) {
    $col = explode(' ', $col_def)[0];
    try {
        $pdo->exec("ALTER TABLE system_accounts ADD COLUMN $col_def;");
        $results[] = "✅ Added system_accounts.$col";
    } catch (Exception $e) {
        $results[] = "⚠️ system_accounts.$col already exists (skipped)";
    }
}

// ── post_campaigns table ─────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS post_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        post_type VARCHAR(50) DEFAULT 'Post',
        total_posts INT DEFAULT 0,
        scheduled_time DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $results[] = "✅ post_campaigns table ready";
} catch (Exception $e) {
    $results[] = "❌ post_campaigns: " . $e->getMessage();
}

// ── scheduled_posts columns ──────────────────────────────────────────────
$sp_cols = [
    'campaign_id INT DEFAULT NULL',
    'comment_lines TEXT DEFAULT NULL',
    'published_at DATETIME DEFAULT NULL',
    'post_id VARCHAR(100) DEFAULT NULL',
    'error_msg TEXT DEFAULT NULL',
];
foreach ($sp_cols as $col_def) {
    $col = explode(' ', $col_def)[0];
    try {
        $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN $col_def;");
        $results[] = "✅ Added scheduled_posts.$col";
    } catch (Exception $e) {
        $results[] = "⚠️ scheduled_posts.$col already exists (skipped)";
    }
}

echo "<pre style='font-family:monospace; font-size:14px; padding:20px;'>\n";
echo "=== upgrade_db.php ===\n\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo "\n✔ Done!\n</pre>";
?>
