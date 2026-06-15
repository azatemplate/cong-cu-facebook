<?php
// actions/zalo_get_oas.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

$account_id = $_SESSION['account_id'];

try {
    $stmt = $pdo->prepare("SELECT oa_id, name, avatar, is_active FROM zalo_oas WHERE account_id = ? ORDER BY created_at DESC");
    $stmt->execute([$account_id]);
    $oas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get App ID to generate OAuth link
    $stmt_set = $pdo->prepare("SELECT app_id FROM zalo_settings WHERE account_id = ?");
    $stmt_set->execute([$account_id]);
    $settings = $stmt_set->fetch(PDO::FETCH_ASSOC);
    $app_id = $settings ? $settings['app_id'] : '';

    $oauth_url = '';
    if (!empty($app_id)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        
        // Build callback path
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        // If script is in actions/, parent directory is the root
        $parent_dir = rtrim(dirname($script_dir), '/\\');
        $redirect_uri = $protocol . $host . $parent_dir . '/actions/zalo_auth.php';
        
        $oauth_url = "https://oauth.zaloapp.com/v4/oa/permission?app_id=" . urlencode($app_id) . "&redirect_uri=" . urlencode($redirect_uri);
    }

    echo json_encode([
        'status' => 'success',
        'data' => $oas,
        'oauth_url' => $oauth_url
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
}
?>
