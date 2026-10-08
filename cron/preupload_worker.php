<?php
// cron/preupload_worker.php
// Worker xử lý upload nháp 1 bài viết sang Meta Graph API trước giờ hẹn.

if (php_sapi_name() !== 'cli' && !isset($_GET['post_id'])) {
    die("CLI access only.");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/drive_utils.php';

$post_id = 0;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $post_id = (int)$argv[1];
} elseif (isset($_GET['post_id']) && is_numeric($_GET['post_id'])) {
    $post_id = (int)$_GET['post_id'];
}

if ($post_id <= 0) {
    die("Invalid Post ID.\n");
}

$temp_drive_file = null;

try {
    $stmt = $pdo->prepare("
        SELECT sp.*, p.access_token as page_access_token, sa.access_token as account_token
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
    $raw_media  = $post['media_path'];
    $post_type  = $post['post_type'];

    if (empty($page_id) || empty($page_token) || empty($raw_media)) {
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'failed' WHERE id = {$post_id}");
        die("Missing required page credentials or media path.\n");
    }

    // ── XỬ LÝ ĐƯỜNG DẪN MEDIA (Google Drive / Thư mục / File Local) ──
    $abs_media_path = '';
    $is_drive  = (strpos($raw_media, 'drive:') === 0);
    $is_folder = (strpos($raw_media, 'folder:') === 0);

    if ($is_folder) {
        $folder_id   = substr($raw_media, 7);
        $drive_token = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
        if ($drive_token) {
            $file_info = resolve_drive_folder_file($pdo, $drive_token, $folder_id);
            if (!isset($file_info['error']) && !empty($file_info['id'])) {
                $drive_file_id  = $file_info['id'];
                $new_media_path = 'drive:' . $drive_file_id;
                $pdo->prepare("UPDATE scheduled_posts SET media_path = ? WHERE id = ?")->execute([$new_media_path, $post_id]);
                
                $dl_info = download_drive_file_temp($drive_token, $drive_file_id);
                if (isset($dl_info['path']) && file_exists($dl_info['path'])) {
                    $abs_media_path  = $dl_info['path'];
                    $temp_drive_file = $abs_media_path;
                }
            }
        }
    } elseif ($is_drive) {
        $drive_file_id = substr($raw_media, 6);
        $drive_token   = get_drive_access_token($pdo, $post['account_id'], $post['page_id']);
        if ($drive_token) {
            $dl_info = download_drive_file_temp($drive_token, $drive_file_id);
            if (isset($dl_info['path']) && file_exists($dl_info['path'])) {
                $abs_media_path  = $dl_info['path'];
                $temp_drive_file = $abs_media_path;
            }
        }
    } else {
        $is_remote_url = (strpos($raw_media, 'http://') === 0 || strpos($raw_media, 'https://') === 0);
        if ($is_remote_url) {
            $abs_media_path = $raw_media;
        } else {
            $abs_media_path = __DIR__ . '/../' . ltrim($raw_media, '/');
            if (!file_exists($abs_media_path) && file_exists($raw_media)) {
                $abs_media_path = $raw_media;
            }
        }
    }

    if (empty($abs_media_path)) {
        $pdo->exec("UPDATE scheduled_posts SET preupload_status = 'failed' WHERE id = {$post_id}");
        die("❌ không thể xác định file media để Pre-upload.\n");
    }

    echo "🚀 [Pre-upload Worker] Bắt đầu Upload nháp Post #{$post_id} (Type: {$post_type})...\n";

    $res = fb_preupload_media($page_id, $page_token, $abs_media_path, $post_type);

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
} finally {
    // Không tự ý xóa temp file nếu còn cần cho lệnh publish sau này (chỉ xóa nếu là tmp download)
}
