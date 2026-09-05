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

$target_account_id = isset($_POST['target_account_id']) ? intval($_POST['target_account_id']) : 0;
$page_ids_json = isset($_POST['page_ids']) ? $_POST['page_ids'] : '[]';
$page_ids = json_decode($page_ids_json, true);

if (!$target_account_id) {
    echo json_encode(['status' => 'error', 'msg' => 'ID người nhận không hợp lệ.']);
    exit;
}

if (empty($page_ids) || !is_array($page_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi dữ liệu Fanpage.']);
    exit;
}

if ($target_account_id == $owner_account_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Không thể tự chia sẻ cho chính mình.']);
    exit;
}

// Check if target account exists
$stmt_check = $pdo->prepare("SELECT id FROM system_accounts WHERE id = ?");
$stmt_check->execute([$target_account_id]);
if (!$stmt_check->fetch()) {
    echo json_encode(['status' => 'error', 'msg' => 'Tài khoản nhận không tồn tại.']);
    exit;
}

$success_count = 0;
$stmt_insert = $pdo->prepare("INSERT IGNORE INTO page_shares (page_id, owner_account_id, shared_with_account_id) VALUES (?, ?, ?)");

foreach ($page_ids as $page_id) {
    // Basic verification - does this page exist and does the user own it?
    // Admin can share any page. Regular user can only share pages their FB account tokens own.
    $can_share = false;
    
    $stmt_verify = $pdo->prepare("SELECT p.id FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ? AND u.account_id = ?");
$stmt_verify->execute([$page_id, $owner_account_id]);
if ($stmt_verify->fetch()) {
    $can_share = true;
}
    
    if ($can_share) {
        $stmt_insert->execute([$page_id, $owner_account_id, $target_account_id]);
        $success_count += $stmt_insert->rowCount();
    }
}

if ($success_count > 0) {
    echo json_encode(['status' => 'success', 'msg' => "Đã phân quyền chia sẻ $success_count Fanpage thành công."]);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Không có Fanpage nào được thêm (Do lỗi quyền hoặc đã được chia sẻ trước đó).']);
}
?>
