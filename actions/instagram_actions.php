<?php
// actions/instagram_actions.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/fb_api.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['account_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập hệ thống.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$action = $_REQUEST['action'] ?? '';

if ($action === 'sync_channels') {
    try {
        $col_ig = $pdo->query("SHOW COLUMNS FROM pages LIKE 'ig_account_id'");
        if ($col_ig->rowCount() === 0) {
            $pdo->exec("ALTER TABLE pages ADD COLUMN ig_account_id VARCHAR(100) DEFAULT NULL, ADD COLUMN ig_username VARCHAR(191) DEFAULT NULL, ADD COLUMN ig_avatar TEXT DEFAULT NULL, ADD COLUMN ig_followers_count INT DEFAULT 0");
        }

        $stmt = $pdo->prepare("
            SELECT p.page_id, p.access_token 
            FROM pages p
            JOIN users u ON p.user_id = u.id
            WHERE u.account_id = ?
        ");
        $stmt->execute([$account_id]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $synced = 0;
        $upd_stmt = $pdo->prepare("UPDATE pages SET ig_account_id = ?, ig_username = ?, ig_avatar = ?, ig_followers_count = ? WHERE page_id = ?");

        foreach ($pages as $pg) {
            $token = decryptData($pg['access_token']);
            if (!$token) continue;

            $res = fb_api_request($pg['page_id'], [
                'fields' => 'instagram_business_account{id,username,name,profile_picture_url,followers_count,media_count}',
                'access_token' => $token
            ]);

            if ($res['status_code'] === 200 && isset($res['data']['instagram_business_account']['id'])) {
                $ig = $res['data']['instagram_business_account'];
                $upd_stmt->execute([
                    $ig['id'],
                    $ig['username'] ?? '',
                    $ig['profile_picture_url'] ?? '',
                    (int)($ig['followers_count'] ?? 0),
                    $pg['page_id']
                ]);
                $synced++;
            }
        }

        echo json_encode(['success' => true, 'message' => "Đã đồng bộ $synced kênh Instagram Business thành công."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi đồng bộ: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'fetch_posts') {
    $page_id = trim($_GET['page_id'] ?? '');
    if (empty($page_id)) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng chọn Trang Instagram.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.page_id, p.access_token, p.ig_account_id, p.name as page_name, p.ig_username 
        FROM pages p
        JOIN users u ON p.user_id = u.id
        WHERE p.page_id = ? AND u.account_id = ?
    ");
    $stmt->execute([$page_id, $account_id]);
    $page_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page_info || empty($page_info['ig_account_id'])) {
        echo json_encode(['success' => false, 'message' => 'Trang này chưa kết nối tài khoản Instagram Business.']);
        exit;
    }

    $token = decryptData($page_info['access_token']);
    $ig_id = $page_info['ig_account_id'];

    $fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count';
    $res = fb_api_request("$ig_id/media", [
        'fields' => $fields,
        'limit' => 50,
        'access_token' => $token
    ]);

    if ($res['status_code'] !== 200 || !isset($res['data']['data'])) {
        $err = $res['data']['error']['message'] ?? 'Không thể tải danh sách bài viết từ Instagram API.';
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }

    $posts = $res['data']['data'];

    foreach ($posts as &$post) {
        $media_id = $post['id'];
        $post['views'] = 0;

        $metric = '';
        if (($post['media_type'] ?? '') === 'REELS' || ($post['media_type'] ?? '') === 'VIDEO') {
            $metric = 'plays,reach,saved';
        } else {
            $metric = 'impressions,reach,saved';
        }

        $ins_res = fb_api_request("$media_id/insights", [
            'metric' => $metric,
            'access_token' => $token
        ]);

        if ($ins_res['status_code'] === 200 && isset($ins_res['data']['data'])) {
            foreach ($ins_res['data']['data'] as $m) {
                if ($m['name'] === 'plays' || $m['name'] === 'impressions') {
                    $post['views'] = $m['values'][0]['value'] ?? 0;
                }
            }
        }
    }
    unset($post);

    echo json_encode(['success' => true, 'data' => $posts, 'ig_username' => $page_info['ig_username']]);
    exit;
}

if ($action === 'delete_post') {
    $page_id = trim($_POST['page_id'] ?? '');
    $media_id = trim($_POST['media_id'] ?? '');

    if (empty($page_id) || empty($media_id)) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông số bài viết cần xóa.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.access_token 
        FROM pages p
        JOIN users u ON p.user_id = u.id
        WHERE p.page_id = ? AND u.account_id = ?
    ");
    $stmt->execute([$page_id, $account_id]);
    $pg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pg) {
        echo json_encode(['success' => false, 'message' => 'Trang không hợp lệ.']);
        exit;
    }

    $token = decryptData($pg['access_token']);

    $res = fb_api_request($media_id, [
        'access_token' => $token
    ], 'DELETE');

    if ($res['status_code'] === 200 && !empty($res['data']['success'])) {
        echo json_encode(['success' => true, 'message' => 'Đã xóa bài viết trên Instagram thành công.']);
    } else {
        $err = $res['data']['error']['message'] ?? 'Xóa bài viết thất bại từ phía Instagram API.';
        echo json_encode(['success' => false, 'message' => $err]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
