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
    die("Thiếu cấu hình Cài Đặt Google Client ID và Secret của bạn. Vui lòng cấu hình trước khi kết nối Google Drive.");
}
$client_id = $account['gg_client_id'];
$client_secret = $account['gg_client_secret'];
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . $base_dir . "/google_callback.php";

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

$refresh_token = $token_data['refresh_token'] ?? null;
// Mặc định Google chỉ cấp refresh_token ở lần đầu tiên Login (prompt=consent). Nếu đã login trước đó, chỉ có access_token.
// Ở google_login.php chúng ta đã ép prompt=consent nên ở đây chắc chắn sẽ có.

if ($refresh_token) {
    $u_stmt = $pdo->prepare("UPDATE system_accounts SET gg_refresh_token = ? WHERE id = ?");
    $u_stmt->execute([$refresh_token, $account_id]);
    
    $_SESSION['flash_msg'] = "Liên kết Google Drive thành công!";
    header("Location: settings.php");
    exit;
} else {
    // Falls back if user revoked directly from Google account settings and re-granted without refresh block clearing.
    die("Google không trả về Refresh Token. Thử ngắt kết nối trong Cài đặt tài khoản Google của bạn và thử lại.");
}
?>
