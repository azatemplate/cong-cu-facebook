<?php
// run_publish_step.php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== START PUBLISH SIMULATOR ===\n";

$MAX_WORKERS = 15;
try {
    $res_limit = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_workers'")->fetchColumn();
    if ($res_limit) $MAX_WORKERS = (int)$res_limit;
} catch (Exception $e) {}

$active_workers = (int)$pdo->query("SELECT COUNT(DISTINCT page_id) FROM scheduled_posts WHERE status = 'processing'")->fetchColumn();

echo "MAX_WORKERS: $MAX_WORKERS\n";
echo "ACTIVE_WORKERS: $active_workers\n";
echo "AVAILABLE_SLOTS: " . ($MAX_WORKERS - $active_workers) . "\n\n";

$sql = "
    SELECT DISTINCT sp.page_id, sp.account_id, sp.post_type, p.user_id
    FROM scheduled_posts sp
    LEFT JOIN system_accounts sa ON sp.account_id = sa.id
    LEFT JOIN pages p ON sp.page_id = p.page_id
    WHERE sp.scheduled_time <= NOW()
      AND sp.page_id IS NOT NULL
      AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
      AND (
        sp.status = 'pending'
        OR (sp.status = 'failed' AND (sp.retry_count IS NULL OR sp.retry_count < COALESCE(sa.max_retries, 3)))
      )
";
$stmt = $pdo->query($sql);
$raw_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total raw pages found with overdue posts: " . count($raw_pages) . "\n";

$pages_by_user = [];
foreach ($raw_pages as $row) {
    if ($row['post_type'] === 'YouTube') {
        $uid = 'yt_' . $row['account_id'];
    } else {
        $uid = $row['user_id'] ?: ('noid_' . $row['page_id']);
    }
    if (!isset($pages_by_user[$uid])) {
        $pages_by_user[$uid] = [];
    }
    $pages_by_user[$uid][] = $row['page_id'];
}

echo "Grouped by User/Key:\n";
foreach ($pages_by_user as $uid => $pages) {
    echo "- Key: $uid | Pages: " . implode(',', $pages) . "\n";
    
    // Check lock file
    $lock_dir = __DIR__ . '/locks';
    $lock_key = md5('uid_' . $uid);
    $lock_file = $lock_dir . "/publish_user_" . $lock_key . ".lock";
    if (file_exists($lock_file)) {
        $age = time() - filemtime($lock_file);
        echo "  [LOCKED] Lock file exists! Age: {$age}s | Path: " . basename($lock_file) . "\n";
    } else {
        echo "  [FREE] No lock file.\n";
    }
}

exit;
?>
