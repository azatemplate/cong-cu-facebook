<?php
// actions/publish_post.php
error_reporting(0);
ob_start();
session_start();

// ── Auth Guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['account_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập. Vui lòng đăng nhập lại.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fb_api.php';
require_once __DIR__ . '/../includes/drive_utils.php';
require_once __DIR__ . '/../includes/ai_rewriter.php';

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

$account_id = $_SESSION['account_id'];
$user_id    = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

// Support either single page_id or array of page_ids
$page_ids = [];
if (isset($_POST['page_ids']) && is_array($_POST['page_ids'])) {
    $page_ids = $_POST['page_ids'];
} elseif (isset($_POST['page_id']) && !empty($_POST['page_id'])) {
    $page_ids[] = $_POST['page_id'];
}

$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$use_ai  = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';
$comment_lines = isset($_POST['enable_comment']) && !empty(trim($_POST['comment_lines'] ?? ''))
    ? trim($_POST['comment_lines'])
    : null;

if (!$user_id || empty($page_ids) || empty($message)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng điền đầy đủ các thông tin bắt buộc.']);
    exit;
}

$drive_file_ids_str = isset($_POST['drive_file_id']) ? trim($_POST['drive_file_id']) : '';
$upload_dir = __DIR__ . '/../uploads/';

// ── Build Media Pool ──────────────────────────────────────────────────────
$media_pool = [];
$post_type  = 'Status';

if (!empty($drive_file_ids_str)) {
    $ids = array_filter(array_map('trim', explode(",", $drive_file_ids_str)));
    foreach ($ids as $id) {
        $media_pool[] = ['type' => 'drive', 'id' => $id];
        $post_type = 'Image';
    }
}

if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
    $count = count($_FILES['images']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
            $media_pool[] = [
                'type'     => 'local',
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'name'     => $_FILES['images']['name'][$i],
                'mime'     => mime_content_type($_FILES['images']['tmp_name'][$i])
            ];
            $post_type = 'Image';
        }
    }
}

$content_data = json_encode([
    'description' => $message,
    'use_ai'      => $use_ai,
    'title'       => '',
    'auto_title'  => false
]);

$scheduled_time = isset($_POST['scheduled_time']) && !empty(trim($_POST['scheduled_time']))
    ? trim($_POST['scheduled_time'])
    : date('Y-m-d H:i:s');

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ── Create Campaign (fault-tolerant: works even if table doesn't exist yet) ──
$campaign_id   = null;
$page_count    = count($page_ids);
$campaign_name = $post_type . ' — ' . $page_count . ' Pages — ' . date('d/m/Y H:i');
try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
    $camp_stmt->execute([$account_id, $campaign_name, $post_type, $page_count, $scheduled_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (PDOException $e) {
    // Table may not exist yet — run migrate.php to create it. Scheduling continues without campaign.
}

// ── Insert Scheduled Posts ───────────────────────────────────────────────────
// Detect if campaign_id/comment_lines columns exist via INFORMATION_SCHEMA
$has_extra_cols = false;
try {
    $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='campaign_id'");
    $has_extra_cols = ($col_chk && $col_chk->fetchColumn() > 0);
} catch (Exception $e) { }

if (!$has_extra_cols) $campaign_id = null;

$s_stmt_with    = $has_extra_cols
    ? $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id, comment_lines) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
    : null;
$s_stmt_without = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");

// Save local uploaded files once (so they persist from tmp)
$saved_local_files = [];
foreach ($media_pool as $idx => $media) {
    if ($media['type'] === 'local') {
        $ext      = pathinfo($media['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = uniqid('img_') . '.' . $ext;
        copy($media['tmp_name'], $upload_dir . $filename);
        $saved_local_files[$idx] = 'uploads/' . $filename;
    }
}

/**
 * Build media_path for one page.
 * - If multiple images: shuffle and return JSON array of paths → publish_worker will do multi-photo post
 * - If single image: return single path string (backward compatible)
 */
function build_media_path_shuffled($media_pool, $saved_local_files) {
    if (empty($media_pool)) return null;

    // Shuffle a copy of the pool for this page
    $pool = $media_pool;
    shuffle($pool);

    $paths = [];
    foreach ($pool as $media) {
        if ($media['type'] === 'drive') {
            $paths[] = 'drive:' . $media['id'];
        } else {
            // Find saved local file path by matching tmp_name
            foreach ($saved_local_files as $idx => $saved_path) {
                if ($media_pool[$idx]['tmp_name'] === $media['tmp_name']) {
                    $paths[] = $saved_path;
                    break;
                }
            }
        }
    }

    // Single image → backward compatible string
    if (count($paths) === 1) return $paths[0];
    // Multiple images → JSON array (publish_worker will detect and handle)
    return json_encode($paths);
}

$success_count = 0;
foreach ($page_ids as $p_id) {
    $media_path = build_media_path_shuffled($media_pool, $saved_local_files);
    if ($campaign_id !== null && $s_stmt_with !== null) {
        $s_stmt_with->execute([$account_id, $p_id, $post_type, $content_data, $media_path, $scheduled_time, $campaign_id, $comment_lines]);
    } else {
        $s_stmt_without->execute([$account_id, $p_id, $post_type, $content_data, $media_path, $scheduled_time]);
    }
    $success_count++;
}

$is_scheduled = strtotime($scheduled_time) > time();
if ($is_scheduled) {
    echo json_encode(['status' => 'success', 'msg' => "Đã lên lịch thành công cho $success_count Fanpage.", 'campaign_id' => $campaign_id]);
} else {
    echo json_encode(['status' => 'success', 'msg' => "Đã đưa $success_count bài đăng vào hàng đợi xử lý ngay lập tức.", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
}
?>
