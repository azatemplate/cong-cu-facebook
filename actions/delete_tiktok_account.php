<?php
// actions/delete_tiktok_account.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$id = intval($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['status' => 'error', 'msg' => 'ID tài khoản TikTok không hợp lệ.']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM tiktok_accounts WHERE id = ? AND account_id = ?");
    $stmt->execute([$id, $account_id]);
    
    echo json_encode(['status' => 'success', 'msg' => 'Đã xóa và hủy kết nối kênh TikTok thành công!']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xóa kênh: ' . $e->getMessage()]);
}
?>
