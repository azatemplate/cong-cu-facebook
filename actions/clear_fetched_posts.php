<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Chỉ chấp nhận phương thức POST']);
    exit;
}

$account_id = (int)$_SESSION['account_id'];

try {
    $stmt = $pdo->prepare("DELETE FROM fetched_fanpage_posts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $count = $stmt->rowCount();

    echo json_encode([
        'status' => 'success',
        'msg'    => "Đã xóa sạch {$count} bài viết trong danh sách tạm."
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xóa dữ liệu: ' . $e->getMessage()]);
}
?>
