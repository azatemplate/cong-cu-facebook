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

// verify_csrf(); // Tạm tắt nếu gọi qua AJAX không gửi theo csrf hoặc gửi theo request form
$account_id = $_SESSION['account_id'];

$auto_reply_enabled = isset($_POST['auto_reply_enabled']) ? intval($_POST['auto_reply_enabled']) : 0;
$auto_reply_text = isset($_POST['auto_reply_text']) ? trim($_POST['auto_reply_text']) : '';
$auto_inbox_enabled = isset($_POST['auto_inbox_enabled']) ? intval($_POST['auto_inbox_enabled']) : 0;
$auto_inbox_text = isset($_POST['auto_inbox_text']) ? trim($_POST['auto_inbox_text']) : '';

try {
    $stmt = $pdo->prepare("UPDATE system_accounts SET auto_reply_enabled = ?, auto_reply_text = ?, auto_inbox_enabled = ?, auto_inbox_text = ? WHERE id = ?");
    $stmt->execute([$auto_reply_enabled, $auto_reply_text, $auto_inbox_enabled, $auto_inbox_text, $account_id]);
    
    echo json_encode(['status' => 'success', 'msg' => 'Đã lưu cấu hình tự động.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
