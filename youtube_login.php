<?php
require_once __DIR__ . '/includes/db.php';
session_start();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];

// Check user privilege and Client ID
$stmt = $pdo->prepare("SELECT gg_client_id, youtube_multi_api FROM system_accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

$youtube_multi_api = (!empty($account['youtube_multi_api']) || $_SESSION['role'] === 'admin') ? 1 : 0;
$client_id = '';

if ($youtube_multi_api === 1 && isset($_REQUEST['reauth_channel_id'])) {
    $reauth_channel_id = intval($_REQUEST['reauth_channel_id']);
    $ch_stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM youtube_channels WHERE id = ? AND account_id = ?");
    $ch_stmt->execute([$reauth_channel_id, $account_id]);
    $ch_info = $ch_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ch_info && !empty($ch_info['gg_client_id'])) {
        $_SESSION['custom_gg_client_id'] = $ch_info['gg_client_id'];
        $_SESSION['custom_gg_client_secret'] = $ch_info['gg_client_secret'];
        $client_id = $ch_info['gg_client_id'];
    } else {
        unset($_SESSION['custom_gg_client_id']);
        unset($_SESSION['custom_gg_client_secret']);
        if (!$account || empty($account['gg_client_id'])) {
            die("Vui lòng nhập Google Client ID trong mục Cài Đặt (Settings) của bạn trước khi kết nối Kênh YouTube.");
        }
        $client_id = $account['gg_client_id'];
    }
} elseif ($youtube_multi_api === 1 && isset($_REQUEST['gg_client_id']) && !empty(trim($_REQUEST['gg_client_id']))) {
    $client_id = trim($_REQUEST['gg_client_id']);
    $client_secret = trim($_REQUEST['gg_client_secret'] ?? '');
    
    $_SESSION['custom_gg_client_id'] = $client_id;
    $_SESSION['custom_gg_client_secret'] = $client_secret;
} else {
    // Clear custom credentials from session
    unset($_SESSION['custom_gg_client_id']);
    unset($_SESSION['custom_gg_client_secret']);
    
    if (!$account || empty($account['gg_client_id'])) {
        die("Vui lòng nhập Google Client ID trong mục Cài Đặt (Settings) của bạn trước khi kết nối Kênh YouTube.");
    }
    $client_id = $account['gg_client_id'];
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . $base_dir . "/youtube_callback.php";

// Scopes cần thiết cho YouTube
$scopes = [
    'https://www.googleapis.com/auth/youtube.upload',
    'https://www.googleapis.com/auth/youtube.force-ssl',
    'https://www.googleapis.com/auth/userinfo.profile'
];

$auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'response_type' => 'code',
    'scope' => implode(' ', $scopes),
    'access_type' => 'offline',
    'prompt' => 'consent' // Ép trả về refresh_token mỗi lần đăng nhập
]);

header("Location: $auth_url");
exit;
?>
