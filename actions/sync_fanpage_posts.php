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
$target_page_id = $_POST['page_id'] ?? 'ALL';
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

    // Fetch pages
    if ($target_page_id === 'ALL' || empty($target_page_id)) {
        $stmt_pages = $pdo->prepare("
            (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN users u ON p.user_id = u.id WHERE u.account_id = :aid)
            UNION
            (SELECT p.page_id, p.name, p.access_token FROM pages p JOIN page_shares ps ON p.page_id = ps.page_id WHERE ps.shared_with_account_id = :aid2)
        ");
        $stmt_pages->execute([':aid' => $account_id, ':aid2' => $account_id]);
    } else {
        $stmt_pages = $pdo->prepare("
            SELECT p.page_id, p.name, p.access_token 
            FROM pages p 
            JOIN users u ON p.user_id = u.id 
            WHERE u.account_id = :aid AND p.page_id = :pid
        ");
        $stmt_pages->execute([':aid' => $account_id, ':pid' => $target_page_id]);
    }

    $pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pages)) {
        echo json_encode(['status' => 'error', 'msg' => 'Không tìm thấy Fanpage nào để quét']);
        exit;
    }

    $total_synced = 0;
    $pages_synced = 0;

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
        if (empty($p['access_token'])) continue;
        $page_token = decryptData($p['access_token']);
        if (empty($page_token)) continue;

        // Fetch top 50 published posts
        $endpoint = "{$p['page_id']}/published_posts";
        $params = [
            'access_token' => $page_token,
            'fields'       => 'id,message,created_time,full_picture,permalink_url,reactions.summary(true),comments.summary(true)',
            'limit'        => 50
        ];

        $res = fb_api_request($endpoint, $params, 'GET');

        if (!empty($res['data']) && is_array($res['data'])) {
            $pages_synced++;
            foreach ($res['data'] as $post) {
                $fb_post_id = $post['id'] ?? '';
                if (empty($fb_post_id)) continue;

                $msg = $post['message'] ?? '';
                $picture = $post['full_picture'] ?? '';
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

    echo json_encode([
        'status' => 'success',
        'msg'    => "Đã quét và cập nhật thành công {$total_synced} bài viết từ {$pages_synced} Fanpage."
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Lỗi quét bài viết: ' . $e->getMessage()]);
}
?>
