<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn.']);
    exit;
}

$page_id = trim($_GET['page_id'] ?? '');

if (!$page_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu page_id']);
    exit;
}

try {
    $account_id = $_SESSION['account_id'];
    
    // Lấy thông tin cấu hình CAPI cho Page và đảm bảo user có quyền truy cập
    $stmt = $pdo->prepare("
        SELECT p.page_id, p.name, p.capi_pixel_id, p.capi_token, p.auto_send_capi
        FROM pages p
        JOIN users u ON p.user_id = u.id
        WHERE p.page_id = ? AND (u.account_id = ? OR p.page_id IN (SELECT page_id FROM page_shares WHERE shared_with_account_id = ?))
    ");
    $stmt->execute([$page_id, $account_id, $account_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$page) {
        echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền quản lý Fanpage này.']);
        exit;
    }
    
    // Giải mã CAPI token nếu có dữ liệu
    if (!empty($page['capi_token'])) {
        $page['capi_token'] = decryptData($page['capi_token']);
    } else {
        $page['capi_token'] = '';
    }

    echo json_encode(['status' => 'success', 'data' => $page]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi máy chủ: ' . $e->getMessage()]);
}
