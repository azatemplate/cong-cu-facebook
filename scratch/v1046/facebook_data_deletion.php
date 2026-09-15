<?php
/**
 * Facebook Data Deletion Callback URL
 * 
 * Complies with Facebook Platform Policy. Parses the signed_request,
 * verifies the signature with App Secret, deletes all user data,
 * and returns status URL and confirmation code in JSON.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

// --- Functions to verify and decode Facebook Signed Request ---
function base64_url_decode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) {
        $padlen = 4 - $remainder;
        $input .= str_repeat('=', $padlen);
    }
    return base64_decode(strtr($input, '-_', '+/'));
}

function parse_signed_request($signed_request, $secret) {
    if (strpos($signed_request, '.') === false) {
        return null;
    }
    list($encoded_sig, $payload) = explode('.', $signed_request, 2);

    $sig = base64_url_decode($encoded_sig);
    $data = json_decode(base64_url_decode($payload), true);

    if (empty($data) || !isset($data['algorithm']) || strtolower($data['algorithm']) !== 'hmac-sha256') {
        return null;
    }

    // Confirm signature
    $expected_sig = hash_hmac('sha256', $payload, $secret, true);
    if ($sig !== $expected_sig) {
        return null;
    }

    return $data;
}

// --- Handle GET Request (Public explanation / UI page) ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Facebook Data Deletion Callback - Information</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css">
        <style>
            :root {
                --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
                --card-bg: rgba(30, 41, 59, 0.7);
                --card-border: rgba(255, 255, 255, 0.08);
                --text-glow: 0 0 20px rgba(99, 102, 241, 0.2);
            }
            body {
                margin: 0;
                padding: 40px 20px;
                font-family: 'Outfit', sans-serif;
                background: var(--bg-gradient);
                color: #f1f5f9;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                box-sizing: border-box;
            }
            .deletion-container {
                max-width: 600px;
                width: 100%;
                background: var(--card-bg);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border: 1px solid var(--card-border);
                border-radius: 20px;
                padding: 40px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3), var(--text-glow);
                text-align: center;
                animation: fadeIn 0.6s ease-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .icon {
                font-size: 64px;
                margin-bottom: 20px;
                display: inline-block;
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.05); }
            }
            h1 {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 15px;
                background: linear-gradient(to right, #818cf8, #c084fc);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            p {
                color: #94a3b8;
                line-height: 1.6;
                font-size: 15px;
                margin-bottom: 30px;
            }
            .form-group {
                margin-bottom: 20px;
                text-align: left;
            }
            label {
                display: block;
                font-size: 13px;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #6366f1;
                margin-bottom: 8px;
                font-weight: 600;
            }
            .input-group {
                display: flex;
                gap: 10px;
            }
            input[type="text"] {
                flex: 1;
                padding: 12px 16px;
                border-radius: 10px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                background: rgba(15, 23, 42, 0.6);
                color: #fff;
                font-size: 15px;
                outline: none;
                transition: all 0.3s;
            }
            input[type="text"]:focus {
                border-color: #6366f1;
                box-shadow: 0 0 10px rgba(99, 102, 241, 0.3);
            }
            .btn-lookup {
                background: linear-gradient(to right, #4f46e5, #7c3aed);
                color: #fff;
                border: none;
                padding: 12px 24px;
                border-radius: 10px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
            }
            .btn-lookup:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(99, 102, 241, 0.4);
            }
            .footer-links {
                margin-top: 30px;
                font-size: 13px;
                border-top: 1px solid rgba(255, 255, 255, 0.05);
                padding-top: 20px;
            }
            .footer-links a {
                color: #818cf8;
                text-decoration: none;
                transition: color 0.2s;
            }
            .footer-links a:hover {
                color: #c084fc;
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="deletion-container">
            <div class="icon">🛡️</div>
            <h1>Ủy Quyền Xóa Dữ Liệu</h1>
            <p>
                Đây là cổng tiếp nhận yêu cầu xóa dữ liệu tự động từ Facebook. Khi bạn gỡ bỏ ứng dụng hoặc gửi yêu cầu xóa dữ liệu, hệ thống của chúng tôi sẽ xóa toàn bộ mã token, fanpage đã liên kết, lịch đăng bài và thông tin cá nhân của bạn để bảo mật quyền riêng tư.
            </p>
            
            <form action="deletion_status.php" method="GET">
                <div class="form-group">
                    <label for="id">Tra cứu trạng thái yêu cầu xóa</label>
                    <div class="input-group">
                        <input type="text" id="id" name="id" placeholder="Nhập mã xác nhận xóa dữ liệu (ví dụ: del_...)" required>
                        <button type="submit" class="btn-lookup">Tra cứu</button>
                    </div>
                </div>
            </form>

            <div class="footer-links">
                <a href="privacy_policy.php">Chính sách bảo mật</a>
                <span style="color: rgba(255,255,255,0.2); margin: 0 10px;">|</span>
                <a href="terms_of_service.php">Điều khoản dịch vụ</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- Handle POST Request (Facebook Data Deletion Callback) ---
header('Content-Type: application/json');

if (!isset($_POST['signed_request'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing signed_request parameter.']);
    exit;
}

$signed_request = $_POST['signed_request'];

// Get all active App Secrets to support multi-tenant/multi-app configuration
$secrets = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT fb_app_secret FROM system_accounts WHERE fb_app_secret IS NOT NULL AND fb_app_secret != ''");
    $secrets = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Database error fetching app secrets: " . $e->getMessage());
}

$data = null;
foreach ($secrets as $secret) {
    $parsed = parse_signed_request($signed_request, $secret);
    if ($parsed !== null) {
        $data = $parsed;
        break;
    }
}

if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signed_request signature.']);
    exit;
}

$fb_user_id = $data['user_id'] ?? null;
if (empty($fb_user_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing user_id in signed_request payload.']);
    exit;
}

$confirmation_code = 'del_' . bin2hex(random_bytes(16));
$deleted_successfully = false;

try {
    // Look up the user ID in the users table matching the Facebook User ID
    $stmt = $pdo->prepare("SELECT id, account_id FROM users WHERE fb_id = ?");
    $stmt->execute([$fb_user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_id = intval($user['id']);
        $account_id = intval($user['account_id']);

        $pdo->beginTransaction();

        // 1. Get page IDs of this user
        $pg_stmt = $pdo->prepare("SELECT page_id FROM pages WHERE user_id = ?");
        $pg_stmt->execute([$user_id]);
        $page_ids = $pg_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($page_ids)) {
            $in = implode(',', array_fill(0, count($page_ids), '?'));

            // Delete scheduled posts
            $pdo->prepare("DELETE FROM scheduled_posts WHERE page_id IN ($in)")->execute($page_ids);

            // Delete posts history
            $pdo->prepare("DELETE FROM posts_history WHERE page_id IN ($in)")->execute($page_ids);

            // Delete page shares
            $pdo->prepare("DELETE FROM page_shares WHERE page_id IN ($in)")->execute($page_ids);
        }

        // Delete campaigns that don't have scheduled posts
        $pdo->prepare("DELETE FROM post_campaigns WHERE account_id = ? AND id NOT IN (SELECT DISTINCT campaign_id FROM scheduled_posts WHERE campaign_id IS NOT NULL)")->execute([$account_id]);

        // Delete pages
        $pdo->prepare("DELETE FROM pages WHERE user_id = ?")->execute([$user_id]);

        // Delete user
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);

        // Record the deletion request
        $ins_stmt = $pdo->prepare("INSERT INTO data_deletion_requests (confirmation_code, fb_user_id, status) VALUES (?, ?, 'completed')");
        $ins_stmt->execute([$confirmation_code, $fb_user_id]);

        $pdo->commit();
        $deleted_successfully = true;

        // Clear dashboard cache
        $cache_dir = __DIR__ . '/uploads/cache';
        if (is_dir($cache_dir)) {
            foreach (glob($cache_dir . "/dashboard_user_{$account_id}_*.json") as $cf) { @unlink($cf); }
            foreach (glob($cache_dir . "/dashboard_admin_*.json") as $cf) { @unlink($cf); }
        }
    } else {
        // User not found in database (might have been deleted already), still insert log
        $ins_stmt = $pdo->prepare("INSERT INTO data_deletion_requests (confirmation_code, fb_user_id, status) VALUES (?, ?, 'not_found')");
        $ins_stmt->execute([$confirmation_code, $fb_user_id]);
        $deleted_successfully = true;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Facebook Deletion error for user fb_id $fb_user_id: " . $e->getMessage());
}

if ($deleted_successfully) {
    // Generate status URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $domain = $_SERVER['HTTP_HOST'];
    $status_url = $protocol . $domain . get_base_url() . "deletion_status.php?id=" . $confirmation_code;

    echo json_encode([
        'url' => $status_url,
        'confirmation_code' => $confirmation_code
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error occurred while deleting data.']);
}
exit;
