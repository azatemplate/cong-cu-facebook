<?php
// includes/customer_api_helper.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../setup_customer_api_table.php';

function push_customer_lead_to_api($pdo, $account_id, $customer_data, $is_manual = false) {
    if (empty($account_id) || empty($customer_data)) {
        return ['success' => false, 'msg' => 'Thiếu dữ liệu tài khoản hoặc khách hàng'];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM customer_api_configs WHERE account_id = ?");
        $stmt->execute([$account_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || empty($config['api_url'])) {
            return ['success' => false, 'msg' => 'Chưa cấu hình URL API Endpoint. Vui lòng vào "🔌 Cấu hình API" để điền URL.'];
        }

        if (!$is_manual && intval($config['is_enabled']) !== 1) {
            return ['success' => false, 'msg' => 'Tính năng tự động đẩy API hiện đang tắt.'];
        }

        $condition = $config['trigger_condition'] ?? 'has_phone';
        $send_scope = $config['send_scope'] ?? 'all';
        $phone = trim($customer_data['phone'] ?? '');
        $consulted = intval($customer_data['consulted'] ?? 0);

        if (!$is_manual) {
            // Check send scope
            if ($send_scope === 'new_only') {
                if (isset($customer_data['is_new']) && $customer_data['is_new'] === false) {
                    return ['success' => false, 'msg' => 'Cấu hình chỉ đẩy khách hàng mới.'];
                }
            }

            // Check trigger condition
            if ($condition === 'consulted') {
                if ($consulted !== 1) {
                    return ['success' => false, 'msg' => 'Khách hàng chưa chuyển sang trạng thái "Đã tư vấn".'];
                }
            } elseif ($condition === 'has_phone') {
                if (empty($phone)) {
                    return ['success' => false, 'msg' => 'Khách hàng chưa có số điện thoại.'];
                }
            }
        }

        $payload_array = [
            'token' => !empty($config['api_token']) ? trim($config['api_token']) : '',
            'access_token' => !empty($config['api_token']) ? trim($config['api_token']) : '',
            'name' => $customer_data['name'] ?? 'Khách hàng',
            'phone' => $phone,
            'province' => $customer_data['province'] ?? '',
            'platform' => $customer_data['platform'] ?? 'Website',
            'consulted' => $consulted,
            'consulted_label' => ($consulted === 1 ? 'Đã tư vấn' : ($consulted === 2 ? 'Khách quay lại' : ($consulted === 3 ? 'Dừng tư vấn' : ($consulted === 4 ? 'Chờ xử lý' : 'Chưa tư vấn')))),
            'notes' => $customer_data['notes'] ?? '',
            'sales_phone' => $customer_data['sales_phone'] ?? '',
            'sales_notes' => $customer_data['sales_notes'] ?? '',
            'pushed_at' => date('Y-m-d H:i:s')
        ];

        $payload = json_encode($payload_array, JSON_UNESCAPED_UNICODE);

        $ch = curl_init(trim($config['api_url']));
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($payload)
        ];

        if (!empty($config['api_token'])) {
            $token = trim($config['api_token']);
            $headers[] = "Authorization: Bearer {$token}";
            $headers[] = "X-Api-Key: {$token}";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'msg' => "Đã gửi thành công dữ liệu qua API! (HTTP {$httpCode})", 'response' => $result];
        } else {
            $errMsg = $curlErr ?: ("Mã phản hồi HTTP " . $httpCode . ($result ? ": " . mb_substr($result, 0, 150) : ""));
            return ['success' => false, 'msg' => "Gửi API thất bại ({$errMsg})", 'response' => $result];
        }

    } catch (Exception $e) {
        return ['success' => false, 'msg' => 'Lỗi ngoại lệ: ' . $e->getMessage()];
    }
}
