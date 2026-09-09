<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/fb_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Chỉ chấp nhận phương thức POST']);
    exit;
}

$account_id = (int)$_SESSION['account_id'];
$target_page_id = trim($_POST['page_id'] ?? 'ALL');
$date_from = trim($_POST['date_from'] ?? '');
$date_to   = trim($_POST['date_to'] ?? '');
session_write_close();

try {
    // Ensuring table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS fetched_fanpage_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        page_id VARCHAR(100) NOT NULL,
        fb_post_id VARCHAR(100) UNIQUE NOT NULL,
        message TEXT NULL,
        picture TEXT NULL,
        permalink_url TEXT NULL,
        likes_count INT DEFAULT 0,
        comments_count INT DEFAULT 0,
        views_count INT DEFAULT 0,
        post_created_at DATETIME NOT NULL,
        synced_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_acc_page (account_id, page_id),
        INDEX idx_stats (likes_count, comments_count, post_created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Fetch page using strict page token belonging to the target page
    $stmt_pages = $pdo->prepare("
        SELECT DISTINCT p.page_id, p.name, p.access_token 
        FROM pages p 
        LEFT JOIN users u ON p.user_id = u.id 
        LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
        WHERE (u.account_id = :aid OR ps.shared_with_account_id = :aid2)
          AND (:pid = 'ALL' OR p.page_id = :pid2)
    ");
    $stmt_pages->execute([
        ':aid'  => $account_id,
        ':aid2' => $account_id,
        ':pid'  => $target_page_id,
        ':pid2' => $target_page_id
    ]);
    $pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pages)) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Fanpage hợp lệ hoặc bạn chưa được phân quyền sử dụng Fanpage này']);
        exit;
    }

    $total_synced = 0;
    $pages_synced = 0;
    $api_errors = [];

    $stmt_upsert = $pdo->prepare("
        INSERT INTO fetched_fanpage_posts 
        (account_id, page_id, fb_post_id, message, picture, permalink_url, likes_count, comments_count, post_created_at, synced_at)
        VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
        message = VALUES(message),
        picture = VALUES(picture),
        permalink_url = VALUES(permalink_url),
        likes_count = VALUES(likes_count),
        comments_count = VALUES(comments_count),
        synced_at = NOW()
    ");

    foreach ($pages as $p) {
        if (empty($p['access_token'])) {
            $api_errors[] = "Trang {$p['name']}: Thiếu Access Token";
            continue;
        }

        $page_token = decryptData($p['access_token']);
        if (empty($page_token)) {
            $api_errors[] = "Trang {$p['name']}: Token không hợp lệ";
            continue;
        }

        // Fetch published posts using Page Access Token (me/posts endpoint)
        $params = [
            'access_token' => $page_token,
            'fields'       => 'id,message,created_time,full_picture,permalink_url,attachments{media,type,url},reactions.summary(true),comments.summary(true)',
            'limit'        => 100
        ];

        if (!empty($date_from)) {
            $params['since'] = strtotime($date_from . " 00:00:00");
        }
        if (!empty($date_to)) {
            $params['until'] = strtotime($date_to . " 23:59:59");
        }

        // Endpoints to query: me/posts is primary when using Page Token
        $endpoints = ["me/posts", "me/published_posts", "me/feed", "{$p['page_id']}/posts"];
        $res_data = [];
        $last_err = '';

        foreach ($endpoints as $ep) {
            $res = fb_api_request($ep, $params, 'GET');
            if (!empty($res['data']) && is_array($res['data'])) {
                $res_data = $res['data'];
                break;
            } elseif (!empty($res['error']['message'])) {
                $last_err = $res['error']['message'];
            }
        }

        if (empty($res_data) && !empty($last_err)) {
            $api_errors[] = "Trang {$p['name']}: {$last_err}";
        }

        if (!empty($res_data)) {
            $pages_synced++;
            foreach ($res_data as $post) {
                $fb_post_id = $post['id'] ?? '';
                if (empty($fb_post_id)) continue;

                $created_raw = $post['created_time'] ?? '';
                $created_ts = !empty($created_raw) ? strtotime($created_raw) : time();
                
                // Double check date filtering in PHP
                if (!empty($date_from) && $created_ts < strtotime($date_from . " 00:00:00")) continue;
                if (!empty($date_to) && $created_ts > strtotime($date_to . " 23:59:59")) continue;

                $created_at = date('Y-m-d H:i:s', $created_ts);
                $msg = $post['message'] ?? '';
                $picture = $post['full_picture'] ?? '';
                if (empty($picture) && !empty($post['attachments']['data'][0]['media']['image']['src'])) {
                    $picture = $post['attachments']['data'][0]['media']['image']['src'];
                }
                $link = $post['permalink_url'] ?? "https://facebook.com/{$fb_post_id}";
                $created_raw = $post['created_time'] ?? '';
                $created_at = !empty($created_raw) ? date('Y-m-d H:i:s', strtotime($created_raw)) : date('Y-m-d H:i:s');

                $likes = (int)($post['reactions']['summary']['total_count'] ?? 0);
                $comments = (int)($post['comments']['summary']['total_count'] ?? 0);

                $stmt_upsert->execute([
                    $account_id,
                    $p['page_id'],
                    $fb_post_id,
                    $msg,
                    $picture,
                    $link,
                    $likes,
                    $comments,
                    $created_at
                ]);
                $total_synced++;
            }
        }
    }

    $msg = "Đã quét và cập nhật thành công {$total_synced} bài viết từ {$pages_synced} Fanpage bằng Token chính chủ.";
    if (!empty($api_errors) && $total_synced === 0) {
        $msg .= " Thông báo từ Facebook: " . implode(" | ", array_unique($api_errors));
    }

    echo json_encode([
        'status' => ($total_synced > 0 || empty($api_errors)) ? 'success' : 'error',
        'msg'    => $msg
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi quét bài viết: ' . $e->getMessage()]);
}
?>
