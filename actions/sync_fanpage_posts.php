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

// Target pages selection (single, array, or 'ALL')
$raw_pages = $_POST['page_ids'] ?? ($_POST['page_id'] ?? 'ALL');
$target_page_ids = [];
if (is_array($raw_pages)) {
    $target_page_ids = array_map('strval', $raw_pages);
} elseif (is_string($raw_pages) && strpos($raw_pages, '[') !== false) {
    $decoded = @json_decode($raw_pages, true);
    $target_page_ids = is_array($decoded) ? array_map('strval', $decoded) : [$raw_pages];
} else {
    $target_page_ids = [(string)$raw_pages];
}

$limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
if ($limit < 1) $limit = 10;
if ($limit > 100) $limit = 100;

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

    // Fetch pages matching user authority
    if (in_array('ALL', $target_page_ids, true)) {
        $stmt_pages = $pdo->prepare("
            SELECT DISTINCT p.page_id, p.name, p.access_token 
            FROM pages p 
            LEFT JOIN users u ON p.user_id = u.id 
            LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
            WHERE (u.account_id = :aid OR ps.shared_with_account_id = :aid2)
        ");
        $stmt_pages->execute([':aid' => $account_id, ':aid2' => $account_id]);
    } else {
        $in_clause = implode(',', array_fill(0, count($target_page_ids), '?'));
        $stmt_pages = $pdo->prepare("
            SELECT DISTINCT p.page_id, p.name, p.access_token 
            FROM pages p 
            LEFT JOIN users u ON p.user_id = u.id 
            LEFT JOIN page_shares ps ON p.page_id = ps.page_id 
            WHERE (u.account_id = ? OR ps.shared_with_account_id = ?)
              AND p.page_id IN ($in_clause)
        ");
        $stmt_pages->execute(array_merge([$account_id, $account_id], $target_page_ids));
    }

    $pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pages)) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Fanpage hợp lệ hoặc bạn chưa được phân quyền sử dụng các Fanpage đã chọn']);
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

        // Build Graph API Request Parameters per Page
        $params = [
            'access_token' => $page_token,
            'fields'       => 'id,message,created_time,permalink_url,full_picture,attachments{media,type,url},reactions.summary(true),comments.summary(true)',
            'limit'        => $limit
        ];

        if (!empty($date_from)) {
            $params['since'] = strtotime($date_from . " 00:00:00");
        }
        if (!empty($date_to)) {
            $params['until'] = strtotime($date_to . " 23:59:59");
        }

        // Endpoints to query: {PAGE_ID}/posts is primary when using Page Token
        $endpoints = [
            "{$p['page_id']}/posts",
            "me/posts",
            "{$p['page_id']}/published_posts",
            "{$p['page_id']}/feed"
        ];
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

        // Fallback with simpler fields if Graph API errored on complex fields
        if (empty($res_data)) {
            $fallback_params = [
                'access_token' => $page_token,
                'fields'       => 'id,message,created_time,permalink_url,full_picture',
                'limit'        => $limit
            ];
            if (!empty($date_from)) $fallback_params['since'] = strtotime($date_from . " 00:00:00");
            if (!empty($date_to))   $fallback_params['until'] = strtotime($date_to . " 23:59:59");

            $res = fb_api_request("{$p['page_id']}/posts", $fallback_params, 'GET');
            if (!empty($res['data']) && is_array($res['data'])) {
                $res_data = $res['data'];
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
                
                // Double check date filtering in PHP if user specified dates
                if (!empty($date_from) && $created_ts < strtotime($date_from . " 00:00:00")) continue;
                if (!empty($date_to) && $created_ts > strtotime($date_to . " 23:59:59")) continue;

                $created_at = date('Y-m-d H:i:s', $created_ts);
                $msg = $post['message'] ?? '';
                
                // Picture fallback logic
                $picture = $post['full_picture'] ?? '';
                if (empty($picture) && !empty($post['attachments']['data'][0]['media']['image']['src'])) {
                    $picture = $post['attachments']['data'][0]['media']['image']['src'];
                }

                $link = $post['permalink_url'] ?? "https://facebook.com/{$fb_post_id}";
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

    $msg = "Đã quét và cập nhật thành công {$total_synced} bài viết (giới hạn {$limit} bài/page) từ {$pages_synced} Fanpage.";
    if (!empty($api_errors)) {
        $msg .= " Phản hồi Facebook: " . implode(" | ", array_unique($api_errors));
    }

    echo json_encode([
        'status' => ($total_synced > 0 || empty($api_errors)) ? 'success' : 'error',
        'msg'    => $msg
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi quét bài viết: ' . $e->getMessage()]);
}
?>
