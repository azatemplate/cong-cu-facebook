<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
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
$raw_post_ids = $_POST['post_ids'] ?? [];

$post_ids = [];
if (is_array($raw_post_ids)) {
    $post_ids = array_values(array_filter(array_map('strval', $raw_post_ids)));
} elseif (is_string($raw_post_ids)) {
    $decoded = @json_decode($raw_post_ids, true);
    $post_ids = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [trim($raw_post_ids)];
}

if (empty($post_ids)) {
    send_json(['status' => 'error', 'msg' => 'Vui lòng chọn ít nhất 1 bài viết để xóa.']);
}

session_write_close();

try {
    // Fetch all accessible pages to get access tokens
    $stmt_pages = $pdo->prepare("
        (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = :aid)
        UNION
        (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id WHERE ps.shared_with_account_id = :aid2)
    ");
    $stmt_pages->execute([':aid' => $account_id, ':aid2' => $account_id]);
    $all_pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

    $page_token_map = [];
    foreach ($all_pages as $p) {
        if (!empty($p['access_token'])) {
            $page_token_map[(string)$p['page_id']] = decryptData($p['access_token']);
        }
    }

    // Map posts to page_ids from local DB
    $stmt_fetched = $pdo->prepare("SELECT fb_post_id, page_id FROM fetched_fanpage_posts WHERE account_id = ?");
    $stmt_fetched->execute([$account_id]);
    $fetched_map = [];
    while ($row = $stmt_fetched->fetch(PDO::FETCH_ASSOC)) {
        $fetched_map[(string)$row['fb_post_id']] = (string)$row['page_id'];
    }

    $deleted_count = 0;
    $failed_count = 0;
    $errors = [];

    $stmt_del_db = $pdo->prepare("DELETE FROM fetched_fanpage_posts WHERE fb_post_id = ? AND account_id = ?");

    foreach ($post_ids as $fb_post_id) {
        $page_id = $fetched_map[$fb_post_id] ?? '';
        if (empty($page_id) && strpos($fb_post_id, '_') !== false) {
            $parts = explode('_', $fb_post_id);
            $page_id = $parts[0];
        }

        $token = $page_token_map[$page_id] ?? '';
        if (empty($token)) {
            // Fallback: search any valid token in account
            $token = reset($page_token_map) ?: '';
        }

        if (empty($token)) {
            $failed_count++;
            $errors[] = "Bài {$fb_post_id}: Không tìm thấy Access Token của Fanpage";
            $stmt_del_db->execute([$fb_post_id, $account_id]);
            continue;
        }

        // Call Facebook API DELETE /{fb_post_id}
        $res = fb_api_request("{$fb_post_id}", ['access_token' => $token], 'DELETE');
        
        if (
            ($res['status_code'] >= 200 && $res['status_code'] < 300) ||
            !empty($res['data']['success']) ||
            (isset($res['data']['error']['code']) && in_array($res['data']['error']['code'], [100, 10, 210, 803])) // Object doesn't exist or already deleted
        ) {
            $deleted_count++;
            $stmt_del_db->execute([$fb_post_id, $account_id]);
        } else {
            $err_msg = $res['data']['error']['message'] ?? 'Lỗi không xác định từ FB API';
            $failed_count++;
            $errors[] = "Bài {$fb_post_id}: {$err_msg}";
            // Still delete from local DB if Facebook says post is missing
            if (strpos(strtolower($err_msg), 'does not exist') !== false || strpos(strtolower($err_msg), 'deleted') !== false) {
                $stmt_del_db->execute([$fb_post_id, $account_id]);
            }
        }
    }

    if ($deleted_count > 0) {
        $msg = "Đã xóa thành công {$deleted_count} bài viết khỏi Fanpage!";
        if ($failed_count > 0) {
            $msg .= " ({$failed_count} bài thất bại: " . implode('; ', array_slice($errors, 0, 3)) . ")";
        }
        send_json(['status' => 'success', 'msg' => $msg, 'deleted_count' => $deleted_count]);
    } else {
        $err_summary = !empty($errors) ? implode('; ', array_slice($errors, 0, 3)) : 'Không thể xóa bài viết.';
        send_json(['status' => 'error', 'msg' => "Xóa thất bại: " . $err_summary]);
    }

} catch (Throwable $e) {
    send_json(['status' => 'error', 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
