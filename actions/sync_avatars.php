<?php
// Suppress all warnings/notices from appearing in output
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

session_start();
if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
    exit;
}

try {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/fb_api.php';

    $account_id = $_SESSION['account_id'];

    // Get all users (tokens) belonging to this account
    $stmt = $pdo->prepare("SELECT id, access_token FROM users WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy token nào.']);
        exit;
    }

    $updated = 0;
    $update_stmt = $pdo->prepare("UPDATE pages SET avatar = ? WHERE page_id = ?");

    foreach ($users as $user) {
        $token = decryptData($user['access_token']);
        if (empty($token)) continue;

        // Use the existing get_fb_user_pages() which already includes picture{url}
        $after_cursor = null;
        $has_next = true;

        while ($has_next) {
            $res = get_fb_user_pages($token, $after_cursor);

            if ($res['status_code'] === 200 && isset($res['data']['data'])) {
                foreach ($res['data']['data'] as $page) {
                    if (isset($page['picture']['data']['url']) && !empty($page['picture']['data']['url'])) {
                        $avatar_url = "avatar.php?id=" . $page['id'];
                        $update_stmt->execute([$avatar_url, $page['id']]);
                        $updated++;
                    }
                }

                // Check for next page
                if (isset($res['data']['paging']['cursors']['after']) && count($res['data']['data']) > 0) {
                    $after_cursor = $res['data']['paging']['cursors']['after'];
                } else {
                    $has_next = false;
                }
            } else {
                $has_next = false;
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'msg' => "Đã cập nhật avatar cho {$updated} Fanpage.",
        'updated' => $updated
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
