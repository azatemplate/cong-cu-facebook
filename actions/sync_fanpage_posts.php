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

$only_has_text = isset($_POST['only_has_text']) ? intval($_POST['only_has_text']) : 1;

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

    // Fetch all pages owned or shared with this account (UNION query for 100% reliability)
    $stmt_pages = $pdo->prepare("
        (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = :aid)
        UNION
        (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id WHERE ps.shared_with_account_id = :aid2)
    ");
    $stmt_pages->execute([':aid' => $account_id, ':aid2' => $account_id]);
    $all_pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_pages)) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Fanpage nào trong tài khoản của bạn.']);
        exit;
    }

    // Filter target pages
    $pages = [];
    $is_all = in_array('ALL', $target_page_ids, true) || empty($target_page_ids);
    foreach ($all_pages as $p) {
        if ($is_all || in_array((string)$p['page_id'], $target_page_ids, true)) {
            $pages[] = $p;
        }
    }

    if (empty($pages)) {
        echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 Fanpage để quét.']);
        exit;
    }

    // Clear old fetched posts for selected target pages to keep DB light, clean and prevent disk bloat
    $target_page_id_list = array_column($pages, 'page_id');
    if (!empty($target_page_id_list)) {
        $in_del = implode(',', array_fill(0, count($target_page_id_list), '?'));
        $stmt_del = $pdo->prepare("DELETE FROM fetched_fanpage_posts WHERE account_id = ? AND page_id IN ($in_del)");
        $stmt_del->execute(array_merge([$account_id], $target_page_id_list));
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

        // Fast Graph API Request Parameters (Ultra-fast direct response without summary field timeouts)
        $params = [
            'access_token' => $page_token,
            'fields'       => 'id,message,created_time,permalink_url,full_picture,attachments{media,type,url}',
            'limit'        => $limit
        ];

        // Primary endpoints for Page Access Token
        $endpoints = [
            "me/posts",
            "{$p['page_id']}/posts"
        ];
        $res_data = [];
        $last_err = '';

        foreach ($endpoints as $ep) {
            $res = fb_api_request($ep, $params, 'GET');
            if (!empty($res['data']['data']) && is_array($res['data']['data'])) {
                $res_data = $res['data']['data'];
                break;
            } elseif (!empty($res['data']['error']['message'])) {
                $last_err = $res['data']['error']['message'];
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
                $created_at = date('Y-m-d H:i:s', $created_ts);
                
                $msg = $post['message'] ?? '';
                if ($only_has_text && trim($msg) === '') {
                    continue;
                }
                
                // Picture fallback logic
                $picture = $post['full_picture'] ?? '';
                if (empty($picture) && !empty($post['attachments']['data'][0]['media']['image']['src'])) {
                    $picture = $post['attachments']['data'][0]['media']['image']['src'];
                }

                $link = $post['permalink_url'] ?? "https://facebook.com/{$fb_post_id}";
                $likes = (int)($post['likes']['summary']['total_count'] ?? ($post['reactions']['summary']['total_count'] ?? 0));
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
    if (!empty($api_errors) && $total_synced === 0) {
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
