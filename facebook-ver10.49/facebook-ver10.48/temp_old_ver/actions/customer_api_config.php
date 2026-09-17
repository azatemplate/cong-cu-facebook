<?php
// actions/customer_api_config.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../setup_customer_api_table.php';
require_once __DIR__ . '/../includes/customer_api_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập']);
    exit;
}

$account_id = intval($_SESSION['account_id']);
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    // 1. ĐỌC CẤU HÌNH API HIỆN TẠI
    if ($action === 'get_config') {
        $stmt = $pdo->prepare("SELECT * FROM customer_api_configs WHERE account_id = ?");
        $stmt->execute([$account_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            $config = [
                'is_enabled' => 0,
                'api_url' => '',
                'api_token' => '',
                'trigger_condition' => 'has_phone',
                'send_scope' => 'all'
            ];
        }

        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }

    // 2. LƯU CẤU HÌNH API
    if ($action === 'save_config') {
        $is_enabled = isset($_POST['is_enabled']) && ($_POST['is_enabled'] == '1' || $_POST['is_enabled'] == 'true') ? 1 : 0;
        $api_url = trim($_POST['api_url'] ?? '');
        $api_token = trim($_POST['api_token'] ?? '');
        $trigger_condition = trim($_POST['trigger_condition'] ?? 'has_phone');
        $send_scope = trim($_POST['send_scope'] ?? 'all');

        if (!in_array($trigger_condition, ['consulted', 'has_phone'])) {
            $trigger_condition = 'has_phone';
        }
        if (!in_array($send_scope, ['all', 'new_only'])) {
            $send_scope = 'all';
        }

        $sql = "
            INSERT INTO customer_api_configs (account_id, is_enabled, api_url, api_token, trigger_condition, send_scope)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                api_url = VALUES(api_url),
                api_token = VALUES(api_token),
                trigger_condition = VALUES(trigger_condition),
                send_scope = VALUES(send_scope)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$account_id, $is_enabled, $api_url, $api_token, $trigger_condition, $send_scope]);

        echo json_encode(['status' => 'success', 'msg' => 'Đã lưu cấu hình API tự động đẩy Lead thành công!']);
        exit;
    }

    // 3. THỬ GỬI DỮ LIỆU MẪU QUA API (TEST PUSH)
    if ($action === 'test_push') {
        $api_url = trim($_POST['api_url'] ?? '');
        $api_token = trim($_POST['api_token'] ?? '');

        if (empty($api_url)) {
            echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập URL API']);
            exit;
        }

        $sample_data = [
            'token' => !empty($api_token) ? $api_token : '',
            'access_token' => !empty($api_token) ? $api_token : '',
            'name' => 'Nguyễn Văn Test',
            'phone' => '0912345678',
            'province' => 'Hồ Chí Minh',
            'platform' => 'Website',
            'consulted' => 1,
            'consulted_label' => 'Đã tư vấn',
            'notes' => 'Yêu cầu tư vấn sản phẩm cốp pha cùm giáo [Mẫu Test API]',
            'sales_phone' => '0909000111',
            'sales_notes' => 'Ghi chú thử nghiệm đẩy API',
            'pushed_at' => date('Y-m-d H:i:s')
        ];

        $payload = json_encode($sample_data, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($api_url);
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($payload)
        ];

        if (!empty($api_token)) {
            $headers[] = "Authorization: Bearer {$api_token}";
            $headers[] = "X-Api-Key: {$api_token}";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            echo json_encode([
                'status' => 'success',
                'msg' => "Kết nối API thành công! (Mã HTTP: {$httpCode})",
                'response' => $response
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'msg' => "API phản hồi lỗi HTTP {$httpCode}: " . ($error ?: $response),
                'response' => $response
            ]);
        }
        exit;
    }

    // 4. THỦ CÔNG ĐẨY 1 KHÁCH HÀNG QUA API
    if ($action === 'push_single_customer') {
        $platform = trim($_POST['platform'] ?? '');
        $sender_id = trim($_POST['sender_id'] ?? '');
        $oa_id = trim($_POST['oa_id'] ?? '');

        $cust_data = null;
        if ($platform === 'Website') {
            $st = $pdo->prepare("SELECT name, phone, province, notes, consulted, 'Website' AS platform FROM web_visitors WHERE visitor_uuid = ? AND account_id = ?");
            $st->execute([$sender_id, $account_id]);
            $cust_data = $st->fetch(PDO::FETCH_ASSOC);
        } elseif ($platform === 'Zalo') {
            $st = $pdo->prepare("SELECT name, phone, province, notes, sales_phone, sales_notes, consulted, 'Zalo' AS platform FROM zalo_customers WHERE sender_id = ? AND oa_id = ?");
            $st->execute([$sender_id, $oa_id]);
            $cust_data = $st->fetch(PDO::FETCH_ASSOC);
        } elseif ($platform === 'Facebook') {
            $st = $pdo->prepare("SELECT name, phone, province, notes, sales_phone, sales_notes, consulted, 'Facebook' AS platform FROM fb_customers WHERE sender_id = ? AND page_id = ?");
            $st->execute([$sender_id, $oa_id]);
            $cust_data = $st->fetch(PDO::FETCH_ASSOC);
        }

        if (!$cust_data) {
            echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy thông tin khách hàng']);
            exit;
        }

        $res = push_customer_lead_to_api($pdo, $account_id, $cust_data, true);
        if ($res['success']) {
            echo json_encode(['status' => 'success', 'msg' => $res['msg']]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => $res['msg']]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Thao tác không hợp lệ']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
