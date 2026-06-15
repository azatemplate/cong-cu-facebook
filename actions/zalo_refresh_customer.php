<?php
// actions/zalo_refresh_customer.php
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

if (empty($oa_id) || empty($sender_id)) {
    echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số OA ID hoặc Khách hàng ID.']);
    exit;
}

try {
    // Verify that this OA belongs to the current user
    $stmt_oa = $pdo->prepare("SELECT oa_id FROM zalo_oas WHERE oa_id = ? AND account_id = ?");
    $stmt_oa->execute([$oa_id, $_SESSION['account_id']]);
    if (!$stmt_oa->fetch()) {
        echo json_encode(['status' => 'error', 'msg' => 'Bạn không có quyền quản lý kênh OA này.']);
        exit;
    }

    $access_token = zalo_get_active_token($oa_id, $pdo);
    if (!$access_token) {
        echo json_encode(['status' => 'error', 'msg' => 'Không thể lấy Access Token của Zalo OA.']);
        exit;
    }

    // Call Zalo API to get profile
    $url = ZALO_API_BASE . 'v3.0/oa/user/detail?data=' . urlencode(json_encode(['user_id' => $sender_id]));
    $headers = [
        "access_token: {$access_token}"
    ];
    $res = zalo_api_request($url, 'GET', $headers);

    if ($res['status_code'] === 200 && isset($res['data']['error'])) {
        if ($res['data']['error'] === 0 && isset($res['data']['data'])) {
            $profile = $res['data']['data'];
            $name = $profile['display_name'] ?? ($profile['displayName'] ?? ($profile['sharedInfo']['name'] ?? 'Khách hàng Zalo'));
            $avatar = $profile['avatar'] ?? '';
            $province = '';
            if (!empty($profile['sharedInfo']['city'])) {
                // simple province extraction function
                require_once __DIR__ . '/../zalo_webhook.php';
                if (function_exists('detect_vietnam_province')) {
                    $province = detect_vietnam_province($profile['sharedInfo']['city']);
                }
            }

            // Update zalo_customers
            $stmt_upd = $pdo->prepare("
                INSERT INTO zalo_customers (oa_id, sender_id, name, avatar, province)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    name = VALUES(name),
                    avatar = VALUES(avatar),
                    province = COALESCE(NULLIF(VALUES(province), ''), province)
            ");
            $stmt_upd->execute([$oa_id, $sender_id, $name, $avatar, $province]);

            // Update sender_name in zalo_messages
            $stmt_msg = $pdo->prepare("
                UPDATE zalo_messages 
                SET sender_name = ? 
                WHERE oa_id = ? AND sender_id = ?
            ");
            $stmt_msg->execute([$name, $oa_id, $sender_id]);

            echo json_encode([
                'status' => 'success',
                'msg' => 'Đồng bộ thông tin khách hàng từ Zalo thành công.',
                'data' => [
                    'name' => $name,
                    'avatar' => $avatar,
                    'province' => $province
                ]
            ]);
        } else {
            $err_code = $res['data']['error'];
            $err_msg = $res['data']['message'] ?? 'Lỗi không xác định';
            $friendly_msg = "Không thể lấy thông tin: Zalo trả về mã lỗi {$err_code} ({$err_msg}). Người dùng có thể chưa nhấn Quan tâm OA hoặc chưa cho phép chia sẻ thông tin cá nhân.";
            echo json_encode(['status' => 'error', 'msg' => $friendly_msg]);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Không phản hồi từ Zalo API hoặc lỗi kết nối.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi xử lý: ' . $e->getMessage()]);
}
?>
