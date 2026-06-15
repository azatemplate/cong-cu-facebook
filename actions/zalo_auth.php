<?php
// actions/zalo_auth.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/zalo_api.php';
require_once __DIR__ . '/../includes/security.php';

$_s_account_id = $_SESSION['account_id'] ?? 0;
if (!$_s_account_id) {
    die("Yêu cầu phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại hệ thống.");
}

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$oa_id = isset($_GET['oa_id']) ? trim($_GET['oa_id']) : '';

if (empty($code) || empty($oa_id)) {
    header('Location: ../live-chat-oa.php?tab=channels&auth=error&msg=' . urlencode('Thiếu thông số phản hồi từ Zalo OAuth.'));
    exit;
}

// 1. Lấy thông tin Zalo App Credentials từ CSDL
$stmt = $pdo->prepare("SELECT app_id, app_secret FROM zalo_settings WHERE account_id = ?");
$stmt->execute([$_s_account_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings || empty($settings['app_id']) || empty($settings['app_secret'])) {
    header('Location: ../live-chat-oa.php?tab=channels&auth=error&msg=' . urlencode('Bạn chưa cấu hình App ID và App Secret của Zalo App.'));
    exit;
}

$app_id = $settings['app_id'];
$app_secret = decryptData($settings['app_secret']);

// 2. Trao đổi Code lấy Access Token & Refresh Token
$tokens = zalo_get_tokens_by_code($code, $app_id, $app_secret);
if (!$tokens || empty($tokens['access_token'])) {
    header('Location: ../live-chat-oa.php?tab=channels&auth=error&msg=' . urlencode('Không thể lấy Access Token từ Zalo API.'));
    exit;
}

$access_token = $tokens['access_token'];
$refresh_token = $tokens['refresh_token'] ?? '';
$expires_in = intval($tokens['expires_in'] ?? 90000); // Mặc định 25h của Zalo

// 3. Gọi Zalo API lấy tên và avatar của OA
$oa_profile = zalo_get_oa_profile($access_token);
$oa_name = $oa_profile['name'] ?? 'Zalo OA';
$oa_avatar = $oa_profile['avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($oa_name);

$expires_at = date('Y-m-d H:i:s', time() + $expires_in);
$refresh_expires_at = date('Y-m-d H:i:s', time() + (90 * 86400)); // Refresh token sống 3 tháng

// 4. Lưu / cập nhật Zalo OA vào cơ sở dữ liệu
try {
    $stmt_save = $pdo->prepare("
        INSERT INTO zalo_oas (oa_id, account_id, name, avatar, access_token, refresh_token, expires_at, refresh_expires_at, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE 
            account_id = VALUES(account_id),
            name = VALUES(name),
            avatar = VALUES(avatar),
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            expires_at = VALUES(expires_at),
            refresh_expires_at = VALUES(refresh_expires_at),
            is_active = 1
    ");
    $stmt_save->execute([
        $oa_id,
        $_s_account_id,
        $oa_name,
        $oa_avatar,
        $access_token,
        $refresh_token,
        $expires_at,
        $refresh_expires_at
    ]);

    header('Location: ../live-chat-oa.php?tab=channels&auth=success');
    exit;
} catch (PDOException $e) {
    header('Location: ../live-chat-oa.php?tab=channels&auth=error&msg=' . urlencode('Lỗi lưu CSDL: ' . $e->getMessage()));
    exit;
}
