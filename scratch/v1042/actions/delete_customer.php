<?php
// actions/delete_customer.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập']);
    exit;
}

$account_id = intval($_SESSION['account_id']);
$platform = trim($_POST['platform'] ?? '');
$sender_id = trim($_POST['sender_id'] ?? '');
$oa_id = trim($_POST['oa_id'] ?? '');
$action = trim($_POST['action'] ?? '');

try {
    // 1. Xóa tất cả khách vãng lai test trên Website
    if ($action === 'delete_all_website_test') {
        $stmt1 = $pdo->prepare("DELETE FROM web_messages WHERE account_id = ?");
        $stmt1->execute([$account_id]);

        $stmt2 = $pdo->prepare("DELETE FROM web_visitors WHERE account_id = ?");
        $stmt2->execute([$account_id]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã xóa sạch toàn bộ danh sách khách test Website!']);
        exit;
    }

    if (empty($sender_id) || empty($platform)) {
        echo json_encode(['status' => 'error', 'msg' => 'Thiếu thông tin khách hàng cần xóa']);
        exit;
    }

    if ($platform === 'Website') {
        $st1 = $pdo->prepare("DELETE FROM web_messages WHERE visitor_uuid = ? AND account_id = ?");
        $st1->execute([$sender_id, $account_id]);

        $st2 = $pdo->prepare("DELETE FROM web_visitors WHERE visitor_uuid = ? AND account_id = ?");
        $st2->execute([$sender_id, $account_id]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã xóa khách hàng Website thành công!']);
        exit;
    }

    if ($platform === 'Zalo') {
        $st1 = $pdo->prepare("DELETE FROM zalo_messages WHERE sender_id = ? AND oa_id = ?");
        $st1->execute([$sender_id, $oa_id]);

        $st2 = $pdo->prepare("DELETE FROM zalo_customers WHERE sender_id = ? AND oa_id = ?");
        $st2->execute([$sender_id, $oa_id]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã xóa khách hàng Zalo thành công!']);
        exit;
    }

    if ($platform === 'Facebook') {
        $st1 = $pdo->prepare("DELETE FROM fb_messages WHERE sender_id = ? AND page_id = ?");
        $st1->execute([$sender_id, $oa_id]);

        $st2 = $pdo->prepare("DELETE FROM fb_customers WHERE sender_id = ? AND page_id = ?");
        $st2->execute([$sender_id, $oa_id]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã xóa khách hàng Facebook thành công!']);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Nền tảng không hợp lệ']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xóa dữ liệu: ' . $e->getMessage()]);
    exit;
}
