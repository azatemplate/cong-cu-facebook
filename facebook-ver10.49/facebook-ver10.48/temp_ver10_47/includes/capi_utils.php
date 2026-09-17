<?php
// includes/capi_utils.php

/**
 * Loại bỏ dấu tiếng Việt để tăng khả năng so khớp (match) của Facebook CAPI
 */
function capi_remove_vietnamese_accents($str) {
    $accents = array(
        'a'=>'á|à|ả|ã|ạ|ă|ắ|ằ|ẳ|ẵ|ặ|â|ấ|ầ|ẩ|ẫ|ậ|å|ä|æ|ā|ą|å|ǎ',
        'A'=>'Á|À|Ả|Ã|Ạ|Ă|Ắ|Ằ|Ẳ|Ẵ|Ặ|Â|Ấ|Ầ|Ẩ|Ẫ|Ậ|Å|Ä|Æ|Ā|Ą|Å|Ǎ',
        'd'=>'đ',
        'D'=>'Đ',
        'e'=>'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ|ē|ē|ĕ|ė|ę|ě',
        'E'=>'É|È|Ẻ|Ẽ|Ẹ|Ê|Ế|Ề|Ể|Ễ|Ệ|Ē|Ē|Ĕ|Ė|Ę|Ě',
        'i'=>'í|ì|ỉ|ĩ|ị|ī|ĭ|į|ǐ',
        'I'=>'Í|Ì|Ỉ|Ĩ|Ị|Ī|Ĭ|Į|Ǐ',
        'o'=>'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ|ø|ō|ő|ǒ',
        'O'=>'Ó|Ò|Ỏ|Õ|Ọ|Ô|Ố|Ồ|Ổ|Ỗ|Ộ|Ơ|Ớ|Ờ|Ở|Ỡ|Ợ|Ø|Ō|Ő|Ǒ',
        'u'=>'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự|ū|ŭ|ů|ű|ų|ǔ',
        'U'=>'Ú|Ù|Ủ|Ũ|Ụ|Ư|Ứ|Ừ|Ử|Ữ|Ự|Ū|Ŭ|Ů|Ű|Ų|Ǔ',
        'y'=>'ý|ỳ|ỷ|ỹ|ỵ',
        'Y'=>'Ý|Ỳ|Ỷ|Ỹ|Ỵ'
    );
    foreach($accents as $non_accent => $accent_regex) {
        $str = preg_replace("/($accent_regex)/i", $non_accent, $str);
    }
    return $str;
}

/**
 * Chuẩn hóa số điện thoại về định dạng chỉ gồm chữ số và bắt đầu bằng mã quốc gia (mặc định 84 cho Việt Nam)
 */
function capi_normalize_phone($phone) {
    // Loại bỏ tất cả ký tự không phải số
    $clean = preg_replace('/\D/', '', $phone);
    if (empty($clean)) {
        return '';
    }
    
    // Nếu bắt đầu bằng 0, thay bằng 84
    if (strpos($clean, '0') === 0) {
        $clean = '84' . substr($clean, 1);
    }
    
    return $clean;
}

/**
 * Gửi sự kiện Lead lên Facebook Conversions API
 */
function send_facebook_capi_lead($pixel_id, $capi_token, $page_id, $psid, $customer_data = [], $test_event_code = null) {
    if (empty($pixel_id) || empty($capi_token) || empty($page_id) || empty($psid)) {
        return ['success' => false, 'error' => 'Thiếu thông số cấu hình CAPI (Pixel ID, Token, Page ID hoặc PSID).'];
    }

    $user_data = [
        'page_id' => $page_id,
        'page_messaging_id' => $psid
    ];

    // 1. Chuẩn hóa & mã hóa SĐT nếu có
    $phone = trim($customer_data['phone'] ?? '');
    if (!empty($phone)) {
        $normalized_phone = capi_normalize_phone($phone);
        if (!empty($normalized_phone)) {
            $user_data['ph'] = hash('sha256', $normalized_phone);
        }
    }

    // 2. Chuẩn hóa & mã hóa họ tên
    $name = trim($customer_data['name'] ?? '');
    if (!empty($name)) {
        $clean_name = trim(mb_strtolower(capi_remove_vietnamese_accents($name)));
        $parts = explode(' ', $clean_name);
        
        // Facebook CAPI yêu cầu mã hóa fn (First Name - Tên) và ln (Last Name - Họ)
        if (count($parts) > 0) {
            $first_name = end($parts); // Tên cuối cùng
            $user_data['fn'] = hash('sha256', $first_name);
            
            if (count($parts) > 1) {
                $last_name = $parts[0]; // Họ đầu tiên
                $user_data['ln'] = hash('sha256', $last_name);
            }
        }
    }

    // 3. Chuẩn bị payload
    $event = [
        'event_name' => 'Lead',
        'event_time' => time(),
        'action_source' => 'system_generated',
        'user_data' => $user_data,
        'custom_data' => [
            'lead_event_source' => 'messenger'
        ]
    ];

    $payload = [
        'data' => [$event]
    ];
    
    if (!empty($test_event_code)) {
        $payload['test_event_code'] = $test_event_code;
    }

    $url = "https://graph.facebook.com/v20.0/" . urlencode($pixel_id) . "/events";

    // 4. Gọi API
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $capi_token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    // Tắt kiểm tra SSL ở môi trường dev nếu cần thiết
    if (defined('APP_ENV') && APP_ENV === 'development') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    // Ghi log phục vụ debug
    $log_dir = __DIR__ . '/../locks';
    if (!file_exists($log_dir)) {
        @mkdir($log_dir, 0777, true);
    }
    $log_file = $log_dir . '/capi_debug.log';
    $log_msg = date('Y-m-d H:i:s') . " - Page: $page_id, PSID: $psid - HTTP: $http_code | Resp: $response | Err: $curl_err\n";
    @file_put_contents($log_file, $log_msg, FILE_APPEND);

    if ($http_code === 200) {
        $res_data = json_decode($response, true);
        if (isset($res_data['events_received']) && $res_data['events_received'] > 0) {
            return ['success' => true, 'response' => $res_data];
        }
    }

    $err_desc = $curl_err ?: $response;
    return ['success' => false, 'error' => "HTTP $http_code - $err_desc"];
}
