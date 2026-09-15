<?php
// debug_publish.php - Chạy start_publish.php đồng bộ để xem output/lỗi trực tiếp
require_once __DIR__ . '/includes/php_cli.php';
header('Content-Type: text/plain; charset=utf-8');

$php_bin = get_php_cli_bin();
echo "PHP CLI: $php_bin\n";
echo "Exists: " . (file_exists($php_bin) ? "YES" : "NO") . "\n\n";

// Chạy start_publish.php đồng bộ và bắt toàn bộ output
$script = __DIR__ . '/cron/start_publish.php';
echo "=== CHẠY start_publish.php ===\n";
$output = [];
$return_code = -1;
exec("\"$php_bin\" \"$script\" 2>&1", $output, $return_code);
echo "Return code: $return_code\n";
echo "Output:\n";
echo implode("\n", $output);

echo "\n\n=== CHẠY THỬ publish_worker.php cho 1 user ===\n";
// Tìm 1 user có bài pending
require_once __DIR__ . '/includes/db.php';
$stmt = $pdo->query("
    SELECT DISTINCT u.id AS user_id, GROUP_CONCAT(DISTINCT sp.page_id) AS page_ids, sa.username
    FROM scheduled_posts sp
    JOIN pages p ON sp.page_id = p.page_id
    JOIN users u ON p.user_id = u.id
    JOIN system_accounts sa ON sp.account_id = sa.id
    WHERE sp.status = 'pending' 
      AND sp.scheduled_time <= NOW()
      AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
    GROUP BY u.id
    LIMIT 1
");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "Không tìm thấy user nào có bài pending (kiểm tra JOIN pages/users).\n\n";
    
    // Debug: kiểm tra xem có bài pending mà không match JOIN không
    echo "=== DEBUG: Bài pending KHÔNG match JOIN ===\n";
    $check = $pdo->query("
        SELECT sp.id, sp.page_id, sp.account_id, sp.status, sp.scheduled_time,
               (SELECT COUNT(*) FROM pages p WHERE p.page_id = sp.page_id) AS page_exists,
               sa.username, sa.expire_date
        FROM scheduled_posts sp
        JOIN system_accounts sa ON sp.account_id = sa.id
        WHERE sp.status = 'pending' AND sp.scheduled_time <= NOW()
          AND (sa.expire_date IS NULL OR sa.expire_date >= NOW())
        LIMIT 10
    ");
    foreach ($check->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "Post #{$r['id']} | page:{$r['page_id']} | account:{$r['username']} | page_in_DB:{$r['page_exists']} | sched:{$r['scheduled_time']}\n";
    }
} else {
    echo "User #{$row['user_id']} ({$row['username']}) | Pages: {$row['page_ids']}\n";
    
    // Chạy worker đồng bộ
    $worker = __DIR__ . '/cron/publish_worker.php';
    $pages = $row['page_ids'];
    $uid = $row['user_id'];
    $output2 = [];
    $rc2 = -1;
    exec("\"$php_bin\" \"$worker\" \"$pages\" \"$uid\" 2>&1", $output2, $rc2);
    echo "Return code: $rc2\n";
    echo implode("\n", $output2);
}
