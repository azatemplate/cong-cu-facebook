<?php
// includes/zalo_api.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

define('ZALO_API_BASE', 'https://openapi.zalo.me/');

/**
 * Gửi HTTP request đến Zalo API
 */
function zalo_api_request($url, $method = 'GET', $headers = [], $post_fields = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Bypass SSL in development
    if (defined('APP_ENV') && APP_ENV === 'development') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($post_fields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['status_code' => 0, 'data' => ['error' => ['message' => $curl_err]]];
    }

    return [
        'status_code' => $http_code,
        'data'        => json_decode($response, true)
    ];
}

/**
 * Đổi code lấy Access Token và Refresh Token sử dụng OAuth v4 (yêu cầu PKCE)
 */
function zalo_get_tokens_by_code($code, $app_id, $app_secret, $code_verifier) {
    $url = 'https://oauth.zaloapp.com/v4/oa/access_token';
    $headers = [
        "secret_key: {$app_secret}",
        "Content-Type: application/x-www-form-urlencoded"
    ];
    $fields = http_build_query([
        'code' => $code,
        'app_id' => $app_id,
        'grant_type' => 'authorization_code',
        'code_verifier' => $code_verifier
    ]);

    $res = zalo_api_request($url, 'POST', $headers, $fields);
    if ($res['status_code'] === 200 && !empty($res['data']['access_token'])) {
        return $res['data'];
    }
    
    // Log error for debugging
    error_log("zalo_get_tokens_by_code failed. Status: " . $res['status_code'] . ", Response: " . json_encode($res['data']));
    return null;
}

/**
 * Tự động kiểm tra và refresh Zalo OA Access Token từ cơ sở dữ liệu
 */
function zalo_get_active_token($oa_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM zalo_oas WHERE oa_id = ?");
    $stmt->execute([$oa_id]);
    $oa = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$oa) return null;

    $expires_at = $oa['expires_at'] ? strtotime($oa['expires_at']) : 0;
    // Buffer 5 phút cho an toàn
    if (time() < ($expires_at - 300)) {
        return $oa['access_token'];
    }

    // Access Token hết hạn -> Bắt đầu Refresh Token
    $stmt_set = $pdo->prepare("SELECT app_id, app_secret FROM zalo_settings WHERE account_id = ?");
    $stmt_set->execute([$oa['account_id']]);
    $settings = $stmt_set->fetch(PDO::FETCH_ASSOC);
    if (!$settings || empty($settings['app_id']) || empty($settings['app_secret'])) {
        return null;
    }

    $app_id = $settings['app_id'];
    $app_secret = decryptData($settings['app_secret']);

    $url = 'https://oauth.zaloapp.com/v4/oa/access_token';
    $headers = [
        "secret_key: {$app_secret}",
        "Content-Type: application/x-www-form-urlencoded"
    ];
    $fields = http_build_query([
        'refresh_token' => $oa['refresh_token'],
        'app_id' => $app_id,
        'grant_type' => 'refresh_token'
    ]);

    $res = zalo_api_request($url, 'POST', $headers, $fields);
    if ($res['status_code'] === 200 && !empty($res['data']['access_token'])) {
        $data = $res['data'];
        $new_access = $data['access_token'];
        $new_refresh = $data['refresh_token'];
        $exp_time = date('Y-m-d H:i:s', time() + intval($data['expires_in']));
        // Refresh token của Zalo sống 3 tháng
        $ref_exp_time = date('Y-m-d H:i:s', time() + (90 * 86400));

        $upd = $pdo->prepare("UPDATE zalo_oas SET access_token = ?, refresh_token = ?, expires_at = ?, refresh_expires_at = ? WHERE oa_id = ?");
        $upd->execute([$new_access, $new_refresh, $exp_time, $ref_exp_time, $oa_id]);

        return $new_access;
    }

    return null;
}

/**
 * Lấy thông tin Zalo Official Account
 */
function zalo_get_oa_profile($access_token) {
    $url = ZALO_API_BASE . 'v2.0/oa/getoa';
    $headers = [
        "access_token: {$access_token}"
    ];
    $res = zalo_api_request($url, 'GET', $headers);
    if ($res['status_code'] === 200 && isset($res['data']['data'])) {
        return $res['data']['data'];
    }
    return null;
}

/**
 * Lấy thông tin chi tiết khách hàng nhắn tin (Zalo Profile)
 */
function zalo_get_customer_profile($access_token, $user_id) {
    $url = ZALO_API_BASE . 'v2.0/oa/getprofile?data=' . urlencode(json_encode(['user_id' => $user_id]));
    $headers = [
        "access_token: {$access_token}"
    ];
    $res = zalo_api_request($url, 'GET', $headers);
    if ($res['status_code'] === 200 && isset($res['data']['data'])) {
        return $res['data']['data'];
    }
    return null;
}

/**
 * Upload hình ảnh lên Zalo OA lấy attachment_id để gửi tin nhắn
 */
function zalo_upload_image($access_token, $file_path, $file_name, $file_type) {
    $url = ZALO_API_BASE . 'v2.0/oa/upload/image';
    $headers = [
        "access_token: {$access_token}"
    ];
    
    $post_fields = [
        'file' => new CURLFile($file_path, $file_type, $file_name)
    ];

    $res = zalo_api_request($url, 'POST', $headers, $post_fields);
    if ($res['status_code'] === 200 && isset($res['data']['data']['attachment_id'])) {
        return $res['data']['data']['attachment_id'];
    }
    return null;
}

/**
 * Gửi tin nhắn văn bản (text) cho khách hàng (Customer Service Message)
 */
function zalo_send_text_message($access_token, $recipient_id, $text) {
    $url = ZALO_API_BASE . 'v3.0/oa/message/cs';
    $headers = [
        "access_token: {$access_token}",
        "Content-Type: application/json"
    ];
    $body = json_encode([
        'recipient' => ['user_id' => $recipient_id],
        'message' => ['text' => $text]
    ]);

    return zalo_api_request($url, 'POST', $headers, $body);
}

/**
 * Gửi tin nhắn hình ảnh (image) cho khách hàng
 */
function zalo_send_image_message($access_token, $recipient_id, $attachment_id) {
    $url = ZALO_API_BASE . 'v3.0/oa/message/cs';
    $headers = [
        "access_token: {$access_token}",
        "Content-Type: application/json"
    ];
    $body = json_encode([
        'recipient' => ['user_id' => $recipient_id],
        'message' => [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'media',
                    'elements' => [
                        [
                            'media_type' => 'image',
                            'attachment_id' => $attachment_id
                        ]
                    ]
                ]
            ]
        ]
    ]);

    return zalo_api_request($url, 'POST', $headers, $body);
}
