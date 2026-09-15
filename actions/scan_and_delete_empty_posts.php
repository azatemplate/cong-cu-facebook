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

$limit = isset($_POST['limit']) ? intval($_POST['limit']) : 100;
if ($limit < 1) $limit = 20;
if ($limit > 1000) $limit = 1000;

$accumulated_scanned = isset($_POST['accumulated_scanned']) ? intval($_POST['accumulated_scanned']) : 0;
$accumulated_deleted = isset($_POST['accumulated_deleted']) ? intval($_POST['accumulated_deleted']) : 0;

$raw_cursors = $_POST['next_cursors'] ?? '';
$next_cursors = [];
if (!empty($raw_cursors)) {
    $decoded_c = @json_decode($raw_cursors, true);
    if (is_array($decoded_c)) $next_cursors = $decoded_c;
}

session_write_close();

try {
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
        send_json(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 Fanpage để quét và xóa.']);
    }

    $step_scanned = 0;
    $step_deleted = 0;
    $new_next_cursors = [];

    $stmt_del_db = $pdo->prepare("DELETE FROM fetched_fanpage_posts WHERE fb_post_id = ? AND account_id = ?");
    $target_chunk_for_this_request = 30; // Max 30 posts per HTTP request (~2-3s execution)

    foreach ($pages as $p) {
        if ($accumulated_scanned + $step_scanned >= $limit) break;

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
                    'fields'       => 'id,message,created_time,permalink_url',
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
            $max_attempts_per_step = 2;

            while (!empty($current_res['data']) && is_array($current_res['data']) && ($accumulated_scanned + $step_scanned) < $limit && $attempts < $max_attempts_per_step) {
                $attempts++;
                foreach ($current_res['data'] as $post) {
                    if (($accumulated_scanned + $step_scanned) >= $limit) break;

                    $step_scanned++;
                    $fb_post_id = $post['id'] ?? '';
                    if (empty($fb_post_id)) continue;

                    $msg = trim($post['message'] ?? '');
                    if ($msg === '') {
                        $del_res = fb_api_request("{$fb_post_id}", ['access_token' => $page_token], 'DELETE');
                        if (
                            ($del_res['status_code'] >= 200 && $del_res['status_code'] < 300) ||
                            !empty($del_res['data']['success']) ||
                            (isset($del_res['data']['error']['code']) && in_array($del_res['data']['error']['code'], [100, 10, 210, 803]))
                        ) {
                            $step_deleted++;
                            $stmt_del_db->execute([$fb_post_id, $account_id]);
                        }
                    }

                    if ($step_scanned >= $target_chunk_for_this_request) break;
                }

                if (!empty($current_res['paging']['next'])) {
                    $new_next_cursors[$page_id] = $current_res['paging']['next'];
                } else {
                    unset($new_next_cursors[$page_id]);
                }

                if ($step_scanned >= $target_chunk_for_this_request) break;

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

    $total_scanned_now = $accumulated_scanned + $step_scanned;
    $total_deleted_now = $accumulated_deleted + $step_deleted;
    $finished = ($total_scanned_now >= $limit) || empty($new_next_cursors) || ($step_scanned === 0);

    if ($finished) {
        $msg = "Đã quét {$total_scanned_now} bài viết và XÓA THÀNH CÔNG {$total_deleted_now} bài không chữ khỏi Fanpage!";
    } else {
        $msg = "Đang quét & xóa bài... (Đã duyệt {$total_scanned_now}/{$limit} bài, đã xóa {$total_deleted_now} bài)";
    }

    send_json([
        'status'              => 'success',
        'finished'            => $finished,
        'step_scanned'        => $step_scanned,
        'step_deleted'        => $step_deleted,
        'total_scanned'       => $total_scanned_now,
        'total_deleted'       => $total_deleted_now,
        'next_cursors'        => json_encode($new_next_cursors),
        'msg'                 => $msg
    ]);

} catch (Throwable $e) {
    send_json(['status' => 'error', 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
