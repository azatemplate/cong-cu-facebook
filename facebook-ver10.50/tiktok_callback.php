<?php
// tiktok_callback.php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/tiktok_api.php';
require_once __DIR__ . '/includes/security.php';

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];

$code  = trim($_GET['code'] ?? '');
$state = trim($_GET['state'] ?? '');
$error = trim($_GET['error'] ?? '');

if ($error) {
    $_SESSION['flash_msg'] = "Ủy quyền TikTok bị hủy hoặc lỗi: " . htmlspecialchars($error);
    header("Location: tiktok.php");
    exit;
}

if (empty($code)) {
    $_SESSION['flash_msg'] = "Không nhận được mã xác thực Authorization Code từ TikTok.";
    header("Location: tiktok.php");
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . get_base_url() . "tiktok_callback.php";

// Exchange code for token
$res = exchange_tiktok_code($code, $redirect_uri);

if ($res['status'] !== 'success') {
    $_SESSION['flash_msg'] = "Lỗi đổi Token TikTok: " . ($res['msg'] ?? 'Không xác định');
    header("Location: tiktok.php");
    exit;
}

$token_data = $res['data'];
$access_token  = $token_data['access_token'] ?? '';
$refresh_token = $token_data['refresh_token'] ?? '';
$open_id       = $token_data['open_id'] ?? '';
$expires_in    = intval($token_data['expires_in'] ?? 86400);
$refresh_exp   = intval($token_data['refresh_expires_in'] ?? 31536000);

if (empty($access_token) || empty($open_id)) {
    $_SESSION['flash_msg'] = "Dữ liệu Token hoặc OpenID TikTok không hợp lệ.";
    header("Location: tiktok.php");
    exit;
}

$expires_at = time() + $expires_in;
$refresh_expires_at = time() + $refresh_exp;

// Fetch creator info
$user_info_res = get_tiktok_user_info($access_token);
$display_name  = 'TikTok User';
$avatar        = '';
$union_id      = '';

if ($user_info_res['status'] === 'success') {
    $u = $user_info_res['user'];
    $display_name = $u['display_name'] ?? 'TikTok User';
    $avatar       = $u['avatar_url'] ?? '';
    $union_id     = $u['union_id'] ?? '';
// Check max_tiktok_accounts limit before inserting new account
$chk_tt = $pdo->prepare("SELECT id FROM tiktok_accounts WHERE account_id = ? AND open_id = ?");
$chk_tt->execute([$account_id, $open_id]);
$existing_tt = $chk_tt->fetch();

if (!$existing_tt) {
    $acc_stmt = $pdo->prepare("SELECT role, max_tiktok_accounts FROM system_accounts WHERE id = ?");
    $acc_stmt->execute([$account_id]);
    $acc_info = $acc_stmt->fetch(PDO::FETCH_ASSOC);
    $max_tiktok_accounts = (int)($acc_info['max_tiktok_accounts'] ?? 10);
    $is_admin = (($acc_info['role'] ?? '') === 'admin');

    if (!$is_admin && $max_tiktok_accounts > 0) {
        $cnt_tt_stmt = $pdo->prepare("SELECT COUNT(*) FROM tiktok_accounts WHERE account_id = ?");
        $cnt_tt_stmt->execute([$account_id]);
        $curr_tt_count = (int)$cnt_tt_stmt->fetchColumn();

        if ($curr_tt_count >= $max_tiktok_accounts) {
            $_SESSION['flash_msg'] = "⚠️ Tài khoản của bạn đã đạt giới hạn tối đa $max_tiktok_accounts Kênh TikTok (Hiện tại: $curr_tt_count/$max_tiktok_accounts). Vui lòng liên hệ Admin để nâng cấp gói cước!";
            header("Location: tiktok.php");
            exit;
        }
    }
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO tiktok_accounts (account_id, open_id, union_id, display_name, avatar, access_token, refresh_token, expires_at, refresh_expires_at, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            union_id = VALUES(union_id),
            display_name = VALUES(display_name),
            avatar = VALUES(avatar),
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            expires_at = VALUES(expires_at),
            refresh_expires_at = VALUES(refresh_expires_at),
            is_active = 1
    ");
    $stmt->execute([
        $account_id,
        $open_id,
        $union_id,
        $display_name,
        $avatar,
        $access_token,
        $refresh_token,
        $expires_at,
        $refresh_expires_at
    ]);

    $_SESSION['flash_msg'] = "Liên kết tài khoản TikTok [{$display_name}] thành công!";
} catch (Exception $e) {
    $_SESSION['flash_msg'] = "Lỗi lưu tài khoản TikTok vào cơ sở dữ liệu: " . $e->getMessage();
}

header("Location: tiktok.php");
exit;
?>
