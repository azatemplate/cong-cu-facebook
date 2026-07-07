<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

function errorHandler($errno, $errstr, $errfile, $errline) {
    file_put_contents(__DIR__ . '/test_err_get.txt', "Error [$errno]: $errstr in $errfile on line $errline");
}
set_error_handler("errorHandler");
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err !== null) {
        file_put_contents(__DIR__ . '/test_err_get.txt', "Fatal: {$err['message']} in {$err['file']} on line {$err['line']}");
    }
});

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$page_id = $_GET['page_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$cursor = $_GET['after'] ?? '';
$merge_all = isset($_GET['merge_all']) ? intval($_GET['merge_all']) : 0;

$formatted_posts = [];
$next_cursor = '';
$next_cursors_multi = [];

// Get Unread post IDs for this account or specific page
$unread_post_ids = [];
try {
    if ($merge_all === 1) {
        $stmt_notif = $pdo->prepare("
            SELECT n.post_id FROM page_notifications n
            JOIN pages p ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
            JOIN users u ON p.user_id = u.id
            WHERE (
                u.account_id = ?
                OR EXISTS (SELECT 1 FROM page_shares ps WHERE ps.page_id = p.page_id AND ps.shared_with_account_id = ?)
            ) AND n.is_read = 0 AND n.type = 'comment' AND n.post_id IS NOT NULL
        ");
        $stmt_notif->execute([$_SESSION['account_id'], $_SESSION['account_id']]);
    } else {
        $stmt_notif = $pdo->prepare("
            SELECT post_id FROM page_notifications 
            WHERE page_id = ? AND is_read = 0 AND type = 'comment' AND post_id IS NOT NULL
        ");
        $stmt_notif->execute([$page_id]);
    }
    if ($stmt_notif) {
        $unread_post_ids = $stmt_notif->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    // If the table doesn't exist yet, we just ignore
}

// Giải phóng session lock sớm — tránh block các request song song (pollBadge, live_chat, vv.)
session_write_close();

if ($merge_all === 1) {
    $append = isset($_GET['append']) ? intval($_GET['append']) : 0;
    
    // Tối ưu: Chỉ lấy tối đa 20 Fanpage có hoạt động chat hoặc thông báo bình luận gần đây nhất
    // Việc này giúp tránh quá tải kết nối API Facebook khi tài khoản có tới 750+ Fanpage vệ tinh
    $stmt_pages = $pdo->prepare("
        SELECT page_id, name, user_id, access_token, MAX(last_active) as max_active
        FROM (
            (SELECT p.page_id, p.name, p.user_id, p.access_token,
                   GREATEST(
                       COALESCE(MAX(c.updated_time), '1970-01-01 00:00:00'),
                       COALESCE(MAX(n.created_at), '1970-01-01 00:00:00')
                   ) as last_active
            FROM pages p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN fb_conversations c ON p.page_id = c.page_id
            LEFT JOIN page_notifications n ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
            WHERE u.account_id = :aid
            GROUP BY p.page_id, p.name, p.user_id, p.access_token)
            UNION
            (SELECT p.page_id, p.name, p.user_id, p.access_token,
                   GREATEST(
                       COALESCE(MAX(c.updated_time), '1970-01-01 00:00:00'),
                       COALESCE(MAX(n.created_at), '1970-01-01 00:00:00')
                   ) as last_active
            FROM pages p
            JOIN page_shares ps ON p.page_id = ps.page_id
            LEFT JOIN fb_conversations c ON p.page_id = c.page_id
            LEFT JOIN page_notifications n ON n.page_id COLLATE utf8mb4_0900_ai_ci = p.page_id
            WHERE ps.shared_with_account_id = :aid2
            GROUP BY p.page_id, p.name, p.user_id, p.access_token)
        ) as combined_pages
        GROUP BY page_id, name, user_id, access_token
        ORDER BY max_active DESC, page_id DESC
        LIMIT 20
    ");
    $stmt_pages->bindValue(':aid', $_SESSION['account_id'], PDO::PARAM_INT);
    $stmt_pages->bindValue(':aid2', $_SESSION['account_id'], PDO::PARAM_INT);
    $stmt_pages->execute();
    $all_pages_raw = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);
    
    $pages = [];
    $cursors = [];
    if ($append === 1 && isset($_SESSION['lc_merge_cursors'])) {
        $cursors = $_SESSION['lc_merge_cursors'];
    } elseif ($append === 0) {
        session_start();
        $_SESSION['lc_merge_cursors'] = [];
        session_write_close();
    }

    foreach ($all_pages_raw as $p) {
        if (!empty($p['access_token'])) {
            if ($append === 1 && empty($cursors[$p['page_id']])) continue;
            $p['access_token'] = decryptData($p['access_token']);
            $pages[] = $p;
        }
    }
    
    if (empty($pages)) {
        echo json_encode(['status' => 'success', 'data' => [], 'next_cursor' => '', 'merged' => true]);
        exit;
    }
    
    $multi_result = get_fb_posts_multi($pages, 15, $cursors);
    $data = $multi_result['data'];
    
    session_start();
    $_SESSION['lc_merge_cursors'] = $multi_result['cursors'];
    session_write_close();
    
    $next_cursor = !empty($multi_result['cursors']) ? 'has_more' : '';
    
} else {
    if (!$page_id || !$user_id) {
        echo json_encode(['status' => 'error', 'msg' => 'Thiếu tham số']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT u.account_id, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE p.page_id = ? AND u.id = ?");
    $stmt->execute([$page_id, $user_id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page || !$page['access_token']) {
        echo json_encode(['status' => 'error', 'msg' => 'Không có quyền truy cập hoặc Page chưa cấu hình token']);
        exit;
    }

    $token = decryptData($page['access_token']);
    $endpoint = "$page_id/feed";
    $params = [
        'fields' => 'id,message,created_time,full_picture,comments.summary(true),reactions.summary(true)',
        'limit' => 10, // Giảm từ 30 → 10 để tránh lỗi "reduce amount of data"
        'access_token' => $token
    ];
    if ($cursor) {
        $params['after'] = $cursor;
    }

    $response = fb_api_request($endpoint, $params, 'GET');

    if (isset($response['data']['error'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Lỗi FB: ' . $response['data']['error']['message']]);
        exit;
    }
    $data = $response['data']['data'] ?? [];
    $next_cursor = $response['data']['paging']['cursors']['after'] ?? null;
    
    // Nv1: Trích xuất bài cụ thể nếu user jump từ link
    $target_post_id = $_GET['target_post_id'] ?? '';
    if ($target_post_id && !$cursor) {
        // Đảm bảo target có dạng PAGEID_POSTID
        $full_target_id = strpos($target_post_id, '_') !== false ? $target_post_id : "{$page_id}_{$target_post_id}";
        
        // Kiểm tra xem bài này có trong list $data vừa lấy chưa (tránh trùng)
        $found = false;
        foreach ($data as $p) {
            if ($p['id'] === $full_target_id) { $found = true; break; }
        }
        
        if (!$found) {
            $single_res = fb_api_request($full_target_id, [
                'fields' => 'id,message,created_time,full_picture,comments.summary(true),reactions.summary(true)',
                'access_token' => $token
            ], 'GET');
            if (isset($single_res['data']['id'])) {
                array_unshift($data, $single_res['data']);
            }
        }
    }
}

foreach ($data as $post) {
    if ($merge_all === 1) {
        $comment_count = $post['comment_count'] ?? 0;
        $reaction_count = $post['reaction_count'] ?? 0;
    } else {
        $comment_count = $post['comments']['summary']['total_count'] ?? 0;
        $reaction_count = $post['reactions']['summary']['total_count'] ?? 0;
    }
    
    $formatted_posts[] = [
        'id' => $post['id'],
        'page_id' => $post['_page_id'] ?? $page_id,
        'user_id' => $post['_user_id'] ?? $user_id,
        'message' => $post['message'] ?? 'Bài viết/Ảnh không có chữ',
        'created_time' => $post['created_time'],
        'picture' => $merge_all === 1 ? ($post['picture'] ?? null) : ($post['full_picture'] ?? null),
        'comment_count' => $comment_count,
        'reaction_count' => $reaction_count,
        'has_comments' => $comment_count > 0,
        'page_name' => $post['_page_name'] ?? null,
        'is_unread' => in_array($post['id'], $unread_post_ids)
    ];
}

echo json_encode([
    'status' => 'success',
    'data' => $formatted_posts,
    'next_cursor' => $next_cursor,
    'merged' => ($merge_all === 1)
]);
