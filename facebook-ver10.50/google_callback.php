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
$user_id = isset($_SESSION['drive_auth_user_id']) ? intval($_SESSION['drive_auth_user_id']) : 0;

$client_id = null;
$client_secret = null;

if ($user_id > 0) {
    // Kiểm tra quyền mở rộng API Drive của tài khoản hệ thống
    $stmt_acc = $pdo->prepare("SELECT role, drive_multi_api FROM system_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $acc_row = $stmt_acc->fetch(PDO::FETCH_ASSOC);
    $is_admin = ($acc_row['role'] ?? '') === 'admin';
    $drive_multi_api = (int)($acc_row['drive_multi_api'] ?? 0);

    if (!$is_admin && !$drive_multi_api) {
        die("Tài khoản của bạn không có quyền mở rộng API Drive riêng.");
    }

    // Luồng OAuth riêng của tài khoản Facebook (User)
    $stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM users WHERE id = ? AND account_id = ?");
    $stmt->execute([$user_id, $account_id]);
    $user_row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_row) {
        die("Không tìm thấy tài khoản Facebook tương ứng.");
    }
    
    $client_id = $user_row['gg_client_id'];
    $client_secret = $user_row['gg_client_secret'];
    
    // Nếu trống credentials riêng, lấy mặc định từ hệ thống
    if (empty($client_id) || empty($client_secret)) {
        $stmt_sys = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = ?");
        $stmt_sys->execute([$account_id]);
        $sys = $stmt_sys->fetch(PDO::FETCH_ASSOC);
        
        if (!$sys || empty($sys['gg_client_id']) || empty($sys['gg_client_secret'])) {
            die("Thiếu cấu hình Google Client ID và Secret mặc định hoặc riêng cho tài khoản này.");
        }
        $client_id = $sys['gg_client_id'];
        $client_secret = $sys['gg_client_secret'];
    }
} else {
    // Luồng OAuth hệ thống dùng chung
    $stmt = $pdo->prepare("SELECT gg_client_id, gg_client_secret FROM system_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account || empty($account['gg_client_id']) || empty($account['gg_client_secret'])) {
        die("Thiếu cấu hình Cài Đặt Google Client ID và Secret của bạn. Vui lòng cấu hình trước khi kết nối Google Drive.");
    }
    $client_id = $account['gg_client_id'];
    $client_secret = $account['gg_client_secret'];
}

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

if ($refresh_token) {
    if ($user_id > 0) {
        $u_stmt = $pdo->prepare("UPDATE users SET gg_refresh_token = ? WHERE id = ? AND account_id = ?");
        $u_stmt->execute([$refresh_token, $user_id, $account_id]);
        
        unset($_SESSION['drive_auth_user_id']);
        $_SESSION['flash_msg'] = "Liên kết Google Drive cho tài khoản thành công!";
        header("Location: token_management.php?status=success_drive");
        exit;
    } else {
        $u_stmt = $pdo->prepare("UPDATE system_accounts SET gg_refresh_token = ? WHERE id = ?");
        $u_stmt->execute([$refresh_token, $account_id]);
        
        $_SESSION['flash_msg'] = "Liên kết Google Drive thành công!";
        header("Location: settings.php");
        exit;
    }
} else {
    die("Google không trả về Refresh Token. Thử ngắt kết nối trong Cài đặt tài khoản Google của bạn và thử lại.");
}
