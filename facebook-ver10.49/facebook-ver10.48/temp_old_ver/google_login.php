<?php
require_once __DIR__ . '/includes/db.php';
session_start();

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

$client_id = null;

if ($user_id > 0) {
    // Kiểm tra quyền sở hữu
    $stmt = $pdo->prepare("SELECT id, gg_client_id FROM users WHERE id = ? AND account_id = ?");
    $stmt->execute([$user_id, $account_id]);
    $user_row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_row) {
        die("Không tìm thấy tài khoản Facebook hoặc tài khoản không thuộc quyền quản lý của bạn.");
    }

    // Kiểm tra quyền mở rộng API Drive của tài khoản hệ thống
    $stmt_acc = $pdo->prepare("SELECT role, drive_multi_api FROM system_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $acc_row = $stmt_acc->fetch(PDO::FETCH_ASSOC);
    $is_admin = ($acc_row['role'] ?? '') === 'admin';
    $drive_multi_api = (int)($acc_row['drive_multi_api'] ?? 0);

    if (!$is_admin && !$drive_multi_api) {
        die("Tài khoản của bạn không có quyền mở rộng API Drive riêng.");
    }
    
    $client_id = $user_row['gg_client_id'];
    $_SESSION['drive_auth_user_id'] = $user_id;
} else {
    unset($_SESSION['drive_auth_user_id']);
}

// Nếu không có client_id riêng (hoặc là luồng hệ thống), lấy từ system_accounts
if (empty($client_id)) {
    $stmt_sys = $pdo->prepare("SELECT gg_client_id FROM system_accounts WHERE id = ?");
    $stmt_sys->execute([$account_id]);
    $client_id = $stmt_sys->fetchColumn();
}

if (empty($client_id)) {
    die("Vui lòng nhập Google Client ID trong mục Cài Đặt (Settings) hoặc Cấu hình API của Token trước khi kết nối Google Drive.");
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . $base_dir . "/google_callback.php";

$scopes = [
    'https://www.googleapis.com/auth/drive'
];

$auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'response_type' => 'code',
    'scope' => implode(' ', $scopes),
    'access_type' => 'offline',
    'prompt' => 'consent'
]);

header("Location: $auth_url");
exit;
