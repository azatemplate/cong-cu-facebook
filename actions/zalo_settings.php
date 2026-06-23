<?php
// actions/zalo_settings.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// Guard auth
if (!isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Phiên đăng nhập hết hạn. Vui lòng đăng nhập lại.']);
    exit;
}

$account_id = $_SESSION['account_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT app_id, app_secret, oa_secret, phone_request_enabled, phone_request_hours, phone_request_text, province_request_text, product_request_text, followup_request_enabled, followup_request_hours, followup_request_text FROM zalo_settings WHERE account_id = ?");
        $stmt->execute([$account_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($settings) {
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'app_id' => $settings['app_id'],
                    'has_secret' => !empty($settings['app_secret']),
                    'has_oa_secret' => !empty($settings['oa_secret']),
                    'phone_request_enabled' => (int)($settings['phone_request_enabled'] ?? 0),
                    'phone_request_hours' => (int)($settings['phone_request_hours'] ?? 1),
                    'phone_request_text' => $settings['phone_request_text'] ?? '',
                    'province_request_text' => $settings['province_request_text'] ?? '',
                    'product_request_text' => $settings['product_request_text'] ?? '',
                    'followup_request_enabled' => (int)($settings['followup_request_enabled'] ?? 0),
                    'followup_request_hours' => (int)($settings['followup_request_hours'] ?? 12),
                    'followup_request_text' => $settings['followup_request_text'] ?? ''
                ]
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'app_id' => '',
                    'has_secret' => false,
                    'has_oa_secret' => false,
                    'phone_request_enabled' => 0,
                    'phone_request_hours' => 1,
                    'phone_request_text' => '',
                    'province_request_text' => '',
                    'product_request_text' => '',
                    'followup_request_enabled' => 0,
                    'followup_request_hours' => 12,
                    'followup_request_text' => ''
                ]
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    verify_csrf();

    $app_id = isset($_POST['app_id']) ? trim($_POST['app_id']) : '';
    $app_secret = isset($_POST['app_secret']) ? trim($_POST['app_secret']) : '';
    $oa_secret = isset($_POST['oa_secret']) ? trim($_POST['oa_secret']) : '';

    if (empty($app_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập App ID.']);
        exit;
    }

    try {
        // Lấy config cũ
        $stmt = $pdo->prepare("SELECT app_secret, oa_secret FROM zalo_settings WHERE account_id = ?");
        $stmt->execute([$account_id]);
        $old_settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (empty($app_secret) || $app_secret === '••••••••••••••••••••••••') {
            if ($old_settings && !empty($old_settings['app_secret'])) {
                // Giữ nguyên secret cũ
                $encrypted_secret = $old_settings['app_secret'];
            } else {
                echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập App Secret.']);
                exit;
            }
        } else {
            // Mã hóa secret mới
            $encrypted_secret = encryptData($app_secret);
        }

        if (empty($oa_secret) || $oa_secret === '••••••••••••••••••••••••') {
            if ($old_settings && !empty($old_settings['oa_secret'])) {
                // Giữ nguyên oa secret cũ
                $encrypted_oa_secret = $old_settings['oa_secret'];
            } else {
                echo json_encode(['status' => 'error', 'msg' => 'Vui lòng nhập OA Secret Key (Webhook).']);
                exit;
            }
        } else {
            // Mã hóa oa secret mới
            $encrypted_oa_secret = encryptData($oa_secret);
        }

        $stmt_save = $pdo->prepare("
            INSERT INTO zalo_settings (account_id, app_id, app_secret, oa_secret)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                app_id = VALUES(app_id),
                app_secret = VALUES(app_secret),
                oa_secret = VALUES(oa_secret),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt_save->execute([$account_id, $app_id, $encrypted_secret, $encrypted_oa_secret]);

        echo json_encode(['status' => 'success', 'msg' => 'Lưu cấu hình Zalo App thành công.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi lưu cơ sở dữ liệu: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'msg' => 'Method not allowed']);
}
?>
