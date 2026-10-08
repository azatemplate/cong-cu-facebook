<?php
// cron/preupload_worker.php
// Worker xử lý upload nháp 1 bài viết sang Meta Graph API trước giờ hẹn.

if (php_sapi_name() !== 'cli' && !isset($_GET['post_id'])) {
    die("CLI access only.");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';

$post_id = 0;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $post_id = (int)$argv[1];
} elseif (isset($_GET['post_id']) && is_numeric($_GET['post_id'])) {
    $post_id = (int)$_GET['post_id'];
}

if ($post_id <= 0) {
    die("Invalid Post ID.\n");
}

try {
    $stmt = $pdo->prepare("
        SELECT sp.*, p.page_access_token, sa.access_token as account_token
        FROM scheduled_posts sp
        LEFT JOIN pages p ON sp.page_id = p.page_id
        LEFT JOIN system_accounts sa ON sp.account_id = sa.id
        WHERE sp.id = ?
    ");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        die("Post #{$post_id} not found.\n");
    }

    $page_token = !empty($post['page_access_token']) ? $post['page_access_token'] : ($post['account_token'] ?? '');
    $page_id    = $post['page_id'];
    $media_path = $post['media_path'];
    $post_type  = $post['post_type'];

    if (empty($page_id) || empty($page_token) || empty($media_path)) {
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'failed' WHERE id = {$post_id}");
        die("Missing required page credentials or media path.\n");
    }

    echo "🚀 [Pre-upload Worker] Bắt đầu Upload nháp Post #{$post_id} (Type: {$post_type})...\n";

    $res = fb_preupload_media($page_id, $page_token, $media_path, $post_type);

    if (!empty($res['status']) && !empty($res['media_id'])) {
        $media_id   = $res['media_id'];
        $session_id = $res['session_id'] ?? '';

        $stmt_up = $pdo->prepare("
            UPDATE scheduled_posts 
            SET preupload_status = 'uploaded', 
                preuploaded_media_id = ?, 
                preupload_session_id = ? 
            WHERE id = ?
        ");
        $stmt_up->execute([$media_id, $session_id, $post_id]);

        echo "✅ [Pre-upload Worker] Thành công! Post #{$post_id} -> Preuploaded Media ID: {$media_id}\n";
    } else {
        $err = $res['error'] ?? 'Unknown error';
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'failed' WHERE id = {$post_id}");
        echo "❌ [Pre-upload Worker] Thất bại cho Post #{$post_id}: {$err}\n";
    }

} catch (Exception $e) {
    @$pdo->exec("UPDATE scheduled_posts SET preupload_status = 'failed' WHERE id = {$post_id}");
    echo "Lỗi Pre-upload Worker: " . $e->getMessage() . "\n";
}
