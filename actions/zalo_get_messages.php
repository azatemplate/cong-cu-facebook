<?php
// actions/zalo_get_messages.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/zalo_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

$oa_id = isset($_GET['oa_id']) ? trim($_GET['oa_id']) : '';
$sender_id = isset($_GET['sender_id']) ? trim($_GET['sender_id']) : '';
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$count = isset($_GET['count']) ? intval($_GET['count']) : 10;

if (empty($oa_id) || empty($sender_id)) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số OA ID hoặc Sender ID.']);
    exit;
}

try {
    // Verify that this OA belongs to the current user
    $stmt_oa = $pdo->prepare("SELECT oa_id FROM zalo_oas WHERE oa_id = ? AND account_id = ?");
    $stmt_oa->execute([$oa_id, $_SESSION['account_id']]);
    if (!$stmt_oa->fetch()) {
        echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền truy cập kênh OA này.']);
        exit;
    }

    // Get access token
    $access_token = zalo_get_active_token($oa_id, $pdo);
    if (!$access_token) {
        echo json_encode(['status' => 'error', 'msg' => 'Không thể lấy Access Token của Zalo OA. Vui lòng kết nối lại kênh.']);
        exit;
    }

    // Call Zalo API to get conversation messages
    $data_param = json_encode([
        'user_id' => $sender_id,
        'offset' => $offset,
        'count' => $count
    ]);
    
    $url = ZALO_API_BASE . 'v2.0/oa/conversation?data=' . urlencode($data_param);
    $headers = [
        "access_token: {$access_token}"
    ];
    
    $res = zalo_api_request($url, 'GET', $headers);
    
    if ($res['status_code'] === 200 && isset($res['data']['error']) && $res['data']['error'] === 0) {
        $messages = $res['data']['data'] ?? [];
        
        echo json_encode([
            'status' => 'success',
            'data' => $messages,
            'next_offset' => $offset + count($messages)
        ]);
    } else {
        $error_msg = $res['data']['message'] ?? 'Lỗi không xác định khi gọi Zalo API.';
        echo json_encode(['status' => 'error', 'msg' => $error_msg]);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
}
?>
