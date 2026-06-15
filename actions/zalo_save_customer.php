<?php
// actions/zalo_save_customer.php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

$oa_id = trim($_POST['oa_id'] ?? '');
$sender_id = trim($_POST['sender_id'] ?? '');
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$province = trim($_POST['province'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$oa_id || !$sender_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu oa_id hoặc sender_id']);
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

    // Update or insert customer
    $stmt = $pdo->prepare("
        INSERT INTO zalo_customers (oa_id, sender_id, name, phone, province, notes)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name), 
            phone = VALUES(phone), 
            province = VALUES(province), 
            notes = VALUES(notes)
    ");
    $stmt->execute([$oa_id, $sender_id, $name, $phone, $province, $notes]);

    echo json_encode(['status' => 'success', 'msg' => 'Cập nhật thông tin khách hàng thành công.']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
