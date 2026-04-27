<?php
require_once __DIR__ . '/includes/db.php';
session_start();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];

// Lấy Client ID từ Database
$stmt = $pdo->prepare("SELECT gg_client_id FROM system_accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || empty($account['gg_client_id'])) {
    die("Vui lòng nhập Google Client ID trong mục Cài Đặt (Settings) của bạn trước khi kết nối Kênh YouTube.");
}
$client_id = $account['gg_client_id'];
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
