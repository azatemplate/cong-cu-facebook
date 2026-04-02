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

// Lấy credentials
$stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || empty($account['gg_client_id']) || empty($account['gg_client_secret'])) {
    $stmt_admin = $pdo->query("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = 1");
    $admin_account = $stmt_admin->fetch(PDO::FETCH_ASSOC);
    if (!$admin_account || empty($admin_account['gg_client_id']) || empty($admin_account['gg_client_secret'])) {
        die("Thiếu cấu hình Client ID và Secret (hoặc liên hệ Admin).");
    }
    $client_id = $admin_account['gg_client_id'];
    $client_secret = $admin_account['gg_client_secret'];
} else {
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
    die("Lỗi lấy thông tin Kênh YouTube: " . htmlspecialchars($yt_data['error']['message']));
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
        $u_stmt = $pdo->prepare("UPDATE youtube_channels SET channel_title=?, channel_avatar=?, refresh_token=? WHERE id=?");
        $u_stmt->execute([$channel_title, $channel_avatar, $final_refresh_token, $existing['id']]);
    } else {
        if (!$refresh_token) {
            continue; // Lỗi: mới thêm nhưng google không trả về refresh token
        }
        $i_stmt = $pdo->prepare("INSERT INTO youtube_channels (account_id, channel_id, channel_title, channel_avatar, refresh_token) VALUES (?, ?, ?, ?, ?)");
        $i_stmt->execute([$account_id, $channel_id, $channel_title, $channel_avatar, $refresh_token]);
    }
    $added_count++;
}

if ($added_count > 0) {
    $_SESSION['flash_msg'] = "Liên kết $added_count Kênh YouTube thành công!";
    header("Location: youtube_channels.php");
    exit;
} else {
    die("Không có kênh nào được thêm (Lưu ý: Bạn phải cấp đủ quyền để Google trả về Token hợp lệ). Vui lòng thử lại.");
}
?>
