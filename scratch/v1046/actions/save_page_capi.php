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

$page_id = trim($_POST['page_id'] ?? '');
$page_ids = $_POST['page_ids'] ?? '';
$capi_pixel_id = trim($_POST['capi_pixel_id'] ?? '');
$capi_token = trim($_POST['capi_token'] ?? '');
$auto_send_capi = isset($_POST['auto_send_capi']) ? intval($_POST['auto_send_capi']) : 0;

$page_ids_arr = [];
if (!empty($page_ids)) {
    $decoded = json_decode($page_ids, true);
    if (is_array($decoded)) {
        $page_ids_arr = array_map('trim', $decoded);
    }
}
if (empty($page_ids_arr) && !empty($page_id)) {
    $page_ids_arr[] = $page_id;
}

if (empty($page_ids_arr)) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu page_id hoặc danh sách page_ids']);
    exit;
}

try {
    $account_id = $_SESSION['account_id'];
    
    // Đảm bảo user có quyền quản lý các Page này
    $stmt = $pdo->prepare("
        SELECT p.page_id 
        FROM pages p
        JOIN users u ON p.user_id = u.id
        WHERE p.page_id = ? AND (u.account_id = ? OR p.page_id IN (SELECT page_id FROM page_shares WHERE shared_with_account_id = ?))
    ");
    
    $allowed_page_ids = [];
    foreach ($page_ids_arr as $pid) {
        $stmt->execute([$pid, $account_id, $account_id]);
        if ($stmt->fetch()) {
            $allowed_page_ids[] = $pid;
        }
    }
    
    if (empty($allowed_page_ids)) {
        echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền quản lý các Fanpage đã chọn.']);
        exit;
    }
    
    // Mã hóa CAPI token bảo mật
    $encrypted_token = null;
    if (!empty($capi_token)) {
        $encrypted_token = encryptData($capi_token);
    }
    
    $upd = $pdo->prepare("UPDATE pages SET capi_pixel_id = ?, capi_token = ?, auto_send_capi = ? WHERE page_id = ?");
    foreach ($allowed_page_ids as $pid) {
        $upd->execute([
            empty($capi_pixel_id) ? null : $capi_pixel_id,
            $encrypted_token,
            $auto_send_capi,
            $pid
        ]);
    }

    echo json_encode(['status' => 'success', 'msg' => 'Lưu cấu hình CAPI thành công cho ' . count($allowed_page_ids) . ' Fanpage.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi máy chủ: ' . $e->getMessage()]);
}
