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

try {
    // Lấy cấu hình cũ để tránh ghi đè các tham số mới nếu gọi từ form cũ
    $stmt_acc = $pdo->prepare("SELECT * FROM system_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $old_acc = $stmt_acc->fetch(PDO::FETCH_ASSOC);

    $auto_reply_enabled = isset($_POST['auto_reply_enabled']) ? intval($_POST['auto_reply_enabled']) : (int)($old_acc['auto_reply_enabled'] ?? 0);
    $auto_reply_text = isset($_POST['auto_reply_text']) ? trim($_POST['auto_reply_text']) : ($old_acc['auto_reply_text'] ?? '');
    $auto_inbox_enabled = isset($_POST['auto_inbox_enabled']) ? intval($_POST['auto_inbox_enabled']) : (int)($old_acc['auto_inbox_enabled'] ?? 0);
    $auto_inbox_text = isset($_POST['auto_inbox_text']) ? trim($_POST['auto_inbox_text']) : ($old_acc['auto_inbox_text'] ?? '');
    $auto_pages_scope = isset($_POST['auto_pages_scope']) ? $_POST['auto_pages_scope'] : ($old_acc['auto_pages_scope'] ?? 'ALL');

    $phone_request_enabled = isset($_POST['phone_request_enabled']) ? intval($_POST['phone_request_enabled']) : (int)($old_acc['phone_request_enabled'] ?? 0);
    $phone_request_hours = isset($_POST['phone_request_hours']) ? intval($_POST['phone_request_hours']) : (int)($old_acc['phone_request_hours'] ?? 1);
    $phone_request_text = isset($_POST['phone_request_text']) ? trim($_POST['phone_request_text']) : ($old_acc['phone_request_text'] ?? '');
    $province_request_text = isset($_POST['province_request_text']) ? trim($_POST['province_request_text']) : ($old_acc['province_request_text'] ?? '');
    $product_request_text = isset($_POST['product_request_text']) ? trim($_POST['product_request_text']) : ($old_acc['product_request_text'] ?? '');

    if ($phone_request_hours < 1) $phone_request_hours = 1;
    if ($phone_request_hours > 24) $phone_request_hours = 24;

    $stmt = $pdo->prepare("UPDATE system_accounts SET auto_reply_enabled = ?, auto_reply_text = ?, auto_inbox_enabled = ?, auto_inbox_text = ?, auto_pages_scope = ?, phone_request_enabled = ?, phone_request_hours = ?, phone_request_text = ?, province_request_text = ?, product_request_text = ? WHERE id = ?");
    $stmt->execute([
        $auto_reply_enabled, $auto_reply_text, 
        $auto_inbox_enabled, $auto_inbox_text, 
        $auto_pages_scope, 
        $phone_request_enabled, $phone_request_hours, 
        $phone_request_text, $province_request_text, $product_request_text,
        $account_id
    ]);
    
    echo json_encode(['status' => 'success', 'msg' => 'Đã lưu cấu hình tự động.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
