<?php
// check_customer_comments.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

echo "=== DIAGNOSING RECENT COMMENTS IN DATABASE ===\n\n";

try {
    // 1. Fetch total comments in page_notifications
    $total_comments = $pdo->query("SELECT COUNT(*) FROM page_notifications WHERE type = 'comment'")->fetchColumn();
    echo "Total comment notifications in database: $total_comments\n\n";
    
    // 2. Fetch comments received today (2026-07-07)
    $stmt_today = $pdo->prepare("SELECT * FROM page_notifications WHERE type = 'comment' AND created_at >= '2026-07-07 00:00:00' ORDER BY id DESC LIMIT 20");
    $stmt_today->execute();
    $today_comments = $stmt_today->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Comments received today (2026-07-07): " . count($today_comments) . "\n";
    print_r($today_comments);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
