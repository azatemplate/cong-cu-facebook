<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/capi_utils.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

$page_id = trim($_POST['page_id'] ?? '');
$is_test = isset($_POST['is_test']) ? intval($_POST['is_test']) : 0;
$test_event_code = trim($_POST['test_event_code'] ?? '');

if (!$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu page_id']);
    exit;
}

try {
    $account_id = $_SESSION['account_id'];
    
    // Lấy cấu hình CAPI của Page và kiểm tra quyền truy cập
    $stmt_page = $pdo->prepare("
        SELECT p.page_id, p.capi_pixel_id, p.capi_token 
        FROM pages p
        JOIN users u ON p.user_id = u.id
        WHERE p.page_id = ? AND (u.account_id = ? OR p.page_id IN (SELECT page_id FROM page_shares WHERE shared_with_account_id = ?))
    ");
    $stmt_page->execute([$page_id, $account_id, $account_id]);
    $page = $stmt_page->fetch(PDO::FETCH_ASSOC);
    
    if (!$page || empty($page['capi_pixel_id']) || empty($page['capi_token'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cấu hình đầy đủ Pixel ID và CAPI Token trước khi đẩy.']);
        exit;
    }
    
    $pixel_id = $page['capi_pixel_id'];
    $capi_token = decryptData($page['capi_token']);
    
    // Luồng gửi sự kiện TEST
    if ($is_test === 1) {
        $res = send_facebook_capi_lead($pixel_id, $capi_token, $page_id, '100000000000001', [
            'name' => 'Khách hàng CAPI Test',
            'phone' => '0901234567'
        ], $test_event_code);
        
        if ($res['success']) {
            echo json_encode(['status' => 'success', 'msg' => 'Gửi sự kiện CAPI Test thành công! Vui lòng kiểm tra trong Trình quản lý sự kiện Facebook.']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Lỗi gửi test: ' . $res['error']]);
        }
        exit;
    }
    
    $sender_id = trim($_POST['sender_id'] ?? '');
    if (!$sender_id) {
        echo json_encode(['status' => 'error', 'msg' => 'Thiếu sender_id']);
        exit;
    }
    
    // Lấy thông tin khách hàng
    $stmt_cust = $pdo->prepare("SELECT name, phone FROM fb_customers WHERE page_id = ? AND sender_id = ?");
    $stmt_cust->execute([$page_id, $sender_id]);
    $customer = $stmt_cust->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer || empty($customer['phone'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Khách hàng chưa có Số điện thoại, chưa đủ điều kiện để đẩy CAPI.']);
        exit;
    }
    
    // Gọi Facebook CAPI
    $res = send_facebook_capi_lead($pixel_id, $capi_token, $page_id, $sender_id, [
        'name' => $customer['name'],
        'phone' => $customer['phone']
    ]);
    
    if ($res['success']) {
        // Cập nhật trạng thái đã đẩy CAPI
        $upd = $pdo->prepare("UPDATE fb_customers SET capi_pushed = 1 WHERE page_id = ? AND sender_id = ?");
        $upd->execute([$page_id, $sender_id]);
        
        echo json_encode(['status' => 'success', 'msg' => 'Đẩy sự kiện CAPI Lead thành công!']);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi kết nối CAPI: ' . $res['error']]);
    }
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi máy chủ: ' . $e->getMessage()]);
}
