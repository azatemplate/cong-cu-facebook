<?php
// actions/publish_story.php
error_reporting(0);
ob_start();
session_start();
set_time_limit(0); // Chống timeout khi tạo hàng ngàn story

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

ob_end_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.']);
    exit;
}

$account_id          = $_SESSION['account_id'];
$user_id             = intval($_POST['user_id'] ?? 0);
$drive_file_ids_str  = trim($_POST['drive_file_id'] ?? '');
$comment_lines = isset($_POST['enable_comment']) && !empty(trim($_POST['comment_lines'] ?? ''))
    ? trim($_POST['comment_lines'])
    : null;
$delete_drive_file   = isset($_POST['delete_drive_file']) && $_POST['delete_drive_file'] == '1';
$is_drive_folder     = (strpos($drive_file_ids_str, 'folder:') === 0);

$page_ids = [];
if (isset($_POST['page_ids']) && is_array($_POST['page_ids'])) {
    $page_ids = $_POST['page_ids'];
} elseif (!empty($_POST['page_id'])) {
    $page_ids[] = $_POST['page_id'];
}

if (!$user_id || empty($page_ids)) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng chọn đầy đủ User và Fanpage.']);
    exit;
}

// ── Build Media Pool ──────────────────────────────────────────────────────
$media_pool = [];

if (!empty($drive_file_ids_str)) {
    foreach (array_filter(array_map('trim', explode(",", $drive_file_ids_str))) as $id) {
        $media_pool[] = ['type' => 'drive', 'id' => $id];
    }
}

if (isset($_FILES['media']) && is_array($_FILES['media']['name'])) {
    for ($i = 0; $i < count($_FILES['media']['name']); $i++) {
        if ($_FILES['media']['error'][$i] === UPLOAD_ERR_OK) {
            $media_pool[] = [
                'type'     => 'local',
                'tmp_name' => $_FILES['media']['tmp_name'][$i],
                'name'     => $_FILES['media']['name'][$i],
                'mime'     => mime_content_type($_FILES['media']['tmp_name'][$i])
            ];
        }
    }
} elseif (empty($media_pool) && isset($_FILES['media']) && !is_array($_FILES['media']['name']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
    $media_pool[] = [
        'type'     => 'local',
        'tmp_name' => $_FILES['media']['tmp_name'],
        'name'     => $_FILES['media']['name'],
        'mime'     => mime_content_type($_FILES['media']['tmp_name'])
    ];
}

if (empty($media_pool) && !$is_drive_folder) {
    echo json_encode(['status' => 'error', 'msg' => 'Vui lòng cung cấp ít nhất 1 File (Ảnh/Video hoặc từ Google Drive).']);
    exit;
}

// ── Schedule Matrix ───────────────────────────────────────────────────────
$start_date = trim($_POST['start_date'] ?? '');
$end_date   = trim($_POST['end_date'] ?? '');
$time_slots = trim($_POST['time_slots'] ?? '');
$schedule_dates = [];

if (!empty($start_date) && !empty($end_date) && !empty($time_slots)) {
    $slots   = array_filter(array_map('trim', explode(',', $time_slots)));
    $current = strtotime($start_date);
    $end     = strtotime($end_date);
    if ($current && $end && $current <= $end) {
        while ($current <= $end) {
            foreach ($slots as $slot) {
                $schedule_dates[] = date('Y-m-d', $current) . ' ' . $slot . ':00';
            }
            $current = strtotime('+1 day', $current);
        }
    }
}

if ($delete_drive_file && !$is_drive_folder) {
    $drive_count = 0;
    foreach ($media_pool as $m) {
        if ($m['type'] === 'drive') {
            $drive_count++;
        }
    }
    
    $total_posts = !empty($schedule_dates) ? count($schedule_dates) * count($page_ids) : count($page_ids);
    if ($drive_count < $total_posts) {
        echo json_encode(['status' => 'error', 'msg' => "Số lượng file Google Drive đã chọn ({$drive_count} file) không đủ. Bạn cần tối thiểu {$total_posts} file cho {$total_posts} story."]);
        exit;
    }
}

$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

// Pre-copy local files to avoid copying thousands of times in the loop
foreach ($media_pool as &$media) {
    if ($media['type'] === 'local') {
        $is_photo = strpos($media['mime'], 'image') !== false;
        $ext      = pathinfo($media['name'], PATHINFO_EXTENSION) ?: ($is_photo ? 'jpg' : 'mp4');
        $filename = uniqid('story_') . '.' . $ext;
        $media_path = 'uploads/' . $filename;
        copy($media['tmp_name'], $upload_dir . $filename);
        $media['saved_path'] = $media_path;
        $media['is_photo']   = $is_photo;
    }
}
unset($media);

// ── Helper: resolve media for story ──────────────────────────────────────
function resolve_story_media($media) {
    if ($media['type'] === 'drive') {
        return ['drive:' . $media['id'], 'Story'];
    }
    $post_type = 'Story (' . ($media['is_photo'] ? 'Image' : 'Video') . ')';
    return [$media['saved_path'], $post_type];
}

// ── Create Campaign (fault-tolerant) ─────────────────────────────────────
$campaign_id = null;
$first_time    = !empty($schedule_dates) ? $schedule_dates[0] : date('Y-m-d H:i:s');
$total_posts   = !empty($schedule_dates) ? count($schedule_dates) * count($page_ids) : count($page_ids);
$campaign_name = 'Story — ' . count($page_ids) . ' Pages';
if (!empty($schedule_dates)) {
    $campaign_name .= ' — ' . date('d/m', strtotime($start_date)) . '→' . date('d/m/Y', strtotime($end_date));
} else {
    $campaign_name .= ' — ' . date('d/m/Y H:i');
}
try {
    $camp_stmt = $pdo->prepare("INSERT INTO post_campaigns (account_id, name, post_type, total_posts, scheduled_time) VALUES (?, ?, ?, ?, ?)");
    $camp_stmt->execute([$account_id, $campaign_name, 'Story', $total_posts, $first_time]);
    $campaign_id = $pdo->lastInsertId();
} catch (PDOException $e) {
    // Table may not exist yet. Scheduling continues without campaign.
}

// ── Insert Posts ──────────────────────────────────────────────────────────
$has_extra_cols = true;

if (!$has_extra_cols) $campaign_id = null;

$s_stmt_with    = $has_extra_cols
    ? $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status, campaign_id, comment_lines) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
    : null;
$s_stmt_without = $pdo->prepare("INSERT INTO scheduled_posts (account_id, page_id, post_type, content, media_path, scheduled_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");

$drive_pool = [];
if ($delete_drive_file) {
    foreach ($media_pool as $m) {
        if ($m['type'] === 'drive') {
            $drive_pool[] = $m;
        }
    }
    shuffle($drive_pool);
}

$success_count  = 0;
$dates_to_use   = !empty($schedule_dates) ? $schedule_dates : [date('Y-m-d H:i:s')];

try {
    $pdo->beginTransaction();
    foreach ($dates_to_use as $datetime) {
        foreach ($page_ids as $p_id) {
            if ($is_drive_folder) {
                $media_path = $drive_file_ids_str;
                $post_type = 'Story';
            } else {
                if ($delete_drive_file) {
                    $media = array_shift($drive_pool);
                } else {
                    $media = $media_pool[array_rand($media_pool)];
                }
                [$media_path, $post_type] = resolve_story_media($media);
            }
            
            $payload_arr = [];
            if ($delete_drive_file) {
                $payload_arr['delete_drive_file'] = 1;
            }
            $content_payload = !empty($payload_arr) ? json_encode($payload_arr) : 'Story';
            
            if ($campaign_id !== null && $s_stmt_with !== null) {
                $s_stmt_with->execute([$account_id, $p_id, $post_type, $content_payload, $media_path, $datetime, $campaign_id, $comment_lines]);
            } else {
                $s_stmt_without->execute([$account_id, $p_id, $post_type, $content_payload, $media_path, $datetime]);
            }
            $success_count++;
        }
    }
    $pdo->commit();

    if (!empty($schedule_dates)) {
        echo json_encode(['status' => 'success', 'msg' => "Đã thả {$success_count} Story vào hàng đợi lên lịch hàng loạt!", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
    } else {
        echo json_encode(['status' => 'success', 'msg' => "Đã đưa $success_count Story vào hàng đợi xử lý ngay.", 'redirect' => 'manage_posts.php', 'campaign_id' => $campaign_id]);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'msg' => 'Quá trình lưu dữ liệu có lỗi: ' . $e->getMessage()]);
}
?>
