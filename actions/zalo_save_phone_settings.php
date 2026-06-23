<?php
// actions/zalo_save_phone_settings.php
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

$account_id = $_SESSION['account_id'];

$phone_request_enabled = isset($_POST['phone_request_enabled']) ? intval($_POST['phone_request_enabled']) : 0;
$phone_request_hours = isset($_POST['phone_request_hours']) ? intval($_POST['phone_request_hours']) : 1;
$phone_request_text = isset($_POST['phone_request_text']) ? trim($_POST['phone_request_text']) : '';
$province_request_text = isset($_POST['province_request_text']) ? trim($_POST['province_request_text']) : '';
$product_request_text = isset($_POST['product_request_text']) ? trim($_POST['product_request_text']) : '';

$followup_request_enabled = isset($_POST['followup_request_enabled']) ? intval($_POST['followup_request_enabled']) : 0;
$followup_request_hours = isset($_POST['followup_request_hours']) ? intval($_POST['followup_request_hours']) : 12;
$followup_request_text = isset($_POST['followup_request_text']) ? trim($_POST['followup_request_text']) : '';

if ($phone_request_hours < 1) $phone_request_hours = 1;
if ($phone_request_hours > 24) $phone_request_hours = 24;

if ($followup_request_hours < 1) $followup_request_hours = 1;
if ($followup_request_hours > 720) $followup_request_hours = 720;

try {
    // Check if zalo_settings exists for this account
    $stmt_check = $pdo->prepare("SELECT account_id FROM zalo_settings WHERE account_id = ?");
    $stmt_check->execute([$account_id]);
    $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE zalo_settings SET phone_request_enabled = ?, phone_request_hours = ?, phone_request_text = ?, province_request_text = ?, product_request_text = ?, followup_request_enabled = ?, followup_request_hours = ?, followup_request_text = ? WHERE account_id = ?");
        $stmt->execute([$phone_request_enabled, $phone_request_hours, $phone_request_text, $province_request_text, $product_request_text, $followup_request_enabled, $followup_request_hours, $followup_request_text, $account_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO zalo_settings (account_id, phone_request_enabled, phone_request_hours, phone_request_text, province_request_text, product_request_text, followup_request_enabled, followup_request_hours, followup_request_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$account_id, $phone_request_enabled, $phone_request_hours, $phone_request_text, $province_request_text, $product_request_text, $followup_request_enabled, $followup_request_hours, $followup_request_text]);
    }

    echo json_encode(['status' => 'success', 'msg' => 'Đã lưu cấu hình tự động xin thông tin Zalo thành công.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi DB: ' . $e->getMessage()]);
}
?>
