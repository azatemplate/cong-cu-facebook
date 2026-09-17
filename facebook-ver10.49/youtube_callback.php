<?php
require_once __DIR__ . '/includes/db.php';
session_start();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}


if (isset($_GET['error'])) {
    die("Lỗi ủy quyền từ Google: " . htmlspecialchars($_GET['error']));
}

if (!isset($_GET['code'])) {
    die("Không tìm thấy mã Authorization Code.");
}

$code = $_GET['code'];
$account_id = $_SESSION['account_id'];

// Check user privilege and default credentials
$stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret, youtube_multi_api FROM system_accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

$youtube_multi_api = (!empty($account['youtube_multi_api']) || $_SESSION['role'] === 'admin') ? 1 : 0;
$client_id = '';
$client_secret = '';
$is_custom = false;

if ($youtube_multi_api === 1 && !empty($_SESSION['custom_gg_client_id']) && !empty($_SESSION['custom_gg_client_secret'])) {
    $client_id = $_SESSION['custom_gg_client_id'];
    $client_secret = $_SESSION['custom_gg_client_secret'];
    $is_custom = true;
} else {
    // Clear session variables to be safe
    unset($_SESSION['custom_gg_client_id']);
    unset($_SESSION['custom_gg_client_secret']);
    
    if (!$account || empty($account['gg_client_id']) || empty($account['gg_client_secret'])) {
        die("Thiếu cấu hình Cài Đặt Google Client ID và Secret của bạn. Vui lòng cấu hình trước khi kết nối.");
    }
    $client_id = $account['gg_client_id'];
    $client_secret = $account['gg_client_secret'];
}
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . $base_dir . "/youtube_callback.php";

// Exchange code for tokens
$post_fields = [
    'code' => $code,
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri' => $redirect_uri,
    'grant_type' => 'authorization_code'
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
$response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($response, true);

if (isset($token_data['error'])) {
    die("Lỗi cấp Token từ Google: " . htmlspecialchars($token_data['error_description'] ?? $token_data['error']));
}

$access_token = $token_data['access_token'] ?? null;
$refresh_token = $token_data['refresh_token'] ?? null;

if (!$access_token) {
    die("Không lấy được Access Token!");
}

if (!$refresh_token) {
    // Falls back if user revoked directly from Google account settings and re-granted without refresh block clearing.
    // Lấy lại từ DB xem nếu đã có refresh token của Kênh YouTube này chăng?
    // Nhưng vì không biết trước channelId, phải fetch channel list trước.
    // Việc này sẽ xử lý sau.
}

// Fetch YouTube Channel Information
$yt_ch = curl_init('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
curl_setopt($yt_ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($yt_ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $access_token",
    "Accept: application/json"
]);
$yt_response = curl_exec($yt_ch);
curl_close($yt_ch);

$yt_data = json_decode($yt_response, true);

if (isset($yt_data['error'])) {
    $err_msg = $yt_data['error']['message'] ?? '';
    // Thêm logic fallback nếu bị lỗi Quota
    if (strpos(strtolower($err_msg), 'quota') !== false || (isset($yt_data['error']['errors'][0]['reason']) && $yt_data['error']['errors'][0]['reason'] === 'quotaExceeded')) {
        // Thử lấy thông tin tài khoản Google cơ bản thay thế
        $ui_ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
        curl_setopt($ui_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ui_ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Accept: application/json"
        ]);
        $ui_response = curl_exec($ui_ch);
        curl_close($ui_ch);
        
        $ui_data = json_decode($ui_response, true);
        
        if (isset($ui_data['sub'])) {
            $yt_data = [
                'items' => [
                    [
                        'id' => 'no_id_' . $ui_data['sub'],
                        'snippet' => [
                            'title' => ($ui_data['name'] ?? 'Tài khoản YouTube'),
                            'thumbnails' => [
                                'default' => [
                                    'url' => $ui_data['picture'] ?? ''
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        } else {
            die("Lỗi lấy thông tin Kênh: Hệ thống đã chạm ngưỡng giới hạn Quota API của Google. Vui lòng thử đăng nhập lại để làm mới phiên (Profile API), hoặc cấu hình lại Google Client ID cá nhân.");
        }
    } else {
        die("Lỗi lấy thông tin Kênh YouTube: " . htmlspecialchars($err_msg));
    }
}

if (empty($yt_data['items'])) {
    die("Tài khoản Google này không liên kết với Kênh YouTube nào.");
}

$added_count = 0;

foreach ($yt_data['items'] as $item) {
    $channel_id = $item['id'];
    $channel_title = $item['snippet']['title'] ?? 'YouTube Channel';
    $channel_avatar = $item['snippet']['thumbnails']['default']['url'] ?? '';

    // Lưu vào database
    $chk_stmt = $pdo->prepare("SELECT id, refresh_token FROM youtube_channels WHERE account_id = ? AND channel_id = ?");
    $chk_stmt->execute([$account_id, $channel_id]);
    $existing = $chk_stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $final_refresh_token = $refresh_token ?: $existing['refresh_token'];
        $u_stmt = $pdo->prepare("UPDATE youtube_channels SET channel_title=?, channel_avatar=?, refresh_token=?, gg_client_id=?, gg_client_secret=? WHERE id=?");
        $u_stmt->execute([$channel_title, $channel_avatar, $final_refresh_token, $is_custom ? $client_id : null, $is_custom ? $client_secret : null, $existing['id']]);
    } else {
        if (!$refresh_token) {
            continue; // Lỗi: mới thêm nhưng google không trả về refresh token
        }
        
        // Check max_yt_channels limit for user
        $acc_stmt = $pdo->prepare("SELECT role, max_yt_channels FROM system_accounts WHERE id = ?");
        $acc_stmt->execute([$account_id]);
        $acc_info = $acc_stmt->fetch(PDO::FETCH_ASSOC);
        $is_admin = (($acc_info['role'] ?? '') === 'admin');
        $max_yt_channels = (int)($acc_info['max_yt_channels'] ?? 10);
        
        if (!$is_admin && $max_yt_channels > 0) {
            $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM youtube_channels WHERE account_id = ?");
            $cnt_stmt->execute([$account_id]);
            $curr_yt_count = (int)$cnt_stmt->fetchColumn();
            
            if ($curr_yt_count >= $max_yt_channels) {
                $_SESSION['flash_msg'] = "⚠️ Tài khoản của bạn đã đạt giới hạn tối đa $max_yt_channels Kênh YouTube (Hiện tại: $curr_yt_count/$max_yt_channels). Vui lòng nâng cấp gói cước để thêm kênh mới!";
                header("Location: youtube.php?tab=channels");
                exit;
            }
        }

        $i_stmt = $pdo->prepare("INSERT INTO youtube_channels (account_id, channel_id, channel_title, channel_avatar, refresh_token, gg_client_id, gg_client_secret) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $i_stmt->execute([$account_id, $channel_id, $channel_title, $channel_avatar, $refresh_token, $is_custom ? $client_id : null, $is_custom ? $client_secret : null]);
    }
    $added_count++;
}

// Clear custom credentials from session
unset($_SESSION['custom_gg_client_id']);
unset($_SESSION['custom_gg_client_secret']);

if ($added_count > 0) {
    $_SESSION['flash_msg'] = "Liên kết $added_count Kênh YouTube thành công!";
    header("Location: youtube_channels.php");
    exit;
} else {
    die("Không có kênh nào được thêm (Lưu ý: Bạn phải cấp đủ quyền để Google trả về Token hợp lệ). Vui lòng thử lại.");
}
?>
