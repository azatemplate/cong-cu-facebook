<?php
session_start();
set_time_limit(0); // Prevent PHP execution timeout when fetching thousands of pages
if (!isset($_SESSION['account_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['access_token']);
    $account_id = $_SESSION['account_id'];
    
    if (empty($token)) {
        header('Location: ../token_management.php?status=error&msg=' . urlencode('Token không được để trống.'));
        exit;
    }
    // 0. Long-Lived Token Exchange Attempt
    $stmt_app = $pdo->prepare("SELECT fb_app_id, fb_app_secret FROM system_accounts WHERE id = ?");
    $stmt_app->execute([$account_id]);
    $current_acc = $stmt_app->fetch(PDO::FETCH_ASSOC);

    $app_id = null;
    $app_secret = null;

    if (!empty($current_acc['fb_app_id']) && !empty($current_acc['fb_app_secret'])) {
        $app_id = $current_acc['fb_app_id'];
        $app_secret = $current_acc['fb_app_secret'];
    } else {
        $stmt_admin = $pdo->prepare("SELECT fb_app_id, fb_app_secret FROM system_accounts WHERE role = 'admin' LIMIT 1");
        $stmt_admin->execute();
        $admin_acc = $stmt_admin->fetch(PDO::FETCH_ASSOC);
        if ($admin_acc && !empty($admin_acc['fb_app_id']) && !empty($admin_acc['fb_app_secret'])) {
            $app_id = $admin_acc['fb_app_id'];
            $app_secret = $admin_acc['fb_app_secret'];
        }
    }

    if ($app_id && $app_secret) {
        $long_token = fb_exchange_token($token, $app_id, $app_secret);
        if ($long_token) {
            $token = $long_token; // Overwrite short-lived token provided by UI
        }
    }

    // 1. Verify User Token
    $profile_response = get_fb_user_profile($token);
    
    if ($profile_response['status_code'] !== 200 || !isset($profile_response['data']['id'])) {
        $error_msg = isset($profile_response['data']['error']['message']) ? $profile_response['data']['error']['message'] : 'Token không hợp lệ hoặc đã hết hạn.';
        header('Location: ../token_management.php?status=error&msg=' . urlencode($error_msg));
        exit;
    }

    $fb_user_id = $profile_response['data']['id'];
    $fb_user_name = $profile_response['data']['name'];

    // 2. Save User to DB
    $stmt = $pdo->prepare("SELECT id FROM users WHERE fb_id = ?");
    $stmt->execute([$fb_user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_db_id = $user['id'];
        $update_stmt = $pdo->prepare("UPDATE users SET name = ?, access_token = ?, account_id = ? WHERE id = ?");
        $update_stmt->execute([$fb_user_name, encryptData($token), $account_id, $user_db_id]);
    } else {
        $insert_stmt = $pdo->prepare("INSERT INTO users (fb_id, name, access_token, account_id) VALUES (?, ?, ?, ?)");
        $insert_stmt->execute([$fb_user_id, $fb_user_name, encryptData($token), $account_id]);
        $user_db_id = $pdo->lastInsertId();
    }

    // 2.5 Fetch current page constraints (including expiry)
    $limit_stmt = $pdo->prepare("SELECT role, page_limit, expire_date FROM system_accounts WHERE id = ?");
    $limit_stmt->execute([$account_id]);
    $acc_info = $limit_stmt->fetch(PDO::FETCH_ASSOC);
    $page_limit = (int)$acc_info['page_limit'];
    $is_admin   = ($acc_info['role'] === 'admin');

    // Block expired accounts from adding new tokens/pages
    if (!$is_admin && !empty($acc_info['expire_date']) && strtotime($acc_info['expire_date']) < time()) {
        header('Location: ../token_management.php?status=error&msg=' . urlencode('Tài khoản đã hết hạn. Vui lòng liên hệ Admin để gia hạn.'));
        exit;
    }

    $count_stmt = $pdo->prepare("SELECT COUNT(id) FROM pages WHERE user_id IN (SELECT id FROM users WHERE account_id = ?)");
    $count_stmt->execute([$account_id]);
    $current_total_pages = (int)$count_stmt->fetchColumn();

    // 3. Fetch user's pages with pagination
    $pages_count = 0;
    $after_cursor = null;
    $has_next = true;

    // Ensure avatar column exists before using it
    try {
        $col = $pdo->query("SHOW COLUMNS FROM pages LIKE 'avatar'");
        if ($col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE pages ADD COLUMN avatar TEXT DEFAULT NULL");
        } else {
            // If column exists but is VARCHAR, convert to TEXT
            $colInfo = $col->fetch(PDO::FETCH_ASSOC);
            if (stripos($colInfo['Type'], 'varchar') !== false) {
                $pdo->exec("ALTER TABLE pages MODIFY COLUMN avatar TEXT DEFAULT NULL");
            }
        }
    } catch (Exception $e) {}

    // Prepare statements once outside the loop
    $p_check_stmt = $pdo->prepare("SELECT p.id, u.account_id FROM pages p LEFT JOIN users u ON p.user_id = u.id WHERE p.page_id = ?");
    $p_update_stmt = $pdo->prepare("UPDATE pages SET name = ?, access_token = ?, category = ?, followers_count = ?, avatar = ?, user_id = ? WHERE page_id = ?");
    $p_insert_stmt = $pdo->prepare("INSERT INTO pages (page_id, name, access_token, category, followers_count, avatar, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");

    while ($has_next) {
        $pages_response = get_fb_user_pages($token, $after_cursor);
        
        if ($pages_response['status_code'] === 200 && isset($pages_response['data']['data'])) {
            $pages = $pages_response['data']['data'];
            
            foreach ($pages as $page) {
                $page_id = $page['id'];
                $page_name = $page['name'];
                $page_token = isset($page['access_token']) ? $page['access_token'] : '';
                $category = isset($page['category']) ? $page['category'] : '';
                $followers = isset($page['followers_count']) ? $page['followers_count'] : 0;
                $avatar = isset($page['picture']['data']['url']) ? $page['picture']['data']['url'] : null;

                // Upsert Page
                $p_check_stmt->execute([$page_id]);
                $existing_page = $p_check_stmt->fetch(PDO::FETCH_ASSOC);

                $belongs_to_me = false;
                if ($existing_page && $existing_page['account_id'] == $account_id) {
                    $belongs_to_me = true;
                }

                if ($existing_page) {
                    $p_update_stmt->execute([$page_name, encryptData($page_token), $category, $followers, $avatar, $user_db_id, $page_id]);
                    $pages_count++;
                } else {
                    $p_insert_stmt->execute([$page_id, $page_name, encryptData($page_token), $category, $followers, $avatar, $user_db_id]);
                    $pages_count++;
                }
            }
            
            // Check for next page cursor
            if (isset($pages_response['data']['paging']['cursors']['after']) && count($pages) > 0) {
                $after_cursor = $pages_response['data']['paging']['cursors']['after'];
            } else {
                $has_next = false;
            }
        } else {
            // Stop loop on API error
            $has_next = false;
        }
    }

    // Clear Dashboard Cache
    $cache_dir = __DIR__ . '/../uploads/cache';
    foreach (glob($cache_dir . "/dashboard_user_{$account_id}_*.json") as $cf) { @unlink($cf); }
    foreach (glob($cache_dir . "/dashboard_admin_*.json") as $cf) { @unlink($cf); }

    header('Location: ../token_management.php?status=success&pages=' . $pages_count);
    exit;
} else {
    header('Location: ../token_management.php');
    exit;
}
?>
