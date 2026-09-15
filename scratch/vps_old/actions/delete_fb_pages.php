<?php
session_start();
if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['page_ids'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Bad request']);
    exit;
}

$page_ids = json_decode($_POST['page_ids'], true);
if (empty($page_ids) || !is_array($page_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid data']);
    exit;
}

$account_id = $_SESSION['account_id'];

try {
    $pdo->beginTransaction();
    
    foreach ($page_ids as $pid) {
        $pid = trim($pid);
        if (empty($pid)) continue;
        
        // Kiểm tra quyền: Người dùng hiện tại phải có token quản lý page này (thông qua bảng users)
        // Lưu ý: User có thể share page này cho người khác, nhưng chỉ người chủ (thuộc users có account_id) mới được xóa hoàn toàn.
        $stmt_check = $pdo->prepare("
            SELECT p.page_id 
            FROM pages p
            JOIN users u ON p.user_id = u.id
            WHERE p.page_id = ? AND u.account_id = ?
        ");
        $stmt_check->execute([$pid, $account_id]);
        
        if ($stmt_check->fetch()) {
            // Xóa theo thứ tự để tránh lỗi cascade (nếu không có DB cascade setup)
            // 1. Xóa bài viết đang hẹn giờ / đang chờ
            $pdo->prepare("DELETE FROM scheduled_posts WHERE page_id = ?")->execute([$pid]);
            // 2. Xóa lịch sử bài đã đăng
            $pdo->prepare("DELETE FROM posts_history WHERE page_id = ?")->execute([$pid]);
            // 3. Xóa phân quyền share
            $pdo->prepare("DELETE FROM page_shares WHERE page_id = ?")->execute([$pid]);
            // 4. Cuối cùng, xóa page
            $pdo->prepare("DELETE FROM pages WHERE page_id = ?")->execute([$pid]);
        }
    }
    
    $pdo->commit();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xử lý Database: ' . $e->getMessage()]);
}
