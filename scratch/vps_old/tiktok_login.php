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

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$redirect_uri = $protocol . $_SERVER['HTTP_HOST'] . get_base_url() . "tiktok_callback.php";

$state = bin2hex(random_bytes(16));
$_SESSION['tiktok_oauth_state'] = $state;

$auth_url = get_tiktok_auth_url($redirect_uri, $state);

if (empty($auth_url)) {
    $_SESSION['flash_msg'] = "Vui lòng cấu hình TikTok Client Key & Client Secret trong phần Cài Đặt Hệ Thống trước.";
    header("Location: tiktok.php");
    exit;
}

header("Location: " . $auth_url);
exit;
?>
