<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

function send_json($data) {
    @ob_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['account_id'])) {
    send_json(['status' => 'error', 'msg' => 'Unauthorized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['status' => 'error', 'msg' => 'Chỉ chấp nhận phương thức POST']);
}

$account_id = (int)$_SESSION['account_id'];

try {
    $stmt = $pdo->prepare("DELETE FROM fetched_fanpage_posts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $count = $stmt->rowCount();

    send_json([
        'status' => 'success',
        'msg'    => "Đã xóa sạch {$count} bài viết trong danh sách tạm."
    ]);
} catch (Exception $e) {
    send_json(['status' => 'error', 'msg' => 'Lỗi xóa dữ liệu: ' . $e->getMessage()]);
}
?>
