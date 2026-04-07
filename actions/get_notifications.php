<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['account_id'];
$offset      = max(0, intval($_GET['offset'] ?? 0));
$limit       = 20; // số thông báo mỗi lần tải
session_write_close(); // Giải phóng session lock sớm để các request khác không bị block

// 1. Fetch Post Errors — chỉ lần đầu (offset = 0)
$failed_posts = [];
if ($offset === 0) {
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
}

// 2. Fetch Live Notifications với phân trang
$live_notifs = [];
$has_more    = false;
try {
    // Tự động tạo bảng nếu chưa có
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;");
        // Lưu ý: ALTER TABLE đã bị xóa khỏi đây để tránh metadata lock
        // làm block các query khác trên bảng page_notifications
    } catch (Exception $e) {}

    // Fetch limit+1 để biết còn thêm hay không
    $fetch_limit = $limit + 1;
    $stmt2 = $pdo->prepare("
        SELECT n.*, p.name as page_name
        FROM page_notifications n
        JOIN pages p ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
        JOIN users u ON p.user_id = u.id
        WHERE (
            u.account_id = ?
            OR EXISTS (
                SELECT 1 FROM page_shares ps
                WHERE ps.page_id = p.page_id AND ps.shared_with_account_id = ?
            )
        ) AND n.is_read = 0
        ORDER BY n.created_at DESC
        LIMIT {$fetch_limit} OFFSET {$offset}
    ");
    $stmt2->execute([$account_id, $account_id]);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > $limit) {
        $has_more = true;
        array_pop($rows); // bỏ record thứ 21 — chỉ dùng để kiểm tra has_more
    }
    $live_notifs = $rows;
} catch (Exception $e) {
    @file_put_contents(__DIR__ . '/../notif_error.txt', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
}

echo json_encode([
    'status'       => 'success',
    'failed_posts' => $failed_posts,
    'live_notifs'  => $live_notifs,
    'has_more'     => $has_more,
    'offset'       => $offset,
]);
