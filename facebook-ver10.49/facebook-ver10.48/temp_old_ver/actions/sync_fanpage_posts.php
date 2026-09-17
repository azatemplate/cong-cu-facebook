<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(120);
ini_set('memory_limit', '512M');
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/fb_api.php';

function send_json($data) {
    @ob_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['account_id'])) {
    send_json(['status' => 'error', 'msg' => 'Unauthorized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['status' => 'error', 'msg' => 'Chỉ chấp nhận phương thức POST']);
}

$account_id = (int)$_SESSION['account_id'];

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
if ($limit > 1000) $limit = 1000;

$only_has_text = isset($_POST['only_has_text']) ? intval($_POST['only_has_text']) : 1;
$is_first = isset($_POST['is_first']) ? intval($_POST['is_first']) : 1;
$accumulated_synced = isset($_POST['accumulated_synced']) ? intval($_POST['accumulated_synced']) : 0;

$raw_cursors = $_POST['next_cursors'] ?? '';
$next_cursors = [];
if (!empty($raw_cursors)) {
    $decoded_c = @json_decode($raw_cursors, true);
    if (is_array($decoded_c)) $next_cursors = $decoded_c;
}

session_write_close();

try {
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

    $stmt_pages = $pdo->prepare("
        (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = :aid)
        UNION
        (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id WHERE ps.shared_with_account_id = :aid2)
    ");
    $stmt_pages->execute([':aid' => $account_id, ':aid2' => $account_id]);
    $all_pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_pages)) {
        send_json(['status' => 'error', 'msg' => 'Không tìm thấy Fanpage nào trong tài khoản của bạn.']);
    }

    $pages = [];
    $is_all = in_array('ALL', $target_page_ids, true) || empty($target_page_ids);
    foreach ($all_pages as $p) {
        if ($is_all || in_array((string)$p['page_id'], $target_page_ids, true)) {
            $pages[] = $p;
        }
    }

    if (empty($pages)) {
        send_json(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 Fanpage để quét.']);
    }

    // Only clear table on first batch request of session
    if ($is_first === 1) {
        $stmt_del = $pdo->prepare("DELETE FROM fetched_fanpage_posts WHERE account_id = ?");
        $stmt_del->execute([$account_id]);
    }

    $step_synced = 0;
    $new_next_cursors = [];

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

    $target_chunk_for_this_request = 50; // Max 50 posts per HTTP request (~2s execution)

    foreach ($pages as $p) {
        if ($accumulated_synced + $step_synced >= $limit) break;

        $page_id = (string)$p['page_id'];
        if (empty($p['access_token'])) continue;

        $page_token = decryptData($p['access_token']);
        if (empty($page_token)) continue;

        $initial_res = null;
        $next_url = $next_cursors[$page_id] ?? null;

        if (!empty($next_url)) {
            $res_next = fb_api_request_url($next_url);
            if (!empty($res_next['data']['data']) && is_array($res_next['data']['data'])) {
                $initial_res = $res_next['data'];
            }
        } else {
            $endpoints = [
                "{$page_id}/published_posts",
                "me/published_posts",
                "{$page_id}/posts",
                "me/posts"
            ];
            foreach ($endpoints as $ep) {
                $params = [
                    'access_token' => $page_token,
                    'fields'       => 'id,message,created_time,permalink_url,full_picture',
                    'limit'        => 25
                ];
                $res = fb_api_request($ep, $params, 'GET');
                if (!empty($res['data']['data']) && is_array($res['data']['data'])) {
                    $initial_res = $res['data'];
                    break;
                }
            }
        }

        if (!empty($initial_res['data']) && is_array($initial_res['data'])) {
            $current_res = $initial_res;
            $attempts = 0;
            $max_attempts_per_step = 3;

            while (!empty($current_res['data']) && is_array($current_res['data']) && ($accumulated_synced + $step_synced) < $limit && $attempts < $max_attempts_per_step) {
                $attempts++;
                foreach ($current_res['data'] as $post) {
                    if (($accumulated_synced + $step_synced) >= $limit) break;

                    $fb_post_id = $post['id'] ?? '';
                    if (empty($fb_post_id)) continue;

                    $msg = $post['message'] ?? '';
                    if ($only_has_text && trim($msg) === '') continue;

                    $picture = $post['full_picture'] ?? '';
                    $link = $post['permalink_url'] ?? "https://facebook.com/{$fb_post_id}";
                    $likes = (int)($post['likes']['summary']['total_count'] ?? 0);
                    $comments = (int)($post['comments']['summary']['total_count'] ?? 0);
                    $created_ts = !empty($post['created_time']) ? strtotime($post['created_time']) : time();

                    $stmt_upsert->execute([
                        $account_id,
                        $page_id,
                        $fb_post_id,
                        $msg,
                        $picture,
                        $link,
                        $likes,
                        $comments,
                        date('Y-m-d H:i:s', $created_ts)
                    ]);

                    $step_synced++;
                    if ($step_synced >= $target_chunk_for_this_request) break;
                }

                if (!empty($current_res['paging']['next'])) {
                    $new_next_cursors[$page_id] = $current_res['paging']['next'];
                } else {
                    unset($new_next_cursors[$page_id]);
                }

                if ($step_synced >= $target_chunk_for_this_request) break;

                if (!empty($current_res['paging']['next'])) {
                    $res_n = fb_api_request_url($current_res['paging']['next']);
                    if (!empty($res_n['data']['data']) && is_array($res_n['data']['data'])) {
                        $current_res = $res_n['data'];
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }
        }
    }

    $total_now = $accumulated_synced + $step_synced;
    $finished = ($total_now >= $limit) || empty($new_next_cursors) || ($step_synced === 0);

    if ($finished) {
        $msg = "Đã quét và cập nhật thành công {$total_now} bài viết!";
    } else {
        $msg = "Đang quét bài từ Fanpage... (Đã thu thập {$total_now}/{$limit} bài)";
    }

    send_json([
        'status'         => 'success',
        'finished'       => $finished,
        'step_synced'    => $step_synced,
        'total_synced'   => $total_now,
        'next_cursors'   => json_encode($new_next_cursors),
        'msg'            => $msg
    ]);

} catch (Throwable $e) {
    send_json(['status' => 'error', 'msg' => 'Lỗi quét bài viết: ' . $e->getMessage()]);
}
