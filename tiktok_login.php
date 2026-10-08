<?php
// tiktok_login.php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/tiktok_api.php';
require_once __DIR__ . '/includes/security.php';

if (!isset($_SESSION['account_id'])) {
    header("Location: login.php");
    exit;
}

$account_id = $_SESSION['account_id'];
$stmt_acc = $pdo->prepare("SELECT role, max_tiktok_accounts FROM system_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$acc_info = $stmt_acc->fetch(PDO::FETCH_ASSOC);

$is_admin = (($acc_info['role'] ?? '') === 'admin');
$max_tiktok_accounts = (int)($acc_info['max_tiktok_accounts'] ?? 10);

if (!$is_admin) {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM tiktok_accounts WHERE account_id = ? AND is_active = 1");
    $cnt_stmt->execute([$account_id]);
    $curr_tt_count = (int)$cnt_stmt->fetchColumn();
    if ($curr_tt_count >= $max_tiktok_accounts) {
        $_SESSION['flash_msg'] = "⚠️ Tài khoản của bạn đã đạt/vượt giới hạn tối đa {$max_tiktok_accounts} Kênh TikTok (Hiện tại: {$curr_tt_count}/{$max_tiktok_accounts}). Vui lòng liên hệ Admin để nâng cấp hạn ngạch!";
        header("Location: tiktok.php?tab=channels");
        exit;
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . get_base_url() . "tiktok_callback.php";

$state = bin2hex(random_bytes(16));
$_SESSION['tiktok_oauth_state'] = $state;

$auth_url = get_tiktok_auth_url($redirect_uri, $state);

if (empty($auth_url)) {
    $_SESSION['flash_msg'] = "Vui lòng cấu hình TikTok Client Key & Client Secret trong phần Cài Đặt Hệ Thống trước.";
    header("Location: tiktok.php?tab=channels");
    exit;
}

header("Location: " . $auth_url);
exit;
?>
