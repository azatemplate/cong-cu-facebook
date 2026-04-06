<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];

// 1. Fetch Post Errors
$failed_posts = [];
try {
    $stmt1 = $pdo->prepare("
        SELECT sp.id, sp.error_msg, sp.scheduled_time, p.name as page_name, p.page_id
        FROM scheduled_posts sp
        JOIN pages p ON sp.page_id = p.page_id
        JOIN users u ON p.user_id = u.id
        WHERE sp.status = 'failed' AND u.account_id = ?
        ORDER BY sp.scheduled_time DESC
        LIMIT 10
    ");
    $stmt1->execute([$account_id]);
    $failed_posts = $stmt1->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 2. Fetch Unread Inbox & Comments (from Webhook DB tracking)
$live_notifs = [];
try {
    // Tự động thử tạo bảng page_notifications nếu lỗi chưa có bảng (giúp setup mượt hơn không cần manual)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS page_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_id VARCHAR(50) NOT NULL,
            type VARCHAR(20) NOT NULL,
            sender_id VARCHAR(50) NULL,
            sender_name VARCHAR(100) NULL,
            snippet TEXT NULL,
            post_id VARCHAR(50) NULL,
            comment_id VARCHAR(50) NULL,
            conversation_id VARCHAR(50) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page (page_id),
            INDEX idx_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {}

    // Get page IDs belonging to this account
    $stmt_pages = $pdo->prepare("
        SELECT DISTINCT p.page_id FROM pages p 
        JOIN users u ON p.user_id = u.id 
        WHERE u.account_id = ?
    ");
    $stmt_pages->execute([$account_id]);
    $accessible_page_ids = $stmt_pages->fetchAll(PDO::FETCH_COLUMN);

    // Also add shared pages if table exists
    try {
        $stmt_shared = $pdo->prepare("SELECT DISTINCT page_id FROM page_shares WHERE shared_with_account_id = ?");
        $stmt_shared->execute([$account_id]);
        $shared_ids = $stmt_shared->fetchAll(PDO::FETCH_COLUMN);
        $accessible_page_ids = array_unique(array_merge($accessible_page_ids, $shared_ids));
    } catch (Exception $e) {}

    if (!empty($accessible_page_ids)) {
        $placeholders = implode(',', array_fill(0, count($accessible_page_ids), '?'));
        $stmt2 = $pdo->prepare("
            SELECT n.*, p.name as page_name 
            FROM page_notifications n
            JOIN pages p ON n.page_id = p.page_id
            WHERE n.page_id IN ($placeholders) AND n.is_read = 0
            ORDER BY n.created_at DESC
            LIMIT 20
        ");
        $stmt2->execute($accessible_page_ids);
        $live_notifs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

echo json_encode([
    'status' => 'success',
    'failed_posts' => $failed_posts,
    'live_notifs' => $live_notifs
]);
