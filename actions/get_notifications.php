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
        // Fix collation mismatch if table already existed with wrong collation
        $pdo->exec("ALTER TABLE page_notifications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;");
    } catch (Exception $e) {}

    // Direct query: notifications for pages belonging to this account
    $stmt2 = $pdo->prepare("
        SELECT n.*, p.name as page_name 
        FROM page_notifications n
        JOIN pages p ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
        JOIN users u ON p.user_id = u.id
        WHERE u.account_id = ? AND n.is_read = 0
        ORDER BY n.created_at DESC
        LIMIT 20
    ");
    $stmt2->execute([$account_id]);
    $live_notifs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Log error for debugging
    @file_put_contents(__DIR__ . '/../notif_error.txt', date('Y-m-d H:i:s').' '.$e->getMessage()."\n", FILE_APPEND);
}

echo json_encode([
    'status' => 'success',
    'failed_posts' => $failed_posts,
    'live_notifs' => $live_notifs
]);
