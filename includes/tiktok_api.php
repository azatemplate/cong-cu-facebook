<?php
// includes/tiktok_api.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Ensure DB tables exist for TikTok integration
 */
function init_tiktok_tables() {
    global $pdo;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tiktok_accounts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `account_id` INT NOT NULL,
                `open_id` VARCHAR(255) NOT NULL,
                `union_id` VARCHAR(255) DEFAULT NULL,
                `display_name` VARCHAR(255) NOT NULL,
                `avatar` TEXT DEFAULT NULL,
                `access_token` TEXT NOT NULL,
                `refresh_token` TEXT DEFAULT NULL,
                `expires_at` INT NOT NULL,
                `refresh_expires_at` INT DEFAULT NULL,
                `is_active` TINYINT(1) DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `idx_open_id` (`account_id`, `open_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {
        error_log("init_tiktok_tables error: " . $e->getMessage());
    }
}

// Auto init table
init_tiktok_tables();

/**
 * Get TikTok App Credentials from Admin Settings (system_settings ONLY)
 * All users across the system MUST use Admin's TikTok Developer App credentials.
 */
function get_tiktok_client_config() {
    global $pdo;
    $client_key = '';
    $client_secret = '';
    $scopes = '';

    // ALWAYS fetch global Admin config from system_settings ONLY
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('tiktok_client_key', 'tiktok_client_secret', 'tiktok_scopes')");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($r['setting_key'] === 'tiktok_client_key' && !empty($r['setting_value'])) $client_key = trim($r['setting_value']);
            if ($r['setting_key'] === 'tiktok_client_secret' && !empty($r['setting_value'])) $client_secret = trim($r['setting_value']);
            if ($r['setting_key'] === 'tiktok_scopes' && !empty($r['setting_value'])) $scopes = trim($r['setting_value']);
        }
    } catch (Exception $e) {}

    return [
        'client_key' => $client_key,
        'client_secret' => $client_secret,
        'scopes' => $scopes
    ];
}

/**
 * Generate TikTok OAuth Login Authorization URL
 */
function get_tiktok_auth_url($redirect_uri, $state = '') {
    $cfg = get_tiktok_client_config();
    $client_key = trim($cfg['client_key'] ?? '');
    if (empty($client_key)) {
        return '';
    }

    $scopes = !empty($cfg['scopes']) ? $cfg['scopes'] : 'user.info.basic,video.upload,user.info.profile,user.info.stats,video.list';
    
    $params = [
        'client_key' => $client_key,
        'scope' => $scopes,
        'response_type' => 'code',
        'redirect_uri' => $redirect_uri,
        'state' => $state,
        'prompt' => 'consent',
        'disable_auto_login' => 'true'
    ];

    return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query($params);
}

/**
 * Exchange Authorization Code for Access Token
 */
function exchange_tiktok_code($code, $redirect_uri) {
    $cfg = get_tiktok_client_config();
    if (empty($cfg['client_key']) || empty($cfg['client_secret'])) {
        return ['status' => 'error', 'msg' => 'Chưa cấu hình TikTok Client Key / Client Secret.'];
    }

    $url = 'https://open.tiktokapis.com/v2/oauth/token/';
    $post_fields = http_build_query([
        'client_key' => $cfg['client_key'],
        'client_secret' => $cfg['client_secret'],
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirect_uri
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['status' => 'error', 'msg' => 'Lỗi kết nối TikTok Token API: ' . $err];
    }

    $data = json_decode($raw, true);
    if (!empty($data['access_token']) || !empty($data['data']['access_token'])) {
        $token_info = $data['data'] ?? $data;
        return [
            'status' => 'success',
            'data' => $token_info
        ];
    }

    $error_msg = $data['error_description'] ?? ($data['message'] ?? 'Lỗi xác thực Token TikTok');
    return ['status' => 'error', 'msg' => $error_msg];
}

/**
 * Get User Info from TikTok API
 */
function get_tiktok_user_info($access_token) {
    $url = 'https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id,avatar_url,display_name';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($raw, true);
    if (isset($data['data']['user'])) {
        return ['status' => 'success', 'user' => $data['data']['user']];
    }

    return ['status' => 'error', 'msg' => $data['error']['message'] ?? 'Không lấy được thông tin TikTok User'];
}

/**
 * Query TikTok Creator Info for privacy levels & settings
 */
function get_tiktok_creator_info($access_token) {
    $url = 'https://open.tiktokapis.com/v2/post/publish/creator/info/query/';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json; charset=UTF-8'
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($raw, true);
    if (isset($data['data'])) {
        return $data['data'];
    }
    return null;
}

/**
 * Post Video to TikTok Content Posting Direct Post API
 */
function post_tiktok_video_direct($access_token, $video_url_or_file, $title, $options = []) {
    $url = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
    
    $privacy_level = $options['privacy_level'] ?? 'PUBLIC_TO_EVERYONE';
    $disable_comment = !empty($options['disable_comment']) ? true : false;
    $disable_duet = !empty($options['disable_duet']) ? true : false;
    $disable_stitch = !empty($options['disable_stitch']) ? true : false;
    $auto_add_music = !empty($options['auto_add_music']) ? true : false;

    // Helper function for curling TikTok API
    $send_request = function($p_level) use ($url, $access_token, $title, $video_url_or_file, $disable_comment, $disable_duet, $disable_stitch, $auto_add_music) {
        $payload = [
            'post_info' => [
                'title' => !empty($title) ? $title : 'TikTok Video',
                'privacy_level' => $p_level,
                'disable_comment' => $disable_comment,
                'disable_duet' => $disable_duet,
                'disable_stitch' => $disable_stitch,
                'auto_add_music' => $auto_add_music
            ],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $video_url_or_file
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json; charset=UTF-8'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['error' => ['message' => 'Lỗi cURL: ' . $err]];
        return json_decode($raw, true) ?: [];
    };

    // Tự động kiểm tra quyền riêng tư cho phép của kênh trước để gửi 1 request duy nhất thành công siêu tốc
    $target_privacy = $privacy_level;
    $creator_info = get_tiktok_creator_info($access_token);
    if ($creator_info && !empty($creator_info['privacy_level_options'])) {
        $allowed = $creator_info['privacy_level_options'];
        if (!in_array($target_privacy, $allowed) && !empty($allowed)) {
            $target_privacy = $allowed[0];
        }
    }

    $res = $send_request($target_privacy);

    if (isset($res['data']['publish_id'])) {
        return [
            'status' => 'success',
            'publish_id' => $res['data']['publish_id'],
            'msg' => '🎉 Đã gửi yêu cầu đăng video lên TikTok thành công!'
        ];
    }

    // Nếu gửi lần 1 bị TikTok từ chối (thường do TikTok App ở chế độ Sandbox/Draft giới hạn Public), tự động thử với các chế độ riêng tư khác
    $fallback_levels = ['SELF_ONLY', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'PUBLIC_TO_EVERYONE'];
    foreach ($fallback_levels as $alt_lvl) {
        if ($alt_lvl === $target_privacy) continue;
        $res_alt = $send_request($alt_lvl);
        if (isset($res_alt['data']['publish_id'])) {
            return [
                'status' => 'success',
                'publish_id' => $res_alt['data']['publish_id'],
                'msg' => "🎉 Đã đăng video lên TikTok thành công ở chế độ '{$alt_lvl}'!"
            ];
        }
    }

    $msg = $res['error']['message'] ?? ($res['message'] ?? 'Lỗi không đăng được bài TikTok');
    if (stripos($msg, 'guidelines') !== false) {
        $msg .= ' (Nếu App TikTok của bạn ở dạng Thử nghiệm/Draft, vui lòng vào TikTok App nâng cấp App lên Live/Production hoặc kiểm tra lại ủy quyền tài khoản).';
    }

    return ['status' => 'error', 'msg' => $msg];
}
?>
