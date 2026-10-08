<?php
// actions/delete_tiktok_account.php
ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$is_admin   = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true) ?? [];
$id = intval($_POST['id'] ?? $_GET['id'] ?? ($json_input['id'] ?? 0));

if (!$id) {
    ob_clean();
    echo json_encode(['status' => 'error', 'msg' => 'ID tài khoản TikTok không hợp lệ.']);
    exit;
}

try {
    // Delete scheduled posts linked to this TikTok channel
    $stmt_sp = $pdo->prepare("DELETE FROM scheduled_posts WHERE page_id = ? AND post_type = 'TikTok'");
    $stmt_sp->execute([$id]);

    if ($is_admin) {
        $stmt = $pdo->prepare("DELETE FROM tiktok_accounts WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM tiktok_accounts WHERE id = ? AND account_id = ?");
        $stmt->execute([$id, $account_id]);
    }
    
    ob_clean();
    echo json_encode(['status' => 'success', 'msg' => 'Đã xóa và hủy kết nối kênh TikTok thành công!']);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xóa kênh: ' . $e->getMessage()]);
}
?>
