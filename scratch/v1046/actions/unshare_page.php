<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Bạn chưa đăng nhập.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed.']);
    exit;
}

$owner_account_id = $_SESSION['account_id'];
$is_admin = ($_SESSION['role'] === 'admin');

$page_id = isset($_POST['page_id']) ? trim($_POST['page_id']) : '';
$target_account_id = isset($_POST['target_account_id']) ? intval($_POST['target_account_id']) : 0;

if (!$page_id || !$target_account_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Dữ liệu không hợp lệ.']);
    exit;
}

// Security Check: Verify if the user has the right to unshare this page
$can_unshare = false;
// Only the owner of the page (or someone whose system account owns the user token) can unshare
$stmt_verify = $pdo->prepare("SELECT p.id FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ? AND u.account_id = ?");
$stmt_verify->execute([$page_id, $owner_account_id]);
if ($stmt_verify->fetch()) {
    $can_unshare = true;
}

if (!$can_unshare) {
    echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền thu hồi chia sẻ trên Fanpage này.']);
    exit;
}

// Proceed to delete the share record
$stmt_delete = $pdo->prepare("DELETE FROM page_shares WHERE page_id = ? AND shared_with_account_id = ?");
$stmt_delete->execute([$page_id, $target_account_id]);

if ($stmt_delete->rowCount() > 0) {
    echo json_encode(['status' => 'success', 'msg' => 'Đã thu hồi quyền thành công.']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy dữ liệu chia sẻ hoặc đã được thu hồi trước đó.']);
}
?>
