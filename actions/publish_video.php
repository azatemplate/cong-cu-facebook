<?php
// actions/publish_video.php
error_reporting(0);
ob_start();
session_start();
set_time_limit(0); // Chống timeout khi tạo hàng ngàn bài viết

// ── Auth Guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['account_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập.']);
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

$page_ids = [];
if (isset($_POST['page_ids']) && is_array($_POST['page_ids'])) {
    $page_ids = $_POST['page_ids'];
} elseif (isset($_POST['page_id']) && !empty($_POST['page_id'])) {
    $page_ids[] = $_POST['page_id'];
}

$title_input     = trim($_POST['title'] ?? '');
$desc_input      = trim($_POST['description'] ?? '');
$use_ai          = isset($_POST['use_ai']) && $_POST['use_ai'] == '1';
$is_reel         = isset($_POST['is_reel']) && $_POST['is_reel'] == '1';
$auto_title      = isset($_POST['auto_title']) && $_POST['auto_title'] == '1';
$tiktok_urls_str = trim($_POST['tiktok_urls'] ?? '');
$drive_file_ids_str = trim($_POST['drive_file_id'] ?? '');
$comment_lines = isset($_POST['enable_comment']) && !empty(trim($_POST['comment_lines'] ?? ''))
    ? trim($_POST['comment_lines'])
    : null;

// New: Insights-based comment mode
$comment_mode = null;
$comment_threshold_views = 0;
$comment_threshold_likes = 0;
$comment_threshold_comments = 0;

if ($comment_lines) {
    $comment_mode = 'timer'; // existing 120s mode
} elseif (isset($_POST['enable_comment_insights']) && !empty(trim($_POST['comment_lines_insights'] ?? ''))) {
    $comment_lines = trim($_POST['comment_lines_insights']);
    $comment_mode = 'insights';
    $comment_threshold_views = intval($_POST['threshold_views'] ?? 1000);
    $comment_threshold_likes = intval($_POST['threshold_likes'] ?? 10);
    $comment_threshold_comments = intval($_POST['threshold_comments'] ?? 5);
}

if (!$user_id || empty($page_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn đầy đủ User và Fanpage.']);
    exit;
}

// ── Build Media Pool ──────────────────────────────────────────────────────
$media_pool  = [];
$drive_token = null;

if (!empty($tiktok_urls_str)) {
    foreach (array_filter(array_map('trim', explode("\n", $tiktok_urls_str))) as $url) {
        $media_pool[] = ['type' => 'tiktok', 'url' => $url, 'title' => ''];
    }
}

if (!empty($drive_file_ids_str)) {
    // Lấy tên file từ frontend (đã có sẵn từ Drive browser) thay vì gọi API cho từng file
    $drive_names_str = trim($_POST['drive_file_names'] ?? '');
    $drive_ids_arr = array_filter(array_map('trim', explode(",", $drive_file_ids_str)));
    $drive_names_arr = !empty($drive_names_str) ? explode("|||", $drive_names_str) : [];
    
    foreach ($drive_ids_arr as $idx => $id) {
        $drive_title = '';
        $drive_name = isset($drive_names_arr[$idx]) ? trim($drive_names_arr[$idx]) : '';
        if (empty($drive_name)) {
            if (!$drive_token) $drive_token = get_drive_access_token($pdo, $account_id);
            if ($drive_token) $drive_name = get_drive_file_name($drive_token, $id);
        }
        if ($drive_name) {
            $drive_title = pathinfo($drive_name, PATHINFO_FILENAME);
        }
        $media_pool[] = ['type' => 'drive', 'id' => $id, 'title' => $drive_title, 'original_name' => $drive_name];
    }
}

if (isset($_FILES['video']) && is_array($_FILES['video']['name'])) {
    for ($i = 0; $i < count($_FILES['video']['name']); $i++) {
        if ($_FILES['video']['error'][$i] === UPLOAD_ERR_OK) {
            $media_pool[] = [
                'type'     => 'local',
                'tmp_name' => $_FILES['video']['tmp_name'][$i],
                'name'     => $_FILES['video']['name'][$i],
                'mime'     => mime_content_type($_FILES['video']['tmp_name'][$i])
            ];
        }
    }
} elseif (empty($media_pool) && isset($_FILES['video']) && !is_array($_FILES['video']['name']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $media_pool[] = [
        'type'     => 'local',
        'tmp_name' => $_FILES['video']['tmp_name'],
        'name'     => $_FILES['video']['name'],
        'mime'     => mime_content_type($_FILES['video']['tmp_name'])
    ];
}

if (empty($media_pool)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cung cấp ít nhất 1 Video (TikTok, Drive hoặc Tải lên).']);
    exit;
}

$post_type  = $is_reel ? 'Reel' : 'Video';
$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

// Pre-copy local files to avoid copying thousands of times in the loop
foreach ($media_pool as &$media) {
    if ($media['type'] === 'local') {
        $ext        = pathinfo($media['name'], PATHINFO_EXTENSION) ?: 'mp4';
        $filename   = uniqid('vid_') . '.' . $ext;
        $media_path = 'uploads/' . $filename;
        copy($media['tmp_name'], $upload_dir . $filename);
        $media['saved_path'] = $media_path;
    }
}
unset($media);

// ── Helper: Resolve media for 1 slot ─────────────────────────────────────
function resolve_media_path_video($media, $auto_title, $title_input, $desc_input) {
    $t_title = $title_input;
    $t_desc  = $desc_input;
    $media_path = null;
    $original_source = null;

    if ($media['type'] === 'drive') {
        $media_path = 'drive:' . $media['id'];
        $original_source = isset($media['original_name']) && !empty($media['original_name']) ? $media['original_name'] : $media['title'];
        if ($auto_title) { $t_title = $media['title']; $t_desc = $media['title'] . ($desc_input ? "\n\n" . $desc_input : ''); }
    } elseif ($media['type'] === 'tiktok') {
        $media_path = 'tiktok:' . $media['url'];
        $original_source = $media['url'];
        if ($auto_title) { 
            $t_title = $media['title']; 
            $t_desc = $media['title'] . ($desc_input ? "\n\n" . $desc_input : ''); 
        }
    } elseif ($media['type'] === 'local') {
        $media_path = $media['saved_path']; // use pre-copied path
        $original_source = $media['name'];
        if ($auto_title) {
            $fn_no_ext = pathinfo($media['name'], PATHINFO_FILENAME);
            $t_title   = $fn_no_ext;
            $t_desc    = $fn_no_ext . ($desc_input ? "\n\n" . $desc_input : '');
        }
    }
    return [$media_path, $t_title, $t_desc, $original_source];
}

// ── Schedule Matrix Parsing ───────────────────────────────────────────────
$start_date  = trim($_POST['start_date'] ?? '');
$end_date    = trim($_POST['end_date'] ?? '');
$time_slots  = trim($_POST['time_slots'] ?? '');
$schedule_dates = [];

if (!empty($start_date) && !empty($end_date) && !empty($time_slots)) {
    $slots   = array_filter(array_map('trim', explode(',', $time_slots)));
    $current = strtotime($start_date);
    $end     = strtotime($end_date);
    if ($current && $end && $current <= $end) {
        while ($current <= $end) {
            $date_str = date('Y-m-d', $current);
            foreach ($slots as $slot) {
                $schedule_dates[] = $date_str . ' ' . $slot . ':00';
            }
            $current = strtotime('+1 day', $current);
        }
    }
}

// ── Create Campaign (fault-tolerant) ─────────────────────────────────────
$campaign_id = null;
$first_time    = !empty($schedule_dates) ? $schedule_dates[0] : date('Y-m-d H:i:s');
$total_posts   = !empty($schedule_dates) ? count($schedule_dates) * count($page_ids) : count($page_ids);
$campaign_name = $post_type . ' — ' . count($page_ids) . ' Pages';
if (!empty($schedule_dates)) {
    $campaign_name .= ' — ' . date('d/m', strtotime($start_date)) . '→' . date('d/m/Y', strtotime($end_date));
} else {
    $campaign_name .= ' — ' . date('d/m/Y H:i');
}
try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
    $camp_stmt->execute([$account_id, $campaign_name, $post_type, $total_posts, $first_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (PDOException $e) {
    // Table may not exist yet — run migrate.php. Scheduling continues without campaign.
}

// ── Insert Posts ─────────────────────────────────────────────────────
// Detect if campaign_id/comment_lines columns exist via INFORMATION_SCHEMA
$has_extra_cols = false;
try {
    $col_chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='campaign_id'");
    $has_extra_cols = ($col_chk && $col_chk->fetchColumn() > 0);
} catch (Exception $e) { }

$has_comment_mode = false;
try {
    $col_chk2 = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='scheduled_posts' AND COLUMN_NAME='comment_mode'");
    $has_comment_mode = ($col_chk2 && $col_chk2->fetchColumn() > 0);
} catch (Exception $e) { }

if (!$has_extra_cols) $campaign_id = null;

$s_stmt_with    = ($has_extra_cols && $has_comment_mode)
    ? $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id, comment_lines, comment_mode, comment_threshold_views, comment_threshold_likes, comment_threshold_comments) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)")
    : ($has_extra_cols
        ? $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id, comment_lines) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
        : null);
$s_stmt_without = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");

function insert_sp($s_with, $s_without, $campaign_id, $comment_lines, $comment_mode, $comment_threshold_views, $comment_threshold_likes, $comment_threshold_comments, $has_comment_mode, ...$args) {
    if ($campaign_id !== null && $s_with !== null) {
        if ($has_comment_mode) {
            $s_with->execute(array_merge($args, [$campaign_id, $comment_lines, $comment_mode, $comment_threshold_views, $comment_threshold_likes, $comment_threshold_comments]));
        } else {
            $s_with->execute(array_merge($args, [$campaign_id, $comment_lines]));
        }
    } else {
        $s_without->execute($args);
    }
}

$success_count = 0;

try {
    $pdo->beginTransaction();

    if (!empty($schedule_dates)) {
        // Scheduled matrix mode
        foreach ($schedule_dates as $datetime) {
            foreach ($page_ids as $p_id) {
                $media = $media_pool[array_rand($media_pool)];
                [$media_path, $t_title, $t_desc, $original_source] = resolve_media_path_video($media, $auto_title, $title_input, $desc_input);
                $content_data = json_encode(['description' => $t_desc, 'title' => $t_title, 'auto_title' => $auto_title, 'use_ai' => $use_ai, 'original_source' => $original_source]);
                insert_sp($s_stmt_with, $s_stmt_without, $campaign_id, $comment_lines, $comment_mode, $comment_threshold_views, $comment_threshold_likes, $comment_threshold_comments, $has_comment_mode, $account_id, $p_id, $post_type, $content_data, $media_path, $datetime);
                $success_count++;
            }
        }
        $pdo->commit();
        echo json_encode(['status' => 'success', 'msg' => "Đã thả {$success_count} bài vào hàng đợi lên lịch hàng loạt!", 'campaign_id' => $campaign_id]);
    } else {
        // Immediate queue mode
        $now = date('Y-m-d H:i:s');
        foreach ($page_ids as $p_id) {
            $media = $media_pool[array_rand($media_pool)];
            [$media_path, $t_title, $t_desc, $original_source] = resolve_media_path_video($media, $auto_title, $title_input, $desc_input);
            $content_data = json_encode(['description' => $t_desc, 'title' => $t_title, 'auto_title' => $auto_title, 'use_ai' => $use_ai, 'original_source' => $original_source]);
            insert_sp($s_stmt_with, $s_stmt_without, $campaign_id, $comment_lines, $comment_mode, $comment_threshold_views, $comment_threshold_likes, $comment_threshold_comments, $has_comment_mode, $account_id, $p_id, $post_type, $content_data, $media_path, $now);
            $success_count++;
        }
        $pdo->commit();
        echo json_encode(['status' => 'success', 'msg' => "Đã đưa $success_count bài vào hàng đợi xử lý ngay.", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'msg' => 'Quá trình lưu dữ liệu có lỗi: ' . $e->getMessage()]);
}
?>
