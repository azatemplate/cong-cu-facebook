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
$consulted = isset($_POST['consulted']) ? intval($_POST['consulted']) : 0;
$sales_phone = trim($_POST['sales_phone'] ?? '');
$sales_notes = trim($_POST['sales_notes'] ?? '');

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
        INSERT INTO zalo_customers (oa_id, sender_id, name, phone, province, notes, consulted, sales_phone, sales_notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name), 
            phone = VALUES(phone), 
            province = VALUES(province), 
            notes = VALUES(notes),
            consulted = VALUES(consulted),
            sales_phone = VALUES(sales_phone),
            sales_notes = VALUES(sales_notes)
    ");
    $stmt->execute([$oa_id, $sender_id, $name, $phone, $province, $notes, $consulted, $sales_phone, $sales_notes]);

    // Tự động đẩy dữ liệu sang API nếu cấu hình kích hoạt
    try {
        require_once __DIR__ . '/../includes/customer_api_helper.php';
        push_customer_lead_to_api($pdo, $_SESSION['account_id'], [
            'name' => $name,
            'phone' => $phone,
            'province' => $province,
            'notes' => $notes,
            'consulted' => $consulted,
            'sales_phone' => $sales_phone,
            'sales_notes' => $sales_notes,
            'platform' => 'Zalo'
        ]);
    } catch (Exception $e) {}

    echo json_encode(['status' => 'success', 'msg' => 'Cập nhật thông tin khách hàng thành công.']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
