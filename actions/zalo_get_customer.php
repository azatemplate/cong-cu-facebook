<?php
// actions/zalo_get_customer.php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}
session_write_close();

$oa_id = isset($_GET['oa_id']) ? trim($_GET['oa_id']) : '';
$sender_id = isset($_GET['sender_id']) ? trim($_GET['sender_id']) : '';

if (empty($oa_id) || empty($sender_id)) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số oa_id hoặc sender_id']);
    exit;
}

try {
    // Verify that this OA belongs to the current user
    $stmt_oa = $pdo->prepare("SELECT oa_id FROM zalo_oas WHERE oa_id = ? AND account_id = ?");
    $stmt_oa->execute([$oa_id, $_SESSION['account_id']]);
    if (!$stmt_oa->fetch()) {
        echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền quản lý khách hàng của kênh này.']);
        exit;
    }

    // Fetch customer details
    $stmt = $pdo->prepare("SELECT name, phone, province, notes, consulted, sales_phone, sales_notes FROM zalo_customers WHERE oa_id = ? AND sender_id = ?");
    $stmt->execute([$oa_id, $sender_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt_lock = $pdo->prepare("SELECT expire_at FROM zalo_chat_locks WHERE oa_id = ? AND sender_id = ? AND expire_at > NOW()");
    $stmt_lock->execute([$oa_id, $sender_id]);
    $is_locked = $stmt_lock->fetch() ? 1 : 0;

    if (!$customer) {
        $customer = [
            'name' => '',
            'phone' => '',
            'province' => '',
            'notes' => '',
            'consulted' => 0,
            'sales_phone' => '',
            'sales_notes' => ''
        ];
    }
    $customer['is_locked'] = $is_locked;

    echo json_encode([
        'status' => 'success',
        'data' => $customer
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
